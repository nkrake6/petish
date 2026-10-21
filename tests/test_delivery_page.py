"""Exercise fixed-page release boundaries without production credentials or data."""
import copy
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import unittest
import yaml

ROOT = Path(__file__).resolve().parents[1]
PHP = shutil.which('php') or '/workspace/.petish-dev/bin/php'
REPORT = ROOT / 'scripts/delivery-page-report.py'
UPDATE = ROOT / 'scripts/delivery-page-update.php'
spec = importlib.util.spec_from_file_location('delivery_page_report', REPORT)
report = importlib.util.module_from_spec(spec)
spec.loader.exec_module(report)


def inspection():
    return {'status': 'success', 'category': 'complete', 'checks': dict.fromkeys([
        'target_paths_verified', 'target_environment_verified', 'woocommerce_runtime_verified',
        'all_outbound_http_blocked', 'mail_calls_blocked', 'remote_file_access_disabled',
        'cron_and_async_runner_disabled', 'production_read_only_verified'], True),
        'page': {'id': 1002, 'content_sha256': 'a' * 64, 'content_bytes': 300,
            'template': 'default', 'block_markup': False, 'shortcodes_present': False,
            'elementor_builder': False, 'elementor_data_present': False,
            'object_cache_dropin_present': False, 'advanced_cache_dropin_present': False}}


