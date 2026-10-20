"""Exercise deployment with the real adapter/probe and a protocol-only test double.
No network connection or message delivery occurs in these fixtures.
"""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[1]
PHP=Path('/workspace/.petish-dev/bin/php')

@unittest.skipUnless(PHP.is_file(), 'private verified PHP runtime required')
class MailConfigurationTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory()
        self.home=Path(self.temp.name)
        self.site=self.home/'domains/petish.biz/public_html'
        self.mu=self.site/'wp-content/mu-plugins'
        self.mu.mkdir(parents=True)
        (self.site/'wp-config.php').write_text("<?php $table_prefix='wp_';")
        self.private=self.site.parent/'private/petish-mailer'
        self.config=self.private/'smtp.json'
        self.adapter=self.mu/'petish-mailer.php'
        self.stage=self.home/'.petish-mail-config.Abc12345'
        self.stage.mkdir(mode=0o700)
        for name in ('configure-petish-mail.php','configure-petish-mail-remote.sh','probe-petish-smtp.php'):
            shutil.copyfile(ROOT/'scripts'/name,self.stage/name)
        shutil.copyfile(ROOT/'wordpress/wp-content/mu-plugins/petish-mailer.php',self.stage/'petish-mailer.php')
        self.values={'host':'smtp.hostinger.com','port':465,'encryption':'smtps','username':'administrator@petish.biz','password':'private-fixture-password'}
        (self.stage/'credential.json').write_text(json.dumps(self.values)); (self.stage/'credential.json').chmod(0o600)
        self.metadata={'status':'success','mail_plugins':[],'smtp_constant_names':[]}
        self.write_metadata()
        core=self.site/'wp-includes/PHPMailer';core.mkdir(parents=True)
        (core/'Exception.php').write_text('<?php namespace PHPMailer\\PHPMailer; class Exception extends \\Exception {}')
        (core/'SMTP.php').write_text('<?php namespace PHPMailer\\PHPMailer; class SMTP {}')
        (core/'PHPMailer.php').write_text('''<?php namespace PHPMailer\\PHPMailer;
#[\\AllowDynamicProperties] class PHPMailer {
const ENCRYPTION_SMTPS='ssl';
function __construct($exceptions=true){}
function isSMTP(){$this->Mailer='smtp';}
function setFrom($address,$name,$unused=false){$this->From=$address; $this->FromName=$name;}
function smtpConnect(){file_put_contents(getenv('HOME').'/protocol.log',"EHLO AUTH\\n",FILE_APPEND); if(file_exists(getenv('HOME').'/fail-auth'))throw new Exception('private rejection');return true;}
function smtpClose(){file_put_contents(getenv('HOME').'/protocol.log',"QUIT\\n",FILE_APPEND);}
}''')
        self.env={**os.environ,'HOME':str(self.home),'PATH':str(PHP.parent)+':'+os.environ['PATH']}
        self.env.pop('WP_ENVIRONMENT_TYPE',None)

    def tearDown(self): self.temp.cleanup()
    def write_metadata(self):
        body="#!/usr/bin/env bash\nprintf '%s\\n' '"+json.dumps(self.metadata)+"'\n"
        (self.stage/'inspect-email-settings-remote.sh').write_text(body)
    def run_remote(self):
        r=subprocess.run(['bash',str(self.stage/'configure-petish-mail-remote.sh'),str(self.stage)],env=self.env,capture_output=True,text=True,timeout=20)
        self.assertNotIn(self.values['password'],r.stdout+r.stderr)
        return r
    def previous(self):
        self.private.mkdir(parents=True,mode=0o700)
        values={**self.values,'password':'previous-private-password'}
        self.config.write_text(json.dumps(values));self.config.chmod(0o600)
        self.adapter.write_text((ROOT/'wordpress/wp-content/mu-plugins/petish-mailer.php').read_text()+'\n// prior fixture\n')
        return self.config.read_bytes(),self.adapter.read_bytes()
    def assert_auth_only(self):
        protocol=(self.home/'protocol.log').read_text()
        self.assertIn('AUTH',protocol);self.assertIn('QUIT',protocol)
        self.assertNotIn('MAIL',protocol);self.assertNotIn('RCPT',protocol);self.assertNotIn('DATA',protocol)
    def test_success_installs_validated_config_before_adapter_and_cleans_stage(self):
        r=self.run_remote();self.assertEqual(r.returncode,0,r.stdout)
        self.assertEqual(json.loads(r.stdout),{'configured':True,'smtp_authentication_verified':True,'tls_peer_verification':True,'message_submitted':False})
        self.assertEqual(json.loads(self.config.read_text()),self.values)
        self.assertEqual(self.config.stat().st_mode&0o7777,0o600)
        self.assertEqual(self.private.stat().st_mode&0o7777,0o700)
        self.assertEqual(self.adapter.read_bytes(),(ROOT/'wordpress/wp-content/mu-plugins/petish-mailer.php').read_bytes())
        self.assertFalse(self.stage.exists());self.assert_auth_only()
    def test_failed_authentication_never_activates_adapter_and_removes_private_candidate(self):
        (self.home/'fail-auth').touch();r=self.run_remote()
        self.assertNotEqual(r.returncode,0);self.assertEqual(json.loads(r.stdout)['failure_category'],'smtp_authentication')
        self.assertFalse(self.config.exists());self.assertFalse(self.adapter.exists());self.assertFalse(self.private.exists());self.assertFalse(self.stage.exists());self.assert_auth_only()
    def test_failed_authentication_preserves_previous_credentials_and_adapter(self):
        old=self.previous();(self.home/'fail-auth').touch();r=self.run_remote()
        self.assertNotEqual(r.returncode,0);self.assertEqual((self.config.read_bytes(),self.adapter.read_bytes()),old)
        self.assertEqual(list(self.private.iterdir()),[self.config]);self.assert_auth_only()
    def test_private_symlink_refused_without_writing_outside(self):
        outside=self.home/'elsewhere';outside.mkdir(mode=0o700)
        self.private.parent.mkdir(parents=True);self.private.symlink_to(outside,target_is_directory=True)
        r=self.run_remote();self.assertNotEqual(r.returncode,0)
        self.assertEqual(list(outside.iterdir()),[]);self.assertFalse(self.adapter.exists());self.assertFalse((self.home/'protocol.log').exists())
    def test_other_active_mailer_stops_before_credentials_or_auth(self):
        self.metadata['mail_plugins']=[{'slug':'wp-mail-smtp','active':True}];self.write_metadata()
        r=self.run_remote();self.assertNotEqual(r.returncode,0)
        self.assertEqual(json.loads(r.stdout)['failure_category'],'existing_mailer');self.assertFalse(self.private.exists());self.assertFalse((self.home/'protocol.log').exists())
    def test_staging_marker_and_dynamic_environment_refused(self):
        (self.site/'wp-config.php').write_text("<?php define('WP_ENVIRONMENT_TYPE',getenv('EXAMPLE')); ")
        r=self.run_remote();self.assertNotEqual(r.returncode,0);self.assertFalse(self.adapter.exists());self.assertFalse(self.private.exists())
    def test_partial_installation_rollback_restores_preimages(self):
        old=self.previous()
        (self.stage/'metadata.json').write_text(json.dumps(self.metadata))
        helper=self.stage/'configure-petish-mail.php'
        r=subprocess.run([str(PHP),str(helper),'prepare',str(self.stage)],env=self.env,capture_output=True,timeout=20)
        self.assertEqual(r.returncode,0)
        state=json.loads((self.stage/'state.json').read_text())
        Path(state['candidate']).replace(self.config) # Simulate interruption after credential rename.
        self.assertNotEqual(self.config.read_bytes(),old[0])
        r=subprocess.run([str(PHP),str(helper),'rollback',str(self.stage)],env=self.env,capture_output=True,timeout=20)
        self.assertEqual(r.returncode,0)
        self.assertEqual((self.config.read_bytes(),self.adapter.read_bytes()),old)
        self.assertEqual(list(self.private.iterdir()),[self.config])
    def test_runner_rejects_malformed_successful_ssh_output(self):
        tools=self.home/'tools';tools.mkdir()
        stub=tools/'sshpass'
        stub.write_text("#!/usr/bin/env python3\nimport sys\nsys.stdin.buffer.read()\nprint('[]')\n")
        stub.chmod(0o700)
        key=self.home/'hostkey'
        subprocess.run(['ssh-keygen','-q','-t','ed25519','-N','','-f',str(key)],check=True,capture_output=True)
        pub=key.with_suffix('.pub').read_text().split()
        (self.home/'petish-candidate-host-keys').write_text('[example.invalid]:65002 '+pub[0]+' '+pub[1]+'\n')
        fingerprint=subprocess.check_output(['ssh-keygen','-lf',str(key.with_suffix('.pub')),'-E','sha256'],text=True).split()[1]
        env={**self.env,'PATH':str(tools)+':'+self.env['PATH'],'RUNNER_TEMP':str(self.home),
             'PETISH_SMTP_PASSWORD':self.values['password'],'SSHPASS':'fixture-ssh-password',
             'SSH_EXPECTED_FINGERPRINT':fingerprint,'SSH_HOST':'example.invalid','SSH_PORT':'65002','SSH_USER':'fixture'}
        r=subprocess.run(['bash','scripts/hostinger-configure-mail.sh'],cwd=ROOT,env=env,capture_output=True,timeout=20)
        self.assertNotEqual(r.returncode,0)
        self.assertFalse(json.loads((self.home/'petish-mail-result.json').read_text())['configured'])
        self.assertNotIn(self.values['password'].encode(),r.stdout+r.stderr)
        self.assertEqual(list(self.home.glob('petish-mail-bundle.*')),[])

    def runner_fixture(self, stub_body):
        tools=self.home/'runner-tools';tools.mkdir()
        stub=tools/'sshpass';stub.write_text('#!/usr/bin/env python3\n'+stub_body);stub.chmod(0o700)
        key=self.home/'runner-hostkey'
        subprocess.run(['ssh-keygen','-q','-t','ed25519','-N','','-f',str(key)],check=True,capture_output=True)
        pub=key.with_suffix('.pub').read_text().split()
        (self.home/'petish-candidate-host-keys').write_text('[example.invalid]:65002 '+pub[0]+' '+pub[1]+'\n')
        fingerprint=subprocess.check_output(['ssh-keygen','-lf',str(key.with_suffix('.pub')),'-E','sha256'],text=True).split()[1]
        env={**self.env,'PATH':str(tools)+':'+self.env['PATH'],'RUNNER_TEMP':str(self.home),
             'PETISH_SMTP_PASSWORD':self.values['password'],'SSHPASS':'fixture-ssh-password',
             'SSH_EXPECTED_FINGERPRINT':fingerprint,'SSH_HOST':'example.invalid','SSH_PORT':'65002','SSH_USER':'fixture'}
        return env,stub,tools

    def test_runner_preserves_only_safe_smtp_diagnostics(self):
        safe={'configured':False,'smtp_authentication_verified':False,'message_submitted':False,
              'failure_category':'smtp_authentication','smtp_reply_code':535,
              'smtp_enhanced_code':'5.7.8','error_stage':'smtp_authentication',
              'raw_error':'PRIVATE SERVER RESPONSE'}
        env,stub,tools=self.runner_fixture("import sys\nsys.stdin.buffer.read()\nprint("+repr(json.dumps(safe))+")\nsys.exit(1)\n")
        r=subprocess.run(['bash','scripts/hostinger-configure-mail.sh'],cwd=ROOT,env=env,capture_output=True,timeout=20)
        self.assertNotEqual(r.returncode,0)
        result=json.loads((self.home/'petish-mail-result.json').read_text())
        self.assertEqual(result['smtp_reply_code'],535);self.assertEqual(result['smtp_enhanced_code'],'5.7.8')
        self.assertEqual(result['error_stage'],'smtp_authentication')
        self.assertNotIn('PRIVATE SERVER RESPONSE',r.stdout.decode());self.assertNotIn('raw_error',result)
        invalid={**safe,'smtp_reply_code':True,'smtp_enhanced_code':'5.7.8 private text','error_stage':'private arbitrary text'}
        stub.write_text("#!/usr/bin/env python3\nimport sys\nsys.stdin.buffer.read()\nprint("+repr(json.dumps(invalid))+")\nsys.exit(1)\n")
        r=subprocess.run(['bash','scripts/hostinger-configure-mail.sh'],cwd=ROOT,env=env,capture_output=True,timeout=20)
        result=json.loads((self.home/'petish-mail-result.json').read_text())
        for key in ('smtp_reply_code','smtp_enhanced_code','error_stage'):self.assertNotIn(key,result)

    def test_receiver_handoff_has_one_rollback_owner_and_term_restores_partial_swap(self):
        old=self.previous()
        # The fake SSH receiver runs the actual extraction and deployment shell.
        # Metadata stays a fixture; configuration, atomic swap and rollback are real.
        stub_body='''import os,subprocess,sys
command='export PETISH_TEST_RECEIVER_PID=$$; '+sys.argv[-1]
needle='tar -xf - -C "$stage";'
command=command.replace(needle,needle+' cp "$HOME/.petish-mail-config.Abc12345/inspect-email-settings-remote.sh" "$stage/inspect-email-settings-remote.sh";')
sys.exit(subprocess.run(['bash','-c',command],env=os.environ).returncode)
'''
        env,stub,tools=self.runner_fixture(stub_body)
        php=tools/'php'
        wrapper='''import json,os,signal,sys,time
from pathlib import Path
if len(sys.argv)>1 and Path(sys.argv[1]).name=='probe-petish-smtp.php':
    stage=Path(sys.argv[1]).parent
    state=json.loads((stage/'state.json').read_text())
    Path(state['candidate']).replace(Path(os.environ['HOME'])/'domains/petish.biz/private/petish-mailer/smtp.json')
    owner=int(os.environ['PETISH_TEST_RECEIVER_PID'])
    (Path(os.environ['HOME'])/'rollback-owner.json').write_text(json.dumps({'single_owner':os.getppid()==owner,'state_present':(stage/'state.json').is_file()}))
    os.kill(owner,signal.SIGTERM)
    time.sleep(0.05)
    sys.exit(1)
os.execv(PHP_REAL,[PHP_REAL]+sys.argv[1:])
'''
        php.write_text('#!/usr/bin/env python3\nPHP_REAL='+repr(str(PHP))+'\n'+wrapper);php.chmod(0o700)
        r=subprocess.run(['bash','scripts/hostinger-configure-mail.sh'],cwd=ROOT,env=env,capture_output=True,timeout=20)
        self.assertNotEqual(r.returncode,0)
        owner=json.loads((self.home/'rollback-owner.json').read_text())
        self.assertEqual(owner,{'single_owner':True,'state_present':True})
        self.assertEqual((self.config.read_bytes(),self.adapter.read_bytes()),old)
        self.assertEqual(list(self.home.glob('.petish-mail-config.*')),[self.stage])
        self.assertEqual(list(self.private.iterdir()),[self.config])

    def test_missing_runner_secret_stops_before_ssh(self):
        env={**self.env,'RUNNER_TEMP':str(self.home)};env.pop('PETISH_SMTP_PASSWORD',None)
        r=subprocess.run(['bash','scripts/hostinger-configure-mail.sh'],cwd=ROOT,env=env,capture_output=True,timeout=20)
        self.assertNotEqual(r.returncode,0)
        report=json.loads((self.home/'petish-mail-result.json').read_text())
        self.assertEqual(report['failure_category'],'credential_missing');self.assertFalse(self.adapter.exists())

if __name__=='__main__': unittest.main()
