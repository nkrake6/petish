"""Publication gate tests: synthetic native previews must stay inert and private."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest


SCRIPT = Path(__file__).resolve().parents[1] / 'scripts/validate-email-previews.py'
SPEC = importlib.util.spec_from_file_location('validate_email_previews', SCRIPT)
validator = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(validator)

HTML = '''<!doctype html><html><head><meta charset="utf-8">
<style type="text/css">body { color: #6532A1; background: #EDE7FF; }
@media screen and (max-width: 600px) { table { width: 100%; } }</style>
</head><body><table role="presentation"><tr><td>
<img src="petish-logo-v2.png" alt="Petish" width="240">
<h1>Petish — order P-1042</h1><p>Hello Alex Taylor,</p>
<p>Corner &amp; Gap Cleaning Brush: $8.00</p>
<p>Standard delivery: $6.60</p><p>Total: $14.60 (includes $1.33 GST)</p>
<p>alex@petish.invalid</p><a href="https://petish.biz/shop/">Shop</a>
<a href="mailto:orders@petish.biz">orders@petish.biz</a>
</td></tr></table></body></html>'''


class PreviewValidationTests(unittest.TestCase):
    def rejected(self, html, category=None):
        with self.assertRaises(validator.PreviewRejected) as caught:
            validator.validate_html(html)
        if category:
            self.assertEqual(caught.exception.category, category)

    def test_native_email_shape_and_fixed_public_links_are_accepted(self):
        validator.validate_html(HTML)
        for link in validator.ALLOWED_LINKS:
            with self.subTest(link=link):
                validator.validate_html(HTML.replace('https://petish.biz/shop/', link))

    def test_active_markup_and_unexpected_attributes_are_rejected(self):
        for payload in (
            '<script>alert(1)</script>', '<form></form>', '<iframe></iframe>',
            '<object></object>', '<embed>', '<link href="https://evil.invalid/">',
            '<svg></svg>', '<math></math>', '<input>',
            '<p onclick="alert(1)">text</p>', '<p OnLoAd="alert(1)">text</p>',
            '<p class="x" CLASS="y">text</p>', '<div srcdoc="text">text</div>',
            '<meta http-equiv="refresh" content="0;url=https://evil.invalid/">',
            '<!-- hidden markup -->', '<?php echo 1;?>',
        ):
            with self.subTest(payload=payload):
                self.rejected(HTML.replace('<h1>', payload + '<h1>'))

    def test_images_cannot_load_external_or_embedded_content(self):
        for source in (
            'https://petish.biz/logo.png', '//evil.invalid/logo.png',
            'data:image/png;base64,AA==', 'petish-logo-v2.png?track=1',
            'petish-logo-v2.png#x', './petish-logo-v2.png', 'petish%2dlogo.png',
        ):
            with self.subTest(source=source):
                self.rejected(HTML.replace('src="petish-logo-v2.png"', f'src="{source}"'))
        self.rejected(HTML.replace('alt="Petish"', 'alt="Petish" srcset="https://evil.invalid/logo.png 2x"'))

    def test_links_are_exact_public_routes_without_queries_or_fragments(self):
        for link in (
            'https://evil.invalid/', 'https://petish.biz.evil.invalid/',
            'https://petish.biz@evil.invalid/', 'https://petish.biz:443/',
            'https://petish.biz/shop/?key=abc', 'https://petish.biz/shop/#order',
            'javascript:alert(1)', 'data:text/html,hello', '/shop/',
            'https://petish.biz/shop/%2e%2e/private/',
        ):
            with self.subTest(link=link):
                self.rejected(HTML.replace('https://petish.biz/shop/', link))

    def test_css_cannot_request_resources_or_execute_obfuscated_content(self):
        for css in (
            'background:url(https://evil.invalid/pixel)',
            '@import "https://evil.invalid/x.css";',
            'width:expression(alert(1))', 'behavior:htc(test)',
            '-moz-binding:url(test)', r'background:u\72l(test)',
            'background:u&#114;l(test)', 'background:%75rl(test)',
            'background:javascript:alert(1)', 'background:data:text/plain,x',
        ):
            with self.subTest(css=css):
                self.rejected(HTML.replace('<h1>', f'<p style="{css}">x</p><h1>'))
                self.rejected(HTML.replace('</style>', css + '</style>'))

    def test_native_woocommerce_mobile_child_selectors_are_accepted(self):
        css = '@media(max-width:600px){#body_content table > tbody > tr > td{padding:10px!important}}'
        validator.validate_html(HTML.replace('</style>', css + '</style>'))
        self.rejected(HTML.replace('</style>', '</style><script>unsafe()</script><style>'), 'active_content')

    def test_sensitive_text_and_unknown_email_addresses_are_rejected(self):
        for marker in (
            '/home/user/private.sql', '/workspace/private.sql', '/tmp/private.sql',
            'wp-config.php', 'wc_order_private123', '_wpnonce=private123',
            'staging.petish.biz', 'DB_PASSWORD', 'client_secret',
            'password: private123', 'Bearer abcdefghijklmnop',
            'sk_live_abcdefghijklm', 'github_pat_abcdefghijklm',
            '-----BEGIN RSA PRIVATE KEY-----',
            'someone@example.com', 'someone&#64;example.com',
            'someone%40example.com', '%2Fhome%2Fuser%2Fprivate.sql',
        ):
            with self.subTest(marker=marker):
                self.rejected(HTML.replace('</h1>', '</h1><p>' + marker + '</p>'))

    def test_required_synthetic_content_and_branding_cannot_be_removed(self):
        for old, new in (
            ('P-1042', 'P-9999'), ('Alex', 'Customer'),
            ('Corner &amp; Gap Cleaning Brush', 'Different item'),
            ('$14.60', '$99.99'), ('#6532A1', '#ffffff'), ('#EDE7FF', '#ffffff'),
            ('<img src="petish-logo-v2.png" alt="Petish" width="240">', ''),
        ):
            with self.subTest(old=old):
                self.rejected(HTML.replace(old, new), 'synthetic_content')

    def test_malformed_or_non_html_documents_are_rejected(self):
        for html in (
            HTML.replace('<!doctype html>', ''), HTML + '<html></html>',
            HTML.replace('</td>', ''), HTML.replace('<!doctype html>', '<!doctype svg>'),
            HTML.replace('<p>Hello', '<p><span>Hello'), HTML + 'trailing text',
            HTML.replace('<body>', '<body>\x00'),
        ):
            self.rejected(html)


class PublicationDirectoryTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.directory = Path(self.temp.name)
        for name in validator.FILES:
            (self.directory / name).write_text(HTML, encoding='utf-8')

    def test_only_four_required_files_are_checked_and_logo_can_follow(self):
        (self.directory / 'index.html').write_text('Gallery placeholder')
        (self.directory / 'preview.js').write_text('Gallery placeholder')
        result = validator.validate_directory(self.directory)
        self.assertEqual(result['status'], 'success')
        self.assertEqual(result['files'], list(validator.FILES))
        self.assertTrue(all(result['checks'].values()))

    def test_missing_and_non_regular_files_are_rejected(self):
        path = self.directory / 'note.html'
        path.unlink()
        with self.assertRaises(validator.PreviewRejected):
            validator.validate_directory(self.directory)
        path.symlink_to(self.directory / 'processing.html')
        with self.assertRaises(validator.PreviewRejected):
            validator.validate_directory(self.directory)
        path.unlink()
        os.link(self.directory / 'processing.html', path)
        with self.assertRaises(validator.PreviewRejected):
            validator.validate_directory(self.directory)
        path.unlink()
        os.mkfifo(path)
        with self.assertRaises(validator.PreviewRejected):
            validator.validate_directory(self.directory)

    def test_symlinked_directory_is_rejected(self):
        link = self.directory / 'linked'
        link.symlink_to(self.directory, target_is_directory=True)
        with self.assertRaises(validator.PreviewRejected):
            validator.validate_directory(link)

    def test_invalid_encoding_and_size_are_rejected(self):
        path = self.directory / 'note.html'
        path.write_bytes(b'\xff')
        with self.assertRaises(validator.PreviewRejected) as caught:
            validator.validate_directory(self.directory)
        self.assertEqual(caught.exception.category, 'encoding')
        path.write_bytes(b'x' * (validator.MAX_BYTES + 1))
        with self.assertRaises(validator.PreviewRejected):
            validator.validate_directory(self.directory)

    def test_cli_report_is_finite_json_and_does_not_echo_source_or_path(self):
        result = subprocess.run([sys.executable, str(SCRIPT), str(self.directory)],
                                capture_output=True, text=True, check=False)
        self.assertEqual(result.returncode, 0)
        self.assertEqual(json.loads(result.stdout)['status'], 'success')
        self.assertEqual(result.stderr, '')
        sentinel = 'sensitive-order-private@example.com'
        (self.directory / 'note.html').write_text(HTML.replace('Alex', sentinel))
        result = subprocess.run([sys.executable, str(SCRIPT), str(self.directory)],
                                capture_output=True, text=True, check=False)
        self.assertEqual(result.returncode, 1)
        self.assertEqual(json.loads(result.stdout), {'status': 'failure', 'category': 'sensitive_content'})
        self.assertNotIn(sentinel, result.stdout + result.stderr)
        self.assertNotIn(str(self.directory), result.stdout + result.stderr)
        self.assertEqual(result.stderr, '')


if __name__ == '__main__':
    unittest.main()
