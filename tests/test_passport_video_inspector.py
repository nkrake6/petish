"""Exercise pure-PHP MP4 validation using generated H.264/AAC fixtures, no network."""
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = shutil.which('php') or str(ROOT.parent / '.petish-dev/bin/php')
FFMPEG = shutil.which('ffmpeg')


class PassportVideoInspectorTests(unittest.TestCase):
    @unittest.skipUnless(Path(PHP).is_file() and FFMPEG, 'PHP and ffmpeg are required')
    def test_real_mp4_timing_codec_structure_and_resource_limits(self):
        with tempfile.TemporaryDirectory(prefix='petish-video-inspector-') as folder:
            for name, duration, rate, audio, size in (
                ('valid', '1.00', '25', False, '16x16'),
                ('limit', '20.00', '25', False, '16x16'),
                ('long', '20.49', '100', False, '16x16'),
                ('audio', '19.50', '25', True, '16x16'),
                ('rectangle', '1.00', '25', False, '320x240'),
            ):
                args = [FFMPEG, '-hide_banner', '-loglevel', 'error', '-f', 'lavfi',
                        '-i', f'color=c=purple:s={size}:r={rate}']
                if audio:
                    args += ['-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=44100']
                args += ['-t', duration, '-c:v', 'libx264', '-threads', '1',
                         '-pix_fmt', 'yuv420p', '-flags', '+bitexact']
                if audio:
                    args += ['-c:a', 'aac', '-flags:a', '+bitexact']
                args += ['-fflags', '+bitexact', '-map_metadata', '-1', '-movflags',
                         '+faststart', '-y', str(Path(folder) / f'{name}.mp4')]
                generated = subprocess.run(args, capture_output=True, text=True, timeout=30)
                self.assertEqual(generated.returncode, 0, generated.stderr)
            result = subprocess.run([PHP, str(ROOT / 'tests/passport-video-inspector.php'), folder],
                                    cwd=ROOT, capture_output=True, text=True, timeout=30)
            self.assertEqual(result.returncode, 0, result.stderr or result.stdout)
            self.assertEqual(result.stderr, '')
            report = json.loads(result.stdout)
            self.assertEqual(report['status'], 'success')
            self.assertGreaterEqual(len(report['checks']), 75)
            self.assertTrue(all(report['checks'].values()))


if __name__ == '__main__':
    unittest.main()
