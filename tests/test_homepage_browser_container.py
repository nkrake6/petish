"""Exercise the real Docker wrapper's command boundary using a recording double."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / 'scripts/homepage-browser-container.sh'
DIGEST = 'mcr.microsoft.com/playwright@sha256:' + 'a' * 64
FAKE_DOCKER = '''#!/usr/bin/env python3
import json,os,sys
from pathlib import Path
args=sys.argv[1:]
with Path(os.environ['PETISH_CONTAINER_TEST_LOG']).open('a') as stream:stream.write(json.dumps(args)+'\\n')
if args[:2]==['image','inspect']:print(os.environ.get('PETISH_CONTAINER_TEST_DIGESTS', '[]'))
'''


class HomepageBrowserContainerTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='petish-container-boundary-')
        self.addCleanup(self.temp.cleanup)
        self.directory = Path(self.temp.name)
        self.bin = self.directory / 'bin'; self.bin.mkdir()
        docker = self.bin / 'docker'; docker.write_text(FAKE_DOCKER); docker.chmod(0o700)
        self.log = self.directory / 'calls'
        self.work = self.directory / 'work'; self.work.mkdir(mode=0o700)
        self.env = {**os.environ, 'PATH': str(self.bin) + ':' + os.environ['PATH'],
                    'HOMEPAGE_MODE': 'staging', 'RUNNER_TEMP': str(self.directory),
                    'PETISH_CONTAINER_TEST_LOG': str(self.log),
                    'PETISH_CONTAINER_TEST_DIGESTS': json.dumps([DIGEST]),
                    'PETISH_HOMEPAGE_BROWSER_IMAGE': DIGEST,
                    'SSHPASS': 'private-sentinel', 'GITHUB_TOKEN': 'private-sentinel',
                    'SSH_AUTH_SOCK': '/private-sentinel/socket'}

    def run_script(self, *args, **env):
        return subprocess.run(['bash', str(SCRIPT), *map(str, args)], cwd=ROOT,
                              env={**self.env, **env}, capture_output=True, text=True, timeout=10)

    def calls(self):
        return [json.loads(line) for line in self.log.read_text().splitlines()] if self.log.exists() else []

    def assert_container(self, args, work, script, offline=False):
        self.assertEqual(args[:3], ['run', '--rm', '--init'])
        self.assertIn('--read-only', args)
        self.assertEqual(args[args.index('--pull') + 1], 'never')
        for flag, value in [('--user', f'{os.getuid()}:{os.getgid()}'), ('--cap-drop', 'ALL'),
                            ('--security-opt', 'no-new-privileges'), ('--shm-size', '1g'),
                            ('--workdir', str(ROOT))]:
            self.assertEqual(args[args.index(flag) + 1], value)
        mounts = [args[i + 1] for i, arg in enumerate(args) if arg == '--mount']
        self.assertEqual(mounts, [f'type=bind,source={ROOT},target={ROOT},readonly',
                                 f'type=bind,source={work},target={work}',
                                 f'type=bind,source={work}/browser-git-mask,target={ROOT}/.git,readonly'])
        envs = [args[i + 1] for i, arg in enumerate(args) if arg == '--env']
        self.assertEqual(envs, ['PLAYWRIGHT_BROWSERS_PATH=/ms-playwright', 'HOME=/tmp', 'TMPDIR=/tmp'])
        self.assertNotIn('private-sentinel', json.dumps(args))
        self.assertNotIn('--privileged', args)
        self.assertNotIn('--ipc=host', args)
        index = args.index(DIGEST)
        self.assertEqual(args[index + 1:index + 3], ['node', script])
        if offline:self.assertEqual(args[args.index('--network') + 1], 'none')
        else:self.assertNotIn('--network', args)

    def test_prepare_pulls_versioned_official_image_then_smokes_immutable_digest(self):
        result = self.run_script('prepare')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout, DIGEST + '\n')
        calls = self.calls()
        self.assertEqual(calls[0], ['pull', 'mcr.microsoft.com/playwright:v1.62.1-noble'])
        self.assertEqual(calls[1][-1], 'mcr.microsoft.com/playwright:v1.62.1-noble')
        mounts = [calls[2][i + 1] for i, arg in enumerate(calls[2]) if arg == '--mount']
        work = mounts[1].split(',source=', 1)[1].split(',target=', 1)[0]
        self.assert_container(calls[2], work, 'tools/homepage/container-smoke.mjs', offline=True)
        self.assertFalse(Path(work).exists())

    def test_foreign_repository_missing_or_ambiguous_digest_fails_before_container(self):
        for values in [[], ['other.invalid/playwright@sha256:' + 'a' * 64],
                       ['mcr.microsoft.com/playwright@sha256:bad'], [DIGEST, DIGEST[:-1] + 'b']]:
            self.log.unlink(missing_ok=True)
            result = self.run_script('prepare', PETISH_CONTAINER_TEST_DIGESTS=json.dumps(values))
            self.assertNotEqual(result.returncode, 0)
            self.assertTrue(all(args[0] != 'run' for args in self.calls()))

    def test_runtime_keeps_private_paths_and_passes_only_explicit_browser_environment(self):
        input_path = self.work / 'cart-browser-input.json'; input_path.write_text('{}'); input_path.chmod(0o600)
        output = self.work / 'cart_browser.private.json'
        result = self.run_script('run', self.work, 'tools/homepage/cart-badge-browser.mjs', input_path, output)
        self.assertEqual(result.returncode, 0, result.stderr)
        args = self.calls()[0]
        self.assert_container(args, self.work, 'tools/homepage/cart-badge-browser.mjs')
        self.assertEqual(args[-2:], [str(input_path), str(output)])
        self.assertEqual(list((self.work / 'browser-git-mask').iterdir()), [])

    def test_production_and_unreviewed_image_or_script_never_start_docker(self):
        input_path = self.work / 'browser-input.json'; input_path.write_text('{}')
        base = ['run', self.work, 'tools/homepage/browser.mjs', input_path, self.work / 'report.json']
        for mode in ['inspect', 'apply']:
            self.assertNotEqual(self.run_script(*base, HOMEPAGE_MODE=mode).returncode, 0)
        self.assertNotEqual(self.run_script(*base, PETISH_HOMEPAGE_BROWSER_IMAGE='other.invalid:latest').returncode, 0)
        self.assertNotEqual(self.run_script('run', self.work, 'scripts/hostinger-homepage.sh', input_path, self.work / 'report.json').returncode, 0)
        self.assertEqual(self.calls(), [])

    def test_foreign_mask_content_is_preserved_and_not_mounted(self):
        mask = self.work / 'browser-git-mask'; mask.mkdir(); foreign = mask / 'foreign'; foreign.write_text('preserve')
        result = self.run_script('smoke', DIGEST, self.work)
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(foreign.read_text(), 'preserve')
        self.assertEqual(self.calls(), [])

    def test_both_engines_and_existing_release_gates_remain_required(self):
        smoke = (ROOT / 'tools/homepage/container-smoke.mjs').read_text()
        self.assertIn('for(const engine of [chromium,webkit])', smoke)
        self.assertIn("context.route('**/*',route=>route.abort", smoke)
        source = (ROOT / 'scripts/hostinger-homepage.sh').read_text()
        for script in ['tools/homepage/browser.mjs', 'tools/homepage/cart-badge-browser.mjs']:
            self.assertIn('bash scripts/homepage-browser-container.sh run "$work" ' + script, source)
        workflow = (ROOT / '.github/workflows/homepage.yml').read_text()
        self.assertIn('timeout --kill-after=5s 90s npm ci', workflow)
        self.assertIn('browser_image=$(bash scripts/homepage-browser-container.sh prepare)', workflow)
        self.assertNotIn('install --with-deps chromium webkit', workflow)
        self.assertIn('timeout --kill-after=5s 240s docker pull', SCRIPT.read_text())
        self.assertIn('timeout --kill-after=5s 90s bash "$0" smoke', SCRIPT.read_text())


if __name__ == '__main__':
    unittest.main()
