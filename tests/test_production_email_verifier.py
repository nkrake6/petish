"""Exercise production rendering boundaries without loading a hosted site."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[1]
VERIFIER = ROOT / 'scripts/verify-production-email-branding.php'
PHP = shutil.which('php') or '/workspace/.petish-dev/bin/php'


@unittest.skipUnless(Path(PHP).is_file(), 'PHP is required')
class ProductionEmailVerifierTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='petish-production-verifier-')
        self.directory = Path(self.temporary.name)
        source = VERIFIER.read_text()
        self.source = source
        self.functions = self.directory / 'functions.php'
        self.functions.write_text('<?php\n' + source[source.index('function petish_production_verify_fail()'):source.index('\ntry {\n')])

    def tearDown(self):
        self.temporary.cleanup()

    def execute(self, body):
        fixture = self.directory / 'fixture.php'
        fixture.write_text("<?php\n$report=['status'=>'failure','category'=>'snapshot','checks'=>[]];\nrequire " + repr(str(self.functions)) + ';\n' + body)
        result = subprocess.run([PHP, str(fixture)], capture_output=True, timeout=20)
        self.assertEqual(result.returncode, 0, 'Production verification fixture failed; private diagnostics suppressed')
        self.assertFalse(result.stderr)
        return json.loads(result.stdout)

    @staticmethod
    def parser():
        return r'''
class WP_HTML_Tag_Processor {
 private $html; private $matches=[]; private $index=-1;
 function __construct($html){$this->html=$html;}
 function next_tag($query){
  if($this->index===-1){preg_match_all('/<'.preg_quote(strtolower($query['tag_name']),'/').'\b[^>]*>/i',$this->html,$found);$this->matches=$found[0];}
  $this->index++;return isset($this->matches[$this->index]);
 }
 function get_attribute($name){preg_match('/\b'.preg_quote($name,'/').'="([^"]*)"/i',$this->matches[$this->index],$match);return isset($match[1])?html_entity_decode($match[1],ENT_QUOTES|ENT_HTML5,'UTF-8'):null;}
}
'''

    def test_native_html_requires_full_approved_logo_brand_and_native_order_details(self):
        result = self.execute(self.parser() + r'''
$html='<html><body style="color:#6532A1;background:#EDE7FF"><img src="https://petish.biz/wp-content/themes/dt-the7-child/assets/brand/repaired/combined-light-v2.png" width="240" height="160"><p>Petish Alex P-1042 Corner &amp; Gap Cleaning Brush 14.60</p></body></html>';
$out=[petish_production_verify_html($html,true)];
foreach([
 ['combined-light-v2.png','combined_light_landscape.png'],['https://petish.biz/','https://staging.petish.biz/'],
 ['width="240"','width="220"'],['height="160"','height="100"'],['#6532A1','#8526ff'],
 ['#EDE7FF','#ffffff'],['P-1042',''],['Corner &amp; Gap Cleaning Brush',''],['14.60',''],['Alex','Other'],
]as[$old,$new])$out[]=!petish_production_verify_html(str_replace($old,$new,$html),true);
$out[]=petish_production_verify_html(str_replace(['P-1042','Corner &amp; Gap Cleaning Brush','14.60'],'',$html),false);
echo json_encode($out);
''')
        self.assertEqual(result, [True] * 12)

    def test_action_button_requires_original_url_and_white_on_petish_purple(self):
        result = self.execute(self.parser() + r'''
$url='https://petish.biz/my-account/?key=FIXED_NOT_AN_AUTH_TOKEN&login=alex';
$html='<a class="button" href="https://petish.biz/my-account/?key=FIXED_NOT_AN_AUTH_TOKEN&amp;login=alex" style="background-color:#6532a1;color:#fff;padding:13px">Confirm</a>';
$out=[petish_production_verify_button($html,$url)];
foreach([['#fff','#6532a1'],['#6532a1','#ffffff'],['class="button"','class="other"'],
 ['FIXED_NOT_AN_AUTH_TOKEN','other'],['https://petish.biz/','https://other.invalid/']]as[$old,$new])
 $out[]=!petish_production_verify_button(str_replace($old,$new,$html),$url);
$out[]=petish_production_verify_button(str_replace('#fff','#FFFFFF !important',$html),$url);
echo json_encode($out);
''')
        self.assertEqual(result, [True] * 7)

    def test_paths_reject_links_hardlinks_and_relative_or_traversal_locations(self):
        file = self.directory / 'ordinary.php'
        file.write_text('<?php')
        symlink = self.directory / 'symlink.php'
        symlink.symlink_to(file)
        hardlink = self.directory / 'hard.php'
        os.link(file, hardlink)
        lone = self.directory / 'lone.php'
        lone.write_text('<?php')
        paths = [str(lone), str(file), str(symlink), str(hardlink), './lone.php',
                 str(self.directory) + '/../' + self.directory.name + '/lone.php']
        body = '$out=[];foreach(json_decode(' + json.dumps(json.dumps(paths)) + ',true)as$path)$out[]=petish_production_verify_path($path);echo json_encode($out);'
        self.assertEqual(self.execute(body), [True, False, False, False, False, False])

    def test_real_transport_closures_block_all_hosts_and_mail_without_provider_window(self):
        start = self.source.index('    $block_http=static function')
        end = self.source.index('    add_filter(\'pre_http_request\'', start)
        closures = self.source[start:end]
        result = self.execute(r'''
class WP_Error {function __construct(public $code,public $message){}}
''' + closures + r'''
$out=[];foreach(['https://www.dsers.com/','https://public-api.wordpress.com/','http://localhost/']as$url){
 $error=$block_http(false,['method'=>'POST'],$url);$out[]=$error instanceof WP_Error&&$error->code==='petish_production_verify_http_blocked';
}
$out[]=$block_mail(null,['to'=>'real@example.invalid'])===true;
$out[]=$false(true)===false;$out[]=$zero(99)===0;
echo json_encode($out);
''')
        self.assertEqual(result, [True] * 6)

    def test_state_snapshot_selects_only_digest_stock_and_never_returns_private_values(self):
        result = self.execute(r'''
define('ARRAY_A','ARRAY_A');
$GLOBALS['queries']=[];$GLOBALS['changed']=false;
class Database{
 public $prefix='wp_';public $postmeta='wp_postmeta';
 function get_results($sql,$format){$GLOBALS['queries'][]=$sql;
  if(!str_contains($sql,"SHA2(CONCAT(IF(meta_value IS NULL,'null:','value:'),COALESCE(meta_value,'')),256)"))return [['value_state'=>null]];
  return [['meta_id'=>'1','post_id'=>'5','meta_key'=>'_stock','value_state'=>hash('sha256',$GLOBALS['changed']?'value:':'null:')]];
 }
}
$wpdb=new Database();
function get_option($name,$default){return $name==='woocommerce_email_from_address'?'private-inbox@example.invalid':$default;}
function petish_email_preview_order_counts(){return ['posts'=>'2','hpos'=>'0'];}
function petish_email_preview_account_state(){return ['users'=>3,'auth_state'=>hash('sha256','private-auth-state')];}
$a=petish_production_verify_state();$same=petish_production_verify_state();$GLOBALS['changed']=true;$b=petish_production_verify_state();
$out=[$a===$same,$a['stock']!==$b['stock'],!str_contains(json_encode($a),'private-inbox'),!str_contains(json_encode($a),'private-auth-state')];
foreach($GLOBALS['queries']as$sql)$out[]=str_starts_with($sql,'SELECT ');
echo json_encode($out);
''')
        self.assertTrue(all(result))

    def test_rejected_entrypoint_emits_only_finite_failure_report(self):
        result = subprocess.run([PHP, '-d', 'allow_url_fopen=0', str(VERIFIER), 'PRIVATE_INVALID_REQUEST'],
                                capture_output=True, timeout=20)
        self.assertEqual(result.returncode, 1)
        self.assertFalse(result.stderr)
        self.assertEqual(json.loads(result.stdout), {'status':'failure','category':'input','checks':{}})
        self.assertNotIn(b'PRIVATE_INVALID_REQUEST', result.stdout)

    def test_preflight_mode_is_accepted_but_unsafe_missing_site_refuses_before_bootstrap(self):
        env = dict(os.environ, HOME=str(self.directory))
        result = subprocess.run([PHP, '-d', 'allow_url_fopen=0', str(VERIFIER), '123-1', 'preflight'],
                                env=env, capture_output=True, timeout=20)
        self.assertEqual(result.returncode, 1)
        self.assertFalse(result.stderr)
        self.assertEqual(json.loads(result.stdout), {'status':'failure','category':'paths','checks':{}})
        for mode in ('render', 'PRIVATE_MODE', ''):
            result = subprocess.run([PHP, '-d', 'allow_url_fopen=0', str(VERIFIER), '123-1', mode],
                                    env=env, capture_output=True, timeout=20)
            self.assertEqual(json.loads(result.stdout)['category'], 'input')

    def test_snapshot_failures_identify_the_finite_component_without_dumping_data(self):
        result = self.execute(r'''
define('ARRAY_A','ARRAY_A');
$GLOBALS['where']='stock';
class Database{
 public $prefix='wp_';public $postmeta='wp_postmeta';
 function get_results($sql,$format){return $GLOBALS['where']==='stock'?null:[];}
}
$wpdb=new Database();
function petish_email_preview_order_counts(){if($GLOBALS['where']==='orders')throw new RuntimeException('private detail');return [];}
function petish_email_preview_account_state(){if($GLOBALS['where']==='accounts')throw new RuntimeException('private detail');return [];}
function get_option($name,$default){throw new RuntimeException('private detail');}
$out=[];foreach(['stock','orders','accounts','settings']as$where){
 $GLOBALS['where']=$where;$report['category']='snapshot';
 try{petish_production_verify_state();$out[]=false;}catch(RuntimeException$ignored){$out[]=$report['category']==='snapshot_'.$where;}
}
echo json_encode($out);
''')
        self.assertEqual(result, [True] * 4)


if __name__ == '__main__':
    unittest.main()
