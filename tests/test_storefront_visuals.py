"""Verify bounded payment crops are re-encoded without metadata or arbitrary files."""
import importlib.util
import io
import os
from pathlib import Path
import tempfile
import unittest
from PIL import Image, PngImagePlugin

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('storefront_visuals', ROOT / 'scripts/inspect-storefront-runtime.py')
screen = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(screen)


class PaymentCropTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.directory = Path(self.temporary.name)
        (self.directory / 'public').mkdir(mode=0o700)
        self.browser = {'checks': dict.fromkeys(('fresh_guest_context', 'initial_cart_empty', 'network_barrier_enforced', 'created_guest_cart_cleared'), True)}
        self.browser['observations'] = dict.fromkeys(('product_payment_crop_saved', 'cart_payment_crop_saved'), True)

    def png(self, name='product-afterpay.private.png', size=(440, 120), mode=0o600):
        source = self.directory / name
        metadata = PngImagePlugin.PngInfo()
        metadata.add_text('private', 'private-config-token-sentinel')
        image = Image.new('RGBA', size, (20, 30, 40, 180))
        image.save(source, pnginfo=metadata)
        source.chmod(mode)
        return source

    def test_only_two_fixed_payment_crops_are_published_without_any_source_metadata(self):
        for name in ('product-afterpay.private.png', 'cart-payments.private.png', 'customer-private.png'):
            self.png(name)
        facts, valid = screen.publish_payment_crops(self.directory, self.browser)
        self.assertTrue(valid)
        self.assertEqual(set(path.name for path in (self.directory / 'public').iterdir()), {'product-afterpay.png', 'cart-payments.png'})
        for name, fact in facts.items():
            self.assertTrue(fact['published'])
            self.assertEqual((fact['width'], fact['height']), (440, 120))
            path = self.directory / 'public' / screen.PAYMENT_CROPS[name][1]
            self.assertNotIn(b'private-config-token-sentinel', path.read_bytes())
            self.assertEqual(path.stat().st_mode & 0o777, 0o600)
            with Image.open(path) as image:
                self.assertEqual(image.format, 'PNG')
                self.assertEqual(image.info, {})

    def test_missing_fresh_guest_empty_cart_or_cleanup_proof_never_publishes(self):
        self.png()
        for guard in self.browser['checks']:
            with self.subTest(guard=guard):
                flags = {**self.browser['checks'], guard: False}
                facts, valid = screen.publish_payment_crops(self.directory, {'checks': flags, 'observations': self.browser['observations']})
                self.assertTrue(valid)
                self.assertFalse(any(value['published'] for value in facts.values()))
                self.assertEqual(list((self.directory / 'public').iterdir()), [])

    def test_an_unattested_existing_image_is_never_published(self):
        self.png()
        self.browser['observations']['product_payment_crop_saved'] = False
        facts, valid = screen.publish_payment_crops(self.directory, self.browser)
        self.assertTrue(valid)
        self.assertFalse(facts['product_afterpay']['published'])
        self.assertEqual(list((self.directory / 'public').iterdir()), [])

    def test_symlinks_hardlinks_metadata_only_and_out_of_bounds_images_are_rejected(self):
        source = self.png()
        for case in ('symlink', 'hardlink', 'html', 'large', 'tiny', 'permissions'):
            with self.subTest(case=case):
                source.unlink(missing_ok=True)
                if case == 'symlink':
                    target = self.png('other.png')
                    source.symlink_to(target)
                elif case == 'hardlink':
                    target = self.png('other.png')
                    os.link(target, source)
                elif case == 'html':
                    source.write_bytes(b'<html>' + b'private-config-token-sentinel' * 10)
                    source.chmod(0o600)
                elif case == 'large':
                    self.png(size=(1601, 100))
                elif case == 'tiny':
                    self.png(size=(1, 1))
                else:
                    self.png(mode=0o644)
                facts, valid = screen.publish_payment_crops(self.directory, self.browser)
                self.assertFalse(valid)
                self.assertFalse(facts['product_afterpay']['published'])
                self.assertEqual(list((self.directory / 'public').iterdir()), [])

    def test_optional_absent_crops_do_not_require_pillow_or_publish_arbitrary_files(self):
        (self.directory / 'private.html').write_text('private-config-token-sentinel')
        facts, valid = screen.publish_payment_crops(self.directory, None)
        self.assertTrue(valid)
        self.assertFalse(any(value['published'] for value in facts.values()))
        self.assertEqual(list((self.directory / 'public').iterdir()), [])


if __name__ == '__main__':
    unittest.main()
