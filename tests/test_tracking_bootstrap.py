"""Offline failure-path checks for the private, request-local AST bootstrap.

The test copy substitutes only the pinned entrypoint SHA with an inert fixture's
SHA. This lets the real manifest verifier run without vendoring third-party PHP
or downloading it during tests; the production bootstrap remains unchanged.
"""

import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[1]
BOOTSTRAP = ROOT / "scripts/tracking-rehearsal-bootstrap.php"
PHP = Path("/workspace/.petish-dev/bin/php")
PINNED_MAIN_SHA = "e642a8751e25fef8986abb9fd2441a514313dd003c54afc69ccdd48fc83f506c"
MAIN = b"<?php /* Inert AST entrypoint fixture; never included. */\n"
BASELINE = [
    "petish-fulfilment/petish-fulfilment.php",
    "petish-passport/petish-passport.php",
    "woocommerce/woocommerce.php",
    "woocommerce-payments/woocommerce-payments.php",
]
AST = "woo-advanced-shipment-tracking/woocommerce-advanced-shipment-tracking.php"
RUN = "12345-1"


DRIVER = r'''<?php
define('ARRAY_A', 'ARRAY_A');
$events = []; $marks = []; $hooks = []; $status = 'success';
$failure_stage = null; $cleanup_rejected = false; $effective_plugins = null;
$baseline = json_decode(getenv('FIXTURE_PLUGINS'), true, 32, JSON_THROW_ON_ERROR);
function rehearsal_fail() { throw new RuntimeException('rejected'); }
function rehearsal_path($path, $directory = false) {
    if (!is_string($path) || is_link($path) || realpath($path) !== $path) return false;
    return $directory ? is_dir($path) : is_file($path);
}
function rehearsal_private_json($path) {
    return json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
}
function add_filter($hook, $callback, $priority = 10, $arguments = 1) {
    global $hooks;
    $hooks[$hook][$priority][] = $callback;
}
function add_action($hook, $callback, $priority = 10, $arguments = 1) {
    add_filter($hook, $callback, $priority, $arguments);
}
function apply_filters($hook, $value) {
    global $hooks;
    $callbacks = $hooks[$hook] ?? [];
    ksort($callbacks);
    foreach ($callbacks as $group) foreach ($group as $callback) $value = $callback($value);
    return $value;
}
function do_action($hook) {
    global $hooks;
    $callbacks = $hooks[$hook] ?? [];
    ksort($callbacks);
    foreach ($callbacks as $group) foreach ($group as $callback) $callback();
}
function remove_action($hook, $callback) {
    global $events;
    $events[] = 'remove:' . $hook;
}
function remove_all_actions($hook) {
    global $events;
    $events[] = 'remove_all:' . $hook;
}
if (getenv('FIXTURE_RUNTIME') !== 'missing_environment_function') {
    function wp_get_environment_type() {
        return getenv('FIXTURE_RUNTIME') === 'production' ? 'production' : 'staging';
    }
}
$runtime = getenv('FIXTURE_RUNTIME');
if ($runtime !== 'missing_guard') define('PETISH_STAGING_GUARD_ACTIVE', $runtime !== 'false_guard');
if ($runtime !== 'missing_db_name') define('DB_NAME', $runtime === 'wrong_db_name' ? 'other' : 'fixture_staging');
if ($runtime !== 'missing_db_host') define('DB_HOST', $runtime === 'wrong_db_host' ? 'other' : 'localhost');

final class FixtureDatabase {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $table_exists = false;
    public $rows = [];
    public $drops = 0;
    function __construct() {
        if (getenv('FIXTURE_PREEXISTING') === '1') {
            $this->table_exists = true;
            $this->rows = [['id'=>89, 'ts_slug'=>'existing', 'provider_url'=>'https://existing.invalid/']];
        }
        if (getenv('FIXTURE_BAD_PREFIX') === '1') $this->prefix = 'unsafe`';
    }
    function esc_like($value) { return addcslashes($value, '_%\\'); }
    function prepare($sql, $value) { return str_replace('%s', "'" . $value . "'", $sql); }
    function get_var($sql) {
        global $events;
        if (!str_starts_with($sql, 'SHOW TABLES LIKE ')) rehearsal_fail();
        $events[] = 'check_table';
        return $this->table_exists ? 'wp_woo_shippment_provider' : null;
    }
    function insert($table, $data) {
        global $events;
        if ($table !== 'wp_woo_shippment_provider' || !$this->table_exists) rehearsal_fail();
        $events[] = 'insert_fixture';
        if (getenv('FIXTURE_INSERT_FAILURE') === '1') return 0;
        $this->insert_id = 7;
        $this->rows[] = ['id'=>7] + $data;
        return 1;
    }
    function get_results($sql, $format) {
        global $events;
        if ($sql !== 'SELECT id,ts_slug,provider_url FROM `wp_woo_shippment_provider`' || $format !== ARRAY_A) rehearsal_fail();
        $events[] = 'inspect_cleanup';
        return $this->table_exists ? $this->rows : null;
    }
    function query($sql) {
        global $events;
        if ($sql !== 'DROP TABLE `wp_woo_shippment_provider`' || !$this->table_exists) rehearsal_fail();
        $events[] = 'drop_owned_table';
        $this->drops++;
        $this->table_exists = false;
        $this->rows = [];
        return 1;
    }
}
$wpdb = new FixtureDatabase();
if ($runtime !== 'missing_native_class') {
    final class WC_Advanced_Shipment_Tracking_Install {
        static function get_instance() {
            global $events;
            $events[] = 'native_instance';
            return new self();
        }
        function create_shippment_tracking_table() {
            global $wpdb, $events;
            $events[] = 'create_carrier_table';
            if ($wpdb->table_exists) rehearsal_fail();
            $wpdb->table_exists = true;
        }
    }
}

require getenv('FIXTURE_BOOTSTRAP');
$cleanup = null;
$phase = 'bootstrap';
try {
    $cleanup = petish_tracking_bootstrap(
        getenv('FIXTURE_SITE'), getenv('FIXTURE_PRIVATE'), '12345-1',
        static function($key, $value) use (&$marks) { $marks[$key] = $value; },
        ['DB_NAME'=>'fixture_staging', 'DB_HOST'=>'localhost']
    );
    $phase = 'active_plugins';
    $effective_plugins = apply_filters('option_active_plugins', $baseline);
    if (getenv('FIXTURE_PHASE') !== 'filter_only') {
        $phase = 'plugins_loaded';
        do_action('plugins_loaded');
        $phase = 'downstream';
        if (getenv('FIXTURE_AFTER_CREATE_FAILURE') === '1') throw new RuntimeException('simulated downstream failure');
        if (getenv('FIXTURE_TAMPER_ROWS') === '1') {
            $wpdb->rows[] = ['id'=>90, 'ts_slug'=>'not-owned', 'provider_url'=>'https://other.invalid/'];
        }
    }
} catch (Throwable $error) {
    $status = 'rejected';
    $failure_stage = $phase;
} finally {
    if (is_callable($cleanup) && getenv('FIXTURE_PHASE') !== 'shutdown_only') {
        try { $cleanup(); }
        catch (Throwable $error) { $cleanup_rejected = true; }
    }
}
// Registered after the bootstrap's cleanup: shutdown-only coverage observes its
// final table state, and explicit cleanup also checks shutdown is idempotent.
register_shutdown_function(static function() use (&$status, &$failure_stage, &$marks,
    &$hooks, &$events, &$effective_plugins, &$baseline, &$wpdb, &$cleanup_rejected) {
    echo json_encode([
        'status'=>$status, 'failure_stage'=>$failure_stage, 'marks'=>$marks,
        'hooks'=>array_keys($hooks), 'events'=>$events,
        'effective_plugins'=>$effective_plugins, 'stored_plugins'=>$baseline,
        'table_exists'=>$wpdb->table_exists, 'row_count'=>count($wpdb->rows),
        'drops'=>$wpdb->drops, 'cleanup_rejected'=>$cleanup_rejected,
    ], JSON_THROW_ON_ERROR) . "\n";
});
'''


