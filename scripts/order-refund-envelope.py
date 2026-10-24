#!/usr/bin/env python3
"""Bounded encrypted requests/results; never print customer data."""
import base64
import ast
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import sys
from datetime import datetime, timezone

from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import padding, rsa
from cryptography.hazmat.primitives.ciphers.aead import AESGCM

REQUEST_FORMAT = 'petish-order-refund-request-v1'
PREPARED_FORMAT = 'petish-order-refund-prepared-v1'
FORMAT = 'petish-order-refund-envelope-v1'
ALGORITHM = 'RSA-OAEP-SHA256+A256GCM'
METADATA = {'format', 'algorithm', 'run_id', 'source_commit', 'recipient_sha256'}
FIELDS = METADATA | {'wrapped_key', 'nonce', 'ciphertext'}
RUN = re.compile(r'[1-9][0-9]{0,31}-[1-9][0-9]{0,8}\Z')
SHA = re.compile(r'[a-f0-9]{64}\Z')

def unique(pairs):
    value = {}
    for key, item in pairs:
        if key in value:
            raise ValueError()
        value[key] = item
    return value

def read_regular(path, maximum=65536, private=False):
    path = Path(path)
    if path.parent.resolve(strict=True) != path.parent.absolute():
        raise ValueError()
    info = path.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or not 0 < info.st_size <= maximum \
            or (private and stat.S_IMODE(info.st_mode) != 0o600):
        raise ValueError()
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(fd, 'rb') as stream:
        opened = os.fstat(stream.fileno())
        if (opened.st_dev, opened.st_ino) != (info.st_dev, info.st_ino):
            raise ValueError()
        result = stream.read(maximum + 1)
    if len(result) != info.st_size or len(result) > maximum:
        raise ValueError()
    return result

def load(path, maximum=65536, private=False):
    return json.loads(read_regular(path, maximum, private), object_pairs_hook=unique)

def public_key(raw):
    key = serialization.load_pem_public_key(raw)
    if not isinstance(key, rsa.RSAPublicKey) or key.key_size != 4096 or key.public_numbers().e != 65537:
        raise ValueError()
    der = key.public_bytes(serialization.Encoding.DER, serialization.PublicFormat.SubjectPublicKeyInfo)
    return key, hashlib.sha256(der).hexdigest()

def recipient(path):
    return public_key(read_regular(path, 8192))

def identifiers(run, source):
    if not isinstance(run, str) or not RUN.fullmatch(run) or not isinstance(source, str) \
            or not re.fullmatch(r'[a-f0-9]{40}', source):
        raise ValueError()

def b64(value, size):
    if not isinstance(value, str):
        raise ValueError()
    raw = base64.b64decode(value, validate=True)
    if len(raw) != size or base64.b64encode(raw).decode() != value:
        raise ValueError()
    return raw

def selector(value):
    if not isinstance(value, dict) or set(value) not in ({'schema', 'order_id'}, {'schema', 'order_id', 'expected_billing_first_name'}) or type(value['schema']) is not int \
            or value['schema'] != 1 or type(value['order_id']) is not int or not 1 <= value['order_id'] <= 2147483647:
        raise ValueError()
    if 'expected_billing_first_name' in value:
        name = value['expected_billing_first_name']
        if not isinstance(name, str) or not 1 <= len(name) <= 80 or not name.strip() or re.search(r'[\x00-\x1f\x7f]', name):
            raise ValueError()
    return value

def validate_prepared(value, run, source):
    identifiers(run, source)
    if not isinstance(value, dict) or set(value) != {'format', 'run_id', 'source_commit', 'issued_utc', 'expires_utc', 'request_key_sha256', 'public_key_pem'} \
            or value['format'] != PREPARED_FORMAT or value['run_id'] != run or value['source_commit'] != source:
        raise ValueError()
    issued = datetime.fromisoformat(value['issued_utc'])
    expires = datetime.fromisoformat(value['expires_utc'])
    if issued.tzinfo is None or expires.tzinfo is None or (expires-issued).total_seconds() != 3600 \
            or not issued.timestamp()-300 <= datetime.now(timezone.utc).timestamp() <= expires.timestamp():
        raise ValueError()
    if not isinstance(value['public_key_pem'], str) or len(value['public_key_pem']) > 8192:
        raise ValueError()
    _, fingerprint = public_key(value['public_key_pem'].encode())
    if value['request_key_sha256'] != fingerprint:
        raise ValueError()
    return value

