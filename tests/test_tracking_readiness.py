"""Metadata-only tracking inventory fixtures; no production database is contacted."""

import json
import importlib.util
import os
from pathlib import Path
import secrets
import subprocess
import tempfile
import unittest
import uuid


ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts/inspect-tracking-readiness-remote.sh"
PHP = Path("/workspace/.petish-dev/bin/php")
PAYLOAD = SCRIPT.read_text().split("php <<'PETISH_TRACKING_READINESS'\n", 1)[1].rsplit("\nPETISH_TRACKING_READINESS", 1)[0]
PRIVATE = "private-sentinel-never-publish"

DRIVER = r'''<?php
define('MYSQLI_REPORT_ERROR',1);define('MYSQLI_REPORT_STRICT',2);define('MYSQLI_OPT_CONNECT_TIMEOUT',3);
function mysqli_report($unused){}
function mysqli_init(){return new FixtureDatabase();}
final class FixtureRows {
 private $rows;
 function __construct($rows){$this->rows=$rows;}
 function fetch_assoc(){return array_shift($this->rows);}
 function free(){}
}
final class FixtureDatabase {
 function options($key,$value){}
 function real_connect(...$arguments){}
 function set_charset($value){}
 function close(){}
 function query($sql){
  if(!preg_match('/^SELECT\s/',$sql))throw new RuntimeException('private-sentinel-mutation');
  file_put_contents(getenv('FIXTURE_QUERY_LOG'),json_encode($sql)."\n",FILE_APPEND);
  $minimal=getenv('FIXTURE_MINIMAL')==='1';
  if(str_contains($sql,'information_schema.TABLES')){
   $suffixes=$minimal?['options']:['options','woocommerce_api_keys','wc_webhooks','postmeta','posts','wc_orders_meta','wc_orders'];
   return new FixtureRows(array_map(fn($suffix)=>['TABLE_NAME'=>'wp_'.$suffix],$suffixes));
  }
  if(str_contains($sql,'`wp_options`')){
   $options=['active_plugins'=>serialize(['woo-advanced-shipment-tracking/main.php','private-sentinel-never-publish/plugin.php']),
    'woocommerce_custom_orders_table_enabled'=>'yes','woocommerce_custom_orders_table_data_sync_enabled'=>'no',
    'wcast_enable_tracking_info'=>'1','wcast_enable_tracking_info_in_email'=>'private-sentinel-never-publish',
    'woocommerce_customer_completed_order_settings'=>serialize(['enabled'=>'yes','recipient'=>'private-sentinel-never-publish']),
    'woocommerce_customer_processing_order_settings'=>serialize(['enabled'=>'no','password'=>'private-sentinel-never-publish'])];
   if(getenv('FIXTURE_ACTIVE_OBJECT')==='1')$options['active_plugins']='O:8:"stdClass":0:{}';
   $rows=[];foreach($options as $name=>$value)$rows[]=['option_name'=>$name,'option_value'=>$value];
   return new FixtureRows($rows);
  }
  if(str_contains($sql,'`wp_woocommerce_api_keys`')){
   if(getenv('FIXTURE_QUERY_FAILURE')==='1')throw new RuntimeException('private-sentinel-never-publish: database password');
   return new FixtureRows([['safe_permission'=>'read_write','accessed'=>'1','row_count'=>'2'],['safe_permission'=>'read','accessed'=>'0','row_count'=>'1']]);
  }
  if(str_contains($sql,'`wp_wc_webhooks`'))return new FixtureRows([
   ['safe_active'=>'1','safe_topic'=>'order.updated','safe_callback'=>'dsers','dsers_match'=>'1','row_count'=>'2'],
   ['safe_active'=>'0','safe_topic'=>'other','safe_callback'=>'other','dsers_match'=>'1','row_count'=>'1'],
   ['safe_active'=>'1','safe_topic'=>'product.updated','safe_callback'=>'other','dsers_match'=>'0','row_count'=>'3']]);
  if(str_contains($sql,'`wp_postmeta`'))return new FixtureRows([['meta_key'=>'_wc_shipment_tracking_items','row_count'=>'4']]);
  if(str_contains($sql,'`wp_wc_orders_meta`'))return new FixtureRows([['meta_key'=>getenv('FIXTURE_UNSAFE_KEY')==='1'?'private-sentinel-never-publish':'_tracking_number','row_count'=>'2']]);
  throw new RuntimeException('private-sentinel-unexpected-query');
 }
}
?>
'''


