"""Exercise private clip ownership, exact byte ranges and recoverable deletion."""
import json
import hashlib
import http.client
import os
from pathlib import Path
import shutil
import socket
import struct
import subprocess
import tempfile
import time
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = shutil.which('php') or str(ROOT.parent / '.petish-dev/bin/php')
FFMPEG = shutil.which('ffmpeg')
GETID3 = os.environ.get('PETISH_TEST_GETID3_DIR', '')


class PassportVideoTests(unittest.TestCase):
    @unittest.skipUnless(Path(PHP).is_file() and FFMPEG, 'PHP and ffmpeg are required')
    def test_private_video_lifecycle_and_ranges(self):
        with tempfile.TemporaryDirectory(prefix='petish-video-fixture-') as folder:
            clip = Path(folder) / 'clip.mp4'
            fixture = subprocess.run([FFMPEG, '-v', 'error', '-f', 'lavfi', '-i',
                                      'color=c=blue:s=160x120:r=10', '-t', '1',
                                      '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-an',
                                      '-movflags', '+faststart', '-y', str(clip)],
                                     capture_output=True, text=True, timeout=30)
            self.assertEqual(fixture.returncode, 0, fixture.stderr)
            result = subprocess.run([PHP, str(ROOT / 'tests/passport-video.php'), str(clip)],
                                    cwd=ROOT, capture_output=True, text=True, timeout=30)
            self.assertEqual(result.returncode, 0, result.stderr or result.stdout)
            self.assertEqual(result.stderr, '')
            report = json.loads(result.stdout)
            self.assertEqual(report['status'], 'success')
            self.assertGreaterEqual(len(report['checks']), 65)
            self.assertTrue(all(report['checks'].values()))

    @unittest.skipUnless(Path(PHP).is_file() and FFMPEG and GETID3,
                         'Set PETISH_TEST_GETID3_DIR to a WordPress ID3 directory for real upload tests')
    def test_actual_upload_and_wordpress_analyzer(self):
        with tempfile.TemporaryDirectory(prefix='petish-video-upload-') as folder:
            work = Path(folder)
            public = work / 'public_html'
            public.mkdir()
            source = work / 'source.mp4'
            audio = work / 'audio.mp4'
            rotated = work / 'rotated.mp4'
            long = work / 'long.mp4'
            for target, duration, sound in [(source, '1', False), (audio, '1', True), (long, '20.49', False)]:
                command = [FFMPEG, '-v', 'error', '-f', 'lavfi', '-i', 'color=c=blue:s=160x120:r=100']
                if sound:
                    command += ['-f', 'lavfi', '-i', 'anullsrc=r=44100:cl=mono']
                command += ['-t', duration, '-c:v', 'libx264', '-pix_fmt', 'yuv420p']
                command += ['-c:a', 'aac'] if sound else ['-an']
                command += ['-movflags', '+faststart', '-y', str(target)]
                generated = subprocess.run(command, capture_output=True, text=True, timeout=30)
                self.assertEqual(generated.returncode, 0, generated.stderr)
            rotated_run = subprocess.run([FFMPEG, '-v', 'error', '-i', str(source), '-c', 'copy',
                                          '-metadata:s:v:0', 'rotate=90', '-y', str(rotated)],
                                         capture_output=True, text=True, timeout=30)
            self.assertEqual(rotated_run.returncode, 0, rotated_run.stderr)
            with socket.socket() as sock:
                sock.bind(('127.0.0.1', 0))
                port = sock.getsockname()[1]
            env = dict(os.environ, PETISH_TEST_VIDEO_PUBLIC=str(public), PETISH_TEST_GETID3_DIR=GETID3)
            with (work / 'server.log').open('w+') as log:
                server = subprocess.Popen([PHP, '-d', 'upload_max_filesize=52M', '-d', 'post_max_size=53M',
                                           '-S', f'127.0.0.1:{port}', str(ROOT / 'tests/passport-video-http.php')],
                                          env=env, cwd=ROOT, stdout=log, stderr=log)
                try:
                    for attempt in range(50):
                        try:
                            connection = http.client.HTTPConnection('127.0.0.1', port, timeout=2)
                            connection.request('GET', '/')
                            response = connection.getresponse()
                            self.assertEqual(response.status, 200)
                            response.read()
                            connection.close()
                            break
                        except ConnectionRefusedError:
                            time.sleep(0.02)
                    else:
                        self.fail('PHP test server did not start')

                    def upload(payload, name='clip.mp4', content_type='video/mp4'):
                        boundary = 'PetishOwnedSyntheticFixture'
                        body = (f'--{boundary}\r\nContent-Disposition: form-data; name="video"; filename="{name}"\r\n'
                                f'Content-Type: {content_type}\r\n\r\n').encode() + payload + f'\r\n--{boundary}--\r\n'.encode()
                        connection = http.client.HTTPConnection('127.0.0.1', port, timeout=30)
                        connection.request('POST', '/', body, {'Content-Type': f'multipart/form-data; boundary={boundary}'})
                        response = connection.getresponse()
                        raw = response.read()
                        connection.close()
                        self.assertEqual(response.status, 200, raw)
                        result = json.loads(raw)
                        self.assertEqual(result['remaining'], 0, result)
                        return result

                    for fixture in [source, audio, rotated]:
                        data = fixture.read_bytes()
                        result = upload(data, content_type='application/octet-stream')
                        self.assertEqual(result['status'], 'saved', (fixture.name, result))
                        self.assertEqual(result['sha256'], hashlib.sha256(data).hexdigest())
                        self.assertEqual(result['bytes'], len(data))
                        self.assertEqual(result['mode'], 0o600)
                        self.assertTrue(result['reserved_before_write'])
                        self.assertLessEqual(result['duration'], 20)
                    data = source.read_bytes()
                    padding_size = 50 * 1024 * 1024 - len(data)
                    at_limit = data + struct.pack('>I4s', padding_size, b'free') + b'\0' * (padding_size - 8)
                    self.assertEqual(upload(at_limit)['status'], 'saved')
                    over_limit = at_limit + struct.pack('>I4s', 8, b'free')
                    self.assertEqual(upload(over_limit)['code'], 'petish_video_size')
                    self.assertEqual(upload(long.read_bytes())['status'], 'error')
                    self.assertEqual(upload(b'Not a video, even with an MP4 MIME type.')['status'], 'error')
                    self.assertEqual(upload(data[:-9])['status'], 'error')
                    self.assertEqual(upload(data, 'clip.mov')['code'], 'petish_video_type')
                finally:
                    server.terminate()
                    server.wait(timeout=10)
                log.seek(0)
                self.assertNotIn('PHP Warning:', log.read())


if __name__ == '__main__':
    unittest.main()
