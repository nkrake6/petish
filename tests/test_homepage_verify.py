"""Private homepage verification boundaries without loading WordPress."""
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import struct
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[1]
VERIFIER = ROOT / 'scripts/verify-homepage.php'
PHP = shutil.which('php') or '/workspace/.petish-dev/bin/php'


@unittest.skipUnless(Path(PHP).is_file(), 'PHP is required')
class HomepageVerifierTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='petish-homepage-verifier-')
        self.directory = Path(self.temporary.name)
        self.source = VERIFIER.read_text()
        self.functions = self.directory / 'functions.php'
        self.functions.write_text('<?php\n' + self.source[self.source.index('const PETISH_HOMEPAGE_PREVIOUS_FILES'):self.source.index('\ntry {\n')])

    def tearDown(self):
        self.temporary.cleanup()

    def execute(self, body):
        fixture = self.directory / 'fixture.php'
        fixture.write_text("<?php\n$report=['status'=>'failure','category'=>'products','checks'=>[]];\nrequire " + repr(str(self.functions)) + ';\n' + body)
        result = subprocess.run([PHP, str(fixture)], capture_output=True, timeout=20)
        self.assertEqual(result.returncode, 0, 'Homepage proof fixture failed; private diagnostics suppressed')
        self.assertFalse(result.stderr)
        return json.loads(result.stdout)

    def test_preserved_account_email_files_and_original_hot_rule_match_trusted_source(self):
        self.assertTrue(self.execute('echo json_encode(petish_homepage_verify_previous_files(' + repr(str(ROOT / 'wordpress')) + '));'))
        helper = (ROOT / 'wordpress/wp-content/themes/dt-the7-child/includes/supplier-popularity.php').read_text()
        rule = re.search(r'^function petish_supplier_hot_badge\b.*?^\}\n', helper, re.M | re.S).group(0)
        expected = re.search(r"PETISH_HOMEPAGE_HOT_RULE_SHA256 = '([a-f0-9]{64})'", self.source).group(1)
        self.assertEqual(hashlib.sha256(rule.encode()).hexdigest(), expected)
        badge = (ROOT / 'wordpress/wp-content/themes/dt-the7-child/assets/images/ui/free-delivery-van.png').read_bytes()
        expected_badge = re.search(r"PETISH_HOMEPAGE_BADGE_SHA256 = '([a-f0-9]{64})'", self.source).group(1)
        self.assertEqual(hashlib.sha256(badge).hexdigest(), expected_badge)
        self.assertEqual(struct.unpack('>II', badge[16:24]), (1600, 592))

    def test_rendered_cards_must_resolve_to_known_public_products_in_native_order(self):
        result = self.execute(r'''
class Result implements IteratorAggregate{
 public $length;function __construct(private $items){$this->length=count($items);}
 function getIterator():Traversable{return new ArrayIterator($this->items);}
 function item($i){return $this->items[$i]??null;}
}
class Link{function __construct(private $href){}function getAttribute($name){return $this->href;}}
class Card{function __construct(public $href){}}
class Product{function __construct(private $id){}function get_id(){return $this->id;}}
class XPath{
 function __construct(public $cards){}
 function query($query,$node){return new Result($node==='section'?$this->cards:($node->href===null?[]:[new Link($node->href)]));}
}
$products=['https://petish.biz/product/a/'=>new Product(10),'https://petish.biz/product/b/'=>new Product(20)];
$x=new XPath([new Card(array_keys($products)[1]),new Card(array_keys($products)[0])]);
$out=[petish_homepage_verify_card_ids($x,'section',$products)===[20,10]];
foreach([new Card('https://unrelated.invalid/private/'),new Card(null)]as$bad){$x->cards=[$bad];try{petish_homepage_verify_card_ids($x,'section',$products);$out[]=false;}catch(RuntimeException$ignored){$out[]=true;}}
echo json_encode($out);
''')
        self.assertEqual(result, [True] * 3)

    def test_transport_barriers_never_open_a_supplier_or_payment_window(self):
        start = self.source.index('    $block_http=static function')
        end = self.source.index("    add_filter('pre_http_request'", start)
        result = self.execute(r'''
class WP_Error{function __construct(public $code,public $message){}}
''' + self.source[start:end] + r'''
$out=[];foreach(['https://www.dsers.com/','https://public-api.wordpress.com/','http://localhost/']as$url){
 $blocked=$block_http(false,['method'=>'POST'],$url);$out[]=$blocked instanceof WP_Error&&$blocked->code==='petish_homepage_verify_http_blocked';
}
$out[]=$block_mail(null,['to'=>'real@example.invalid'])===true;$out[]=$false(true)===false;$out[]=$zero(99)===0;
echo json_encode($out);
''')
        self.assertEqual(result, [True] * 6)

    def test_shipping_policy_and_supplier_evidence_are_read_only_private_digests(self):
        result = self.execute(r'''
define('ARRAY_A','ARRAY_A');$GLOBALS['mode']='same';$GLOBALS['queries']=[];
class Database{
 public $prefix='wp_';public $postmeta='wp_postmeta';
 function get_results($sql,$format){$GLOBALS['queries'][]=$sql;
  return [['meta_id'=>'1','post_id'=>'2','meta_key'=>'_petish_supplier_free_delivery_evidence',
   'value_state'=>hash('sha256',str_contains($sql,'_petish_supplier_free_delivery_evidence')&&$GLOBALS['mode']==='evidence'?'changed-private-evidence':'private-evidence')]];
 }
}
$wpdb=new Database();
function get_option($name,$default){return $name==='petish_shipping_mode'?($GLOBALS['mode']==='policy'?'automatic':'quote'):
 ($name==='petish_supplier_shipping_quotes'?['private'=>'PRIVATE_SUPPLIER_DATA']:$default);}
function petish_email_preview_order_counts(){return ['posts'=>'1'];}
function petish_email_preview_account_state(){return ['users'=>1,'auth_state'=>hash('sha256','private-auth')];}
$a=petish_homepage_verify_state();$same=petish_homepage_verify_state();
$GLOBALS['mode']='policy';$policy=petish_homepage_verify_state();$GLOBALS['mode']='evidence';$evidence=petish_homepage_verify_state();
$out=[$a===$same,$a['shipping_policy']!==$policy['shipping_policy'],$a['supplier_evidence']!==$evidence['supplier_evidence'],
 !str_contains(json_encode($a),'PRIVATE_SUPPLIER_DATA'),!str_contains(json_encode($a),'private-evidence')];
foreach($GLOBALS['queries']as$query)$out[]=str_starts_with($query,'SELECT ');
echo json_encode($out);
''')
        self.assertTrue(all(result))

    def test_only_fixed_target_modes_are_accepted_and_output_stays_finite(self):
        import os
        env = dict(os.environ, HOME=str(self.directory))
        for mode in ('production', 'staging'):
            result = subprocess.run([PHP, '-d', 'allow_url_fopen=0', str(VERIFIER), '123-1', mode],
                                    env=env, capture_output=True, timeout=20)
            self.assertEqual(result.returncode, 1)
            self.assertFalse(result.stderr)
            self.assertEqual(json.loads(result.stdout), {'status':'failure','category':'paths','checks':{}})
        for mode in ('../private-site', 'http://other.invalid', '', 'PRIVATE_TARGET'):
            result = subprocess.run([PHP, '-d', 'allow_url_fopen=0', str(VERIFIER), '123-1', mode],
                                    env=env, capture_output=True, timeout=20)
            self.assertEqual(json.loads(result.stdout), {'status':'failure','category':'input','checks':{}})
            self.assertNotIn(mode.encode() if mode else b'PRIVATE', result.stdout)

    def test_hero_media_choices_are_read_only_protected_settings(self):
        result=self.execute(r'''
define('ARRAY_A','ARRAY_A');$GLOBALS['hero_mode']='before';$GLOBALS['hero_reads']=[];
class HeroDatabase{
 public $prefix='wp_';public $postmeta='wp_postmeta';
 function get_results($sql,$format){if(!str_starts_with($sql,'SELECT '))throw new RuntimeException();return [];}
}
$wpdb=new HeroDatabase();
function get_option($name,$default){
 $GLOBALS['hero_reads'][]=$name;
 return $name==='petish_home_hero_images_v1'?['rabbit'=>$GLOBALS['hero_mode']==='before'?41:42]:$default;
}
function update_option(...$args){throw new RuntimeException('No setting writes permitted.');}
function petish_email_preview_order_counts(){return [];}
function petish_email_preview_account_state(){return [];}
$before=petish_homepage_verify_state();$GLOBALS['hero_mode']='after';$after=petish_homepage_verify_state();
echo json_encode([in_array('petish_home_hero_images_v1',$GLOBALS['hero_reads'],true),
 $before['settings']!==$after['settings'],
 !str_contains(json_encode($before),'rabbit')]);
''')
        self.assertEqual(result,[True,True,True])

    def test_home_stylesheet_version_survives_zero_archive_timestamps(self):
        theme=self.directory/'theme'
        for name in ('assets/fonts/fonts.css','assets/css/petish.css','assets/css/home.css',
                     'assets/js/for-your-pet.js','assets/js/home.js','assets/js/theme.js'):
            target=theme/name;target.parent.mkdir(parents=True,exist_ok=True)
            target.write_bytes(('Reviewed '+name).encode())
            os.utime(target,(0,0))
        functions=(ROOT/'wordpress/wp-content/themes/dt-the7-child/functions.php').read_text()
        enqueue=re.search(r"add_action\(\s*'wp_enqueue_scripts',\s*(function\s*\(\)\s*\{.*?\n\}),\s*40\s*\);",functions,re.S)
        self.assertIsNotNone(enqueue)
        body=r'''
$GLOBALS['styles']=[];
function get_stylesheet_directory(){return THEME_DIRECTORY;}
function get_stylesheet_directory_uri(){return 'https://petish.example/theme';}
function is_front_page(){return true;}
function is_product(){return false;}
function wp_enqueue_style($handle,$url,$dependencies,$version){$GLOBALS['styles'][$handle]=[$url,$version];}
function wp_enqueue_script(...$args){}
function wp_dequeue_style(...$args){}
'''.replace('THEME_DIRECTORY',repr(str(theme)))
        body+='\n('+enqueue.group(1)+')();\necho json_encode($GLOBALS[\'styles\'][\'petish-home\']);'
        url,version=self.execute(body)
        self.assertEqual(url,'https://petish.example/theme/assets/css/home.css')
        digest=hashlib.sha256((theme/'assets/css/home.css').read_bytes()).hexdigest()
        self.assertIsInstance(version,str)
        self.assertGreaterEqual(len(version),12)
        self.assertTrue(digest.startswith(version))

    def test_full_read_only_barrier_covers_bootstrap_render_and_final_snapshot(self):
        source = self.source
        attach = source.index("add_filter('query','petish_club_verify_query_guard',PHP_INT_MAX)")
        bootstrap = source.index("require$root.'/wp-load.php'")
        capture = source.index("$bootstrap_blocked=$GLOBALS['petish_club_blocked_write_count']")
        self.assertLess(attach, bootstrap)
        self.assertLess(bootstrap, capture)
        self.assertLess(capture, source.index('$full_before=petish_club_verify_state($root)'))
        self.assertLess(source.index('petish_homepage_verify_render($root);'),
                        source.index("'protected_site_state_unchanged'"))
        self.assertLess(source.index("'protected_site_state_unchanged'"),
                        source.index("'native_read_only_verified'"))
        self.assertIn("if($hook==='shutdown')petish_club_verify_disable_async_dispatch()", source)

    def test_browser_allows_only_exact_read_only_personal_fragment(self):
        script = """
import * as proof from './tools/homepage/browser.mjs';
const endpoint='https://staging.petish.biz/wp-admin/admin-ajax.php?action=petish_for_your_pet';
const good=proof.allowedHomepageRequest(endpoint,'GET');
const bad=[endpoint+'&user_id=1',endpoint+'&pet_id=1',endpoint.replace('petish_for_your_pet','other'),endpoint.replace('staging.petish.biz','petish.biz'),endpoint+'#fragment'];
console.log(JSON.stringify([good,...bad.map(url=>!proof.allowedHomepageRequest(url,'GET')),!proof.allowedHomepageRequest(endpoint,'POST'),!proof.allowedHomepageRequest(endpoint,'HEAD')]));
"""
        result = subprocess.run(['node', '--input-type=module', '-e', script], cwd=ROOT,
                                capture_output=True, timeout=20)
        self.assertEqual(result.returncode, 0)
        self.assertFalse(result.stderr)
        self.assertEqual(json.loads(result.stdout), [True] * 8)


if __name__ == '__main__':
    unittest.main()