@unittest.skipUnless(PHP.is_file(), "private verified PHP runtime required")
class TrackingReadinessTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.site = self.root / "site"
        (self.site / "wp-content/plugins/woo-advanced-shipment-tracking").mkdir(parents=True)
        self.script = self.root / "inspect.php"
        self.script.write_text(DRIVER + PAYLOAD)
        self.log = self.root / "queries.jsonl"
        self.environment = {**os.environ, "PETISH_DB_PREFIX": "wp_", "PETISH_TRACKING_SITE_PATH": str(self.site),
                            "PETISH_DB_HOST": "localhost", "PETISH_DB_USER": "fixture",
                            "PETISH_DB_PASSWORD": PRIVATE, "PETISH_DB_NAME": "fixture",
                            "PETISH_DB_CLIENT_FLAGS": "0", "FIXTURE_QUERY_LOG": str(self.log)}

    def run_inspection(self, **overrides):
        result = subprocess.run([str(PHP), "-n", str(self.script)], env={**self.environment, **overrides},
                                capture_output=True, text=True, timeout=10)
        self.assertNotIn(PRIVATE, result.stdout + result.stderr)
        self.assertNotIn(str(self.root), result.stdout + result.stderr)
        self.assertEqual(result.stderr, "")
        return result, json.loads(result.stdout)

    def test_only_allowlisted_inventory_and_aggregates_are_returned(self):
        result, report = self.run_inspection()
        self.assertEqual(result.returncode, 0)
        self.assertEqual(report["tracking_plugins"], [{"slug": "woo-advanced-shipment-tracking", "installed": True, "active": True}])
        self.assertEqual(report["dsers_api_keys"], {"table_present": True, "count": 3,
                         "permissions": ["read", "read_write"], "last_access_present": True})
        self.assertEqual(report["webhooks"], {"table_present": True, "total": 6, "dsers_active_count": 2,
                         "dsers_topics": ["order.updated", "other"], "callback_classifiers": ["dsers", "other"]})
        self.assertEqual(report["tracking_metadata"]["legacy"], [{"key": "_wc_shipment_tracking_items", "count": 4}])
        self.assertEqual(report["tracking_metadata"]["hpos"], [{"key": "_tracking_number", "count": 2}])
        flags = {entry["flag"]: entry["enabled"] for entry in report["tracking_settings"]}
        self.assertEqual(flags, {"hpos_enabled": True, "hpos_compatibility_sync_enabled": False,
                                "ast_tracking_info_enabled": True, "customer_completed_order_email_enabled": True,
                                "customer_processing_order_email_enabled": False})
        queries = [json.loads(line) for line in self.log.read_text().splitlines()]
        self.assertTrue(all(query.startswith("SELECT ") for query in queries))
        for query in queries:
            self.assertNotIn("consumer_key", query)
            self.assertNotIn("consumer_secret", query)
            if "FROM `wp_postmeta`" in query or "FROM `wp_wc_orders_meta`" in query:
                self.assertIn("m.meta_key IN (", query)
                self.assertNotIn("meta_value", query)
                self.assertNotIn("SELECT m.order_id", query)

    def test_absent_optional_tables_are_explicit_and_not_queried(self):
        result, report = self.run_inspection(FIXTURE_MINIMAL="1")
        self.assertEqual(result.returncode, 0)
        self.assertFalse(report["dsers_api_keys"]["table_present"])
        self.assertFalse(report["webhooks"]["table_present"])
        self.assertFalse(report["tracking_metadata"]["hpos_table_present"])
        self.assertEqual(report["tracking_metadata"]["legacy"], [])
        self.assertEqual(len(self.log.read_text().splitlines()), 2)

    def test_private_database_errors_become_fixed_category_only(self):
        result, report = self.run_inspection(FIXTURE_QUERY_FAILURE="1")
        self.assertEqual(result.returncode, 1)
        self.assertEqual(report, {"status": "unavailable", "category": "api_key_metadata"})

    def test_unexpected_database_meta_key_is_never_published(self):
        result, report = self.run_inspection(FIXTURE_UNSAFE_KEY="1")
        self.assertEqual(result.returncode, 1)
        self.assertEqual(report, {"status": "unavailable", "category": "tracking_metadata"})

    def test_invalid_prefix_stops_before_any_query(self):
        result, report = self.run_inspection(PETISH_DB_PREFIX="wp_`private-sentinel-never-publish")
        self.assertEqual(result.returncode, 1)
        self.assertEqual(report, {"status": "unavailable", "category": "database_configuration"})
        self.assertFalse(self.log.exists())

    def test_active_plugin_objects_are_not_accepted_or_instantiated(self):
        result, report = self.run_inspection(FIXTURE_ACTIVE_OBJECT="1")
        self.assertEqual(result.returncode, 1)
        self.assertEqual(report, {"status": "unavailable", "category": "known_plugin_metadata"})


