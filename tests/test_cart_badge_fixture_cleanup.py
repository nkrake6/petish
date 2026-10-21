"""Fault-test the real cleanup transaction with a bounded in-memory SQL double.

Only the two fixed filesystem constants are rebased into a temporary directory.
The cleanup, journal, ownership and file-attestation functions run unmodified.
These tests supplement, rather than replace, the native staging/browser proof.
"""
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = shutil.which('php') or '/workspace/.petish-dev/bin/php'
SCRIPT = ROOT / 'scripts/cart-badge-staging-proof.php'

HARNESS = r'''
define('CART_BADGE_PROOF_LIBRARY', true);
require $argv[1];
$case = $argv[2];
$report = ['checks' => []];

class CleanupDatabase {
    public $prefix = 'wp_', $users = 'wp_users', $usermeta = 'wp_usermeta';
    public $posts = 'wp_posts', $postmeta = 'wp_postmeta';
    public $term_relationships = 'wp_term_relationships';
    public $comments = 'wp_comments', $commentmeta = 'wp_commentmeta';
    public $last_error = '', $rows = [], $log = [], $snapshot = null, $fail_table = null;
    function prepare($sql, ...$args) {
        $index = 0;
        $result = preg_replace_callback('/%[sd]/', static function($m) use (&$index, $args) {
            $value = $args[$index++];
            return $m[0] === '%d' ? (string)(int)$value : "'".str_replace("'", "''", $value)."'";
        }, $sql);
        if ($index !== count($args)) throw new RuntimeException('Unexpected prepared SQL');
        return $result;
    }
    function esc_like($value) { return addcslashes($value, '_%\\'); }
    function query($sql) {
        $this->log[] = $sql; $this->last_error = '';
        if ($sql === 'START TRANSACTION') {
            if ($this->snapshot !== null) throw new RuntimeException('Nested transaction');
            $this->snapshot = $this->rows; return 0;
        }
        if ($sql === 'ROLLBACK') {
            if ($this->snapshot === null) throw new RuntimeException('Missing transaction');
            $this->rows = $this->snapshot; $this->snapshot = null; return 0;
        }
        if ($sql === 'COMMIT') { $this->snapshot = null; return 0; }
        if (!preg_match('/\ADELETE FROM `([a-z_]+)` WHERE `?([a-z_]+|ID)`?=(\x27[0-9]+\x27|[0-9]+)\z/D', $sql, $m))
            throw new RuntimeException('Unexpected cleanup mutation');
        if ($m[1] === $this->fail_table) { $this->last_error = 'Injected deletion failure'; return false; }
        $count = count($this->rows[$m[1]]);
        $id = trim($m[3], "'");
        $this->rows[$m[1]] = array_values(array_filter($this->rows[$m[1]], static fn($r) => (string)$r[$m[2]] !== $id));
        return $count - count($this->rows[$m[1]]);
    }
    function get_var($sql) {
        if (preg_match('/\ASHOW TABLES LIKE \x27([^\x27]+)\x27\z/D', $sql, $m)) {
            $name = stripslashes($m[1]); return isset($this->rows[$name]) ? $name : null;
        }
        if (!preg_match('/\ASELECT ([A-Za-z_]+) FROM `([a-z_]+)` WHERE ([A-Za-z_]+)=(\x27[0-9]+\x27|[0-9]+)(?: LIMIT 1)?\z/D', $sql, $m))
            throw new RuntimeException('Unexpected cleanup read');
        foreach ($this->rows[$m[2]] as $row) if ((string)$row[$m[3]] === trim($m[4], "'")) return $row[$m[1]];
        return null;
    }
}
$wpdb = new CleanupDatabase();
function get_user_by($field, $value) {
    global $wpdb;
    foreach ($wpdb->rows[$wpdb->users] as $row)
        if ((string)$row[$field === 'id' ? 'ID' : 'user_login'] === (string)$value) return (object)$row;
    return false;
}
function get_post($id) {
    global $wpdb;
    foreach ($wpdb->rows[$wpdb->posts] as $row) if ($row['ID'] === $id) return (object)$row;
    return null;
}
function get_user_meta($id, $key, $single) {
    global $wpdb;
    foreach ($wpdb->rows[$wpdb->usermeta] as $row) if ($row['user_id'] === $id && $row['meta_key'] === $key) return $row['meta_value'];
    return '';
}
function get_post_meta($id, $key, $single) {
    global $wpdb;
    foreach ($wpdb->rows[$wpdb->postmeta] as $row) if ($row['post_id'] === $id && $row['meta_key'] === $key) return $row['meta_value'];
    return '';
}
function wp_check_password($password, $hash, $id) { return hash_equals($hash, hash('sha256', $password)); }
function clean_post_cache($id) {}
function clean_user_cache($id) {}
function petish_club_verify_table_state($table) { global $wpdb; return $wpdb->rows[$table]; }
function petish_club_verify_state($root) {
    global $wpdb;
    if (!empty($GLOBALS['snapshot_error'])) throw new RuntimeException('Injected snapshot failure');
    return [
        'orders' => $wpdb->rows['wp_orders'],
        'accounts' => [$wpdb->rows[$wpdb->users], $wpdb->rows[$wpdb->usermeta]],
        'products' => [$wpdb->rows[$wpdb->posts], $wpdb->rows[$wpdb->postmeta], $wpdb->rows[$wpdb->term_relationships]],
        'settings' => !empty($GLOBALS['snapshot_mismatch']) ? 'changed' : 'stable',
        'passports' => 'stable', 'photos' => 'stable',
    ];
}
function petish_club_verify_state_equal($before, $after) {
    foreach (['orders'=>'orders_unchanged', 'accounts'=>'users_and_authentication_unchanged',
              'products'=>'product_prices_and_stock_unchanged', 'settings'=>'settings_unchanged',
              'passports'=>'existing_passports_unchanged', 'photos'=>'private_photo_files_unchanged'] as $key=>$check)
        cart_badge_mark($check, $before[$key] === $after[$key]);
}
function private_file($path, $bytes) {
    if (file_put_contents($path, $bytes) !== strlen($bytes) || !chmod($path, 0600)) throw new RuntimeException('Fixture write');
}

$work = CART_BADGE_HOME.'/.petish-staging/owned-run';
$mu = CART_BADGE_ROOT.'/wp-content/mu-plugins';
foreach ([$work, $mu] as $directory) if (!mkdir($directory, 0700, true)) throw new RuntimeException('Fixture directory');
private_file(CART_BADGE_HOME.'/.petish-staging/config.php', '<?php // private configuration');
private_file(CART_BADGE_ROOT.'/wp-config.php', '<?php // loader');
private_file($mu.'/existing-guard.php', '<?php // unrelated guard');
$guard = $mu.'/zz-petish-cart-badge-fixture.php';
$input = $work.'/browser-input.json';
$pending = $work.'/guard.pending';
$journal = $work.'/journal.json';
$run = '1234-1'; $token = str_repeat('a', 32); $password = str_repeat('b', 48);
$login = 'petish-cart-'.$token;
$wpdb->rows = [
    'wp_users' => [['ID'=>8,'user_login'=>'unrelated','user_email'=>'other@example.invalid','user_pass'=>'unchanged','roles'=>['administrator']]],
    'wp_usermeta' => [['user_id'=>8,'meta_key'=>'session_tokens','meta_value'=>'unchanged']],
    'wp_posts' => [['ID'=>32,'post_type'=>'product','post_name'=>'unrelated','post_author'=>8]],
    'wp_postmeta' => [['post_id'=>32,'meta_key'=>'_stock','meta_value'=>'7']],
    'wp_term_relationships' => [['object_id'=>32,'term_taxonomy_id'=>5]],
    'wp_woocommerce_sessions' => [['session_key'=>'8','session_value'=>'unchanged']],
    'wp_comments' => [['comment_ID'=>50,'comment_post_ID'=>32]],
    'wp_commentmeta' => [['comment_id'=>50,'meta_key'=>'rating','meta_value'=>'5']],
    'wp_orders' => [['id'=>101,'customer_id'=>8,'total'=>'20']],
];
$original = $wpdb->rows;
$state = ['format'=>'petish-cart-badge-fixture-v1','run'=>$run,'token'=>$token,
    'created'=>1000,'expires'=>2000,'before'=>cart_badge_snapshot(),
    'customer'=>['id'=>7,'login'=>$login,'email'=>$login.'@example.invalid','password'=>$password],
    'product'=>['id'=>31,'slug'=>$login,'path'=>'/product/'.$login.'/'],
    'guard_hash'=>hash('sha256','<?php // owned guard'),'cleaned'=>false];
$missing = str_starts_with($case, 'missing_journal');
if (!$missing) {
    $wpdb->rows['wp_users'][] = ['ID'=>7,'user_login'=>$login,'user_email'=>$login.'@example.invalid','user_pass'=>hash('sha256',$password),'roles'=>['customer']];
    $wpdb->rows['wp_usermeta'][] = ['user_id'=>7,'meta_key'=>'_petish_cart_badge_owned','meta_value'=>$token];
    $wpdb->rows['wp_usermeta'][] = ['user_id'=>7,'meta_key'=>'session_tokens','meta_value'=>'owned'];
    $wpdb->rows['wp_posts'][] = ['ID'=>31,'post_type'=>'product','post_name'=>$login,'post_author'=>7];
    $wpdb->rows['wp_postmeta'][] = ['post_id'=>31,'meta_key'=>'_petish_cart_badge_owned','meta_value'=>$token];
    $wpdb->rows['wp_postmeta'][] = ['post_id'=>31,'meta_key'=>'_stock','meta_value'=>'20'];
    $wpdb->rows['wp_term_relationships'][] = ['object_id'=>31,'term_taxonomy_id'=>5];
    $wpdb->rows['wp_woocommerce_sessions'][] = ['session_key'=>'7','session_value'=>'owned'];
    cart_badge_write($journal, $state);
    private_file($guard, '<?php // owned guard');
    cart_badge_write($input, ['run'=>$run,'customer_id'=>7,'product_id'=>31]);
}
if ($case === 'missing_journal_guard') private_file($guard, '<?php // unattested');
if ($case === 'missing_journal_input') cart_badge_write($input, ['run'=>$run]);
if ($case === 'delete_failure') $wpdb->fail_table = 'wp_postmeta';
if ($case === 'snapshot_failure') $GLOBALS['snapshot_error'] = true;
if ($case === 'snapshot_mismatch') $GLOBALS['snapshot_mismatch'] = true;
if ($case === 'foreign_user_token') $wpdb->rows['wp_usermeta'][1]['meta_value'] = 'foreign';
if ($case === 'reused_customer_id') $wpdb->rows['wp_users'][1]['user_login'] = 'reused-id';
if ($case === 'foreign_product_token') $wpdb->rows['wp_postmeta'][1]['meta_value'] = 'foreign';
if ($case === 'unexpected_product_comment') $wpdb->rows['wp_comments'][] = ['comment_ID'=>51,'comment_post_ID'=>31];
if ($case === 'guard_hardlink') { if (!link($guard, $pending)) throw new RuntimeException('Link fixture'); }
if ($case === 'guard_distinct_inode') private_file($pending, '<?php // owned guard');
if ($case === 'guard_pending_only') { if (!rename($guard, $pending)) throw new RuntimeException('Rename fixture'); }
if ($case === 'guard_hash_mismatch') private_file($guard, '<?php // foreign bytes');
if ($case === 'journal_pending') private_file($journal.'.pending', '{"interrupted":');
if ($case === 'journal_pending_symlink') {
    private_file($work.'/unrelated', 'must remain unchanged');
    if (!symlink($work.'/unrelated', $journal.'.pending')) throw new RuntimeException('Symlink fixture');
}
if ($case === 'journal_pending_hardlink') {
    private_file($work.'/unrelated', 'must remain unchanged');
    if (!link($work.'/unrelated', $journal.'.pending')) throw new RuntimeException('Hardlink fixture');
}
$initial = $wpdb->rows; $success = false; $retry = null;
try { cart_badge_cleanup($run, $work); $success = true; }
catch (Throwable $error) { if ($error->getMessage() !== 'Cart fixture verification failed.' && !str_starts_with($error->getMessage(), 'Injected ')) throw $error; }
if ($case === 'idempotent' && $success) {
    try { cart_badge_cleanup($run, $work); $retry = true; } catch (Throwable $ignored) { $retry = false; }
}
clearstatcache();
$saved = file_exists($journal) ? json_decode(file_get_contents($journal), true) : [];
echo json_encode([
    'success'=>$success, 'retry'=>$retry, 'baseline_restored'=>$wpdb->rows === $original,
    'initial_rows_preserved'=>$wpdb->rows === $initial, 'queries'=>$wpdb->log,
    'guard_exists'=>file_exists($guard)||is_link($guard), 'input_exists'=>file_exists($input)||is_link($input),
    'guard_pending_exists'=>file_exists($pending)||is_link($pending),
    'journal_pending_exists'=>file_exists($journal.'.pending')||is_link($journal.'.pending'),
    'journal_cleaned'=>$saved['cleaned']??false, 'journal_closing'=>$saved['closing']??false,
    'journal_exists'=>file_exists($journal), 'checks'=>$report['checks'],
    'unrelated_file_preserved'=>!file_exists($work.'/unrelated')||file_get_contents($work.'/unrelated')==='must remain unchanged',
], JSON_THROW_ON_ERROR);
'''


