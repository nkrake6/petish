"""Exercise the shared native Woo email shell without mail or network access."""
import json
from html.parser import HTMLParser
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
THEME = ROOT / 'wordpress/wp-content/themes/dt-the7-child'
PHP = shutil.which('php') or '/workspace/.petish-dev/bin/php'


class Structure(HTMLParser):
    def __init__(self):
        super().__init__()
        self.stack, self.ids, self.images, self.errors = [], [], [], []

    def handle_starttag(self, tag, attributes):
        attrs = dict(attributes)
        if 'id' in attrs:
            self.ids.append(attrs['id'])
        if tag == 'img':
            self.images.append(attrs)
        if tag not in {'meta', 'img', 'br', 'hr', 'link', 'input'}:
            self.stack.append(tag)

    def handle_startendtag(self, tag, attributes):
        if tag == 'img':
            self.images.append(dict(attributes))

    def handle_endtag(self, tag):
        if not self.stack or self.stack[-1] != tag:
            self.errors.append(tag)
        else:
            self.stack.pop()


class EmailBrandingTests(unittest.TestCase):
    def render(self, *, rtl=False, heading='Your order is being processed',
               footer='<strong>Petish custom footer</strong>', preview=False, logo=None):
        values = dict(rtl=rtl, heading=heading, footer=footer, preview=preview, logo=logo)
        setup = '''<?php
define('ABSPATH', '/fixture/');
$fixture=json_decode(base64_decode('__DATA__'),true);
$filters=[];$calls=[];
function add_filter($name,$callback,$priority=10,$args=1){$GLOBALS['filters'][$name]=[$callback,$priority,$args];}
function apply_filters($name,$value,...$args){
 $GLOBALS['calls'][]=[$name,count($args),isset($args[0])&&is_object($args[0])?($args[0]->id??null):null];
 if($name==='woocommerce_is_email_preview')return $GLOBALS['fixture']['preview'];
 if($name==='petish_email_logo_url'&&$GLOBALS['fixture']['logo']!==null)return $GLOBALS['fixture']['logo'];
 if($name==='woocommerce_email_footer_text')return $value.'<p>Native footer filter retained</p>';
 return $value;
}
function get_stylesheet_directory_uri(){return 'https://staging.petish.biz/wp-content/themes/dt-the7-child';}
function home_url($path='/'){return 'https://staging.petish.biz'.$path;}
function get_bloginfo($key,...$args){return $key==='charset'?'UTF-8':'Petish & friends';}
function bloginfo($key){echo get_bloginfo($key);}
function language_attributes(){echo 'lang="en-AU"';}
function is_rtl(){return $GLOBALS['fixture']['rtl'];}
function esc_html($text){return htmlspecialchars((string)$text,ENT_QUOTES,'UTF-8');}
function esc_attr($text){return esc_html($text);}
function esc_attr_e($text,...$args){echo esc_attr($text);}
function esc_html_e($text,...$args){echo esc_html($text);}
function esc_url($url){return preg_match('/\\Ahttps:\\/\\//',(string)$url)?htmlspecialchars($url,ENT_QUOTES,'UTF-8'):'';}
function get_option($name){return $GLOBALS['fixture']['footer'];}
function get_transient($name){return '<p>Unsaved native preview footer</p>';}
function wc_get_page_permalink($name){return 'https://staging.petish.biz/my-account/';}
function wptexturize($text){return $text;}
function wpautop($text){return $text;}
function wp_kses_post($text){return preg_replace('/<script\\b[^>]*>.*?<\\/script>/is','',(string)$text);}
require '__HELPER__';
$email=(object)['id'=>'customer_processing_order'];$email_heading=$fixture['heading'];
ob_start();require '__HEADER__';
echo '<p id="native-order-sentinel">NATIVE ORDER BODY #5001 — AUD 14.60</p>';
require '__FOOTER__';$html=ob_get_clean();
echo json_encode(['html'=>$html,'css'=>petish_email_branding_styles('/* native styles */',$email),'filters'=>$filters,'calls'=>$calls]);
'''
        import base64
        setup = setup.replace('__DATA__', base64.b64encode(json.dumps(values).encode()).decode())
        setup = setup.replace('__HELPER__', str(THEME / 'inc/petish-email-branding.php'))
        setup = setup.replace('__HEADER__', str(THEME / 'woocommerce/emails/email-header.php'))
        setup = setup.replace('__FOOTER__', str(THEME / 'woocommerce/emails/email-footer.php'))
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'fixture.php'
            path.write_text(setup)
            result = subprocess.run([PHP, str(path)], capture_output=True, timeout=10)
        self.assertEqual(result.returncode, 0, 'Native template fixture failed')
        return json.loads(result.stdout)

    def test_shared_shell_is_balanced_and_preserves_native_order_body(self):
        result = self.render()
        parser = Structure()
        parser.feed(result['html'])
        self.assertEqual(parser.errors, [])
        self.assertEqual(parser.stack, [])
        self.assertEqual(len(parser.ids), len(set(parser.ids)))
        self.assertEqual(result['html'].count('NATIVE ORDER BODY #5001 — AUD 14.60'), 1)
        self.assertEqual(len(parser.images), 1)
        self.assertEqual(parser.images[0]['width'], '240')
        self.assertEqual(parser.images[0]['height'], '160')
        self.assertIn('/assets/brand/repaired/combined-light-v2.png', parser.images[0]['src'])
        self.assertIn('mailto:orders@petish.biz', result['html'])
        self.assertIn('Native footer filter retained', result['html'])
        self.assertIn('<strong>Petish custom footer</strong>', result['html'])
        self.assertIn(['woocommerce_email_footer_text', 1, 'customer_processing_order'], result['calls'])

    def test_headings_store_name_and_footer_use_native_escaping(self):
        result = self.render(heading='<img src=x onerror=alert(1)>Order & update',
                             footer='<script>alert(1)</script><strong>Safe configured footer</strong>')
        self.assertIn('&lt;img src=x onerror=alert(1)&gt;Order &amp; update', result['html'])
        self.assertNotIn('<img src=x', result['html'])
        self.assertNotIn('<script>', result['html'])
        self.assertIn('<title>Petish &amp; friends</title>', result['html'])
        self.assertIn('<strong>Safe configured footer</strong>', result['html'])

    def test_native_footer_preview_and_rtl_hooks_remain_usable(self):
        result = self.render(rtl=True, preview=True)
        self.assertIn('Unsaved native preview footer', result['html'])
        self.assertNotIn('Petish custom footer', result['html'])
        self.assertIn('dir="rtl"', result['html'])
        self.assertIn('rightmargin="0"', result['html'])
        self.assertIn('[dir="rtl"] #body_content_inner', result['css'])

    def test_css_appends_and_registers_only_the_html_presentation_filter(self):
        result = self.render()
        self.assertTrue(result['css'].startswith('/* native styles */\n'))
        self.assertEqual(result['filters'], {'woocommerce_email_styles': ['petish_email_branding_styles', 999, 2]})
        for expected in ('#6532A1', '#EDE7FF', '#14213D', 'Arial, Helvetica, sans-serif',
                         '@media only screen and (max-width: 620px)', 'max-width: 600px'):
            self.assertIn(expected, result['css'])
        self.assertNotIn('@import', result['css'])
        self.assertNotIn('@font-face', result['css'])
        self.assertFalse((THEME / 'woocommerce/emails/plain').exists())

    def test_custom_logo_filter_cannot_inject_a_script_url(self):
        result = self.render(logo='javascript:alert(1)')
        self.assertNotIn('javascript:', result['html'])
        self.assertIn('src=""', result['html'])


if __name__ == '__main__':
    unittest.main()
