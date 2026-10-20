"""Single-message encrypted-recipient fixtures; no network or live sends."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import time
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / 'scripts/send-petish-test-mail.php'
PHP = Path('/workspace/.petish-dev/bin/php')
FLAG = '--send-one-test'


@unittest.skipUnless(PHP.is_file() and shutil.which('openssl'), 'verified PHP and OpenSSL required')
class SingleTestMailTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.keys = tempfile.TemporaryDirectory()
        cls.key_source = Path(cls.keys.name) / 'fixture.pem'
        subprocess.run(['openssl','genpkey','-algorithm','RSA','-pkeyopt','rsa_keygen_bits:2048','-out',str(cls.key_source)],check=True,capture_output=True)
        cls.public_source = Path(cls.keys.name) / 'public.pem'
        subprocess.run(['openssl','pkey','-in',str(cls.key_source),'-pubout','-out',str(cls.public_source)],check=True,capture_output=True)

    @classmethod
    def tearDownClass(cls): cls.keys.cleanup()

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.home = Path(self.temp.name)
        self.site = self.home / 'domains/petish.biz/public_html'
        mu = self.site / 'wp-content/mu-plugins'; mu.mkdir(parents=True)
        shutil.copyfile(ROOT / 'wordpress/wp-content/mu-plugins/petish-mailer.php', mu / 'petish-mailer.php')
        (self.site / 'wp-config.php').write_text("<?php file_put_contents(getenv('HOME').'/bootstrap.log','unexpected'); exit(99);")
        self.config = self.site.parent / 'private/petish-mailer/smtp.json'
        self.config.parent.mkdir(parents=True, mode=0o700)
        self.secret = 'test-fixture-private-password'
        self.config.write_text(json.dumps({'host':'smtp.hostinger.com','port':465,'encryption':'smtps',
                                          'username':'administrator@petish.biz','password':self.secret}))
        self.config.chmod(0o600)
        self.token = '1234567890abcdef1234567890abcdef'
        self.key_dir = self.home / ('.petish-mail-test-' + self.token); self.key_dir.mkdir(mode=0o700)
        self.key = self.key_dir / 'key.pem'; shutil.copyfile(self.key_source,self.key); self.key.chmod(0o600)
        self.envelope = self.key_dir / 'recipient.enc'
        self.recipient = 'delivery-test@example.net'
        self.encrypt_envelope()
        self.core = self.site / 'wp-includes/PHPMailer'; self.core.mkdir(parents=True)
        (self.core / 'Exception.php').write_text('<?php namespace PHPMailer\\PHPMailer; class Exception extends \\Exception {}')
        (self.core / 'SMTP.php').write_text('<?php namespace PHPMailer\\PHPMailer; class SMTP {}')
        (self.core / 'PHPMailer.php').write_text('''<?php namespace PHPMailer\\PHPMailer;
#[\\AllowDynamicProperties] class PHPMailer {
const ENCRYPTION_SMTPS='ssl'; private $recipients=[];
function __construct($exceptions=true){}
function isSMTP(){$this->Mailer='smtp';}
function setFrom($address,$name,$unused=false){$this->From=$address;$this->FromName=$name;}
function addAddress($address){$this->recipients[]=$address;}
function addReplyTo($address,$name){$this->reply=$address;}
function isHTML($value){$this->html=$value;}
function smtpClose(){}
function send(){
    $record=['recipients'=>$this->recipients,'from'=>$this->From,'sender'=>$this->Sender,
             'username'=>$this->Username,'reply'=>$this->reply,'html'=>$this->html,
             'subject'=>$this->Subject,'body'=>$this->Body,
             'key_consumed'=>!file_exists(getenv('PETISH_FIXTURE_KEY'))];
    file_put_contents(getenv('HOME').'/submissions.jsonl',json_encode($record)."\\n",FILE_APPEND);
    if(file_exists(getenv('HOME').'/fail-submission'))throw new Exception('PRIVATE SMTP ERROR');
    return true;
}
}''')
        self.env = {**os.environ, 'HOME':str(self.home), 'PETISH_FIXTURE_KEY':str(self.key)}

    def tearDown(self): self.temp.cleanup()

    def encrypt_envelope(self, request=None):
        if request is None: request = {'recipient':self.recipient, 'token':self.token}
        subprocess.run(['openssl','pkeyutl','-encrypt','-pubin','-inkey',str(self.public_source),
                        '-pkeyopt','rsa_padding_mode:oaep','-pkeyopt','rsa_oaep_md:sha1',
                        '-out',str(self.envelope)],input=json.dumps(request).encode(),check=True,capture_output=True)
        self.envelope.chmod(0o600)

    def invoke(self, *args):
        result = subprocess.run([str(PHP), str(SCRIPT), *map(str,args)], env=self.env,
                                capture_output=True, text=True, timeout=20)
        self.assertNotIn(self.secret, result.stdout + result.stderr)
        self.assertNotIn(self.recipient, result.stdout + result.stderr)
        self.assertNotIn('PRIVATE SMTP ERROR', result.stdout + result.stderr)
        self.assertFalse((self.home / 'bootstrap.log').exists())
        return result, json.loads(result.stdout)

    def send(self): return self.invoke(self.site,self.key,self.envelope,FLAG)

    def submissions(self):
        file = self.home / 'submissions.jsonl'
        return [json.loads(line) for line in file.read_text().splitlines()] if file.exists() else []

    def test_authorized_envelope_submits_exactly_once_and_consumes_key(self):
        result, report = self.send(); self.assertEqual(result.returncode, 0)
        self.assertEqual(report, {'test_message_submitted':True})
        submissions = self.submissions(); self.assertEqual(len(submissions), 1)
        message = submissions[0]
        self.assertEqual(message['recipients'], [self.recipient])
        self.assertEqual(message['from'], 'orders@petish.biz'); self.assertEqual(message['sender'], 'orders@petish.biz')
        self.assertEqual(message['username'], 'administrator@petish.biz'); self.assertEqual(message['reply'], 'orders@petish.biz')
        self.assertFalse(message['html']); self.assertTrue(message['key_consumed'])
        self.assertEqual(message['subject'], 'Petish SMTP delivery test'); self.assertNotIn(self.secret, message['body'])
        self.assertFalse(self.key.exists())
        replay, report = self.send(); self.assertNotEqual(replay.returncode,0); self.assertEqual(len(self.submissions()),1)

    def test_missing_wrong_or_extra_flag_never_submits(self):
        for args in [(), (self.site,self.key,self.envelope), (self.site,self.key,self.envelope,'--send'),
                     (self.site,self.key,self.envelope,FLAG,'extra')]:
            with self.subTest(args=args):
                result, report = self.invoke(*args); self.assertNotEqual(result.returncode, 0)
                self.assertEqual(report, {'test_message_submitted':False,'error_category':'configuration'})
        self.assertEqual(self.submissions(), []); self.assertTrue(self.key.exists())

    def test_expired_key_never_submits(self):
        expired=time.time()-901; os.utime(self.key,(expired,expired))
        result, report = self.send(); self.assertNotEqual(result.returncode,0)
        self.assertEqual(report['error_category'],'configuration'); self.assertEqual(self.submissions(),[])

    def test_wrong_token_and_header_injection_never_submit(self):
        for request in [{'recipient':self.recipient,'token':'0'*32},
                        {'recipient':'test@example.net\r\nBcc: other@example.net','token':self.token},
                        {'recipient':self.recipient,'token':self.token,'extra':True}]:
            self.encrypt_envelope(request)
            result, report = self.send(); self.assertNotEqual(result.returncode,0)
        self.assertEqual(self.submissions(),[]); self.assertTrue(self.key.exists())

    def test_noncanonical_root_and_nonprivate_config_never_submit(self):
        result, report = self.invoke(str(self.site) + '/.',self.key,self.envelope,FLAG)
        self.assertNotEqual(result.returncode, 0)
        self.config.chmod(0o644); result, report = self.send()
        self.assertNotEqual(result.returncode, 0); self.assertEqual(self.submissions(), [])

    def test_private_key_permissions_and_linked_envelope_refused(self):
        self.key.chmod(0o644); result, report = self.send(); self.assertNotEqual(result.returncode,0)
        self.key.chmod(0o600); saved=self.home/'envelope.saved'; self.envelope.rename(saved); self.envelope.symlink_to(saved)
        result, report = self.send(); self.assertNotEqual(result.returncode,0); self.assertEqual(self.submissions(),[])

    def test_submission_failure_is_sanitized_consumes_key_and_never_retries(self):
        (self.home / 'fail-submission').touch(); result, report = self.send()
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(report, {'test_message_submitted':False,'error_category':'smtp_submission'})
        self.assertEqual(len(self.submissions()), 1); self.assertFalse(self.key.exists())
        replay, report = self.send(); self.assertNotEqual(replay.returncode,0); self.assertEqual(len(self.submissions()),1)

    def test_linked_phpmailer_is_refused(self):
        source = self.core / 'SMTP.php'; saved = self.home / 'SMTP.php'; source.rename(saved); source.symlink_to(saved)
        result, report = self.send(); self.assertNotEqual(result.returncode, 0)
        self.assertEqual(report, {'test_message_submitted':False,'error_category':'runtime'})
        self.assertEqual(self.submissions(), []); self.assertTrue(self.key.exists())


if __name__ == '__main__': unittest.main()
