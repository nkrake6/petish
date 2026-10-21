"""Exercise native-cache queue ownership, one-row scope, and failed-consumer rollback."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = shutil.which('php') or '/workspace/.petish-dev/bin/php'
HELPER = ROOT / 'scripts/litespeed-html-purge.php'

HOOKS = r'''<?php
$GLOBALS['filters']=[];
function add_filter($name,$callback,$priority=10,$args=1){$GLOBALS['filters'][$name][$priority][]=[$callback,$args];}
function add_action($name,$callback,$priority=10,$args=1){add_filter($name,$callback,$priority,$args);}
function remove_filter($name,$callback,$priority=10){
 foreach($GLOBALS['filters'][$name][$priority]??[] as $key=>$entry)if($entry[0]===$callback)unset($GLOBALS['filters'][$name][$priority][$key]);
}
function remove_all_filters($name){unset($GLOBALS['filters'][$name]);}
function remove_all_actions($name){remove_all_filters($name);}
function apply_filters($name,$value,...$args){
 if($name!=='all')foreach($GLOBALS['filters']['all']??[] as $entries)foreach($entries as $entry)$entry[0]($name);
 $groups=$GLOBALS['filters'][$name]??[];ksort($groups);
 foreach($groups as $entries)foreach($entries as $entry)$value=$entry[0](...array_slice([$value,...$args],0,$entry[1]));
 return $value;
}
function do_action($name,...$args){apply_filters($name,$args[0]??null,...array_slice($args,1));}
'''

NATIVE = r'''<?php namespace LiteSpeed;
class Purge {
 const DB_QUEUE='queue';const DB_QUEUE2='queue2';
 static function name($name){return 'litespeed.purge.'.$name;}
 static function cls(){return new self;}
 static function add($tag){
  $value='X-LiteSpeed-Purge: public,abc_'.$tag;
  if(getenv('FAULT')==='native_extra')$value.=',*';
  if(getenv('FAULT')==='native_write')$GLOBALS['wpdb']->query("UPDATE wp_options SET option_value='bad' WHERE option_name='other'");
  \update_option(self::name(getenv('FAULT')==='wrong_queue'?'queue2':'queue'),$value);
 }
 function purge_url($url,$flag,$quiet){
  if($url!=='/delivery-shipping/'||$flag!==false||$quiet!==true)throw new \Exception();
  self::add(Tag::get_uri_tag($url));
 }
}
class Tag {static function get_uri_tag($uri){return 'URL.'.md5($uri);}}
'''

HARNESS = r'''<?php
define('PETISH_HTML_PURGE_LIBRARY',true);define('ARRAY_A','ARRAY_A');
define('LSCWP_V','7.9.1');define('LITESPEED_CLI',true);define('LSWCP_TAG_PREFIX','abc');
require getenv('HELPER');
$report=['checks'=>[]];
function petish_club_verify_path($path,$dir=false){return realpath($path)===$path&&!is_link($path)&&($dir?is_dir($path):is_file($path));}
function petish_club_verify_run($run){return preg_match('/\A[1-9][0-9]*-[1-9][0-9]*\z/D',$run)===1;}
function petish_club_verify_write_json($path,$data,$replace=false){
 if(file_exists($path)&&!$replace)throw new Exception();
 if(getenv('FAULT')==='receipt_commit'&&($data['state']??null)==='committed')throw new Exception();
 file_put_contents($path,json_encode($data));chmod($path,0600);
}
function petish_club_verify_read_json($path){if(!is_file($path)||(fileperms($path)&0777)!==0600)throw new Exception();return json_decode(file_get_contents($path),true);}
function petish_club_verify_bootstrap($target,$read_only){
 if($target!=='production'||$read_only!==false)throw new Exception();
 require getenv('HOME').'/domains/petish.biz/public_html/wp-content/plugins/litespeed-cache/src/purge.cls.php';
 return getenv('HOME').'/domains/petish.biz/public_html';
}
function is_multisite(){return false;}
function wp_cache_delete($key,$group){return true;}
function get_option($name){return $GLOBALS['wpdb']->rows[$name]['option_value']??false;}
function update_option($name,$value){$old=get_option($name);$value=apply_filters('pre_update_option',$value,$name,$old);if($old===$value)return false;throw new Exception('Native write escaped interception');}
class FakeDB {
 public $options='wp_options';public $last_error='';public $rows;public $sql=[];private $before;
 function __construct(){$this->rows=json_decode(file_get_contents(getenv('STATE')),true);}
 function esc_like($v){return $v;}
 function prepare($sql,...$values){foreach($values as $value)$sql=preg_replace('/%s/',"'".str_replace("'","''",$value)."'",$sql,1);return $sql;}
 function get_results($sql,$format){$this->query($sql);if(str_starts_with($sql,'SHOW TRIGGERS'))return getenv('FAULT')==='trigger'?[['Trigger'=>'unexpected']]:[];return array_values($this->rows);}
 function get_row($sql,$format){$this->query($sql);return ['Engine'=>'InnoDB'];}
 function query($sql){
  $checked=apply_filters('query',$sql);if($checked!==$sql)throw new Exception('Unexpected write blocked');$this->sql[]=$sql;
  if($sql==='START TRANSACTION'){$this->before=$this->rows;return 0;}
  if($sql==='COMMIT'){if(getenv('FAULT')==='commit')return false;$this->before=null;return 0;}
  if($sql==='ROLLBACK'){$this->rows=$this->before;$this->before=null;return 0;}
  if(preg_match('/^UPDATE `wp_options` SET option_value=\x27([^\x27]*)\x27 WHERE option_id=\x27([^\x27]*)\x27 AND option_name=\x27([^\x27]*)\x27 AND option_value=\x27([^\x27]*)\x27 AND autoload=\x27([^\x27]*)\x27$/',$sql,$m)){
   $row=$this->rows[$m[3]]??null;
   if(getenv('FAULT')==='cas'||!$row||$row['option_id']!==$m[2]||$row['option_value']!==$m[4]||$row['autoload']!==$m[5])return 0;
   $this->rows[$m[3]]['option_value']=$m[1];
   if(getenv('FAULT')==='drift')$this->rows['litespeed.purge.queue2']['option_value']='unrelated';
   return 1;
  }
  if(preg_match('/^(SELECT|SHOW) /',$sql))return 0;
  throw new Exception('Unexpected SQL');
 }
}
$wpdb=new FakeDB();$ok=false;
try{
 $mode=getenv('MODE');$scope=getenv('SCOPE');$run=getenv('RUN');$operation=getenv('OPERATION');
 if($mode==='inspect')Petish_HTML_Purge::inspect();
 elseif($mode==='queue')Petish_HTML_Purge::queue($scope,$run,$operation);
 elseif($mode==='verify')Petish_HTML_Purge::verify($scope,$run,$operation);
 else throw new Exception();
 $ok=true;
}catch(Throwable $ignored){}
file_put_contents(getenv('STATE'),json_encode($wpdb->rows));
echo json_encode(['ok'=>$ok,'checks'=>$report['checks'],'rows'=>$wpdb->rows,'sql'=>$wpdb->sql]);
'''


def rows():
    return {
        name: {'option_id': str(i), 'option_name': name, 'option_value': '-1', 'autoload': 'on'}
        for i, name in enumerate(['litespeed.purge.queue', 'litespeed.purge.queue2'], 21)
    }


class PurgeBoundaryTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.home = Path(self.temp.name)
        root = self.home / 'domains/petish.biz/public_html'
        (root / 'wp-includes').mkdir(parents=True)
        (root / 'wp-includes/plugin.php').write_text(HOOKS)
        (root / 'wp-content/plugins/litespeed-cache/src').mkdir(parents=True)
        (root / 'wp-content/plugins/litespeed-cache/src/purge.cls.php').write_text(NATIVE)
        self.harness = self.home / 'harness.php'
        self.harness.write_text(HARNESS)
        self.state = self.home / 'state.json'
        self.state.write_text(json.dumps(rows()))

    def call(self, mode='queue', scope='site', operation='apply', run='123-1', fault=''):
        env = dict(os.environ, HOME=str(self.home), STATE=str(self.state), HELPER=str(HELPER),
                   MODE=mode, SCOPE=scope, OPERATION=operation, RUN=run, FAULT=fault)
        result = subprocess.run([PHP, str(self.harness)], env=env, capture_output=True, text=True, check=True)
        self.assertEqual(result.stderr, '')
        return json.loads(result.stdout)

    def change(self, queue=None, queue2=None, delete_second=False):
        state = json.loads(self.state.read_text())
        if queue is not None:
            state['litespeed.purge.queue']['option_value'] = queue
        if queue2 is not None:
            state['litespeed.purge.queue2']['option_value'] = queue2
        if delete_second:
            del state['litespeed.purge.queue2']
        self.state.write_text(json.dumps(state))
        return state

    def test_inspection_is_read_only_and_requires_both_idle_existing_queues(self):
        result = self.call('inspect')
        self.assertTrue(result['ok'])
        self.assertEqual(result['rows'], rows())
        self.assertFalse(any(sql.startswith(('UPDATE', 'START')) for sql in result['sql']))
        for values in [{'queue': 'pending'}, {'queue2': 'pending'}, {'queue2': ''}, {'delete_second': True}]:
            with self.subTest(values=values):
                self.state.write_text(json.dumps(rows()))
                before = self.change(**values)
                result = self.call('inspect')
                self.assertFalse(result['ok'])
                self.assertEqual(result['rows'], before)

    def test_native_site_and_delivery_scopes_touch_exactly_one_existing_value(self):
        import hashlib
        for scope, tail in [('site', ''), ('delivery', 'URL.' + hashlib.md5(b'/delivery-shipping/').hexdigest())]:
            with self.subTest(scope=scope):
                self.state.write_text(json.dumps(rows()))
                result = self.call(scope=scope)
                self.assertTrue(result['ok'])
                expected = rows()
                expected['litespeed.purge.queue']['option_value'] = 'X-LiteSpeed-Purge: public,abc_' + tail
                self.assertEqual(result['rows'], expected)
                self.assertEqual(len([sql for sql in result['sql'] if sql.startswith('UPDATE ')]), 1)
                receipt = self.home / f'.petish-html-purge/123-1-{scope}-apply.json'
                self.assertEqual(receipt.stat().st_mode & 0o777, 0o600)
                self.assertEqual(json.loads(receipt.read_text())['state'], 'committed')

    def test_pending_or_empty_unrelated_queues_are_not_overwritten(self):
        for values in [{'queue': 'pending'}, {'queue2': 'pending'}, {'queue2': ''}, {'delete_second': True}]:
            with self.subTest(values=values):
                self.state.write_text(json.dumps(rows()))
                before = self.change(**values)
                result = self.call()
                self.assertFalse(result['ok'])
                self.assertEqual(result['rows'], before)
                self.assertFalse(any(sql.startswith('UPDATE ') for sql in result['sql']))

    def test_native_scope_tampering_unrelated_writes_and_failed_cas_rollback(self):
        for fault in ['native_extra', 'wrong_queue', 'native_write', 'cas', 'drift', 'commit']:
            with self.subTest(fault=fault):
                result = self.call(run=f'{200 + len(fault)}-1', fault=fault)
                self.assertFalse(result['ok'])
                self.assertEqual(result['rows'], rows())
                self.assertIn('ROLLBACK', result['sql'])

    def test_options_trigger_is_refused_before_any_transaction_or_write(self):
        result = self.call(fault='trigger')
        self.assertFalse(result['ok'])
        self.assertEqual(result['rows'], rows())
        self.assertNotIn('START TRANSACTION', result['sql'])

    def test_cache_dropins_are_refused_before_queue_write(self):
        for kind in ['object', 'advanced']:
            with self.subTest(kind=kind):
                dropin = self.home / f'domains/petish.biz/public_html/wp-content/{kind}-cache.php'
                dropin.write_text('<?php')
                result = self.call()
                self.assertFalse(result['ok'])
                self.assertEqual(result['rows'], rows())
                self.assertNotIn('START TRANSACTION', result['sql'])
                dropin.unlink()

    def test_verify_requires_consumption_and_preserves_second_queue(self):
        self.assertTrue(self.call()['ok'])
        self.assertFalse(self.call('verify')['ok'])
        self.change(queue='-1')
        result = self.call('verify')
        self.assertTrue(result['ok'])
        self.assertFalse(any(sql.startswith(('UPDATE', 'START')) for sql in result['sql']))
        self.change(queue2='other-request')
        self.assertFalse(self.call('verify')['ok'])

    def test_failed_consumer_rollback_reuses_only_its_owned_exact_pending_header(self):
        applied = self.call()
        self.assertTrue(applied['ok'])
        result = self.call(operation='rollback')
        self.assertTrue(result['ok'])
        self.assertEqual(result['rows'], applied['rows'])
        self.assertFalse(any(sql.startswith('UPDATE ') for sql in result['sql']))
        self.change(queue='-1')
        self.assertTrue(self.call('verify', operation='rollback')['ok'])

    def test_rollback_cannot_reuse_another_run_or_changed_pending_queue(self):
        self.assertTrue(self.call()['ok'])
        before = json.loads(self.state.read_text())
        self.assertFalse(self.call(operation='rollback', run='999-1')['ok'])
        self.assertEqual(json.loads(self.state.read_text()), before)
        before = self.change(queue='X-LiteSpeed-Purge: public,abc_URL.' + 'a' * 32)
        self.assertFalse(self.call(operation='rollback')['ok'])
        self.assertEqual(json.loads(self.state.read_text()), before)

    def test_receipt_commit_failure_does_not_claim_queue_consumption(self):
        result = self.call(fault='receipt_commit')
        self.assertFalse(result['ok'])
        self.assertNotEqual(result['rows'], rows())
        self.change(queue='-1')
        self.assertFalse(self.call('verify')['ok'])

    def test_prepared_receipt_can_recover_only_an_exact_still_pending_queue(self):
        result = self.call(fault='receipt_commit')
        self.assertFalse(result['ok'])
        pending = result['rows']
        result = self.call(operation='rollback')
        self.assertTrue(result['ok'])
        self.assertEqual(result['rows'], pending)
        self.assertFalse(any(sql.startswith('UPDATE ') for sql in result['sql']))
        self.change(queue='-1')
        self.assertTrue(self.call('verify', operation='rollback')['ok'])


if __name__ == '__main__':
    unittest.main()
