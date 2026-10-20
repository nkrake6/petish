"""The private diagnostic round-trips only for its recipient and authenticated context."""
import base64
import copy
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

from cryptography.exceptions import InvalidTag
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import padding, rsa
from cryptography.hazmat.primitives.ciphers.aead import AESGCM

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / 'scripts/encrypt-pet-club-option-names.py'
spec = importlib.util.spec_from_file_location('pet_club_encryption', SCRIPT)
crypto = importlib.util.module_from_spec(spec)
spec.loader.exec_module(crypto)


class PetClubEncryptionTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.key = rsa.generate_private_key(public_exponent=65537, key_size=4096)

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='petish-private-envelope-')
        self.addCleanup(self.temp.cleanup)
        self.directory = Path(self.temp.name)
        self.recipient = self.directory / 'public.pem'
        self.recipient.write_bytes(self.key.public_key().public_bytes(
            serialization.Encoding.PEM, serialization.PublicFormat.SubjectPublicKeyInfo))
        self.input = self.directory / 'private.json'
        self.payload = {'format': 'petish-club-private-options-v1', 'run_id': '39514823805-1',
                        'option_names': ['_private_diagnostic_name_sentinel', 'software_key_123']}
        self.input.write_text(json.dumps(self.payload))
        self.input.chmod(0o600)
        self.output = self.directory / 'ciphertext.json'
        self.run = self.payload['run_id']
        self.revision = 'a' * 40

    def create(self):
        value = crypto.encrypt(self.input, self.recipient, self.run, self.revision)
        self.output.write_text(json.dumps(value))
        self.output.chmod(0o600)
        return value

    def unwrap(self, value):
        return self.key.decrypt(base64.b64decode(value['wrapped_key']),
            padding.OAEP(mgf=padding.MGF1(hashes.SHA256()), algorithm=hashes.SHA256(), label=None))

    def test_recipient_round_trip_and_ciphertext_contains_no_names(self):
        value = self.create()
        self.assertEqual(crypto.validate_envelope(self.output, self.recipient, self.run, self.revision), value)
        plaintext = AESGCM(self.unwrap(value)).decrypt(base64.b64decode(value['nonce']),
                                                     base64.b64decode(value['ciphertext']), crypto.aad(value))
        self.assertEqual(json.loads(plaintext), self.payload)
        encoded = json.dumps(value)
        for name in self.payload['option_names']:
            self.assertNotIn(name, encoded)
        self.assertEqual(set(value), crypto.FIELDS)
        self.assertNotEqual(self.create()['ciphertext'], value['ciphertext'])

    def test_authenticated_metadata_and_ciphertext_tampering_fail_decryption(self):
        value = self.create()
        symmetric = self.unwrap(value)
        for field, changed in [('run_id', '39514823806-1'), ('source_commit', 'b' * 40),
                               ('recipient_sha256', 'b' * 64), ('algorithm', 'different')]:
            modified = {**value, field: changed}
            with self.assertRaises(InvalidTag):
                AESGCM(symmetric).decrypt(base64.b64decode(value['nonce']),
                    base64.b64decode(value['ciphertext']), crypto.aad(modified))
        ciphertext = bytearray(base64.b64decode(value['ciphertext']))
        ciphertext[0] ^= 1
        with self.assertRaises(InvalidTag):
            AESGCM(symmetric).decrypt(base64.b64decode(value['nonce']), bytes(ciphertext), crypto.aad(value))

    def test_publication_rejects_unexpected_fields_context_and_encoded_sizes(self):
        value = self.create()
        for field, changed in [('option_names', self.payload['option_names']), ('run_id', '2-1'),
                               ('source_commit', 'b' * 40), ('recipient_sha256', '0' * 64),
                               ('wrapped_key', base64.b64encode(b'x' * 256).decode()),
                               ('nonce', base64.b64encode(b'x' * 16).decode()), ('ciphertext', 'bad!')]:
            self.output.write_text(json.dumps({**value, field: changed}))
            with self.assertRaises(ValueError):
                crypto.validate_envelope(self.output, self.recipient, self.run, self.revision)

    def test_private_payload_scope_modes_links_and_recipient_size_are_enforced(self):
        for payload in ({**self.payload, 'values': ['private-value']},
                        {**self.payload, 'run_id': '2-1'}, {**self.payload, 'option_names': []},
                        {**self.payload, 'option_names': ['name\nsecret']},
                        {**self.payload, 'option_names': ['a'] * 33},
                        {**self.payload, 'option_names': ['é' * 96]}):
            self.input.write_text(json.dumps(payload))
            with self.assertRaises(ValueError):
                crypto.encrypt(self.input, self.recipient, self.run, self.revision)
        self.input.write_text(json.dumps(self.payload))
        self.input.chmod(0o644)
        with self.assertRaises(ValueError):
            crypto.encrypt(self.input, self.recipient, self.run, self.revision)
        self.input.chmod(0o600)
        linked = self.directory / 'linked.json'
        linked.symlink_to(self.input)
        with self.assertRaises(ValueError):
            crypto.encrypt(linked, self.recipient, self.run, self.revision)
        small = rsa.generate_private_key(public_exponent=65537, key_size=2048)
        self.recipient.write_bytes(small.public_key().public_bytes(
            serialization.Encoding.PEM, serialization.PublicFormat.SubjectPublicKeyInfo))
        with self.assertRaises(ValueError):
            crypto.encrypt(self.input, self.recipient, self.run, self.revision)

    def test_wrapper_indexes_only_validated_ciphertext_and_fails_before_git_on_bad_envelope(self):
        wrapper = (ROOT / 'scripts/hostinger-pet-club.sh').read_text()
        start = wrapper.index('publish_result() {')
        end = wrapper.index('\nfinish() {', start)
        function = wrapper[start:end]
        for scenario in ('valid', 'unexpected_private_field', 'unverified'):
            with self.subTest(scenario=scenario):
                work = self.directory / scenario
                (work / 'public').mkdir(parents=True, mode=0o700)
                envelope = crypto.encrypt(self.input, ROOT / 'operations/pet-club-diagnostic-recipient.pem',
                                          self.run, self.revision)
                if scenario == 'unexpected_private_field':
                    envelope['option_names'] = self.payload['option_names']
                target = work / 'public/private-option-names.encrypted.json'
                target.write_text(json.dumps(envelope))
                target.chmod(0o600)
                log = self.directory / (scenario + '.log')
                script = '\n'.join([
                    'work=$1; log=$2; target_run=$3; source_sha=$4; encrypted_option_names_verified=$5',
                    'run=99999-1; mode=cleanup; recovery_operation=diagnose; result_status=success; failure_category=complete',
                    'staging_source_verified=0; rollback_attempted=0; rollback_verified=0; published_commit=',
                    'git() { printf "%s\\n" "$*" >> "$log"; case "$1" in hash-object|write-tree) printf "%040d\\n" 1;; commit-tree) cat >/dev/null; printf "%040d\\n" 2;; esac; }',
                    function, 'publish_result',
                ])
                completed = subprocess.run(['bash', '-c', script, 'fixture', str(work), str(log),
                    self.run, self.revision, '0' if scenario == 'unverified' else '1'], cwd=ROOT, capture_output=True)
                self.assertEqual(completed.returncode == 0, scenario == 'valid')
                if scenario == 'valid':
                    indexed = [line for line in log.read_text().splitlines() if line.startswith('update-index ')]
                    self.assertEqual(len(indexed), 2)
                    self.assertTrue(indexed[0].endswith(',status.json'))
                    self.assertTrue(indexed[1].endswith(',private-option-names.encrypted.json'))
                    self.assertTrue(json.loads((work / 'public/status.json').read_text())['private_option_names_encrypted'])
                else:
                    self.assertFalse(log.exists())
                self.assertNotIn(b'_private_diagnostic_name_sentinel', completed.stdout + completed.stderr)

    def test_cli_never_logs_private_input_and_never_overwrites_output(self):
        arguments = ['python3', str(SCRIPT), str(self.input), str(self.recipient), str(self.output), self.run, self.revision]
        completed = subprocess.run(arguments, capture_output=True)
        self.assertEqual(completed.returncode, 0)
        self.assertEqual(completed.stdout, b'')
        self.assertEqual(completed.stderr, b'')
        self.assertEqual(self.output.stat().st_mode & 0o777, 0o600)
        original = self.output.read_bytes()
        completed = subprocess.run(arguments, capture_output=True)
        self.assertNotEqual(completed.returncode, 0)
        self.assertEqual(self.output.read_bytes(), original)
        self.input.write_text(json.dumps({**self.payload, 'private-value': 'sentinel-do-not-log'}))
        completed = subprocess.run(arguments, capture_output=True)
        self.assertNotIn(b'sentinel-do-not-log', completed.stdout + completed.stderr)


if __name__ == '__main__':
    unittest.main()
