"""Exercise release failure recovery with fake SSH/Git, never a real host."""
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SHA = 'a' * 40
spec = importlib.util.spec_from_file_location('review_release_gate', ROOT / 'scripts/validate-customer-reviews-release.py')
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)


class ProductionDriverTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix='review-driver-test-')
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        for name in ['scripts', 'operations', 'bin', 'runner']:
            (self.root / name).mkdir()
        for name in ['hostinger-customer-reviews-production.sh', 'sanitize-customer-reviews-production.py',
                     'deploy-customer-reviews-remote.php', 'verify-customer-reviews.php']:
            shutil.copyfile(ROOT / 'scripts' / name, self.root / 'scripts' / name)
        self.write('scripts/validate-customer-reviews-release.py', '''import json,os,sys
from pathlib import Path
FILES=''' + repr(gate.FILES) + '''
PREFIX='wordpress/wp-content/plugins/petish-passport/'
if __name__=='__main__':
 with Path(os.environ['TEST_EVENTS']).open('a') as out:out.write('evidence\\n')
 if os.environ['TEST_FAILURE']=='evidence':raise SystemExit(1)
 Path(sys.argv[2]).write_text(json.dumps({'verified':True,'backup_branch':'full-site-backup/1-1','backup_result_branch':'automation-status/backup-1-1','recovery_result_branch':'automation-status/recovery-2-1','staging_status_branch':'automation-status/customer-reviews-3-1','staging_source_commit':'a'*40}))
''')
        self.write('scripts/verify-customer-reviews-public.py', '''import json,os,sys
from pathlib import Path
CHECKS={'public_verified'}
PROBE_CHECKS={'targeted_cache_purge_dispatched','only_probe_get_used'}
if __name__=='__main__':
 phase='rollback-purge' if len(sys.argv)==5 else 'public'
 token=Path(sys.argv[3]);assert token.stat().st_mode&0o777==0o600 and len(token.read_text())==65
 with Path(os.environ['TEST_EVENTS']).open('a') as out:out.write(phase+'\\n')
 failed=os.environ['TEST_FAILURE'] in {'public','rollback','rollback-purge'} and phase=='public'
 failed=failed or (os.environ['TEST_FAILURE']=='rollback-purge' and phase=='rollback-purge')
 Path(sys.argv[1]).write_text(json.dumps({'status':'failure' if failed else 'success','category':'assets' if failed else 'complete','checks':dict.fromkeys(PROBE_CHECKS if phase=='rollback-purge' else CHECKS,not failed)}))
 raise SystemExit(1 if failed else 0)
''')
        self.write('scripts/hostinger-ssh-common.sh', "petish_prepare_ssh() { petish_ssh=(python3 scripts/fake-remote.py); }\n")
        self.write('scripts/fake-remote.py', '''import hashlib,importlib.util,json,os,re,sys
from pathlib import Path
spec=importlib.util.spec_from_file_location('screen','scripts/sanitize-customer-reviews-production.py');screen=importlib.util.module_from_spec(spec);spec.loader.exec_module(screen)
command=sys.argv[1]
match=re.search(r"deploy-customer-reviews-remote.php' '([a-z-]+)'",command)
if match:
 phase=match.group(1);allowed,required,_,count=screen.deploy_schema()
 checks={key:key in required[phase] for key in allowed}
 failed=os.environ['TEST_FAILURE']==phase
 if phase=='rollback' and os.environ['TEST_FAILURE']=='apply':checks['rollback_not_needed']=True
 value={'status':'failure' if failed else 'success','category':'deployment' if failed else 'complete','checks':checks,'changed_file_count':count}
elif 'verify-customer-reviews.php' in command:
 phase='verify';failed=os.environ['TEST_FAILURE']=='verify';keys=screen.names(screen.constant_body(screen.VERIFY,'PETISH_CUSTOMER_REVIEWS_VERIFY_CHECKS'))
 value={'status':'failure' if failed else 'success','category':'contracts' if failed else 'complete','checks':dict.fromkeys(keys,not failed)}
elif 'sha256sum' in command:
 phase='staging';failed=False;value=None
 names=re.search(r'for name in (.*?); do',command,re.S).group(1).split()
 for name in names:print(hashlib.sha256(b'synthetic candidate\\n').hexdigest()+'  /home/u839205597/domains/staging.petish.biz/public_html/wp-content/plugins/petish-passport/'+name)
elif 'tar --extract' in command:
 phase='upload';failed=False;value=None;sys.stdin.buffer.read()
elif 'rm -rf' in command:phase='remove';failed=False;value=None
else:phase='setup';failed=False;value=None
with Path(os.environ['TEST_EVENTS']).open('a') as out:out.write(phase+'\\n')
if value is not None:print(json.dumps(value))
raise SystemExit(1 if failed else 0)
''')
        self.write('bin/ssh-keyscan', '#!/bin/sh\nprintf "synthetic host key\\n"\n', executable=True)
        self.write('bin/git', '''#!/usr/bin/env python3
import os,shutil,sys
from pathlib import Path
args=sys.argv[1:]
if args[:2]==['rev-parse','HEAD']:print('a'*40)
elif args[0]=='show':print('synthetic candidate')
elif args[0]=='hash-object':shutil.copyfile(args[-1],os.environ['TEST_REPORT']);print('b'*40)
elif args[0] in {'mktree','commit-tree'}:sys.stdin.read();print('c'*40)
elif args[0]=='push':
 with Path(os.environ['TEST_EVENTS']).open('a') as out:out.write('publish\\n')
else:raise SystemExit(1)
''', executable=True)

    def write(self, relative, text, executable=False):
        target = self.root / relative
        target.write_text(text)
        target.chmod(0o700 if executable else 0o600)

    def run_driver(self, failure='', mode='apply'):
        env = dict(os.environ, PATH=str(self.root / 'bin') + ':' + os.environ['PATH'],
                   RUNNER_TEMP=str(self.root / 'runner'), GITHUB_RUN_ID='999', GITHUB_RUN_ATTEMPT='1',
                   GITHUB_SHA=SHA, CUSTOMER_REVIEWS_MODE=mode, CUSTOMER_REVIEWS_PREREQUISITES='success',
                   SSH_HOST='82.29.81.104', SSH_PORT='65002', SSH_USER='u839205597',
                   TEST_FAILURE=failure, TEST_EVENTS=str(self.root / 'events'), TEST_REPORT=str(self.root / 'report.json'))
        process = subprocess.run(['bash', 'scripts/hostinger-customer-reviews-production.sh'], cwd=self.root,
                                 env=env, capture_output=True, text=True, timeout=30)
        events = (self.root / 'events').read_text().splitlines()
        report = json.loads((self.root / 'report.json').read_text())
        return process, events, report

    def test_success_is_committed_only_after_every_verification(self):
        process, events, report = self.run_driver()
        self.assertEqual(process.returncode, 0, process.stderr)
        self.assertEqual(events, ['evidence','setup','upload','inspect','staging','apply','enable','verify','resume','public','commit','remove','publish'])
        self.assertEqual(report['status'], 'success')
        self.assertFalse(report['rollback_attempted'])
        self.assertEqual(len(report['source_hashes']), 15)

    def test_inspect_mode_never_applies_or_enables(self):
        process, events, report = self.run_driver(mode='inspect')
        self.assertEqual(process.returncode, 0, process.stderr)
        self.assertEqual(events, ['setup','upload','inspect','remove','publish'])
        self.assertEqual(report['mode'], 'inspect')

    def test_failed_evidence_makes_no_host_connection(self):
        process, events, report = self.run_driver('evidence')
        self.assertNotEqual(process.returncode, 0)
        self.assertEqual(events, ['evidence','publish'])
        self.assertEqual(report['category'], 'evidence')

    def test_failed_public_proof_restores_then_purges_before_removing_tools(self):
        process, events, report = self.run_driver('public')
        self.assertNotEqual(process.returncode, 0)
        self.assertEqual(events[-5:], ['rollback','rollback-purge','rollback-finalize','remove','publish'])
        self.assertNotIn('commit', events)
        self.assertTrue(report['rollback_verified'])

    def test_failed_rollback_keeps_recovery_tools(self):
        process, events, report = self.run_driver('rollback')
        self.assertNotEqual(process.returncode, 0)
        self.assertEqual(events[-2:], ['rollback','publish'])
        self.assertNotIn('remove', events)
        self.assertFalse(report['rollback_verified'])
        self.assertEqual(report['category'], 'recovery_required')

    def test_failed_rollback_purge_keeps_probe_and_tools(self):
        process, events, report = self.run_driver('rollback-purge')
        self.assertNotEqual(process.returncode, 0)
        self.assertEqual(events[-3:], ['rollback','rollback-purge','publish'])
        self.assertNotIn('rollback-finalize', events)
        self.assertNotIn('remove', events)
        self.assertEqual(report['category'], 'recovery_required')

    def test_uncertain_apply_failure_always_attempts_rollback(self):
        process, events, report = self.run_driver('apply')
        self.assertNotEqual(process.returncode, 0)
        self.assertEqual(events[-4:], ['apply','rollback','remove','publish'])
        self.assertTrue(report['rollback_verified'])


if __name__ == '__main__':
    unittest.main()
