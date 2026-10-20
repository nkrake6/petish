"""Private configuration and real PHPMailer configuration tests; no mail sent."""

import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[1]
ADAPTER = ROOT / "wordpress/wp-content/mu-plugins/petish-mailer.php"
PROBE = ROOT / "scripts/probe-petish-smtp.php"
PHP = Path("/workspace/.petish-dev/bin/php")
PHPMailer_ROOT = os.environ.get("PETISH_PHPMAILER_ROOT")


class PetishMailerTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary.name)
        self.public = self.root / "public_html"
        self.public.mkdir()
        self.config = self.root / "private/petish-mailer/smtp.json"
        self.config.parent.mkdir(parents=True, mode=0o700)
        self.values = {"host": "smtp.hostinger.com", "port": 465, "encryption": "smtps",
                       "username": "administrator@petish.biz", "password": "fixture-private-value"}
        self.write_config()

    def tearDown(self):
        self.temporary.cleanup()

    def write_config(self, values=None):
        self.config.write_text(json.dumps(self.values if values is None else values))
        self.config.chmod(0o600)

    def php(self, body, *, environment="production", definitions="", classes=False):
        fixture = self.root / "check.php"
        setup = "<?php\n"
        setup += "define('ABSPATH'," + repr(str(self.public) + "/") + ");\n"
        setup += "define('PETISH_SMTP_PROBE',true);\n" + definitions + "\n"
        setup += "function wp_get_environment_type(){return " + repr(environment) + ";}\n"
        if classes:
            for filename in ("Exception.php", "SMTP.php", "PHPMailer.php"):
                setup += "require_once " + repr(str(Path(PHPMailer_ROOT) / filename)) + ";\n"
        setup += "require_once " + repr(str(ADAPTER)) + ";\n" + body
        fixture.write_text(setup)
        result = subprocess.run([str(PHP), str(fixture)], capture_output=True, check=False, timeout=20)
        self.assertEqual(result.returncode, 0, "PHP fixture failed without printing private diagnostics")
        return json.loads(result.stdout)

    def test_valid_and_missing_configuration(self):
        self.assertTrue(self.php("echo json_encode(Petish_Mailer::read_config()!==false);"))
        self.config.unlink()
        self.assertFalse(self.php("echo json_encode(Petish_Mailer::pre_mail(null));"))

    def test_permission_hardlink_and_symlink_rejected(self):
        self.config.chmod(0o644)
        self.assertFalse(self.php("echo json_encode(Petish_Mailer::read_config());"))
        self.config.chmod(0o600)
        self.config.chmod(0o4600)
        self.assertFalse(self.php("echo json_encode(Petish_Mailer::read_config());"))
        self.config.chmod(0o600)
        other = self.root / "linked.json"
        os.link(self.config, other)
        self.assertFalse(self.php("echo json_encode(Petish_Mailer::read_config());"))
        other.unlink()
        original = self.config.with_suffix(".saved")
        self.config.rename(original)
        self.config.symlink_to(original)
        self.assertFalse(self.php("echo json_encode(Petish_Mailer::read_config());"))

    def test_private_mailer_directory_requires_exact_0700(self):
        for mode in (0o755, 0o750, 0o1700):
            self.config.parent.chmod(mode)
            self.assertFalse(self.php("echo json_encode(Petish_Mailer::read_config());"))
        self.config.parent.chmod(0o700)
        self.assertTrue(self.php("echo json_encode(Petish_Mailer::read_config()!==false);"))

    def test_ancestor_link_and_traversal_rejected(self):
        path = self.config.parent
        saved = path.with_name("saved")
        path.rename(saved)
        path.symlink_to(saved, target_is_directory=True)
        self.assertFalse(self.php("echo json_encode(Petish_Mailer::read_config());"))
        path.unlink()
        saved.rename(path)
        supplied = str(path / ".." / "petish-mailer" / "smtp.json")
        self.assertFalse(self.php("echo json_encode(Petish_Mailer::read_config(" + repr(supplied) + "));"))

    def test_only_exact_private_directory_and_candidate_names_allowed(self):
        outside = self.root / "smtp.json"
        outside.write_text(json.dumps(self.values))
        outside.chmod(0o600)
        self.assertFalse(self.php("echo json_encode(Petish_Mailer::read_config(" + repr(str(outside)) + "));"))
        candidate = self.config.parent / ".smtp-candidate.Abc123.json"
        candidate.write_text(json.dumps(self.values))
        candidate.chmod(0o600)
        self.assertTrue(self.php("echo json_encode(Petish_Mailer::read_config(" + repr(str(candidate)) + ")!==false);"))

    def test_strict_schema_transport_and_controls(self):
        for key, value in [("host", "untrusted.invalid"), ("port", "465"), ("port", 587),
                           ("encryption", "tls"), ("username", "other@petish.biz"),
                           ("password", "secret\r\nheader"), ("extra", True)]:
            with self.subTest(field=key):
                self.write_config({**self.values, key: value})
                self.assertFalse(self.php("echo json_encode(Petish_Mailer::read_config());"))
        self.config.write_text("malformed private JSON")
        self.config.chmod(0o600)
        self.assertFalse(self.php("echo json_encode(Petish_Mailer::read_config());"))

    def test_production_only_and_existing_capture_preserved(self):
        for environment in ("local", "staging", "development"):
            self.assertFalse(self.php("echo json_encode(Petish_Mailer::pre_mail(null));", environment=environment))
        for guard in ("PETISH_STAGING_GUARD_ACTIVE", "PETISH_RECOVERY_GUARD_TOKEN"):
            definition = "define(" + repr(guard) + ",true);"
            self.assertFalse(self.php("echo json_encode(Petish_Mailer::pre_mail(null));", definitions=definition))
            self.assertTrue(self.php("echo json_encode(Petish_Mailer::pre_mail(true));", definitions=definition))
        self.assertIsNone(self.php("echo json_encode(Petish_Mailer::pre_mail(null));"))
        self.assertFalse(self.php("echo json_encode(Petish_Mailer::pre_mail(false));"))

    @unittest.skipUnless(PHPMailer_ROOT, "set PETISH_PHPMAILER_ROOT to actual core PHPMailer directory")
    def test_real_phpmailer_has_fixed_identity_and_verified_implicit_tls(self):
        body = r'''$mail=new \PHPMailer\PHPMailer\PHPMailer(true);
Petish_Mailer::initialize($mail);
$tls=$mail->SMTPOptions['ssl'];
echo json_encode(['smtp'=>$mail->Mailer==='smtp','host'=>$mail->Host==='smtp.hostinger.com',
'port'=>$mail->Port===465,'secure'=>$mail->SMTPSecure==='ssl','auth'=>$mail->SMTPAuth,
'username'=>$mail->Username==='administrator@petish.biz','from'=>$mail->From==='orders@petish.biz',
'sender'=>$mail->Sender==='orders@petish.biz','name'=>$mail->FromName==='Petish',
'verify'=>$tls['verify_peer']&&$tls['verify_peer_name']&&!$tls['allow_self_signed'],
'debug'=>$mail->SMTPDebug===0]);'''
        result = self.php(body, classes=True)
        self.assertTrue(all(result.values()))

    def test_probe_invalid_config_has_only_static_result(self):
        self.config.chmod(0o644)
        result = subprocess.run([str(PHP), str(PROBE), str(self.config), str(self.public), str(ADAPTER)],
                                capture_output=True, timeout=20)
        self.assertEqual(result.returncode, 1)
        self.assertNotIn(b"fixture-private", result.stdout + result.stderr)
        self.assertNotIn(str(self.config).encode(), result.stdout + result.stderr)
        data = json.loads(result.stdout)
        self.assertFalse(data["smtp_authentication_verified"])
        self.assertFalse(data["message_submitted"])
        self.assertEqual(data["error_category"], "configuration")

    def test_probe_fake_authentication_never_submits_a_message_or_boots_wordpress(self):
        classes = self.public / "wp-includes/PHPMailer"
        classes.mkdir(parents=True)
        events = self.root / "fake-events.txt"
        (classes / "Exception.php").write_text("<?php namespace PHPMailer\\PHPMailer; class Exception extends \\RuntimeException {}")
        (classes / "SMTP.php").write_text("<?php namespace PHPMailer\\PHPMailer; class SMTP {}")
        fake = r'''<?php namespace PHPMailer\PHPMailer;
#[\AllowDynamicProperties]
class PHPMailer {
 const ENCRYPTION_SMTPS='ssl';
 public function __construct($exceptions=false){}
 public function isSMTP(){}
 public function setFrom($address,$name,$auto){return true;}
 public function smtpConnect(){file_put_contents(__EVENTS__,'AUTH'.PHP_EOL,FILE_APPEND);return true;}
 public function smtpClose(){file_put_contents(__EVENTS__,'QUIT'.PHP_EOL,FILE_APPEND);}
 public function send(){throw new \RuntimeException('Message submission is forbidden in this test.');}
}
'''.replace("__EVENTS__", repr(str(events)))
        (classes / "PHPMailer.php").write_text(fake)
        (self.public / "wp-load.php").write_text("<?php throw new RuntimeException('WordPress bootstrap is forbidden.');")
        result = subprocess.run([str(PHP), str(PROBE), str(self.config), str(self.public), str(ADAPTER)],
                                capture_output=True, timeout=20)
        self.assertEqual(result.returncode, 0)
        self.assertEqual(events.read_text().splitlines(), ["AUTH", "QUIT"])
        data = json.loads(result.stdout)
        self.assertTrue(data["smtp_authentication_verified"])
        self.assertFalse(data["message_submitted"])

    @unittest.skipUnless(PHPMailer_ROOT, "set PETISH_PHPMAILER_ROOT to actual core PHPMailer directory")
    def test_config_disappearing_before_init_cannot_fall_back_to_local_mail(self):
        self.config.unlink()
        result = self.php(r'''$mail=new \PHPMailer\PHPMailer\PHPMailer(true);
try{Petish_Mailer::initialize($mail);echo json_encode(false);}
catch(\PHPMailer\PHPMailer\Exception $ignored){echo json_encode($mail->Mailer==='smtp');}''', classes=True)
        self.assertTrue(result)


if __name__ == "__main__":
    unittest.main()