def make_request(selector_path, prepared_path, report_public_path):
    value = selector(load(selector_path, 1024, True))
    prepared = load(prepared_path, 16384)
    validate_prepared(prepared, prepared['run_id'], prepared['source_commit'])
    key, _ = public_key(prepared['public_key_pem'].encode())
    _, fingerprint = recipient(report_public_path)
    private = {'schema': 1, 'prepare_run_id': prepared['run_id'], 'report_recipient_sha256': fingerprint, 'selector': value}
    plaintext = json.dumps(private, sort_keys=True, separators=(',', ':')).encode()
    ciphertext = key.encrypt(plaintext, padding.OAEP(mgf=padding.MGF1(hashes.SHA256()), algorithm=hashes.SHA256(), label=None))
    return {'format': REQUEST_FORMAT, 'prepare_run_id': prepared['run_id'], 'request_key_sha256': prepared['request_key_sha256'],
            'report_recipient_sha256': fingerprint, 'encrypted_selector_base64': base64.b64encode(ciphertext).decode()}

def request_payload(value, report_public_path):
    if not isinstance(value, dict) or set(value) != {'format', 'prepare_run_id', 'request_key_sha256', 'report_recipient_sha256', 'encrypted_selector_base64'} \
            or value['format'] != REQUEST_FORMAT or not isinstance(value['prepare_run_id'], str) or not RUN.fullmatch(value['prepare_run_id']):
        raise ValueError()
    for name in ('request_key_sha256', 'report_recipient_sha256'):
        if not isinstance(value[name], str) or not SHA.fullmatch(value[name]):
            raise ValueError()
    _, fingerprint = recipient(report_public_path)
    if value['report_recipient_sha256'] != fingerprint:
        raise ValueError()
    b64(value['encrypted_selector_base64'], 512)
    return value

def aad(value):
    return json.dumps({key: value[key] for key in METADATA}, sort_keys=True, separators=(',', ':')).encode()

def report_schema():
    source = Path(__file__).with_name('inspect-order-refund.php').read_text()
    match = re.search(r'const PETISH_ORDER_REFUND_SCHEMA = (\[.*?\]);', source, re.S)
    if not match or len(match.group(1)) > 8192:
        raise ValueError()
    schema = ast.literal_eval('{'+match.group(1)[1:-1].replace('=>', ':')+'}')
    kinds = {'strings', 'integers', 'amounts', 'nullable_amounts', 'booleans', 'nullable_booleans',
             'refund_strings', 'refund_integers', 'refund_amounts', 'refund_booleans', 'refund_nullable_booleans'}
    if not isinstance(schema, dict) or set(schema) != kinds:
        raise ValueError()
    for names in schema.values():
        if not isinstance(names, list) or not 1 <= len(names) <= 32 or len(names) != len(set(names)) \
                or any(not isinstance(name, str) or not re.fullmatch(r'[a-z][a-z0-9_]{0,79}', name) for name in names):
            raise ValueError()
    return schema

def money(value):
    if not isinstance(value, str) or not re.fullmatch(r'-?(?:0|[1-9][0-9]{0,8})\.[0-9]{2,8}', value):
        raise ValueError()
    whole, fraction = value.split('.')
    canonical = whole+'.'+fraction.rstrip('0').ljust(2, '0')
    if whole == '-0' and not fraction.strip('0'):
        canonical = canonical[1:]
    if value != canonical:
        raise ValueError()

