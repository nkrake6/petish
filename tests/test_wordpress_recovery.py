"""Recovery guard and PHP tokenization tests; optional local Docker fixture."""

import importlib.util
import os
from pathlib import Path
import subprocess
import tempfile
import unittest


SCRIPT = Path(__file__).resolve().parents[1] / "scripts/test-restored-wordpress.py"
SPEC = importlib.util.spec_from_file_location("wordpress_recovery", SCRIPT)
APP = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(APP)


class WordPressRecoveryTests(unittest.TestCase):
    def test_generated_configuration_has_only_disposable_connection(self):
        config = APP.configuration("wp_", "disposable_user", "a" * 64)
        self.assertIn("localhost:/run/mysqld/mysqld.sock", config)
        self.assertIn("DISABLE_WP_CRON", config)
        self.assertIn("WP_HTTP_BLOCK_EXTERNAL", config)
        self.assertIn("DISALLOW_FILE_MODS", config)
        self.assertNotIn("petish.biz", config)
        self.assertNotIn("wp-config.php", config)

    def test_prefix_cannot_inject_php(self):
        for prefix in ("wp_';system('id');//", "../wp_", "", "x" * 129):
            with self.assertRaises(APP.ApplicationRecoveryFailure):
                APP.configuration(prefix, "disposable", "a" * 64)

    def test_guard_blocks_email_http_and_scheduler(self):
        for hook in ("pre_http_request", "pre_wp_mail", "action_scheduler_allow_async_request_runner"):
            self.assertIn(hook, APP.GUARD)

    def test_fixed_http_probe_includes_actual_templates_and_private_photo(self):
        self.assertIn("anonymous_private_photo_denied", APP.HTTP_CHECK)
        self.assertIn("homepage", APP.BOOTSTRAP)
        self.assertIn("wc_get_page_permalink('shop')", APP.BOOTSTRAP)
        self.assertIn("get_permalink($product->get_id())", APP.BOOTSTRAP)
        self.assertNotIn("checkout", APP.HTTP_CHECK)

    @unittest.skipUnless(os.environ.get("PETISH_TEST_DOCKER") == "1", "explicit Docker integration opt-in")
    def test_php_tokenization_never_executes_original_config(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            site = root / "site"
            site.mkdir()
            probe = root / "prefix.php"
            probe.write_text(APP.PREFIX_PROBE)
            config = site / "wp-config.php"
            samples = [
                ("<?php /* $table_prefix='fake_'; */ $table_prefix='wp_'; throw new Exception('never execute');", True),
                ("<?php $table_prefix=getenv('PREFIX');", False),
                ("<?php $table_prefix='wp_'; $table_prefix='other_';", False),
                ("<?php $table_prefix='wp_'; $table_prefix.='more';", False),
            ]
            for text, accepted in samples:
                config.write_text(text)
                result = subprocess.run(APP.DOCKER + ["run", "--rm", "--network", "none",
                    "--user", str(os.getuid()) + ":" + str(os.getgid()),
                    "--mount", "type=bind,src=" + str(site) + ",dst=/srv/restore/site-files,readonly",
                    "--mount", "type=bind,src=" + str(probe) + ",dst=/checks/prefix.php,readonly",
                    "--entrypoint", "php", APP.IMAGE, "/checks/prefix.php"], capture_output=True, timeout=30)
                self.assertEqual(result.returncode == 0, accepted)
                if accepted:
                    self.assertEqual(result.stdout, b"wp_")


if __name__ == "__main__":
    unittest.main()
