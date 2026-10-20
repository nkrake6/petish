"""Verify preview publication gates without bootstrapping WordPress or sending mail."""
import base64
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
HELPER = ROOT / 'scripts/email-branding-preview-remote.php'
PHP = shutil.which('php') or '/workspace/.petish-dev/bin/php'


class EmailPreviewTests(unittest.TestCase):
    def run_php(self, body):
        source = "<?php\nrequire " + json.dumps(str(HELPER)) + ";\n" + body
        with tempfile.TemporaryDirectory() as directory:
            fixture = Path(directory) / 'preview.php'
            fixture.write_text(source)
            result = subprocess.run([PHP, str(fixture)], capture_output=True, timeout=10)
        self.assertEqual(result.returncode, 0, result.stderr.decode())
        self.assertEqual(result.stderr, b'')
        return json.loads(result.stdout)

    def test_include_does_not_bootstrap_wordpress_or_native_order_classes(self):
        self.assertEqual(self.run_php("echo json_encode([class_exists('WC_Order',false),defined('ABSPATH')]);"), [False, False])

    def test_public_gate_allows_only_synthetic_and_store_email_addresses(self):
        samples = [
            '<p>Alex Taylor · alex@petish.invalid · orders@petish.biz</p>',
            '<p>administrator@petish.biz</p>',
            '<p>someone@example.com</p>',
            '<p>someone&#64;example.com</p>',
            '<a href="https://staging.petish.biz/">private</a>',
            '<p>/home/u839205597/site</p>',
            '<p>.petish-staging/qa</p>',
            '<p>wc_order_private</p>',
            '<a href="/?_wpnonce=secret">nonce</a>',
            '<img onerror="unsafe">',
            '<script>unsafe()</script>',
            '<iframe src="https://example.com"></iframe>',
            '<form></form>',
        ]
        encoded = base64.b64encode(json.dumps(samples).encode()).decode()
        actual = self.run_php("$samples=json_decode(base64_decode('" + encoded + "'),true);$result=[];"
                              "foreach($samples as $sample){try{petish_email_preview_public_content($sample);$result[]=true;}"
                              "catch(RuntimeException $e){$result[]=false;}}echo json_encode($result);")
        self.assertEqual(actual, [True] + [False] * (len(samples) - 1))

    def test_css_removes_resource_fetches_and_rejects_active_css(self):
        actual = self.run_php("""
$out=[];
foreach (['color:#6532a1; background:#ede7ff;',
 '@import url(https://private.invalid/style); color:#6532a1;',
 'background-image:url(https://private.invalid/image);',
 'width:expression(alert(1));', 'behavior:unsafe;',
 'font-family:unsafe\\\\name;', '-moz-binding:unsafe;'] as $css) {
 try {$out[]=petish_email_preview_css($css);} catch(RuntimeException $e) {$out[]=false;}
}
echo json_encode($out);
""")
        self.assertEqual(actual[:3], ['color:#6532a1; background:#ede7ff;', ' color:#6532a1;', 'background-image:none;'])
        self.assertEqual(actual[3:], [False] * 4)

    def test_public_document_has_one_canonical_doctype_and_utf8_metadata(self):
        source = '<!DOCTYPE html PUBLIC "old"><html><head><meta http-equiv="Content-Type" content="text/html; charset=ISO-8859-1" /><meta charset="iso-8859-1"><title>Petish</title></head><body>Alex</body></html>'
        body = '$html=petish_email_preview_document(' + json.dumps(source) + ');'
        body += "$result=[$html];foreach(['<html><body>no head</body></html>','<head></head><head></head>'] as $bad){try{petish_email_preview_document($bad);$result[]=false;}catch(RuntimeException $e){$result[]=true;}}echo json_encode($result);"
        actual = self.run_php(body)
        self.assertTrue(actual[0].startswith('<!doctype html>\n<html>'))
        self.assertEqual(actual[0].lower().count('<!doctype'), 1)
        self.assertEqual(actual[0].count('<meta charset="utf-8">'), 1)
        self.assertNotIn('ISO-8859-1', actual[0])
        self.assertNotIn('iso-8859-1', actual[0])
        self.assertEqual(actual[1:], [True, True])

    def test_logo_rewrite_survives_wordpress_url_attribute_escaping(self):
        actual = self.run_php("""
// Match the WordPress URL-attribute contract: bare filenames gain http://.
function esc_url($url) {return str_starts_with($url,'/')||str_contains($url,':')?$url:'http://'.$url;}
function wp_kses($html,$allowed,$protocols) {return $html;}
class WP_HTML_Tag_Processor {
 private $html;private $visited=false;private $src;
 public function __construct($html) {$this->html=$html;preg_match('/src="([^"]+)"/',$html,$match);$this->src=$match[1];}
 public function next_tag(){if($this->visited)return false;$this->visited=true;return true;}
 public function get_tag(){return 'IMG';}
 public function get_attribute($name){return $name==='src'?$this->src:null;}
 public function set_attribute($name,$value){if($name==='src')$this->src=esc_url($value);}
 public function get_updated_html(){return preg_replace('/src="[^"]+"/','src="'.$this->src.'"',$this->html);}
}
$html='<html><head><title>Petish</title></head><body><img src="https://staging.petish.biz/wp-content/themes/dt-the7-child/assets/brand/combined_light_landscape.png" alt="Petish"></body></html>';
$safe=petish_email_preview_sanitize($html);
$checks=[esc_url('petish-logo.png')==='http://petish-logo.png',str_contains($safe,'src="petish-logo.png"'),!str_contains($safe,'http://petish-logo.png')];
foreach([0,2] as $count){try{petish_email_preview_local_images('<img src="/petish-logo.png">',$count);$checks[]=false;}catch(RuntimeException $e){$checks[]=true;}}
echo json_encode($checks);
""")
        self.assertEqual(actual, [True] * 5)

    def test_writer_requires_private_directory_and_never_overwrites(self):
        with tempfile.TemporaryDirectory() as directory:
            work = Path(directory) / 'private'
            work.mkdir(mode=0o700)
            other = Path(directory) / 'shared'
            other.mkdir(mode=0o770)
            other.chmod(0o770)
            link = Path(directory) / 'linked'
            link.symlink_to(work, target_is_directory=True)
            body = "$work=" + json.dumps(str(work)) + ";$out=[];"
            body += "petish_email_preview_write($work,'processing.html','safe synthetic preview');"
            body += "$out[]=(fileperms($work.'/processing.html')&07777)===0600;"
            attempts = [(str(work), 'processing.html'), (str(work), '../escape.html'),
                        (str(other), 'completed.html'), (str(link), 'completed.html')]
            body += "$attempts=json_decode(" + json.dumps(json.dumps(attempts)) + ",true);"
            body += "foreach($attempts as [$dir,$name]){try{petish_email_preview_write($dir,$name,'unsafe');$out[]=false;}catch(RuntimeException $e){$out[]=true;}}"
            body += "symlink('/missing-target',$work.'/refunded.html');try{petish_email_preview_write($work,'refunded.html','unsafe');$out[]=false;}catch(RuntimeException $e){$out[]=true;}"
            body += "echo json_encode($out);"
            self.assertEqual(self.run_php(body), [True] * 6)
            self.assertEqual((work / 'processing.html').read_text(), 'safe synthetic preview')
            self.assertFalse((Path(directory) / 'escape.html').exists())

    def test_synthetic_factory_has_no_saved_order_or_customer_identity(self):
        actual = self.run_php("""
class WC_Order {
 public $data=[];public $preview_added=[];
 protected $items=['line_items'=>null,'tax_lines'=>null,'shipping_lines'=>null,'fee_lines'=>null,'coupon_lines'=>null];
 public function __construct() {}
 public function __call($name,$args) {
  if(str_starts_with($name,'set_')) {$this->data[substr($name,4)]=$args[0];return;}
  throw new RuntimeException('Unexpected operation.');
 }
 public function set_address($address,$type) {$this->data[$type]=$address;}
 public function add_item($item) {
  foreach($this->items as $group) if(!is_array($group))throw new RuntimeException('Unsafe lazy database read.');
  $this->preview_added[]=$item;
 }
 public function save() {throw new RuntimeException('Persistence is forbidden.');}
 public function get_id(){return 0;}
 public function get_customer_id(){return 0;}
 public function get_order_key(){return '';}
}
class WC_Order_Item_Product extends WC_Order {
 public function add_meta_data($key,$value,$unique) {$this->data['meta'][$key]=$value;}
}
class WC_Order_Item_Shipping extends WC_Order {}
class WC_Order_Item_Tax extends WC_Order {}
$order=petish_email_preview_order();
echo json_encode(['id'=>$order->get_id(),'customer'=>$order->get_customer_id(),'key'=>$order->get_order_key(),
 'number'=>$order->get_order_number(),'data'=>$order->data,
 'items'=>array_map(static fn($item)=>$item->data,$order->preview_added)]);
""")
        self.assertEqual((actual['id'], actual['customer'], actual['key']), (0, 0, ''))
        self.assertEqual(actual['number'], 'P-1042')
        self.assertEqual(actual['data']['billing']['email'], 'alex@petish.invalid')
        self.assertEqual(actual['data']['currency'], 'AUD')
        self.assertEqual(actual['data']['total'], '14.60')
        self.assertEqual(actual['data']['shipping_total'], '6.00')
        self.assertEqual(len(actual['items']), 3)
        self.assertEqual(actual['items'][0]['name'], 'Corner & Gap Cleaning Brush')


if __name__ == '__main__':
    unittest.main()