def validate_report(value):
    if not isinstance(value, dict) or value.get('format') != 'petish-order-refund-inspection-v1' \
            or type(value.get('order_id')) is not int or not 1 <= value['order_id'] <= 2147483647:
        raise ValueError()
    if value.get('status') in {'not_found', 'identity_mismatch'}:
        if set(value) != {'format', 'status', 'order_id'}:
            raise ValueError()
        return value
    schema = report_schema()
    top_kinds = ('strings', 'integers', 'amounts', 'nullable_amounts', 'booleans', 'nullable_booleans')
    expected = {'refunds'} | {name for kind in top_kinds for name in schema[kind]}
    if set(value) != expected or value.get('status') != 'success':
        raise ValueError()
    def fields(record, prefix=''):
        for name in schema[prefix+'strings']:
            if not isinstance(record[name], str) or not re.fullmatch(r'[A-Za-z0-9_:. +\-]{1,80}', record[name]):
                raise ValueError()
        for name in schema[prefix+'integers']:
            if type(record[name]) is not int or not 0 <= record[name] <= 2147483647:
                raise ValueError()
        for name in schema[prefix+'amounts']:
            money(record[name])
        for name in schema.get(prefix+'nullable_amounts', []):
            if record[name] is not None:
                money(record[name])
        for name in schema[prefix+'booleans']:
            if type(record[name]) is not bool:
                raise ValueError()
        for name in schema[prefix+'nullable_booleans']:
            if record[name] is not None and type(record[name]) is not bool:
                raise ValueError()
    fields(value)
    enums = {
        'storage': {'hpos', 'legacy'},
        'order_status': {'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed', 'checkout-draft', 'other'},
        'payment_gateway': {'woocommerce_payments', 'woocommerce_payments_afterpay_clearpay', 'woocommerce_payments_klarna', 'stripe', 'ppcp-gateway', 'ppcp-credit-card-gateway', 'bacs', 'cod', 'cheque', 'other'},
        'payment_method_type': {'card', 'afterpay_clearpay', 'klarna', 'link', 'paypal', 'affirm', 'amazon_pay', 'au_becs_debit', 'sepa_debit', 'unknown'},
        'payment_mode': {'prod', 'production', 'test', 'unknown'},
        'capture_status_cached': {'succeeded', 'canceled', 'requires_capture', 'processing', 'requires_payment_method', 'requires_confirmation', 'requires_action', 'unknown'},
        'refund_status_cached': {'pending', 'successful', 'succeeded', 'failed', 'canceled', 'unknown'},
    }
    if any(value[name] not in allowed for name, allowed in enums.items()):
        raise ValueError()
    for name in ('order_currency', 'store_currency', 'intent_currency', 'multi_currency_default_currency'):
        if value[name] != 'unknown' and not re.fullmatch(r'[A-Z]{3}', value[name]):
            raise ValueError()
    if not re.fullmatch(r'[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z', value['generated_utc']):
        raise ValueError()
    for name in ('created_gmt', 'modified_gmt'):
        if not re.fullmatch(r'[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}', value[name]):
            raise ValueError()
    refunds = value['refunds']
    if value['settlement_verified'] is not False or value['original_capture_amount_verified'] is not False \
            or not isinstance(refunds, list) or len(refunds) > 20 or value['refund_count'] != len(refunds):
        raise ValueError()
    expected_refund = {name for kind in schema if kind.startswith('refund_') for name in schema[kind]}
    seen = set()
    for row in refunds:
        if not isinstance(row, dict) or set(row) != expected_refund:
            raise ValueError()
        fields(row, 'refund_')
        if not row['id'] or row['id'] in seen or not re.fullmatch(r'[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}', row['created_gmt']) \
                or not re.fullmatch(r'[A-Z]{3}', row['currency']):
            raise ValueError()
        seen.add(row['id'])
    return value

def encrypt_report(path, public_path, run, source):
    identifiers(run, source)
    raw = read_regular(path, 65536, True)
    validate_report(json.loads(raw, object_pairs_hook=unique))
    key, fingerprint = recipient(public_path)
    envelope = {'format': FORMAT, 'algorithm': ALGORITHM, 'run_id': run, 'source_commit': source, 'recipient_sha256': fingerprint}
    symmetric = AESGCM.generate_key(bit_length=256)
    nonce = os.urandom(12)
    ciphertext = AESGCM(symmetric).encrypt(nonce, raw, aad(envelope))
    wrapped = key.encrypt(symmetric, padding.OAEP(mgf=padding.MGF1(hashes.SHA256()), algorithm=hashes.SHA256(), label=None))
    return {**envelope, 'wrapped_key': base64.b64encode(wrapped).decode(), 'nonce': base64.b64encode(nonce).decode(), 'ciphertext': base64.b64encode(ciphertext).decode()}

def validate_envelope(path, public_path, run, source):
    identifiers(run, source)
    value = load(path, 131072, True)
    _, fingerprint = recipient(public_path)
    if not isinstance(value, dict) or set(value) != FIELDS or value['format'] != FORMAT or value['algorithm'] != ALGORITHM \
            or value['run_id'] != run or value['source_commit'] != source or value['recipient_sha256'] != fingerprint:
        raise ValueError()
    b64(value['wrapped_key'], 512)
    b64(value['nonce'], 12)
    encoded = value['ciphertext']
    if not isinstance(encoded, str):
        raise ValueError()
    cipher = base64.b64decode(encoded, validate=True)
    if not 18 <= len(cipher) <= 65552 or base64.b64encode(cipher).decode() != encoded:
        raise ValueError()
    return value

def write(path, value):
    path = Path(path)
    if path.parent.resolve(strict=True) != path.parent.absolute():
        raise ValueError()
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'w') as stream:
        stream.write(json.dumps(value, sort_keys=True, indent=2)+'\n')

def main(args):
    os.umask(0o077)
    try:
        if len(args) == 5 and args[0] == 'request':
            write(args[4], make_request(*map(Path, args[1:4])))
        elif len(args) == 6 and args[0] == 'encrypt':
            write(args[3], encrypt_report(Path(args[1]), Path(args[2]), args[4], args[5]))
            validate_envelope(Path(args[3]), Path(args[2]), args[4], args[5])
        else:
            raise ValueError()
        return 0
    except Exception:
        print('Private order inspection envelope failed; no customer data was published.', file=sys.stderr)
        return 1

if __name__ == '__main__':
    raise SystemExit(main(sys.argv[1:]))