class DeliveryPageReportTests(unittest.TestCase):
    def test_explicit_operation_branches_run_without_a_new_commit_path_diff(self):
        workflow=yaml.load((ROOT/'.github/workflows/delivery-page.yml').read_text(),Loader=yaml.BaseLoader)
        self.assertEqual(workflow['on']['push'],{'branches':[
            'inspect-delivery-page','rehearse-delivery-page','deploy-delivery-page']})
        self.assertEqual(workflow['concurrency'],{'group':'petish-production-backup-mail','cancel-in-progress':'false'})
        checkout=workflow['jobs']['delivery']['steps'][0]
        self.assertEqual(checkout['with']['ref'],'${{ github.sha }}')

    def test_native_failure_diagnostics_reveal_only_fixed_observations(self):
        value={'status':'failure','category':'native_update','checks':{'rehearsal_rolled_back':True},
          'native_observations':{'native_pre_update_reached':True,'native_field_count':21,
            'expected_field_set':True,'blocked_statement_kind':'update','blocked_table':'options'}}
        self.assertTrue(report.diagnostic(value)['rehearsal_rolled_back'])
        for key,detail in [('sql','Private query'),('blocked_table','private_table'),
          ('blocked_statement_kind','private_verb'),('native_field_count',True),('native_field_count',101),
          ('native_pre_update_reached','yes')]:
            candidate=copy.deepcopy(value);candidate['native_observations'][key]=detail
            with self.subTest(key=key),self.assertRaises(ValueError):report.diagnostic(candidate)

    def test_only_complete_fixed_inspection_schema_is_publishable(self):
        expected = inspection()
        self.assertEqual(report.screen(expected), expected)
        for path, value in [
            (('status',), 'failure'), (('category',), 'private-error'),
            (('checks', 'production_read_only_verified'), False),
            (('checks', 'mail_calls_blocked'), 1),
            (('page', 'id'), True), (('page', 'id'), 0),
            (('page', 'id'), 2 ** 53), (('page', 'content_bytes'), -1),
            (('page', 'content_bytes'), 131073), (('page', 'content_bytes'), True),
            (('page', 'content_sha256'), 'PRIVATE'),
            (('page', 'template'), 'private\nvalue'),
            (('page', 'template'), 'a' * 161),
            (('page', 'shortcodes_present'), 'false'),
            (('page', 'object_cache_dropin_present'), 0),
        ]:
            with self.subTest(path=path, value=value):
                candidate = copy.deepcopy(expected)
                target = candidate
                for key in path[:-1]:
                    target = target[key]
                target[path[-1]] = value
                with self.assertRaises((ValueError, TypeError)):
                    report.screen(candidate)

    def test_unknown_or_missing_fields_cannot_publish_private_state(self):
        for path in [(), ('checks',), ('page',)]:
            candidate = inspection()
            target = candidate
            for key in path:
                target = target[key]
            target['private_content'] = 'DO NOT PUBLISH'
            with self.subTest(path=path), self.assertRaises(ValueError):
                report.screen(candidate)
        for key in ['status', 'category', 'checks', 'page']:
            candidate = inspection()
            del candidate[key]
            with self.subTest(key=key), self.assertRaises((ValueError, KeyError)):
                report.screen(candidate)

    def test_public_copy_excludes_header_footer_forms_and_scripts(self):
        parser = report.PublicCopy()
        parser.feed('''<header>Navigation outside content</header>
        <main id="content"><article><h1>Delivery &amp; shipping</h1>
        <p>Supplier estimates vary by destination.</p>
        <form><label>Private form value</label><input value="Private input"></form>
        <script>window.privateToken = "never publish";</script>
        <style>.private-selector {color:red}</style>
        <nav><a>Account navigation</a></nav><noscript>Fallback private form</noscript>
        <p>Contact <a href="mailto:orders@petish.biz">orders@petish.biz</a>.</p>
        </article></main><footer>Footer unrelated copy</footer>''')
        text = ''.join(parser.parts)
        self.assertTrue(parser.found)
        self.assertIn('Delivery & shipping', text)
        self.assertIn('Supplier estimates vary by destination.', text)
        self.assertIn('orders@petish.biz', text)
        for private in ['Navigation outside content', 'Private', 'private',
                        'Account navigation', 'Footer unrelated copy']:
            self.assertNotIn(private, text)

    def test_public_copy_requires_the_fixed_content_region(self):
        parser = report.PublicCopy()
        parser.feed('<main><p>A different page layout is not accepted silently.</p></main>')
        self.assertFalse(parser.found)
        self.assertEqual(parser.parts, [])

    def test_update_reports_require_exact_mode_checks_and_fixed_page(self):
        for mode in ['rehearse', 'apply', 'rollback']:
            checks = report.UPDATE_COMMON | (
                {'transactional_page_table', 'page_table_has_no_triggers', 'rollback_owns_current_row', 'original_row_restored'} if mode == 'rollback'
                else {'transactional_page_table', 'page_table_has_no_triggers', 'expected_page_content',
                      'native_editable_content_saved'} | (
                    {'rehearsal_rolled_back'} if mode == 'rehearse'
                    else {'private_before_snapshot_saved', 'committed_row_verified'}))
            value = {'status': 'success', 'category': 'complete', 'checks': dict.fromkeys(checks, True),
                     'page': {'id': 1002, 'content_sha256': 'b' * 64}}
            with self.subTest(mode=mode):
                self.assertEqual(report.screen(value, mode), value)
                for change in ['missing_check', 'false_check', 'typed_check', 'other_page',
                               'private_field', 'extra_check', 'wrong_mode']:
                    candidate = copy.deepcopy(value)
                    check = sorted(checks)[0]
                    if change == 'missing_check':
                        del candidate['checks'][check]
                    if change == 'false_check':
                        candidate['checks'][check] = False
                    if change == 'typed_check':
                        candidate['checks'][check] = 1
                    if change == 'other_page':
                        candidate['page']['id'] = 1003
                    if change == 'private_field':
                        candidate['page']['before_image'] = 'Private content'
                    if change == 'extra_check':
                        candidate['checks']['unreviewed_write'] = True
                    with self.subTest(change=change), self.assertRaises(ValueError):
                        report.screen(candidate, 'delete' if change == 'wrong_mode' else mode)


