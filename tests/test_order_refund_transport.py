"""Confidential order selectors and reports, without orders, hosting or network."""
import base64
import copy
from datetime import datetime, timedelta, timezone
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

import yaml

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


def success_report():
    schema=CRYPTO.report_schema();value={}
    defaults={'strings':'unknown','integers':0,'amounts':'0.00','nullable_amounts':None,'booleans':False,'nullable_booleans':None}
    for kind,default in defaults.items():value.update(dict.fromkeys(schema[kind],default))
    value.update(format='petish-order-refund-inspection-v1',status='success',storage='hpos',
        generated_utc='2026-10-25T00:00:00Z',created_gmt='2026-10-24 01:02:03',modified_gmt='2026-10-24 02:03:04',
        order_status='cancelled',order_id=7001,refund_count=1,order_currency='AUD',store_currency='AUD',
        intent_currency='AUD',multi_currency_default_currency='AUD',payment_gateway='woocommerce_payments',
        payment_method_type='card',payment_mode='prod',capture_status_cached='succeeded',refund_status_cached='successful')
    row={}
    for kind in ('strings','integers','amounts','booleans','nullable_booleans'):
        row.update(dict.fromkeys(schema['refund_'+kind],defaults[kind]))
    row.update(id=3000,created_gmt='2026-10-24 02:03:04',currency='AUD',amount='100.00')
    value['refunds']=[row]
    return value


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
        self.selection={'schema':1,'order_id':1775533119,'expected_billing_first_name':'PRIVATE-FIRSTNAME-SENTINEL'}
        self.write(self.selector,self.selection)
        issued=datetime.now(timezone.utc)-timedelta(seconds=10)
        self.prepared={'format':'petish-order-refund-prepared-v1','run_id':self.run,'source_commit':self.source,
            'issued_utc':issued.isoformat(),'expires_utc':(issued+timedelta(hours=1)).isoformat(),
            'request_key_sha256':fingerprint(self.request_key),'public_key_pem':pem(self.request_key).decode()}
        self.prepared_path=self.root/'prepared.json';self.write(self.prepared_path,self.prepared)
        self.private=self.root/'report.private.json'
        self.report={'format':'petish-order-refund-inspection-v1','status':'not_found','order_id':self.selection['order_id']}
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
                      {'schema':1,'order_id':2147483648},{'schema':1,'order_id':1,'email':'PRIVATE'},
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

    def test_report_schema_forbids_customer_details_weak_types_and_unproved_settlement(self):
        value=success_report();self.assertEqual(CRYPTO.validate_report(value),value)
        self.write(self.private,value);self.envelope()
        changes=[lambda v:v.update(customer_email='PRIVATE@example.invalid'),
                 lambda v:v.update(order_id=True),lambda v:v.update(note_count=True),
                 lambda v:v.update(payment_gateway='PRIVATE-CUSTOMER-SENTINEL'),
                 lambda v:v.update(order_total='001.00'),lambda v:v.update(order_total='1.000'),
                 lambda v:v.update(settlement_verified=True),lambda v:v.update(original_capture_amount_verified=True),
                 lambda v:v['refunds'][0].update(reason='PRIVATE-CUSTOMER-SENTINEL'),
                 lambda v:v['refunds'][0].update(id=True),lambda v:v['refunds'][0].update(refunded_payment_flag=1),
                 lambda v:v.update(refund_count=0),lambda v:v.update(refunds=v['refunds']*21,refund_count=21)]
        for change in changes:
            changed=copy.deepcopy(value);change(changed);self.write(self.private,changed)
            with self.assertRaises(ValueError):CRYPTO.encrypt_report(self.private,self.public,self.run,self.source)
        for status in ('not_found','identity_mismatch'):
            minimal={'format':'petish-order-refund-inspection-v1','status':status,'order_id':7001}
            self.assertEqual(CRYPTO.validate_report(minimal),minimal)
            with self.assertRaises(ValueError):CRYPTO.validate_report({**minimal,'private_name':'PRIVATE'})

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


