#!/usr/bin/env python3
"""Encrypt a bounded names-only diagnostic for one reviewed ephemeral recipient."""
import base64
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import sys

from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import padding, rsa
from cryptography.hazmat.primitives.ciphers.aead import AESGCM

FORMAT = 'petish-private-option-envelope-v1'
ALGORITHM = 'RSA-OAEP-SHA256+A256GCM'
METADATA = {'format', 'algorithm', 'run_id', 'source_commit', 'recipient_sha256'}
FIELDS = METADATA | {'wrapped_key', 'nonce', 'ciphertext'}


def read_regular(path, limit, private=False):
    info = path.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or not 0 < info.st_size <= limit \
            or (private and stat.S_IMODE(info.st_mode) != 0o600):
        raise ValueError()
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(descriptor, 'rb') as stream:
        opened = os.fstat(stream.fileno())
        if (opened.st_dev, opened.st_ino) != (info.st_dev, info.st_ino):
            raise ValueError()
        data = stream.read(limit + 1)
    if not 0 < len(data) <= limit:
        raise ValueError()
    return data


def recipient(path):
    key = serialization.load_pem_public_key(read_regular(path, 8192))
    if not isinstance(key, rsa.RSAPublicKey) or key.key_size != 4096 or key.public_numbers().e != 65537:
        raise ValueError()
    public = key.public_bytes(serialization.Encoding.DER, serialization.PublicFormat.SubjectPublicKeyInfo)
    return key, hashlib.sha256(public).hexdigest()


def identifiers(run, source):
    if not isinstance(run, str) or not re.fullmatch(r'[1-9][0-9]{0,31}-[1-9][0-9]{0,8}', run) \
            or not isinstance(source, str) or not re.fullmatch(r'[a-f0-9]{40}', source):
        raise ValueError()


def private_payload(path, run):
    value = json.loads(read_regular(path, 16384, private=True))
    if not isinstance(value, dict) or set(value) != {'format', 'run_id', 'option_names'} \
            or value['format'] != 'petish-club-private-options-v1' or value['run_id'] != run:
        raise ValueError()
    names = value['option_names']
    if not isinstance(names, list) or not 1 <= len(names) <= 32 \
            or any(not isinstance(name, str) or not 1 <= len(name.encode('utf-8')) <= 191
                   or re.search(r'[\x00-\x1f\x7f]', name) for name in names) \
            or names != sorted(set(names)):
        raise ValueError()
    return json.dumps(value, sort_keys=True, separators=(',', ':'), ensure_ascii=True).encode()


def aad(envelope):
    return json.dumps({key: envelope[key] for key in METADATA}, sort_keys=True, separators=(',', ':')).encode()


def encrypt(path, public_key, run, source):
    identifiers(run, source)
    key, fingerprint = recipient(public_key)
    plaintext = private_payload(path, run)
    envelope = {'format': FORMAT, 'algorithm': ALGORITHM, 'run_id': run,
                'source_commit': source, 'recipient_sha256': fingerprint}
    symmetric = AESGCM.generate_key(bit_length=256)
    nonce = os.urandom(12)
    ciphertext = AESGCM(symmetric).encrypt(nonce, plaintext, aad(envelope))
    wrapped = key.encrypt(symmetric, padding.OAEP(mgf=padding.MGF1(hashes.SHA256()),
                                                algorithm=hashes.SHA256(), label=None))
    envelope.update(wrapped_key=base64.b64encode(wrapped).decode(), nonce=base64.b64encode(nonce).decode(),
                    ciphertext=base64.b64encode(ciphertext).decode())
    return envelope


def validate_envelope(path, public_key, run, source):
    identifiers(run, source)
    value = json.loads(read_regular(path, 32768, private=True))
    _, fingerprint = recipient(public_key)
    if not isinstance(value, dict) or set(value) != FIELDS or value['format'] != FORMAT \
            or value['algorithm'] != ALGORITHM or value['run_id'] != run or value['source_commit'] != source \
            or value['recipient_sha256'] != fingerprint:
        raise ValueError()
    for name, minimum, maximum in [('wrapped_key', 512, 512), ('nonce', 12, 12), ('ciphertext', 17, 16400)]:
        encoded = value[name]
        if not isinstance(encoded, str):
            raise ValueError()
        decoded = base64.b64decode(encoded, validate=True)
        if not minimum <= len(decoded) <= maximum or base64.b64encode(decoded).decode() != encoded:
            raise ValueError()
    return value


def main(arguments):
    os.umask(0o077)
    try:
        if len(arguments) != 5:
            raise ValueError()
        source, key, destination = map(Path, arguments[:3])
        run, revision = arguments[3:]
        value = encrypt(source, key, run, revision)
        if destination.parent.resolve(strict=True) != destination.parent.absolute():
            raise ValueError()
        descriptor = os.open(destination, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        with os.fdopen(descriptor, 'w') as stream:
            stream.write(json.dumps(value, sort_keys=True, indent=2) + '\n')
        validate_envelope(destination, key, run, revision)
        return 0
    except Exception:
        print('Private diagnostic encryption failed; no plaintext diagnostic was published.', file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main(sys.argv[1:]))
