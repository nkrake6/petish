"""Confidential order selectors and reports, without orders, hosting or network."""
import base64
import copy
from datetime import datetime, timedelta, timezone
import hashlib
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
SCRIPT = ROOT/'scripts/order-refund-envelope.py'
SPEC = importlib.util.spec_from_file_location('order_refund_transport', SCRIPT)
CRYPTO = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(CRYPTO)
OAEP = padding.OAEP(mgf=padding.MGF1(hashes.SHA256()), algorithm=hashes.SHA256(), label=None)


def pem(key):
    return key.public_key().public_bytes(serialization.Encoding.PEM, serialization.PublicFormat.SubjectPublicKeyInfo)


def fingerprint(key):
    return hashlib.sha256(key.public_key().public_bytes(
        serialization.Encoding.DER, serialization.PublicFormat.SubjectPublicKeyInfo)).hexdigest()


class RefundTransport(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.request_key=rsa.generate_private_key(public_exponent=65537,key_size=4096)
        cls.report_key=rsa.generate_private_key(public_exponent=65537,key_size=4096)

    def setUp(self):
        self.temp=tempfile.TemporaryDirectory(prefix='petish-order-transport-')
        self.addCleanup(self.temp.cleanup);self.root=Path(self.temp.name)
        self.run='40131123456-1';self.source='a'*40
        self.public=self.root/'report-public.pem';self.public.write_bytes(pem(self.report_key))
        self.selector=self.root/'selector.private.json'
        self.selection={'schema':1,'order_id':7755331199881,'expected_billing_first_name':'PRIVATE-FIRSTNAME-SENTINEL'}
        self.write(self.selector,self.selection)
        issued=datetime.now(timezone.utc)-timedelta(seconds=10)
        self.prepared={'format':'petish-order-refund-prepared-v1','run_id':self.run,'source_commit':self.source,
            'issued_utc':issued.isoformat(),'expires_utc':(issued+timedelta(hours=1)).isoformat(),
            'request_key_sha256':fingerprint(self.request_key),'public_key_pem':pem(self.request_key).decode()}
        self.prepared_path=self.root/'prepared.json';self.write(self.prepared_path,self.prepared)
        self.private=self.root/'report.private.json'
        self.report={'schema':1,'order':{'id':self.selection['order_id'],'note':'PRIVATE-CUSTOMER-SENTINEL'},
                     'diagnostic':{'refund_status':'synthetic-only'}}
        self.write(self.private,self.report)
        self.output=self.root/'report.encrypted.json'

    def write(self,path,value):
        path.write_text(json.dumps(value));path.chmod(0o600)

    def request(self):
        return CRYPTO.make_request(self.selector,self.prepared_path,self.public)

    def envelope(self):
        value=CRYPTO.encrypt_report(self.private,self.public,self.run,self.source)
        self.write(self.output,value);return value

    def test_only_prepared_server_key_can_read_the_exact_selector(self):
        self.assertEqual(CRYPTO.validate_prepared(self.prepared,self.run,self.source),self.prepared)
        value=self.request()
        self.assertEqual(CRYPTO.request_payload(value,self.public),value)
        self.assertEqual(set(value),{'format','prepare_run_id','request_key_sha256','report_recipient_sha256','encrypted_selector_base64'})
        self.assertEqual(value['request_key_sha256'],fingerprint(self.request_key))
        self.assertEqual(value['report_recipient_sha256'],fingerprint(self.report_key))
        cipher=base64.b64decode(value['encrypted_selector_base64'],validate=True)
        self.assertEqual(len(cipher),512)
        plaintext=json.loads(self.request_key.decrypt(cipher,OAEP))
        self.assertEqual(plaintext,{'schema':1,'prepare_run_id':self.run,'report_recipient_sha256':fingerprint(self.report_key),'selector':self.selection})
        with self.assertRaises(ValueError):self.report_key.decrypt(cipher,OAEP)
        self.assertNotIn('order_id',json.dumps(value))
        self.assertNotIn(str(self.selection['order_id']),json.dumps(value))
        self.assertNotIn('PRIVATE-FIRSTNAME-SENTINEL',json.dumps(value))
        self.assertNotEqual(self.request()['encrypted_selector_base64'],value['encrypted_selector_base64'])

    def test_preparation_is_bound_to_run_source_key_and_one_hour(self):
        now=datetime.now(timezone.utc)
        cases=[{'run_id':'999-1'},{'source_commit':'b'*40},{'format':'other'},
               {'request_key_sha256':'0'*64},{'customer_name':'PRIVATE'},
               {'issued_utc':(now-timedelta(hours=2)).isoformat(),'expires_utc':(now-timedelta(hours=1)).isoformat()},
               {'issued_utc':(now+timedelta(minutes=6)).isoformat(),'expires_utc':(now+timedelta(minutes=66)).isoformat()},
               {'expires_utc':(datetime.fromisoformat(self.prepared['issued_utc'])+timedelta(hours=2)).isoformat()},
               {'issued_utc':'2026-01-01T00:00:00','expires_utc':'2026-01-01T01:00:00'}]
        for changed in cases:
            with self.subTest(changed=changed),self.assertRaises(ValueError):
                CRYPTO.validate_prepared({**self.prepared,**changed},self.run,self.source)
        for run,source in [('0-1',self.source),('1-0',self.source),('1-1','main'),('../1-1',self.source)]:
            with self.assertRaises(ValueError):CRYPTO.validate_prepared(self.prepared,run,source)

    def test_selector_is_one_order_and_rejects_weak_types_extra_scope_and_duplicates(self):
        for value in [{'schema':1,'order_id':0},{'schema':1,'order_id':-1},{'schema':1,'order_id':True},
                      {'schema':True,'order_id':1},{'schema':1,'order_id':'123'},{'schema':1,'order_id':1.5},
                      {'schema':1,'order_id':9007199254740992},{'schema':1,'order_id':1,'email':'PRIVATE'},
                      {'schema':1,'order_id':1,'order_ids':[1,2]},
                      {'schema':1,'order_id':1,'expected_billing_first_name':True},
                      {'schema':1,'order_id':1,'expected_billing_first_name':''},
                      {'schema':1,'order_id':1,'expected_billing_first_name':'   '},
                      {'schema':1,'order_id':1,'expected_billing_first_name':'PRIVATE\n'},
                      {'schema':1,'order_id':1,'expected_billing_first_name':'x'*81}]:
            with self.subTest(value=value):
                self.write(self.selector,value)
                with self.assertRaises(ValueError):self.request()
        self.selector.write_text('{"schema":1,"order_id":1,"order_id":2}')
        with self.assertRaises(ValueError):self.request()

    def test_request_envelope_rejects_extra_fields_wrong_recipient_and_malformed_ciphertext(self):
        value=self.request()
        for name,changed in [('order_id',self.selection['order_id']),('prepare_run_id','main'),
                             ('request_key_sha256','x'*64),('report_recipient_sha256','0'*64),
                             ('encrypted_selector_base64',base64.b64encode(b'x'*256).decode()),
                             ('encrypted_selector_base64',value['encrypted_selector_base64']+'\n'),
                             ('encrypted_selector_base64','bad!')]:
            with self.subTest(name=name),self.assertRaises(ValueError):
                CRYPTO.request_payload({**value,name:changed},self.public)

    def test_report_round_trip_and_randomization_preserve_private_payload(self):
        value=self.envelope()
        self.assertEqual(CRYPTO.validate_envelope(self.output,self.public,self.run,self.source),value)
        key=self.report_key.decrypt(base64.b64decode(value['wrapped_key']),OAEP)
        plaintext=AESGCM(key).decrypt(base64.b64decode(value['nonce']),base64.b64decode(value['ciphertext']),CRYPTO.aad(value))
        self.assertEqual(json.loads(plaintext),self.report)
        self.assertEqual(set(value),CRYPTO.FIELDS)
        self.assertNotIn('PRIVATE-CUSTOMER-SENTINEL',json.dumps(value))
        self.assertNotEqual(self.envelope()['ciphertext'],value['ciphertext'])

    def test_report_ciphertext_and_authenticated_metadata_cannot_be_changed(self):
        value=self.envelope();key=self.report_key.decrypt(base64.b64decode(value['wrapped_key']),OAEP)
        for name,changed in [('format','other'),('algorithm','other'),('run_id','999-1'),
                             ('source_commit','b'*40),('recipient_sha256','0'*64)]:
            altered={**value,name:changed}
            with self.subTest(name=name),self.assertRaises(InvalidTag):
                AESGCM(key).decrypt(base64.b64decode(value['nonce']),base64.b64decode(value['ciphertext']),CRYPTO.aad(altered))
        for name in ('nonce','ciphertext'):
            altered=bytearray(base64.b64decode(value[name]));altered[0]^=1
            with self.subTest(name=name),self.assertRaises(InvalidTag):
                AESGCM(key).decrypt(bytes(altered) if name=='nonce' else base64.b64decode(value['nonce']),
                    bytes(altered) if name=='ciphertext' else base64.b64decode(value['ciphertext']),CRYPTO.aad(value))

    def test_report_publication_rejects_unknown_fields_context_and_encoded_sizes(self):
        value=self.envelope()
        for name,changed in [('customer','PRIVATE'),('run_id','999-1'),('source_commit','b'*40),
                             ('recipient_sha256','0'*64),('nonce',base64.b64encode(b'x'*16).decode()),
                             ('wrapped_key',base64.b64encode(b'x'*256).decode()),('ciphertext','bad!')]:
            self.write(self.output,{**value,name:changed})
            with self.subTest(name=name),self.assertRaises(ValueError):
                CRYPTO.validate_envelope(self.output,self.public,self.run,self.source)

    def test_private_modes_links_small_keys_and_plaintext_bounds_are_enforced(self):
        self.selector.chmod(0o644)
        with self.assertRaises(ValueError):self.request()
        self.selector.chmod(0o600)
        symlink=self.root/'link';symlink.symlink_to(self.selector)
        with self.assertRaises(ValueError):CRYPTO.make_request(symlink,self.prepared_path,self.public)
        os.link(self.selector,self.root/'hardlink')
        with self.assertRaises(ValueError):self.request()
        self.private.chmod(0o644)
        with self.assertRaises(ValueError):self.envelope()
        self.private.chmod(0o600);self.private.write_text(' '*65537)
        with self.assertRaises(ValueError):self.envelope()
        small=rsa.generate_private_key(public_exponent=65537,key_size=2048)
        self.public.write_bytes(pem(small))
        with self.assertRaises(ValueError):CRYPTO.recipient(self.public)

    def test_cli_logs_no_private_input_and_cannot_overwrite_existing_outputs(self):
        args=['python3',str(SCRIPT),'request',str(self.selector),str(self.prepared_path),str(self.public),str(self.output)]
        result=subprocess.run(args,capture_output=True)
        self.assertEqual(result.returncode,0);self.assertEqual(result.stdout+result.stderr,b'')
        self.assertEqual(self.output.stat().st_mode&0o777,0o600);original=self.output.read_bytes()
        result=subprocess.run(args,capture_output=True)
        self.assertNotEqual(result.returncode,0);self.assertEqual(self.output.read_bytes(),original)
        self.write(self.selector,{**self.selection,'private':'PRIVATE-CUSTOMER-SENTINEL'})
        result=subprocess.run(args,capture_output=True)
        self.assertNotIn(b'PRIVATE-CUSTOMER-SENTINEL',result.stdout+result.stderr)


if __name__=='__main__':unittest.main()
