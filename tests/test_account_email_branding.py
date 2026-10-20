"""Render core account-mail filters without accounts, reset keys, mail or network."""
import base64
from html import unescape
from html.parser import HTMLParser
import json
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
THEME = ROOT / 'wordpress/wp-content/themes/dt-the7-child'
PHP = shutil.which('php') or '/workspace/.petish-dev/bin/php'


class Links(HTMLParser):
    def __init__(self):
        super().__init__()
        self.hrefs = []

    def handle_starttag(self, tag, attrs):
        if tag == 'a':
            self.hrefs.append(dict(attrs).get('href'))


class AccountEmailBrandingTests(unittest.TestCase):
    def render(self, mail, kind='new', woo=True):
        fixture = base64.b64encode(json.dumps(dict(mail=mail, kind=kind)).encode()).decode()
        setup = '''<?php
define('ABSPATH','/fixture/');
$fixture=json_decode(base64_decode('__DATA__'),true);$filters=[];
function add_filter($name,$callback,$priority=10,$args=1){$GLOBALS['filters'][$name]=[$callback,$priority,$args];}
function apply_filters($name,$value,...$args){return $value;}
function __($text,...$args){return $text;}
function get_stylesheet_directory_uri(){return 'https://petish.example/wp-content/themes/dt-the7-child';}
function home_url($path='/'){return 'https://petish.example'.$path;}
function get_bloginfo($key,...$args){return $key==='charset'?'UTF-8':'Petish & friends';}
function bloginfo($key){echo get_bloginfo($key);}
function language_attributes(){echo 'lang="en-AU"';}
function is_rtl(){return false;}
function esc_html($text){return htmlspecialchars((string)$text,ENT_QUOTES,'UTF-8');}
function esc_attr($text){return esc_html($text);}
function esc_attr_e($text,...$args){echo esc_attr($text);}
function esc_html_e($text,...$args){echo esc_html($text);}
function esc_url($url){return preg_match('/\\Ahttps?:\\/\\//i',(string)$url)?htmlspecialchars($url,ENT_QUOTES,'UTF-8'):'';}
function get_option($name){return 'Petish footer';}
function wc_get_page_permalink($name){return 'https://petish.example/my-account/';}
function wptexturize($text){return $text;}
function wpautop($text){return $text;}
function wp_kses_post($text){return $text;}
__WOO__
require '__THEME__/inc/petish-email-branding.php';
require '__THEME__/inc/petish-account-email-branding.php';
$callback='reset'===$fixture['kind']?'petish_account_email_branding_reset_password':'petish_account_email_branding_new_user';
$result=$callback($fixture['mail']);
echo json_encode(['mail'=>$result,'filters'=>$filters,'repeat'=>$callback($result)]);
'''
        woo_stub = '''function wc_get_template_html($name,$args){
 extract($args);ob_start();require '__THEME__/woocommerce/'.$name;return ob_get_clean();
}'''
        setup = setup.replace('__WOO__', woo_stub if woo else '')
        setup = setup.replace('__DATA__', fixture).replace('__THEME__', str(THEME))
        with tempfile.TemporaryDirectory() as directory:
            script = Path(directory) / 'render.php'
            script.write_text(setup)
            output = subprocess.run([PHP, str(script)], capture_output=True, timeout=10)
        self.assertEqual(output.returncode, 0, output.stderr.decode())
        return json.loads(output.stdout)

    def native_mail(self, **overrides):
        return dict(to='person@example.com', subject='[Petish] Native account subject',
                    message='To set your password, visit the following address:\r\n\r\n'
                            'https://petish.example/wp-login.php?login=alex%2Bpets.&key=opaque%2F%2BValue&action=rp&wp_lang=en_AU\r\n',
                    headers='', **overrides)

    def test_new_user_and_reset_use_shared_shell_without_changing_native_fields(self):
        for kind, heading in [('new', 'Your Petish account'), ('reset', 'Reset your password')]:
            with self.subTest(kind=kind):
                original = self.native_mail()
                result = self.render(original, kind)
                rendered = result['mail']
                self.assertEqual(rendered['to'], original['to'])
                self.assertEqual(rendered['subject'], original['subject'])
                self.assertIn(heading, rendered['message'])
                self.assertIn('/assets/brand/repaired/combined-light-v2.png', rendered['message'])
                self.assertIn('mailto:orders@petish.biz', rendered['message'])
                self.assertIn('#EDE7FF', rendered['message'])
                self.assertEqual(rendered['headers'], 'Content-Type: text/html; charset=UTF-8')
                self.assertEqual(result['repeat'], rendered, 'A second filter pass must not wrap HTML again')

    def test_existing_native_links_and_all_message_text_survive_html_conversion(self):
        original = self.native_mail()
        original['message'] += '\r\nUsername: <script>alert("x")</script> & Alex\'s pets\r\n'
        result = self.render(original)['mail']['message']
        links = Links()
        links.feed(result)
        expected_link = original['message'].split('\r\n')[2]
        self.assertIn(expected_link, links.hrefs)
        self.assertNotIn('<script>', result)
        body = re.search(r'<p class="petish-core-account-message"[^>]*>(.*?)</p>', result, re.S).group(1)
        self.assertEqual(unescape(re.sub(r'<[^>]+>', '', body)), original['message'])

    def test_core_content_stays_styled_when_an_email_client_removes_the_head(self):
        rendered = self.render(self.native_mail())['mail']['message']
        without_head = re.sub(r'<head>.*?</head>', '', rendered, flags=re.S)
        paragraph = re.search(r'<p class="petish-core-account-message"[^>]*>', without_head).group(0)
        for declaration in ('color:#14213D', 'font-family:Arial,Helvetica,sans-serif',
                            'font-size:15px', 'line-height:1.65', 'overflow-wrap:anywhere'):
            self.assertIn(declaration, paragraph)
        self.assertIn('max-width:600px', without_head)
        self.assertIn('width:240px; max-width:100%; height:auto;', without_head)

    def test_other_headers_recipients_and_attachments_remain_unchanged(self):
        for headers in ['Reply-To: help@example.com\r\nX-Petish-Test: preserve',
                        ['Reply-To: help@example.com', 'X-Petish-Test: preserve']]:
            with self.subTest(headers=headers):
                original = self.native_mail()
                original.update(headers=headers, to=['one@example.com', 'two@example.com'], attachments=['keep.pdf'])
                result = self.render(original)['mail']
                self.assertEqual(result['to'], original['to'])
                self.assertEqual(result['subject'], original['subject'])
                self.assertEqual(result['attachments'], original['attachments'])
                if isinstance(headers, list):
                    self.assertEqual(result['headers'][:-1], headers)
                else:
                    self.assertTrue(result['headers'].startswith(headers + '\r\n'))

    def test_empty_explicit_formats_and_existing_html_remain_native(self):
        cases = [dict(message=''), dict(message=' \r\n'),
                 dict(headers='Content-Type: text/plain; charset=UTF-8'),
                 dict(headers=['Content-Type: multipart/alternative; boundary=test']),
                 dict(message='<html><body>Existing mail plugin HTML</body></html>')]
        for updates in cases:
            with self.subTest(updates=updates):
                original = self.native_mail()
                original.update(updates)
                self.assertEqual(self.render(original)['mail'], original)

    def test_woocommerce_inactive_leaves_plain_mail_unchanged(self):
        original = self.native_mail()
        self.assertEqual(self.render(original, woo=False)['mail'], original)

    def test_only_two_specific_core_mail_filters_are_added(self):
        filters = self.render(self.native_mail())['filters']
        self.assertEqual(set(filters), {'woocommerce_email_styles', 'wp_new_user_notification_email',
                                        'retrieve_password_notification_email'})
        source = (THEME / 'inc/petish-account-email-branding.php').read_text()
        for forbidden in ['wp_mail(', 'get_password_reset_key(', 'wp_create_user(',
                          'wp_insert_user(', 'wp_mail_content_type', "add_filter( 'wp_mail'"]:
            self.assertNotIn(forbidden, source)


if __name__ == '__main__':
    unittest.main()
