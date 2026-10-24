<?php
/** Isolated filesystem/transaction boundary double. Never bootstrap a real site. */
$cfg = json_decode(file_get_contents($argv[1]), true, 32, JSON_THROW_ON_ERROR);
define('PETISH_CANCELLATION_RELEASE_LIBRARY', true);
define('ARRAY_A', 'ARRAY_A');
define('PETISH_CANCEL_FAQ_CONTENT', "\n\n<!-- petish-cancellation-faq:v1 -->\n[petish_faqs]\n<!-- /petish-cancellation-faq:v1 -->\n");
define('PETISH_CANCEL_FAQ_MARKER', '_petish_cancellation_faq_managed_v1');
define('PETISH_CANCEL_FAQ_OWNER', 'petish-cancellation-faq-v1');
define('PETISH_CANCEL_DELIVERY_SHA', 'e5c6e2575f72e4bd5c1c9e45fb05dd33f27f816c7db0ae3b63b2805e41b9b065');
if (!function_exists('ctype_digit')) { function ctype_digit($value) { return is_string($value) && preg_match('/\A[0-9]+\z/D', $value) === 1; } }
function petish_cancel_faq_base() { return file_get_contents($GLOBALS['cfg']['basefile']); }
function current_time($kind, $gmt = false) { return '2026-10-24 06:00:00'; }
function get_post_meta($id, $key, $single = true) { return $GLOBALS['cfg']['builder'] ?? false ? ($key === '_elementor_edit_mode' ? 'builder' : '') : ''; }
function petish_club_verify_state($root) { return array('flags'=>$GLOBALS['wpdb']->flags, 'commerce'=>$GLOBALS['wpdb']->other); }
function petish_cancel_case_state() { return array('cases'=>'unchanged'); }
function petish_club_verify_table_state($table, $where = '') { return hash('sha256', json_encode($GLOBALS['wpdb']->other)); }
function cxl_release_ensure_probe($root, $release, $run) { cxl_release_mark('cache_probe_installed'); }
function cxl_release_purge_ready($root, $release, $run) { cxl_release_mark('cache_purge_probe_ready'); }
function update_option() { throw new RuntimeException('Forbidden option write.'); }
function delete_option() { throw new RuntimeException('Forbidden option write.'); }
function wp_mail() { throw new RuntimeException('Forbidden mail.'); }
function wc_create_refund() { throw new RuntimeException('Forbidden refund.'); }

