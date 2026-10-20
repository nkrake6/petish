"""Public logo verification tolerates lossless encoding changes, not artwork changes."""
import importlib.util
import io
from pathlib import Path
import unittest
from unittest.mock import patch

from PIL import Image

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('public_email_health', ROOT/'scripts/verify-live-email-release.py')
HEALTH = importlib.util.module_from_spec(spec)
spec.loader.exec_module(HEALTH)
LOGO = ROOT/'wordpress/wp-content/themes/dt-the7-child/assets/brand/repaired/combined-light-v2.png'


class LiveEmailHealthTests(unittest.TestCase):
    def check_image(self, image_bytes):
        def fetch(path):
            if path == HEALTH.LOGO:
                return image_bytes
            return b'<html>Petish woocommerce product cart login <a href="/product/sample/">Product</a></html>'
        checks = {}
        with patch.object(HEALTH, 'fetch', fetch):
            try:
                result = HEALTH.verify(checks)
                return result, checks
            except ValueError:
                return None, checks

    def test_original_and_losslessly_recompressed_logo_both_pass(self):
        original = LOGO.read_bytes()
        with Image.open(io.BytesIO(original)) as image:
            output = io.BytesIO()
            image.save(output, format='PNG', compress_level=0)
        recompressed = output.getvalue()
        self.assertNotEqual(original, recompressed)
        for contents in (original, recompressed):
            with self.subTest(original=contents is original):
                result, checks = self.check_image(contents)
                self.assertEqual(result['status'], 'success')
                self.assertTrue(checks['logo_http_pixels_match'])
                self.assertTrue(checks['approved_logo_http_verified'])

    def test_changed_transparency_or_dimensions_cannot_pass(self):
        for resize in (False, True):
            with Image.open(LOGO) as source:
                image = source.convert('RGBA')
                if resize:
                    image = image.resize((768,512))
                else:
                    red,green,blue,alpha = image.getpixel((0,0))
                    image.putpixel((0,0),(red,green,blue,255-alpha))
                output = io.BytesIO(); image.save(output, format='PNG')
            result, checks = self.check_image(output.getvalue())
            self.assertIsNone(result)
            self.assertFalse(checks['approved_logo_http_verified'])
            self.assertFalse(checks['logo_http_pixels_match'])

    def test_page_error_blocks_release_before_image_verification(self):
        checks = {}
        with patch.object(HEALTH, 'fetch', return_value=b'<html>There has been a critical error</html>'):
            with self.assertRaises(ValueError):
                HEALTH.verify(checks)
        self.assertFalse(checks['homepage_http_verified'])


if __name__ == '__main__':
    unittest.main()