class RequestKeyLifecycle(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.key=rsa.generate_private_key(public_exponent=65537,key_size=4096)

    def setUp(self):
        self.temp=tempfile.TemporaryDirectory(prefix='petish-request-lifecycle-');self.addCleanup(self.temp.cleanup)
        self.root=Path(self.temp.name);self.server=self.root/'server';self.server.mkdir(mode=0o700)
        self.prepare_run='40131123456-1';self.inspect_run='40131123457-1';self.source='a'*40
        self.php=shutil.which('php') or '/workspace/.petish-dev/bin/php'
        source=(ROOT/'scripts/order-refund-request.php').read_text()
        self.assertEqual(source.count("getenv('HOME')"),1)
        # Change only the ambient home lookup in the copied fixture. The real
        # helper still validates every path, owner, mode, key and lifecycle step.
        self.helper=source.replace("getenv('HOME')","getenv('ORDER_REFUND_FIXTURE_ROOT')")
        self.request=self.make_directory('request',self.prepare_run)
        self.inspect=self.make_directory('inspect',self.inspect_run)
        self.private_write(self.request/'request-key.pem',self.key.private_bytes(
            serialization.Encoding.PEM,serialization.PrivateFormat.PKCS8,serialization.NoEncryption()))
        self.private_write(self.request/'request-public.pem',pem(self.key))

    def private_write(self,path,data):
        path.write_bytes(data.encode() if isinstance(data,str) else data);path.chmod(0o600)

    def make_directory(self,kind,run):
        path=self.server/f'.petish-order-refund-{kind}-{run}';path.mkdir(mode=0o700)
        self.private_write(path/'.owned',run)
        self.private_write(path/'order-refund-request.php',self.helper)
        return path

    def call(self,mode,run=None,extra=None,tool=None):
        run=run or self.inspect_run
        script=(tool or (self.request if mode=='prepare' else self.inspect))/'order-refund-request.php'
        value=subprocess.run([self.php,str(script),mode,run,extra or (self.source if mode=='prepare' else self.prepare_run)],
            capture_output=True,env=os.environ|{'ORDER_REFUND_FIXTURE_ROOT':str(self.server)},timeout=15)
        self.assertNotIn(b'PRIVATE-FIRSTNAME-SENTINEL',value.stdout+value.stderr)
        return value

    def prepare(self):
        value=self.call('prepare',self.prepare_run)
        self.assertEqual(value.returncode,0,value.stderr)
        prepared=json.loads(value.stdout)
        self.assertEqual(CRYPTO.validate_prepared(prepared,self.prepare_run,self.source),prepared)
        return prepared

    def test_native_preparation_uses_private_owned_files_and_public_key_only(self):
        value=self.prepare()
        self.assertEqual(value['request_key_sha256'],fingerprint(self.key))
        self.assertNotIn('PRIVATE KEY',json.dumps(value))
        self.assertEqual((self.request/'issued.json').stat().st_mode&0o777,0o600)
        self.assertEqual(self.request.stat().st_mode&0o777,0o700)
        self.assertNotEqual(self.call('prepare',self.prepare_run).returncode,0)

    def test_claim_is_one_use_and_a_different_run_cannot_clean_the_key(self):
        self.prepare();self.assertEqual(self.call('claim').returncode,0)
        self.assertEqual((self.request/'.consumed').read_text(),self.inspect_run)
        self.assertNotEqual(self.call('claim').returncode,0)
        foreign='40131123458-1';other=self.make_directory('inspect',foreign)
        self.assertNotEqual(self.call('claim',foreign,tool=other).returncode,0)
        self.assertNotEqual(self.call('cleanup',foreign,tool=other).returncode,0)
        self.assertTrue((self.request/'request-key.pem').exists())
        self.assertEqual(self.call('cleanup').returncode,0)
        self.assertFalse(self.request.exists());self.assertFalse(self.inspect.exists())

    def test_expired_key_fails_before_decryption_and_still_can_be_cleaned(self):
        self.prepare();path=self.request/'issued.json';value=json.loads(path.read_text())
        value['issued']=int(datetime.now(timezone.utc).timestamp())-3601
        self.private_write(path,json.dumps(value))
        failed=self.call('claim');self.assertNotEqual(failed.returncode,0)
        self.assertEqual(failed.stdout,b'');self.assertEqual(failed.stderr,b'Private inspection request failed.\n')
        self.assertEqual(self.call('cleanup').returncode,0);self.assertFalse(self.request.exists())

    def test_selector_binding_and_exact_private_projection(self):
        self.prepare();self.assertEqual(self.call('claim').returncode,0)
        report_fingerprint=fingerprint(self.key)
        self.private_write(self.inspect/'report-public.pem',pem(self.key))
        request={'prepare_run_id':self.prepare_run,'report_recipient_sha256':report_fingerprint,'request_key_sha256':fingerprint(self.key)}
        self.private_write(self.inspect/'request-envelope.json',json.dumps(request))
        selection={'schema':1,'order_id':7001,'expected_billing_first_name':'PRIVATE-FIRSTNAME-SENTINEL'}
        clear={'schema':1,'prepare_run_id':self.prepare_run,'report_recipient_sha256':report_fingerprint,'selector':selection}
        self.private_write(self.inspect/'selector.private.json',json.dumps(clear,sort_keys=True,separators=(',',':')))
        self.assertEqual(self.call('selector').returncode,0)
        self.assertEqual(json.loads((self.inspect/'request.json').read_text()),selection)
        (self.inspect/'request.json').unlink()
        for change in [lambda v:v.update(report_recipient_sha256='0'*64),lambda v:v['selector'].update(order_id=True),
                       lambda v:v['selector'].update(order_ids=[7001,7002]),lambda v:v['selector'].update(expected_billing_first_name='PRIVATE\n')]:
            value=copy.deepcopy(clear);change(value)
            self.private_write(self.inspect/'selector.private.json',json.dumps(value,sort_keys=True,separators=(',',':')))
            self.assertNotEqual(self.call('selector').returncode,0)
            self.assertFalse((self.inspect/'request.json').exists())

    def test_cleanup_refuses_wrong_modes_links_and_unknown_files(self):
        self.prepare();self.request.chmod(0o755)
        self.assertNotEqual(self.call('cleanup',self.prepare_run,tool=self.request).returncode,0)
        self.request.chmod(0o700)
        extra=self.request/'unknown.private';self.private_write(extra,'PRIVATE-SENTINEL')
        self.assertNotEqual(self.call('cleanup',self.prepare_run,tool=self.request).returncode,0)
        self.assertTrue((self.request/'request-key.pem').exists());extra.unlink()
        link=self.request/'tools.sha256';link.symlink_to(self.request/'request-key.pem')
        self.assertNotEqual(self.call('cleanup',self.prepare_run,tool=self.request).returncode,0)
        link.unlink();os.link(self.request/'request-key.pem',link)
        self.assertNotEqual(self.call('cleanup',self.prepare_run,tool=self.request).returncode,0)
        link.unlink();self.assertEqual(self.call('cleanup',self.prepare_run,tool=self.request).returncode,0)
        self.assertFalse(self.request.exists())


class RefundPipeline(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.key=rsa.generate_private_key(public_exponent=65537,key_size=4096)

    def run_pipeline(self,branch='inspect-order-refund',mode='success'):
        with tempfile.TemporaryDirectory(prefix='petish-refund-pipeline-') as directory:
            base=Path(directory);repo=base/'repo';repo.mkdir();origin=base/'origin.git'
            def git(*args,check=True,cwd=repo):
                return subprocess.run(['git',*args],cwd=cwd,check=check,capture_output=True)
            git('init','--bare','-q',str(origin),cwd=base);git('init','-q')
            git('config','user.name','Fixture');git('config','user.email','fixture@example.invalid')
            git('remote','add','origin',str(origin));(repo/'scripts').mkdir();(repo/'operations').mkdir()
            for name in ['order-refund-envelope.py','order-refund-request.php','inspect-order-refund.php',
                         'hostinger-order-refund-inspection.sh','hostinger-ssh-common.sh']:
                (repo/'scripts'/name).write_bytes((ROOT/'scripts'/name).read_bytes())
            (repo/'operations/order-refund-recipient.pem').write_bytes(pem(self.key))
            issued=datetime.now(timezone.utc)-timedelta(seconds=5)
            prepared={'format':CRYPTO.PREPARED_FORMAT,'run_id':'123-1','source_commit':'a'*40,
                'issued_utc':issued.isoformat(),'expires_utc':(issued+timedelta(hours=1)).isoformat(),
                'request_key_sha256':fingerprint(self.key),'public_key_pem':pem(self.key).decode()}
            prep=base/'prepared.json';prep.write_text(json.dumps(prepared))
            selector=base/'selector.private.json';selector.write_text(json.dumps({'schema':1,'order_id':7001,'expected_billing_first_name':'PRIVATE-FIRSTNAME-SENTINEL'}));selector.chmod(0o600)
            request=CRYPTO.make_request(selector,prep,repo/'operations/order-refund-recipient.pem')
            (repo/'operations/order-refund-inspection.json').write_text(json.dumps(request))
            git('add','.');git('commit','-qm','Synthetic private inspection source')
            revision=git('rev-parse','HEAD').stdout.decode().strip()
            binary=base/'bin';binary.mkdir()
            (binary/'sshpass').write_text('#!/bin/sh\nshift\nexec "$@"\n')
            (binary/'ssh-keygen').write_text('#!/bin/sh\ncat >/dev/null\nprintf "256 SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA fixture\\n"\n')
            (binary/'ssh').write_text('''#!/usr/bin/env python3
import hashlib,io,json,os,sys,tarfile
from datetime import datetime,timedelta,timezone
from pathlib import Path
from cryptography.hazmat.primitives import serialization
base=Path(os.environ['FIXTURE_BASE']);command=sys.argv[-1];phase=os.environ['FIXTURE_PHASE'];mode=os.environ['FIXTURE_MODE']
with (base/'commands.jsonl').open('a') as stream:stream.write(json.dumps(command)+'\\n')
assert 'wp eval' not in command and 'option update' not in command and 'wp-load.php' not in command
if 'tar --extract' in command:
 archive=tarfile.open(fileobj=io.BytesIO(sys.stdin.buffer.read()))
 names=['order-refund-request.php','request-key.pem','request-public.pem','tools.sha256'] if phase=='prepare' else ['order-refund-request.php','inspect-order-refund.php','request-envelope.json','selector.enc','report-public.pem','tools.sha256']
 assert sorted(member.name for member in archive.getmembers())==sorted(names)
 assert all(member.isfile() and member.mode==0o600 for member in archive.getmembers())
 if phase=='prepare':
  public=archive.extractfile('request-public.pem').read();key=serialization.load_pem_public_key(public)
  assert key.key_size==4096
  now=datetime.now(timezone.utc)
  data={'format':'petish-order-refund-prepared-v1','run_id':os.environ['GITHUB_RUN_ID']+'-1','source_commit':os.environ['GITHUB_SHA'],
    'issued_utc':now.isoformat(),'expires_utc':(now+timedelta(hours=1)).isoformat(),
    'request_key_sha256':hashlib.sha256(key.public_bytes(serialization.Encoding.DER,serialization.PublicFormat.SubjectPublicKeyInfo)).hexdigest(),'public_key_pem':public.decode()}
  if mode=='private_failure':data['private_customer']='PRIVATE-CUSTOMER-SENTINEL'
  (base/'prepared.remote.json').write_text(json.dumps(data))
elif '/order-refund-request.php" prepare ' in command:
 print((base/'prepared.remote.json').read_text())
 print('PRIVATE-CUSTOMER-SENTINEL',file=sys.stderr)
elif 'openssl pkeyutl -decrypt' in command:
 for flag in ('rsa_padding_mode:oaep','rsa_oaep_md:sha256','rsa_mgf1_md:sha256'):assert flag in command
 assert ' config get ' in command and 'PETISH_DB_PREFIX' in command and 'allow_url_fopen=0' in command
 if mode=='collector_failure':
  print('PRIVATE-CUSTOMER-SENTINEL',file=sys.stderr);print('bounds',file=sys.stderr);raise SystemExit(1)
 if mode=='unknown_failure':
  print('PRIVATE-CUSTOMER-SENTINEL',file=sys.stderr);raise SystemExit(1)
 data={'format':'petish-order-refund-inspection-v1','status':'not_found','order_id':7001}
 if mode=='private_failure':data['private_customer']='PRIVATE-CUSTOMER-SENTINEL'
 print(json.dumps(data));print('PRIVATE-CUSTOMER-SENTINEL',file=sys.stderr)
elif 'rm -rf -- "$root"' in command:
 if mode=='cleanup_failure':raise SystemExit(1)
else:raise SystemExit(1)
''')
            for path in binary.iterdir():path.chmod(0o700)
            runner=base/'runner';runner.mkdir();(runner/'petish-candidate-host-keys').write_text('fixture-key\n')
            phase='prepare' if branch=='prepare-order-refund-inspection' else 'inspect'
            run='123' if phase=='prepare' else '124'
            env=os.environ|{'PATH':str(binary)+':'+os.environ['PATH'],'RUNNER_TEMP':str(runner),
                'GITHUB_RUN_ID':run,'GITHUB_RUN_ATTEMPT':'1','GITHUB_SHA':revision,'GITHUB_REF_NAME':branch,
                'SSH_HOST':'82.29.81.104','SSH_PORT':'65002','SSH_USER':'u839205597','SSHPASS':'PRIVATE-SECRET-SENTINEL',
                'SSH_EXPECTED_FINGERPRINT':'SHA256:'+('B' if mode=='fingerprint_mismatch' else 'A')*43,
                'ORDER_REFUND_PREREQUISITES':'success','FIXTURE_BASE':str(base),'FIXTURE_PHASE':phase,'FIXTURE_MODE':mode}
            result=subprocess.run(['bash','scripts/hostinger-order-refund-inspection.sh'],cwd=repo,env=env,capture_output=True,timeout=45)
            ref='refs/heads/automation-status/order-refund-'+run+'-1'
            status=git('--git-dir='+str(origin),'show',ref+':status.json',check=False)
            value=json.loads(status.stdout) if status.returncode==0 else None
            files=git('--git-dir='+str(origin),'ls-tree','--name-only',ref,check=False).stdout.decode().splitlines()
            ciphertext=git('--git-dir='+str(origin),'show',ref+':order-refund.encrypted.json',check=False)
            envelope=json.loads(ciphertext.stdout) if ciphertext.returncode==0 else None
            commands=(base/'commands.jsonl').read_text() if (base/'commands.jsonl').exists() else ''
            for private in ('PRIVATE-CUSTOMER-SENTINEL','PRIVATE-FIRSTNAME-SENTINEL','PRIVATE-SECRET-SENTINEL','BEGIN PRIVATE KEY'):
                self.assertNotIn(private,(result.stdout+result.stderr+status.stdout+ciphertext.stdout).decode())
            self.assertFalse(list(runner.glob('petish-order-refund.*')))
            return result.returncode,value,files,envelope,commands

    def test_prepare_publishes_only_public_key_and_retains_the_one_hour_server_key(self):
        code,value,files,envelope,commands=self.run_pipeline('prepare-order-refund-inspection')
        self.assertEqual(code,0);self.assertEqual(files,['status.json']);self.assertIsNone(envelope)
        self.assertEqual(value['status'],'success');self.assertTrue(value['request_key_retained'])
        self.assertFalse(value['temporary_files_removed']);self.assertIsNone(value['failure_code'])
        CRYPTO.validate_prepared(value['prepared'],value['run_id'],value['source_commit'])
        self.assertEqual(len(commands.splitlines()),2)

    def test_inspection_publishes_ciphertext_only_after_cleanup(self):
        code,value,files,envelope,commands=self.run_pipeline()
        self.assertEqual(code,0);self.assertEqual(value['status'],'success')
        self.assertEqual(sorted(files),['order-refund.encrypted.json','status.json'])
        self.assertTrue(value['temporary_files_removed']);self.assertFalse(value['request_key_retained'])
        self.assertTrue(value['encrypted_result_present']);self.assertEqual(len(commands.splitlines()),3)
        key=self.key.decrypt(base64.b64decode(envelope['wrapped_key']),OAEP)
        raw=AESGCM(key).decrypt(base64.b64decode(envelope['nonce']),base64.b64decode(envelope['ciphertext']),CRYPTO.aad(envelope))
        self.assertEqual(json.loads(raw),{'format':'petish-order-refund-inspection-v1','status':'not_found','order_id':7001})

    def test_private_or_invalid_reports_are_discarded_and_keys_are_cleaned(self):
        for branch in ('prepare-order-refund-inspection','inspect-order-refund'):
            with self.subTest(branch=branch):
                code,value,files,envelope,commands=self.run_pipeline(branch,'private_failure')
                self.assertNotEqual(code,0);self.assertEqual(value['status'],'failure')
                self.assertEqual(files,['status.json']);self.assertIsNone(envelope)
                self.assertFalse(value['request_key_retained']);self.assertTrue(value['temporary_files_removed'])
                self.assertIn('rm -rf',commands)

    def test_cleanup_failure_never_publishes_the_encrypted_report(self):
        code,value,files,envelope,_=self.run_pipeline(mode='cleanup_failure')
        self.assertNotEqual(code,0);self.assertEqual(value['status'],'failure');self.assertEqual(value['category'],'cleanup')
        self.assertEqual(files,['status.json']);self.assertIsNone(envelope);self.assertFalse(value['temporary_files_removed'])

    def test_collection_failures_publish_only_exact_allowlisted_error_codes(self):
        for mode,expected in [('collector_failure','bounds'),('unknown_failure',None)]:
            with self.subTest(mode=mode):
                code,value,files,envelope,_=self.run_pipeline(mode=mode)
                self.assertNotEqual(code,0);self.assertEqual(value['failure_code'],expected)
                self.assertEqual(files,['status.json']);self.assertIsNone(envelope);self.assertTrue(value['temporary_files_removed'])

    def test_wrong_host_key_main_and_deploy_branches_never_authenticate(self):
        code,value,files,_,commands=self.run_pipeline(mode='fingerprint_mismatch')
        self.assertNotEqual(code,0);self.assertEqual(value['category'],'transport');self.assertEqual(commands,'')
        for branch in ('main','deploy-homepage'):
            code,value,files,_,commands=self.run_pipeline(branch)
            self.assertNotEqual(code,0);self.assertIsNone(value);self.assertEqual(files,[]);self.assertEqual(commands,'')

    def test_workflow_uses_only_exact_source_and_the_two_authorized_branches(self):
        workflow=yaml.load((ROOT/'.github/workflows/order-refund-inspection.yml').read_text(),Loader=yaml.BaseLoader)
        self.assertEqual(workflow['on']['push']['branches'],['prepare-order-refund-inspection','inspect-order-refund'])
        self.assertEqual(set(workflow['on']),{'push'})
        self.assertEqual(workflow['jobs']['inspect']['steps'][0]['with']['ref'],'${{ github.sha }}')
        self.assertEqual(workflow['concurrency']['group'],'petish-production-backup-mail')


if __name__=='__main__':unittest.main()