@unittest.skipUnless(PHP.is_file(), "private verified PHP runtime required")
class TrackingBootstrapTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.work = Path(self.temp.name)
        self.site = self.work / "site"
        self.plugin = self.site / "wp-content/plugins/woo-advanced-shipment-tracking"
        self.plugin.mkdir(parents=True)
        self.private = self.work / "private"
        self.private.mkdir(mode=0o700)
        self.script = self.work / "scripts/tracking-rehearsal-bootstrap.php"
        self.script.parent.mkdir()
        source = BOOTSTRAP.read_text()
        self.assertEqual(source.count(PINNED_MAIN_SHA), 1, "production trust anchor changed; review this test fixture")
        self.script.write_text(source.replace(PINNED_MAIN_SHA, hashlib.sha256(MAIN).hexdigest()))
        self.manifest = {}
        for index in range(10):
            name = "woocommerce-advanced-shipment-tracking.php" if index == 0 else f"includes/fixture-{index}.php"
            data = MAIN if index == 0 else f"<?php /* inert fixture {index} */\n".encode()
            path = self.plugin / name
            path.parent.mkdir(exist_ok=True)
            path.write_bytes(data)
            self.manifest[name] = hashlib.sha256(data).hexdigest()
        self.write_manifest()
        self.marker = self.plugin / ".petish-tracking-rehearsal-owned"
        self.marker.write_text(RUN + "\n")
        self.marker.chmod(0o600)
        self.driver = self.work / "driver.php"
        self.driver.write_text(DRIVER)
        self.environment = {
            **os.environ,
            "FIXTURE_BOOTSTRAP": str(self.script),
            "FIXTURE_SITE": str(self.site),
            "FIXTURE_PRIVATE": str(self.private),
            "FIXTURE_PLUGINS": json.dumps(BASELINE),
        }

    def write_manifest(self):
        (self.script.parent / "ast-manifest.json").write_text(json.dumps(self.manifest))

    def run_bootstrap(self, allow_remote=False, **overrides):
        result = subprocess.run(
            [str(PHP), "-n", "-d", f"allow_url_fopen={int(allow_remote)}", str(self.driver)],
            env={**self.environment, **overrides}, capture_output=True, text=True, timeout=10,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stderr, "")
        return json.loads(result.stdout)

    def assert_early_rejection(self, report):
        self.assertEqual(report["status"], "rejected")
        self.assertEqual(report["failure_stage"], "bootstrap")
        self.assertEqual(report["hooks"], [])
        self.assertEqual(report["events"], [])
        self.assertFalse(report["table_exists"])
        self.assertEqual(report["drops"], 0)

    def test_file_hash_mismatch_rejects_before_registering_hooks(self):
        (self.plugin / "includes/fixture-1.php").write_text("<?php /* changed after review */\n")
        self.assert_early_rejection(self.run_bootstrap())

    def test_manifest_rejects_missing_and_unreviewed_files_before_hooks(self):
        path = self.plugin / "includes/fixture-1.php"
        original = path.read_bytes()
        path.unlink()
        self.assert_early_rejection(self.run_bootstrap())
        path.write_bytes(original)
        (self.plugin / "unreviewed.php").write_text("<?php /* unreviewed */\n")
        self.assert_early_rejection(self.run_bootstrap())

    def test_self_consistent_manifest_cannot_replace_the_pinned_entrypoint(self):
        changed = MAIN + b"/* different entrypoint */\n"
        (self.plugin / "woocommerce-advanced-shipment-tracking.php").write_bytes(changed)
        self.manifest["woocommerce-advanced-shipment-tracking.php"] = hashlib.sha256(changed).hexdigest()
        self.write_manifest()
        self.assert_early_rejection(self.run_bootstrap())

    def test_remote_file_access_must_be_disabled_before_hooks(self):
        self.assert_early_rejection(self.run_bootstrap(allow_remote=True))

    def test_owned_plugin_marker_requires_private_mode_and_current_run(self):
        self.marker.chmod(0o644)
        self.assert_early_rejection(self.run_bootstrap())
        self.marker.chmod(0o600)
        self.marker.write_text("12344-1\n")
        self.assert_early_rejection(self.run_bootstrap())

    def test_effective_plugins_append_only_ast_and_preserve_stored_baseline(self):
        report = self.run_bootstrap(FIXTURE_PHASE="filter_only")
        self.assertEqual(report["status"], "success")
        self.assertEqual(report["effective_plugins"], BASELINE + [AST])
        self.assertEqual(report["stored_plugins"], BASELINE)
        self.assertNotIn("create_carrier_table", report["events"])
        self.assertEqual(report["drops"], 0)

    def test_unexpected_missing_duplicate_or_already_active_plugins_are_rejected(self):
        cases = [BASELINE + ["unknown/plugin.php"], BASELINE[:-1],
                 BASELINE + [BASELINE[0]], BASELINE + [AST],
                 [None] + BASELINE[1:], "not-an-array"]
        for plugins in cases:
            with self.subTest(plugins=plugins):
                report = self.run_bootstrap(FIXTURE_PLUGINS=json.dumps(plugins))
                self.assertEqual(report["status"], "rejected")
                self.assertEqual(report["failure_stage"], "active_plugins")
                self.assertNotIn("create_carrier_table", report["events"])
                self.assertEqual(report["drops"], 0)

    def test_environment_guard_database_and_native_installer_are_required_before_creation(self):
        cases = ["production", "missing_environment_function", "missing_guard", "false_guard",
                 "wrong_db_name", "missing_db_name", "wrong_db_host", "missing_db_host",
                 "missing_native_class"]
        for runtime in cases:
            with self.subTest(runtime=runtime):
                report = self.run_bootstrap(FIXTURE_RUNTIME=runtime)
                self.assertEqual(report["status"], "rejected")
                self.assertEqual(report["failure_stage"], "plugins_loaded")
                self.assertNotIn("native_instance", report["events"])
                self.assertNotIn("create_carrier_table", report["events"])
                self.assertEqual(report["drops"], 0)

    def test_preexisting_carrier_table_is_never_created_modified_or_dropped(self):
        report = self.run_bootstrap(FIXTURE_PREEXISTING="1")
        self.assertEqual(report["status"], "rejected")
        self.assertEqual(report["failure_stage"], "plugins_loaded")
        self.assertNotIn("create_carrier_table", report["events"])
        self.assertNotIn("insert_fixture", report["events"])
        self.assertTrue(report["table_exists"])
        self.assertEqual(report["row_count"], 1)
        self.assertEqual(report["drops"], 0)

    def test_owned_carrier_fixture_is_removed_after_downstream_failure(self):
        report = self.run_bootstrap(FIXTURE_AFTER_CREATE_FAILURE="1")
        self.assertEqual(report["status"], "rejected")
        self.assertEqual(report["failure_stage"], "downstream")
        self.assertTrue(report["marks"]["tracking_carrier_fixture_created"])
        self.assertTrue(report["marks"]["tracking_carrier_fixture_removed"])
        self.assertFalse(report["table_exists"])
        self.assertEqual(report["drops"], 1)

    def test_owned_empty_table_is_removed_when_fixture_insert_fails(self):
        report = self.run_bootstrap(FIXTURE_INSERT_FAILURE="1")
        self.assertEqual(report["status"], "rejected")
        self.assertEqual(report["failure_stage"], "plugins_loaded")
        self.assertTrue(report["marks"]["tracking_carrier_fixture_removed"])
        self.assertFalse(report["table_exists"])
        self.assertEqual(report["drops"], 1)

    def test_shutdown_removes_owned_fixture_without_explicit_cleanup(self):
        report = self.run_bootstrap(FIXTURE_PHASE="shutdown_only")
        self.assertEqual(report["status"], "success")
        self.assertTrue(report["marks"]["tracking_carrier_fixture_removed"])
        self.assertFalse(report["table_exists"])
        self.assertEqual(report["drops"], 1)

    def test_cleanup_refuses_to_drop_table_if_unowned_rows_appear(self):
        report = self.run_bootstrap(FIXTURE_TAMPER_ROWS="1")
        self.assertTrue(report["cleanup_rejected"])
        self.assertFalse(report["marks"]["tracking_carrier_fixture_removed"])
        self.assertTrue(report["table_exists"])
        self.assertEqual(report["row_count"], 2)
        self.assertEqual(report["drops"], 0)

    def test_unsafe_table_prefix_rejects_before_schema_creation(self):
        report = self.run_bootstrap(FIXTURE_BAD_PREFIX="1")
        self.assertEqual(report["status"], "rejected")
        self.assertEqual(report["failure_stage"], "plugins_loaded")
        self.assertNotIn("check_table", report["events"])
        self.assertNotIn("create_carrier_table", report["events"])
        self.assertEqual(report["drops"], 0)


if __name__ == "__main__":
    unittest.main()