class TrackingDatabaseIntegrationTests(unittest.TestCase):
    @unittest.skipUnless(os.environ.get("PETISH_TEST_DOCKER") == "1", "explicit Docker integration opt-in")
    def test_real_sql_uses_readonly_user_and_rejects_callback_host_lookalikes(self):
        spec = importlib.util.spec_from_file_location("tracking_recovery_fixture", ROOT / "scripts/verify-wordpress-recovery.py")
        recovery = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(recovery)
        with tempfile.TemporaryDirectory() as directory:
            work = Path(directory)
            work.chmod(0o700)
            site = work / "site"
            (site / "wp-content/plugins/woo-advanced-shipment-tracking").mkdir(parents=True)
            script = work / "inventory.php"
            script.write_text(PAYLOAD)
            script.chmod(0o600)
            def literal(value):
                return "0x" + value.encode().hex()
            active_plugin = 'woo-advanced-shipment-tracking/main.php'
            active_serialized = 'a:1:{i:0;s:' + str(len(active_plugin)) + ':"' + active_plugin + '";}'
            sql = work / "fixture.sql"
            sql.write_text("\n".join([
                "CREATE TABLE wp_options (option_name VARCHAR(191) PRIMARY KEY,option_value LONGTEXT);",
                "CREATE TABLE wp_woocommerce_api_keys (permissions VARCHAR(20),description TEXT,last_access DATETIME NULL);",
                "CREATE TABLE wp_wc_webhooks (status VARCHAR(20),name TEXT,topic TEXT,delivery_url TEXT);",
                "CREATE TABLE wp_posts (ID BIGINT PRIMARY KEY,post_type VARCHAR(20));",
                "CREATE TABLE wp_postmeta (post_id BIGINT,meta_key VARCHAR(191),meta_value LONGTEXT,KEY meta_key(meta_key));",
                "CREATE TABLE wp_wc_orders (id BIGINT PRIMARY KEY,type VARCHAR(20));",
                "CREATE TABLE wp_wc_orders_meta (order_id BIGINT,meta_key VARCHAR(191),meta_value LONGTEXT,KEY meta_key(meta_key));",
                "INSERT INTO wp_options VALUES ('active_plugins'," + literal(active_serialized) + ");",
                "INSERT INTO wp_options VALUES ('woocommerce_custom_orders_table_enabled','yes');",
                "INSERT INTO wp_woocommerce_api_keys VALUES ('read_write'," + literal("DSers " + PRIVATE) + ",'2026-01-01 00:00:00'),('read','Other',NULL);",
                "INSERT INTO wp_wc_webhooks VALUES " + ",".join("('active'," + literal(name) + "," + literal(topic) + "," + literal(url) + ")" for name, topic, url in [
                    ("DSers", "order.updated", "https://api.dsers.com/callback?token=" + PRIVATE),
                    ("DSers proxy", "action." + PRIVATE, "https://dsers.com.evil.invalid/" + PRIVATE),
                    ("Other", "product.updated", "https://dsers.com:443@evil.invalid/" + PRIVATE),
                    ("Other", "product.updated", "https://not-dsers.com/" + PRIVATE),
                    ("Other", "order.updated", "https://dsers.com:443/" + PRIVATE),
                ]) + ";",
                "INSERT INTO wp_posts VALUES (1,'shop_order'),(2,'product');",
                "INSERT INTO wp_postmeta VALUES (1,'_wc_shipment_tracking_items'," + literal(PRIVATE) + "),(2,'_wc_shipment_tracking_items'," + literal(PRIVATE) + ");",
                "INSERT INTO wp_wc_orders VALUES (10,'shop_order'),(11,'product');",
                "INSERT INTO wp_wc_orders_meta VALUES (10,'_tracking_number'," + literal(PRIVATE) + "),(11,'_tracking_number'," + literal(PRIVATE) + ");",
            ]) + "\n")
            with recovery.database(sql, work, ["input"]) as (name, volume, _):
                password = secrets.token_hex(24)
                grant = work / "grant.sql"
                grant.write_text("CREATE USER 'tracking_fixture'@'localhost' IDENTIFIED BY '" + password + "';"
                                 "GRANT SELECT ON petish_restore.* TO 'tracking_fixture'@'localhost';")
                with grant.open("rb") as source:
                    recovery.BASE.run_private(["exec", "--interactive", name, "mariadb",
                        "--defaults-extra-file=/tmp/petish-restore-client.cnf", "--binary-mode=1"], work, source=source)
                environment = work / "application.env"
                environment.write_text("PETISH_DB_HOST=localhost:/run/mysqld/mysqld.sock\nPETISH_DB_USER=tracking_fixture\n"
                    "PETISH_DB_PASSWORD=" + password + "\nPETISH_DB_NAME=petish_restore\nPETISH_DB_PREFIX=wp_\n"
                    "PETISH_DB_CLIENT_FLAGS=0\nPETISH_TRACKING_SITE_PATH=/site\n")
                environment.chmod(0o600)
                recovery.BASE.run_private(["pull", recovery.APPLICATION.IMAGE], work, timeout=300)
                app_name = "petish-tracking-fixture-" + uuid.uuid4().hex
                try:
                    output = recovery.BASE.run_private(["run", "--rm", "--name", app_name,
                        "--label", "org.petish.application-recovery=true", "--network", "none",
                        "--user", str(os.getuid()) + ":" + str(os.getgid()), "--cap-drop", "ALL",
                        "--security-opt", "no-new-privileges:true", "--env-file", str(environment),
                        "--mount", "type=volume,src=" + volume + ",dst=/run/mysqld,readonly",
                        "--mount", "type=bind,src=" + str(site) + ",dst=/site,readonly",
                        "--mount", "type=bind,src=" + str(script) + ",dst=/inventory.php,readonly",
                        "--entrypoint", "php", recovery.APPLICATION.IMAGE, "/inventory.php"], work, capture=True, timeout=60)
                finally:
                    subprocess.run(recovery.BASE.DOCKER + ["rm", "--force", app_name],
                                   stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=False, timeout=30)
                self.assertNotIn(PRIVATE.encode(), output)
                report = json.loads(output)
                self.assertEqual(report["status"], "success")
                self.assertEqual(report["dsers_api_keys"]["count"], 1)
                self.assertEqual(report["webhooks"]["total"], 5)
                self.assertEqual(report["webhooks"]["dsers_active_count"], 3)
                self.assertEqual(report["webhooks"]["dsers_topics"], ["order.updated", "other"])
                self.assertEqual(report["tracking_metadata"]["legacy"], [{"key": "_wc_shipment_tracking_items", "count": 1}])
                self.assertEqual(report["tracking_metadata"]["hpos"], [{"key": "_tracking_number", "count": 1}])


if __name__ == "__main__":
    unittest.main()
