"""Production account-menu proof boundaries without a WordPress installation."""
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[1]
VERIFIER = ROOT / 'scripts/verify-account-navigation.php'
PHP = shutil.which('php') or '/workspace/.petish-dev/bin/php'


@unittest.skipUnless(Path(PHP).is_file(), 'PHP is required')
class AccountNavigationVerifierTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='petish-nav-verifier-')
        self.directory = Path(self.temporary.name)
        self.source = VERIFIER.read_text()
        self.functions = self.directory / 'functions.php'
        self.functions.write_text('<?php\n' + self.source[self.source.index('function petish_navigation_verify_fail()'):self.source.index('\ntry {\n')])

    def tearDown(self):
        self.temporary.cleanup()

    def execute(self, body):
        script = self.directory / 'fixture.php'
        script.write_text("<?php\n$report=['status'=>'failure','category'=>'navigation','checks'=>[]];\nrequire " + repr(str(self.functions)) + ';\n' + body)
        result = subprocess.run([PHP, str(script)], capture_output=True, timeout=20)
        self.assertEqual(result.returncode, 0, 'Navigation proof fixture failed; diagnostics suppressed')
        self.assertFalse(result.stderr)
        return json.loads(result.stdout)

    @staticmethod
    def parser():
        return r'''
class WP_HTML_Tag_Processor {
 private $matches;private $index=-1;
 function __construct($html){preg_match_all('/<([a-z]+)\b[^>]*>/i',$html,$matches,PREG_SET_ORDER);$this->matches=$matches;}
 function next_tag(){return isset($this->matches[++$this->index]);}
 function get_tag(){return strtoupper($this->matches[$this->index][1]);}
 function get_attribute($name){preg_match('/\b'.preg_quote($name,'/').'="([^"]*)"/i',$this->matches[$this->index][0],$match);return isset($match[1])?html_entity_decode($match[1],ENT_QUOTES|ENT_HTML5,'UTF-8'):null;}
}
function esc_html($value){return htmlspecialchars($value,ENT_QUOTES,'UTF-8');}
function wc_get_account_endpoint_url($endpoint){return 'https://petish.biz/my-account/'.($endpoint==='dashboard'?'':$endpoint.'/').($endpoint==='customer-logout'?'?_wpnonce=PRIVATE_SYNTHETIC_NONCE':'');}
$items=['dashboard'=>'Dashboard','orders'=>'Orders','my-pets'=>'My Pets','for-my-pet'=>'For my pet','customer-logout'=>'Log out'];
function fixture_html($items,$current){$html='<nav class="woocommerce-MyAccount-navigation"><ul>';foreach($items as$key=>$label){
 $html.='<li class="woocommerce-MyAccount-navigation-link woocommerce-MyAccount-navigation-link--'.$key.($key===$current?' is-active':'').'"><a href="'.htmlspecialchars(wc_get_account_endpoint_url($key),ENT_QUOTES,'UTF-8').'">'.esc_html($label).'</a></li>';
}return $html.'</ul></nav>';}
'''

    def test_native_rows_keep_every_endpoint_logout_and_current_label(self):
        result = self.execute(self.parser() + r'''
$out=[];foreach(['dashboard','orders','my-pets','for-my-pet']as$current)$out[]=petish_navigation_verify_menu_html(fixture_html($items,$current),$items,$current);
echo json_encode($out);
''')
        self.assertEqual(result, [True] * 4)

    def test_missing_logout_wrong_target_current_row_or_custom_link_refuse(self):
        result = self.execute(self.parser() + r'''
$html=fixture_html($items,'my-pets');$without=$items;unset($without['customer-logout']);
$wrong_current=fixture_html($items,'orders');
$out=[!petish_navigation_verify_menu_html(fixture_html($without,'my-pets'),$items,'my-pets'),
 !petish_navigation_verify_menu_html($wrong_current,$items,'my-pets')];
foreach([['https://petish.biz/my-account/orders/','https://evil.invalid/my-account/orders/'],
 ['/my-account/for-my-pet/','/my-account/other/'],['>My Pets<','>Changed<'],
 ['class="woocommerce-MyAccount-navigation"','class="other"']]as[$old,$new])
 $out[]=!petish_navigation_verify_menu_html(str_replace($old,$new,$html),$items,'my-pets');
echo json_encode($out);
''')
        self.assertEqual(result, [True] * 6)

    def test_actual_transport_closures_block_supplier_provider_and_mail(self):
        start = self.source.index('    $block_http=static function')
        end = self.source.index("    add_filter('pre_http_request'", start)
        result = self.execute(r'''
class WP_Error{function __construct(public $code,public $message){}}
''' + self.source[start:end] + r'''
$out=[];foreach(['https://www.dsers.com/','https://public-api.wordpress.com/','http://localhost/']as$url){
 $error=$block_http(false,['method'=>'POST'],$url);$out[]=$error instanceof WP_Error&&$error->code==='petish_navigation_verify_http_blocked';
}
$out[]=$block_mail(null,['to'=>'real@petish.invalid'])===true;$out[]=$false(true)===false;$out[]=$zero(10)===0;
echo json_encode($out);
''')
        self.assertEqual(result, [True] * 6)

    def test_invalid_request_emits_finite_failure_without_private_input(self):
        result = subprocess.run([PHP, '-d', 'allow_url_fopen=0', str(VERIFIER), 'PRIVATE_INVALID_RUN'],
                                capture_output=True, timeout=20)
        self.assertEqual(result.returncode, 1)
        self.assertFalse(result.stderr)
        self.assertEqual(json.loads(result.stdout), {'status':'failure','category':'input','checks':{}})
        self.assertNotIn(b'PRIVATE_INVALID_RUN', result.stdout)


if __name__ == '__main__':
    unittest.main()