class CartBadgeFixtureCleanupTests(unittest.TestCase):
    def run_case(self, case):
        with tempfile.TemporaryDirectory(prefix='petish-cart-cleanup-') as temp:
            home = Path(temp) / 'home'
            source = SCRIPT.read_text()
            for name, old, new in (
                ('CART_BADGE_HOME', '/home/u839205597', home),
                ('CART_BADGE_ROOT', '/home/u839205597/domains/staging.petish.biz/public_html', home / 'site'),
            ):
                needle = f"const {name} = '{old}';"
                self.assertEqual(source.count(needle), 1, 'Fixed filesystem boundary changed')
                source = source.replace(needle, f"const {name} = '{new}';")
            copy = Path(temp) / 'fixture.php'
            copy.write_text(source)
            result = subprocess.run([PHP, '-d', 'display_errors=0', '-r', HARNESS, str(copy), case],
                                    text=True, capture_output=True, timeout=10)
            self.assertEqual(result.returncode, 0, result.stderr)
            return json.loads(result.stdout)

    def assert_rolled_back(self, result):
        self.assertFalse(result['success'])
        self.assertTrue(result['initial_rows_preserved'])
        self.assertIn('ROLLBACK', result['queries'])
        self.assertNotIn('COMMIT', result['queries'])
        self.assertTrue(result['guard_exists'])
        self.assertTrue(result['input_exists'])
        self.assertTrue(result['journal_closing'])
        self.assertFalse(result['journal_cleaned'])

    def test_exact_owned_rows_are_removed_and_every_foreign_row_survives(self):
        result = self.run_case('success')
        self.assertTrue(result['success'])
        self.assertTrue(result['baseline_restored'])
        self.assertTrue(result['journal_cleaned'])
        self.assertFalse(result['guard_exists'])
        self.assertFalse(result['input_exists'])
        self.assertIn('COMMIT', result['queries'])

    def test_successful_cleanup_can_be_repeated(self):
        result = self.run_case('idempotent')
        self.assertTrue(result['success'])
        self.assertTrue(result['retry'])
        self.assertTrue(result['baseline_restored'])

    def test_missing_journal_without_artifacts_is_a_noop(self):
        result = self.run_case('missing_journal')
        self.assertTrue(result['success'])
        self.assertTrue(result['initial_rows_preserved'])
        self.assertFalse(result['journal_exists'])
        self.assertFalse(any(q.startswith('DELETE') for q in result['queries']))

    def test_missing_journal_cannot_authorize_guard_or_input_removal(self):
        for case, artifact in [('missing_journal_guard', 'guard_exists'), ('missing_journal_input', 'input_exists')]:
            with self.subTest(case=case):
                result = self.run_case(case)
                self.assertFalse(result['success'])
                self.assertTrue(result[artifact])
                self.assertTrue(result['initial_rows_preserved'])
                self.assertEqual(result['queries'], [])

    def test_deletion_failure_restores_prior_session_and_taxonomy_deletions(self):
        self.assert_rolled_back(self.run_case('delete_failure'))

    def test_snapshot_failure_and_mismatch_restore_all_deleted_rows(self):
        for case in ['snapshot_failure', 'snapshot_mismatch']:
            with self.subTest(case=case):
                self.assert_rolled_back(self.run_case(case))

    def test_customer_or_product_ownership_mismatch_denies_all_mutations(self):
        for case in ['foreign_user_token', 'reused_customer_id', 'foreign_product_token']:
            with self.subTest(case=case):
                result = self.run_case(case)
                self.assertFalse(result['success'])
                self.assertTrue(result['initial_rows_preserved'])
                self.assertEqual(result['queries'], [])
                self.assertTrue(result['guard_exists'])

    def test_unexpected_product_comment_preserves_comment_and_all_owned_rows(self):
        self.assert_rolled_back(self.run_case('unexpected_product_comment'))

    def test_interrupted_guard_hardlink_publication_recovers_both_names(self):
        result = self.run_case('guard_hardlink')
        self.assertTrue(result['success'])
        self.assertTrue(result['baseline_restored'])
        self.assertFalse(result['guard_exists'])
        self.assertFalse(result['guard_pending_exists'])

    def test_same_bytes_on_distinct_inodes_are_not_treated_as_owned_hardlinks(self):
        result = self.run_case('guard_distinct_inode')
        self.assertFalse(result['success'])
        self.assertTrue(result['guard_exists'])
        self.assertTrue(result['guard_pending_exists'])
        self.assertFalse(result['journal_cleaned'])

    def test_changed_guard_is_preserved_and_cleanup_stays_incomplete(self):
        result = self.run_case('guard_hash_mismatch')
        self.assertFalse(result['success'])
        self.assertTrue(result['guard_exists'])
        self.assertTrue(result['input_exists'])
        self.assertFalse(result['journal_cleaned'])

    def test_private_interrupted_journal_write_can_be_retried(self):
        result = self.run_case('journal_pending')
        self.assertTrue(result['success'])
        self.assertTrue(result['baseline_restored'])
        self.assertFalse(result['journal_pending_exists'])

    def test_pending_journal_links_are_denied_without_touching_the_target(self):
        for case in ['journal_pending_symlink', 'journal_pending_hardlink']:
            with self.subTest(case=case):
                result = self.run_case(case)
                self.assertFalse(result['success'])
                self.assertTrue(result['initial_rows_preserved'])
                self.assertEqual(result['queries'], [])
                self.assertTrue(result['journal_pending_exists'])
                self.assertTrue(result['unrelated_file_preserved'])

    def test_interrupted_guard_write_before_publication_removes_pending_file(self):
        result = self.run_case('guard_pending_only')
        self.assertTrue(result['success'])
        self.assertFalse(result['guard_pending_exists'])
        self.assertTrue(result['journal_cleaned'])


if __name__ == '__main__':
    unittest.main()