@unittest.skipUnless(Path(PHP).is_file(), 'PHP required for fixed-page boundary fixtures')
class DeliveryPageUpdateTests(unittest.TestCase):
    def php(self, body, *, isolated_runtime=False):
        with tempfile.TemporaryDirectory(prefix='petish-delivery-boundaries-') as folder:
            directory = Path(folder)
            library = UPDATE
            if isolated_runtime:
                # Keep the actual transaction/rollback bodies; replace only the
                # production WordPress bootstrap and externally inspected manifest.
                source = UPDATE.read_text()
                replacements = {
                    'petish_delivery_manifest': "function petish_delivery_manifest() { return [$GLOBALS['fixture_manifest'],$GLOBALS['fixture_content']]; }",
                    'petish_delivery_bootstrap': "function petish_delivery_bootstrap() { return '/synthetic'; }",
                    'petish_delivery_bound_hooks': "function petish_delivery_bound_hooks() {}",
                    'petish_delivery_journal_path': "function petish_delivery_journal_path($run,$create) { return getenv('PETISH_TEST_HOME').'/.petish-delivery-page-backups/'.$run.'.json'; }",
                }
                for name, replacement in replacements.items():
                    source, count = re.subn(r'^function ' + name + r'\([^\n]*\) \{.*?^\}',
                                           replacement, source, count=1, flags=re.M | re.S)
                    self.assertEqual(count, 1, name)
                library = directory / 'update-fixture.php'
                library.write_text(source)
            script = directory / 'run.php'
            script.write_text('<?php define("ARRAY_A","ARRAY_A"); require '
                              + repr(str(library)) + ';\n' + body)
            environment = os.environ.copy()
            environment['PETISH_TEST_HOME'] = str(directory)
            process = subprocess.run([PHP, str(script)], capture_output=True,
                                     env=environment, timeout=20)
            self.assertEqual(process.returncode, 0, process.stderr.decode())
            self.assertFalse(process.stderr)
            return json.loads(process.stdout)

    def test_write_permit_is_exact_one_use_and_rejects_all_other_mutations(self):
        result = self.php(r'''
$GLOBALS['petish_delivery_strict']=true;$allowed="UPDATE `wp_posts` SET `post_content` = 'Reviewed' WHERE `ID` = 1002";
$GLOBALS['petish_delivery_next_sql']=$allowed;$out=[];
foreach([$allowed.' ',str_replace('1002','1003',$allowed),'DELETE FROM wp_posts','START TRANSACTION',
 'SELECT 1 INTO OUTFILE \'/tmp/private\'','SELECT 1; UPDATE wp_posts SET post_title=\'Changed\'']as$sql){
 try{petish_delivery_query_guard($sql);$out[]=false;}catch(Throwable$e){$out[]=$GLOBALS['petish_delivery_next_sql']===$allowed;}}
$out[]=petish_delivery_query_guard($allowed)===$allowed&&$GLOBALS['petish_delivery_next_sql']===null;
try{petish_delivery_query_guard($allowed);$out[]=false;}catch(Throwable$e){$out[]=true;}
foreach(['SELECT ID FROM wp_posts','SHOW TABLE STATUS','DESCRIBE wp_posts','/* fixed */ SELECT 1']as$sql)$out[]=petish_delivery_query_guard($sql)===$sql;
$GLOBALS['petish_delivery_strict']=false;$out[]=petish_delivery_query_guard('UPDATE wp_options SET option_value=1')==='SELECT 0 WHERE 1=0';
echo json_encode($out);
''')
        self.assertEqual(result, [True] * 13)

    def test_only_reviewed_native_fields_reconstruct_an_update(self):
        result = self.php(self.native_data_fixture() + r'''
$sql=petish_delivery_update_sql($data,$before,$content);$out=[str_starts_with($sql,'UPDATE `wp_posts` SET `post_content` = '),str_ends_with($sql,' WHERE `ID` = 1002')];
foreach(['missing','extra','title','id','content','null','array','date','reordered','wrong_target']as$case){
 $changed=$data;$original=$before;
 if($case==='missing')unset($changed['post_modified']);if($case==='extra')$changed['private_meta']='Injected';
 if($case==='title')$changed['post_title']='Different';if($case==='id')$changed['ID']=1003;
 if($case==='content')$changed['post_content']='Unreviewed';if($case==='null')$changed['post_modified']=null;
 if($case==='array')$changed['post_modified']=[];if($case==='date')$changed['post_modified']='not a date';
 if($case==='reordered')$changed=array_reverse($changed,true);if($case==='wrong_target')$original['ID']='1003';
 try{petish_delivery_update_sql($changed,$original,$content);$out[]=false;}catch(Throwable$e){$out[]=true;}}
$changed=$before;$changed['post_title']='Foreign editor';$out[]=!petish_delivery_row_equal($before,$changed);
$out[]=petish_delivery_row_equal($before,array_reverse($before,true));echo json_encode($out);
''')
        self.assertEqual(result, [True] * 14)

    def test_one_shot_execution_requires_the_guard_and_successful_database_result(self):
        result = self.php(r'''
class ExecutionWPDB {public $last_error='',$mode='ok';public function query($sql){
 if($this->mode==='skip_guard')return 1;
 petish_delivery_query_guard($sql);
 if($this->mode==='false')return false;
 if($this->mode==='error')$this->last_error='Synthetic database failure';
 return 1;}}
$wpdb=new ExecutionWPDB();$GLOBALS['petish_delivery_strict']=true;
$out=[petish_delivery_once('START TRANSACTION')===1&&$GLOBALS['petish_delivery_next_sql']===null];
foreach(['skip_guard','false','error']as$mode){
 $GLOBALS['petish_delivery_next_sql']=null;$wpdb->mode=$mode;$wpdb->last_error='';
 try{petish_delivery_once('ROLLBACK');$out[]=false;}catch(Throwable$e){$out[]=true;}}
$GLOBALS['petish_delivery_next_sql']='COMMIT';
try{petish_delivery_once('ROLLBACK');$out[]=false;}catch(Throwable$e){$out[]=$GLOBALS['petish_delivery_next_sql']==='COMMIT';}
echo json_encode($out);
''')
        self.assertEqual(result, [True] * 5)

    def test_changed_content_hash_aborts_before_native_update(self):
        result = self.php(self.transaction_fixture() + r'''
$wpdb->row['post_content']='Concurrent public edit';$current=$wpdb->row;
$out=[];try{petish_delivery_change('rehearse','123-1');$out[]=false;}catch(Throwable$e){$out[]=true;}
$out[]=$wpdb->row===$current;$out[]=$wpdb->writes===0;$out[]=$GLOBALS['native_called']===false;
$out[]=$wpdb->transactions===['START TRANSACTION','ROLLBACK'];
$out[]=($report['checks']['expected_page_content']??null)===false;
$out[]=($report['checks']['rehearsal_rolled_back']??null)===true;
echo json_encode($out);
''', isolated_runtime=True)
        self.assertEqual(result, [True] * 7)

    def test_three_column_rehearsal_preserves_the_full_row_and_rolls_back(self):
        result = self.php(self.transaction_fixture() + r'''
$wpdb->row=$before;petish_delivery_change('rehearse','123-1');
echo json_encode([$wpdb->writes===1,$wpdb->row===$before,$GLOBALS['native_called']===false,
 $wpdb->transactions===['START TRANSACTION','ROLLBACK'],
 $report['checks']['native_editable_content_saved']===true,$report['checks']['rehearsal_rolled_back']===true]);
''', isolated_runtime=True)
        self.assertEqual(result,[True]*6)

    def test_rollback_restores_only_its_unchanged_owned_row(self):
        for mode in ['after', 'before', 'foreign', 'wrong_run', 'wrong_page', 'wrong_before_hash', 'wrong_after_hash']:
            result = self.php(self.transaction_fixture() + '$mode=' + json.dumps(mode) + r''';
$journal=['run_id'=>'123-1','page_id'=>1002,'before'=>$before,'after'=>$after];
if($mode==='before')$wpdb->row=$before;
if($mode==='foreign')$wpdb->row['post_title']='Concurrent editor change';
if($mode==='wrong_run')$journal['run_id']='124-1';if($mode==='wrong_page')$journal['page_id']=1003;
if($mode==='wrong_before_hash')$journal['before']['post_content']='Foreign original';
if($mode==='wrong_after_hash')$journal['after']['post_content']='Foreign replacement';
$GLOBALS['fixture_journal']=$journal;file_put_contents(getenv('PETISH_TEST_HOME').'/.petish-delivery-page-backups/123-1.json','{}');$current=$wpdb->row;$passed=false;
try{petish_delivery_rollback('123-1');$passed=true;}catch(Throwable$e){}
$safe=in_array($mode,['after','before'],true);
$out=[$passed===$safe,$wpdb->writes===($mode==='after'?1:0),$wpdb->row===($safe?$before:$current)];
if($mode==='foreign')$out[]=$wpdb->transactions===['START TRANSACTION','ROLLBACK'];
echo json_encode($out);
''', isolated_runtime=True)
            with self.subTest(mode=mode):
                self.assertEqual(result, [True] * (4 if mode == 'foreign' else 3))

    @staticmethod
    def native_data_fixture():
        return r'''
class FixtureWPDB {
 public $posts='wp_posts',$last_error='',$field_types=['post_author'=>'%d','post_parent'=>'%d','menu_order'=>'%d'];
 public function prepare($query,$values){if(!is_array($values))$values=[$values];$i=0;return preg_replace_callback('/%[sd]/',static function($m)use($values,&$i){$v=$values[$i++];return$m[0]==='%d'?(string)(int)$v:"'".str_replace(["\\","'"],["\\\\","\\'"],(string)$v)."'";},$query);}
}
$wpdb=new FixtureWPDB();$keys=['post_author','post_date','post_date_gmt','post_content','post_content_filtered','post_title','post_excerpt',
 'post_status','post_type','comment_status','ping_status','post_password','post_name','to_ping','pinged','post_modified','post_modified_gmt',
 'post_parent','menu_order','post_mime_type','guid'];
$before=array_fill_keys($keys,'');$before['ID']='1002';$before['post_author']='7';$before['post_parent']='0';$before['menu_order']='0';
$before['post_content']='Original delivery copy';$before['post_title']='Delivery & shipping';$before['post_status']='publish';
$before['post_type']='page';$before['post_name']='delivery-shipping';$before['guid']='https://petish.invalid/?page_id=1002';
foreach(['post_date','post_date_gmt','post_modified','post_modified_gmt']as$key)$before[$key]='2026-10-21 00:00:00';
$content='<p>Reviewed supplier-specific delivery estimates.</p>';
$data=['post_content'=>$content,'post_modified'=>'2026-10-21 04:00:00','post_modified_gmt'=>'2026-10-21 04:00:00'];
'''

    @classmethod
    def transaction_fixture(cls):
        return cls.native_data_fixture() + r'''
class TransactionWPDB extends FixtureWPDB {
 public $row,$original,$snapshot,$writes=0,$transactions=[];
 public function get_row($query,$mode){if(str_starts_with($query,'SHOW TABLE STATUS'))return ['Engine'=>'InnoDB'];return$this->row;}
 public function get_results($query,$mode){if(str_starts_with($query,'SHOW TRIGGERS'))return[];throw new RuntimeException('Unexpected fixture query');}
 public function esc_like($value){return$value;}
 public function update($table,$data,$where,$formats,$where_formats){
  if($table!=='wp_posts'||$where!==['ID'=>1002]||$formats!==['%s','%s','%s']||$where_formats!==['%d'])throw new RuntimeException('Wrong scope');
  $sql=petish_delivery_update_sql($data,$this->row,$data['post_content']);petish_delivery_query_guard($sql);
  $this->writes++;$this->row=array_replace($this->row,$data);return 1;
 }
 public function query($query){$sql=petish_delivery_query_guard($query);
  if(in_array($sql,['START TRANSACTION','COMMIT','ROLLBACK'],true)){
   $this->transactions[]=$sql;if($sql==='START TRANSACTION')$this->snapshot=$this->row;
   if($sql==='ROLLBACK')$this->row=$this->snapshot;return 1;}
  if(str_starts_with($sql,'UPDATE `wp_posts` SET ')){$this->writes++;$this->row=$this->original;return 1;}
  throw new RuntimeException('Unexpected fixture query');
 }
}
$after=$before;foreach($data as$key=>$value)$after[$key]=(string)$value;
$wpdb=new TransactionWPDB();$wpdb->row=$after;$wpdb->original=$before;
$GLOBALS['fixture_content']=$content;$GLOBALS['fixture_manifest']=['old_content_sha256'=>hash('sha256',$before['post_content']),'new_content_sha256'=>hash('sha256',$content)];
$GLOBALS['native_called']=false;$report=['checks'=>[]];
mkdir(getenv('PETISH_TEST_HOME').'/.petish-delivery-page-backups',0700);
function petish_club_verify_path($path,$directory=false){return$directory?is_dir($path):is_file($path);}
function petish_club_verify_read_json($path){return$GLOBALS['fixture_journal'];}
function clean_post_cache($id){if($id!==1002)throw new RuntimeException('Wrong cache key');}
function current_time($type,$gmt=false){if($type!=='mysql')throw new RuntimeException('Wrong date format');return '2026-10-21 04:00:00';}
function get_post_meta($id,$key,$single){return '';}
function wp_update_post(...$args){$GLOBALS['native_called']=true;throw new RuntimeException('Unexpected native call');}
'''


if __name__ == '__main__':
    unittest.main()