class CancellationFixtureDatabase {
    public $posts = 'wp_posts'; public $postmeta = 'wp_postmeta'; public $options = 'wp_options';
    public $last_error = ''; public $row; public $meta; public $flags; public $other;
    public $writes = array(); public $locks = false;
    private $prepared = array(); private $transaction = null;
    function __construct($state) { foreach (array('row','meta','flags','other') as $key) $this->$key = $state[$key]; }
    function prepare($format, ...$arguments) {
        if (count($arguments) === 1 && is_array($arguments[0])) $arguments = $arguments[0];
        $sql = $format . ' /* fixture:' . base64_encode(json_encode($arguments)) . ' */';
        $this->prepared[$sql] = array($format, $arguments); return $sql;
    }
    function esc_like($value) { return $value; }
    function get_var($sql) {
        if (str_contains($sql, 'GET_LOCK(')) { $this->locks = true; return '1'; }
        if (str_contains($sql, 'RELEASE_LOCK(')) { $this->locks = false; return '1'; }
        throw new RuntimeException('Unexpected scalar query.');
    }
    function get_row($sql, $output) {
        if (str_starts_with($sql, 'SHOW TABLE STATUS')) return array('Engine'=>'InnoDB');
        if (str_contains($sql, 'FROM `wp_options`')) {
            $args = $this->prepared[$sql][1]; return $this->flags[$args[0]] ?? null;
        }
        throw new RuntimeException('Unexpected row query.');
    }
    function get_results($sql, $output) {
        if (str_starts_with($sql, 'SHOW TRIGGERS')) return array();
        if (str_starts_with($sql, 'SHOW INDEX')) return array(array('Column_name'=>'post_name'));
        if (str_starts_with($sql, 'SELECT * FROM `wp_posts`')) return array($this->row);
        if (str_starts_with($sql, 'SELECT * FROM `wp_postmeta`')) return $this->meta;
        throw new RuntimeException('Unexpected result query.');
    }
    function query($sql) {
        $permitted = cxl_release_query($sql);
        if ($permitted !== $sql) throw new RuntimeException('Unapproved statement.');
        if ($sql === 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') return 0;
        if ($sql === 'START TRANSACTION') {
            if ($this->transaction !== null) throw new RuntimeException('Nested transaction.');
            $this->transaction = array($this->row,$this->meta,$this->flags,$this->other); return 0;
        }
        if ($sql === 'ROLLBACK') {
            if ($this->transaction !== null) list($this->row,$this->meta,$this->flags,$this->other) = $this->transaction;
            $this->transaction = null; return 0;
        }
        if ($sql === 'COMMIT') { $this->transaction = null; return 0; }
        list($format, $args) = $this->prepared[$sql] ?? array('', array());
        if (str_starts_with($format, 'UPDATE `wp_posts` SET `post_content`')) {
            if ($this->transaction === null || (string)$args[3] !== $this->row['ID']) throw new RuntimeException('Wrong page or no transaction.');
            $this->row['post_content']=$args[0];$this->row['post_modified']=$args[1];$this->row['post_modified_gmt']=$args[2];
            $this->writes[]='page';return 1;
        }
        if (str_starts_with($format, 'INSERT INTO `wp_postmeta`')) {
            if ($this->transaction === null || (string)$args[0] !== $this->row['ID'] || $args[1] !== PETISH_CANCEL_FAQ_MARKER) throw new RuntimeException('Wrong marker scope.');
            $this->meta[] = array('meta_id'=>'900','post_id'=>(string)$args[0],'meta_key'=>$args[1],'meta_value'=>$args[2]);
            $this->writes[]='marker';return 1;
        }
        if (str_starts_with($format, 'DELETE FROM `wp_postmeta`')) {
            if ($this->transaction === null || count($this->meta)!==1 || $this->meta[0] !== array('meta_id'=>(string)$args[0],'post_id'=>(string)$args[1],'meta_key'=>$args[2],'meta_value'=>$args[3])) throw new RuntimeException('Wrong marker removal.');
            $this->meta=array();$this->writes[]='remove_marker';return 1;
        }
        throw new RuntimeException('Unexpected write.');
    }
}
$wpdb = new CancellationFixtureDatabase($cfg['database']);
require $cfg['script'];
$GLOBALS['cxl_strict_sql'] = true;
$cxl_release_report = array('checks'=>array_fill_keys(PETISH_CANCELLATION_RELEASE_CHECKS,false));
$success = true; $details = array();
try {
    $operation=$cfg['operation'];$root=$cfg['root'];$content=$root.'/wp-content';$release=$cfg['release'];$run=$cfg['run'];
    if ($operation === 'inspect') { cxl_release_payload($cfg['payload']); list($state,$changed)=cxl_release_live($content);$details=array('files'=>$state,'changed'=>$changed); }
    elseif ($operation === 'apply-files') {
        cxl_release_payload($cfg['payload']);list($state,$changed)=cxl_release_live($content);
        if (!mkdir($release,0700)||!mkdir($release.'/backup',0700)) throw new RuntimeException('Fixture release exists.');
        $protected=cxl_release_protected($root,$content);
        foreach ($state as $name=>$entry) if ($entry['present']) cxl_release_write($release.'/backup/'.array_search($name,array_keys(PETISH_CANCELLATION_RELEASE_FILES),true),file_get_contents($content.'/'.$name));
        $journal=array('run'=>$run,'files'=>$state,'protected'=>$protected,'flags'=>cxl_release_flags());
        cxl_release_write($release.'/journal.json',json_encode($journal));cxl_release_journal($release,$run);
        $count=0;
        foreach (PETISH_CANCELLATION_RELEASE_FILES as $name=>$digest) {
            cxl_release_preimage($content.'/'.$name,$state[$name]);
            if (!$state[$name]['present']||$state[$name]['sha256']!==$digest) cxl_release_atomic($content.'/'.$name,file_get_contents($cfg['payload'].'/'.$name),0644,$run);
            if (++$count === ($cfg['partial_after']??-1)) break;
        }
        $details=array('changed'=>$changed);
    }
    elseif ($operation === 'restore-files') cxl_release_restore($root,$content,$release,$run);
    elseif ($operation === 'require-candidate') cxl_release_require_candidates($content);
    elseif ($operation === 'faq') cxl_release_faq($release,$run);
    elseif ($operation === 'faq-restore') cxl_release_faq($release,$run,true);
    elseif ($operation === 'page-journal') cxl_release_page_journal($release,$run);
    elseif ($operation === 'flags') $details=array('flags'=>cxl_release_flags(),'disabled'=>cxl_release_disabled(cxl_release_flags()));
    elseif ($operation === 'unapproved-option-write') $wpdb->query('UPDATE `wp_options` SET option_value=\'yes\'');
    else throw new RuntimeException('Unknown fixture operation.');
} catch (Throwable $error) { $success=false;$details=array('failure'=>$error->getMessage(),'line'=>$error->getLine()); }
echo json_encode(array('success'=>$success,'details'=>$details,'checks'=>$cxl_release_report['checks'],
    'database'=>array('row'=>$wpdb->row,'meta'=>$wpdb->meta,'flags'=>$wpdb->flags,'other'=>$wpdb->other),
    'writes'=>$wpdb->writes,'lock_released'=>!$wpdb->locks),JSON_THROW_ON_ERROR)."\n";
