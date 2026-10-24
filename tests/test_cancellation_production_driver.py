"""Production release boundaries with local fake SSH/Git; never a real host."""
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import time
import unittest
from unittest.mock import patch
from types import SimpleNamespace

import yaml

ROOT = Path(__file__).resolve().parents[1]
SHA = 'a' * 40
PRIVATE = 'PRIVATE-CUSTOMER-AND-TRANSPORT-SENTINEL'


def module(name):
    spec = importlib.util.spec_from_file_location(name.replace('-', '_'), ROOT / 'scripts' / (name + '.py'))
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


class ProductionDriverTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='cancellation-production-driver-')
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        for name in ('scripts', 'operations', 'bin', 'runner'):
            (self.root / name).mkdir()
        self.configure()

    def write(self, name, value, executable=False):
        path = self.root / name
        path.write_text(value)
        path.chmod(0o700 if executable else 0o600)

    def transport(self, files, prefix):
        """Only these fixed local fake programs can cross transport boundaries."""
        self.write('sitecustomize.py', '''import os,socket,sys
def blocked(*args,**kwargs):raise RuntimeError('Network disabled in production driver fixture.')
socket.create_connection=blocked
socket.socket.connect=blocked
socket.socket.connect_ex=blocked
if len(sys.argv)>1 and sys.argv[1] in {'probe-purge','probe-rollback-purge'}:
 import re,urllib.request
 from pathlib import Path
 class Response:
  def __init__(self,url,failed):self.url=url;self.status=200;self.headers={'X-Petish-Cancellation-Cache-Purge':'failed' if failed else 'dispatched'}
  def __enter__(self):return self
  def __exit__(self,*args):return False
  def geturl(self):return self.url
  def read(self,limit):return b'Synthetic signed cache probe.\\n'
 class Opener:
  def open(self,request,timeout):
   assert request.full_url=='https://petish.biz/wp-admin/admin-post.php?action=petish_cancellation_release&run=999-1'
   assert request.get_method()=='GET'
   headers={key.lower():value for key,value in request.header_items()}
   assert re.fullmatch('[a-f0-9]{64}',headers['x-petish-release']) and 'cookie' not in headers
   phase='rollback-purge' if sys.argv[1]=='probe-rollback-purge' else 'purge'
   with Path(os.environ['TEST_EVENTS']).open('a') as out:out.write(phase+'\\n')
   return Response(request.full_url,os.environ['TEST_FAILURE']==phase)
 urllib.request.build_opener=lambda *args:Opener()
''')
        self.write('scripts/hostinger-ssh-common.sh', 'petish_prepare_ssh() { petish_ssh=(python3 scripts/fake-remote.py); }\n')
        self.write('bin/ssh-keyscan', '#!/bin/sh\nprintf "synthetic host key\\n"\n', executable=True)
        for name in ('ssh', 'sshpass', 'curl', 'wget'):
            self.write('bin/' + name, '#!/bin/sh\nexit 91\n', executable=True)
        self.write('bin/git', '''#!/usr/bin/env python3
import hashlib,os,shutil,sys
from pathlib import Path
args=sys.argv[1:];base=Path(os.environ['TEST_ROOT'])
if args[:2]==['rev-parse','HEAD']:print('0'*40 if os.environ['TEST_FAILURE']=='head' else 'a'*40)
elif args[0]=='show':
 name=args[-1].split(':',1)[1]
 sys.stdout.buffer.write((base/name).read_bytes())
elif args[0]=='ls-tree':
 path=args[-1];data=(base/path).read_bytes();blob=hashlib.sha1(data).hexdigest()
 mode='120000' if os.environ['TEST_FAILURE']=='symlink' else '100644'
 sys.stdout.buffer.write((mode+' blob '+blob+'\\t'+path+'\\0').encode())
elif args[0]=='cat-file':
 files=[path for path in (base/'wordpress/wp-content').rglob('*') if path.is_file()]
 selected=[path for path in files if hashlib.sha1(path.read_bytes()).hexdigest()==args[-1]]
 if not selected:raise SystemExit(93)
 data=selected[0].read_bytes()
 if args[1]=='-s':print(1048577 if os.environ['TEST_FAILURE']=='oversized' else len(data))
 elif args[1]=='blob':sys.stdout.buffer.write(data+(b'changed' if os.environ['TEST_FAILURE']=='blobdrift' else b''))
 else:raise SystemExit(94)
elif args[0]=='hash-object':shutil.copyfile(args[-1],os.environ['TEST_REPORT']);print('b'*40)
elif args[0] in {'mktree','commit-tree'}:sys.stdin.read();print('c'*40)
elif args[0] in {'read-tree','update-index'}:pass
elif args[0]=='write-tree':print('c'*40)
elif args[0]=='push':
 with Path(os.environ['TEST_EVENTS']).open('a') as out:out.write('publish\\n')
 print('PRIVATE-CUSTOMER-AND-TRANSPORT-SENTINEL',file=sys.stderr)
 if os.environ['TEST_FAILURE']=='publish':raise SystemExit(1)
else:raise SystemExit(92)
''', executable=True)
        self.files = tuple(files)
        self.prefix = prefix

    def configure(self):
        gate = module('validate-cancellation-production')
        for source in (ROOT / 'scripts').iterdir():
            if source.is_file() and source.suffix in {'.php', '.py', '.sh'}:
                shutil.copyfile(source, self.root / 'scripts' / source.name)
        self.transport(gate.FILES, gate.PREFIX)
        for name in gate.FILES:
            target = self.root / (gate.PREFIX + name)
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes((ROOT / (gate.PREFIX + name)).read_bytes())
        (self.root / 'operations/delivery-page-content.html').write_bytes((ROOT / 'operations/delivery-page-content.html').read_bytes())
        self.write('scripts/validate-cancellation-production.py', '''import json,os,sys
from pathlib import Path
FILES=''' + repr(gate.FILES) + '''
PREFIX=''' + repr(gate.PREFIX) + '''
if os.environ.get('TEST_FAILURE')=='manifest-extra':FILES=FILES+('plugins/petish-fulfilment/private.sql',)
if __name__=='__main__':
 with Path(os.environ['TEST_EVENTS']).open('a') as out:out.write('evidence\\n')
 print('PRIVATE-CUSTOMER-AND-TRANSPORT-SENTINEL',file=sys.stderr)
 if os.environ['TEST_FAILURE']=='evidence':raise SystemExit(1)
 Path(sys.argv[2]).write_text(json.dumps({'verified':True,'backup_branch':'full-site-backup/1-1','backup_result_branch':'automation-status/backup-1-1','recovery_result_branch':'automation-status/recovery-2-1','staging_status_branch':'automation-status/cancellation-requests-3-1','staging_prepare_branch':'automation-status/cancellation-requests-4-1','staging_install_branch':'automation-status/staging-5-1','staging_source_commit':'a'*40}))
''')
        self.write('scripts/fake-remote.py', '''import hashlib,importlib.util,io,json,os,re,sys,tarfile
from pathlib import Path
def load(name):
 spec=importlib.util.spec_from_file_location(name,'scripts/'+name+'.py');module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module);return module
screen=load('sanitize-cancellation-production');gate=load('validate-cancellation-production')
def diagnostic(kind,error,trace):
 Path(os.environ['TEST_ROOT'],'fake-transport-error.txt').write_text(str(error))
sys.excepthook=diagnostic
command=sys.argv[-1];failure=os.environ['TEST_FAILURE'];value=None;failed=False
match=re.search(r"deploy-cancellation-production\\.php[\\\"']?\\s+[\\\"']?([a-z-]+)",command)
if match:
 phase=match.group(1);allowed,required,categories,count=screen.deploy_schema()
 checks={key:key in required[phase] for key in allowed};failed=failure==phase
 if failure in {'apply-invalid','verify-private','faq-invalid','inspect-private'} and phase==failure.split('-')[0]:
  failed=False;checks['private_customer']='PRIVATE-CUSTOMER-AND-TRANSPORT-SENTINEL'
 value={'status':'failure' if failed else 'success','category':next(iter(sorted(categories-{'complete'}))) if failed else 'complete','checks':checks,'changed_file_count':count}
 if failure=='apply-exit' and phase=='apply':failed=True
elif 'live-inspection.json' in command:
 phase='baseline';value={'run':'999-1','files':{name:hashlib.sha256(Path(gate.PREFIX+name).read_bytes()).hexdigest() for name in gate.FILES}}
 if failure=='baseline-private':value['customer']= 'PRIVATE-CUSTOMER-AND-TRANSPORT-SENTINEL'
elif command=='bash -s':
 script=sys.stdin.read();assert 'sha256sum' in script and 'staging.petish.biz' in script
 phase='staging'
 for index,name in enumerate(gate.FILES):
  digest=hashlib.sha256(Path(gate.PREFIX+name).read_bytes()).hexdigest()
  print(('0'*64 if failure=='staging' and index==0 else digest)+'  /home/u839205597/domains/staging.petish.biz/public_html/wp-content/'+name)
elif 'tar --extract' in command:
 phase='upload';raw=sys.stdin.buffer.read();assert len(raw)<33554432
 with tarfile.open(fileobj=io.BytesIO(raw)) as archive:
  entries=archive.getmembers();assert len(entries)<128
  assert all(not item.name.startswith('/') and '..' not in Path(item.name).parts and (item.isfile() or item.isdir()) for item in entries)
  manifests=[item for item in entries if item.name.endswith('manifest.json') and 'payload' in item.name]
  assert len(manifests)==1
  manifest=json.loads(archive.extractfile(manifests[0]).read());assert set(manifest)==set(gate.FILES)
  token=[item for item in entries if item.name=='.probe-token'];assert len(token)==1 and token[0].mode&0o777==0o600 and len(archive.extractfile(token[0]).read())==65
elif 'rm -rf' in command:phase='remove';failed=failure=='cleanup'
else:phase='setup';failed=failure=='setup'
with Path(os.environ['TEST_EVENTS']).open('a') as out:out.write(phase+'\\n')
print('PRIVATE-CUSTOMER-AND-TRANSPORT-SENTINEL',file=sys.stderr)
if failure=='apply-json' and phase=='apply':print('{"status":')
elif value is not None:print(json.dumps(value))
raise SystemExit(1 if failed else 0)
''')
        self.write('scripts/verify-live-email-release.py', '''import importlib.util,os,sys
from pathlib import Path
def verify(checks):
 spec=importlib.util.spec_from_file_location('schema','scripts/sanitize-cancellation-production.py');schema=importlib.util.module_from_spec(spec);spec.loader.exec_module(schema)
 phase='rollback-health' if sys.argv[1]=='probe-rollback-health' else 'health'
 with Path(os.environ['TEST_EVENTS']).open('a') as out:out.write(phase+'\\n')
 if os.environ['TEST_FAILURE']==phase or (phase=='health' and os.environ['TEST_FAILURE'] in {'rollback','rollback-purge','rollback-health','rollback-finalize'}):raise ValueError('PRIVATE-CUSTOMER-AND-TRANSPORT-SENTINEL')
 checks.update(dict.fromkeys(schema.HEALTH_REQUIRED,True))
def fetch(path):
 if path=='/delivery-shipping/':return b'<section class="petish-faqs" id="change-of-mind">Delivery: 14 days after delivery; unused, complete and in their original packaging; change-of-mind return postage; payment-processing costs; confirm the refund amount before processing; Australian consumer law.</section>'
 assert path.startswith('/wp-content/plugins/') and '?ver=' in path
 return Path('wordpress'+path.split('?')[0]).read_bytes()
''')

    def execute(self, failure='', mode='apply', prerequisites='success', branch=None):
        for path in (self.root / 'events', self.root / 'report.json'):
            path.unlink(missing_ok=True)
        env = dict(os.environ, PATH=str(self.root / 'bin') + ':' + os.environ['PATH'],
                   RUNNER_TEMP=str(self.root / 'runner'), GITHUB_RUN_ID='999', GITHUB_RUN_ATTEMPT='1',
                   GITHUB_SHA=SHA, GITHUB_REF_NAME=branch or ('deploy-cancellation-production' if mode == 'apply' else 'inspect-cancellation-production'),
                   CANCELLATION_PRODUCTION_MODE=mode, CANCELLATION_PRODUCTION_PREREQUISITES=prerequisites,
                   SSH_HOST='82.29.81.104', SSH_PORT='65002', SSH_USER='u839205597', SSHPASS=PRIVATE,
                   TEST_FAILURE=failure, TEST_EVENTS=str(self.root / 'events'), TEST_ROOT=str(self.root),
                   TEST_REPORT=str(self.root / 'report.json'), PYTHONPATH=str(self.root))
        process = subprocess.run(['bash', 'scripts/hostinger-cancellation-production.sh'], cwd=self.root,
                                 env=env, capture_output=True, text=True, timeout=30)
        events_path = self.root / 'events'
        events = events_path.read_text().splitlines() if events_path.exists() else []
        receipt = self.root / 'report.json'
        report = json.loads(receipt.read_text()) if receipt.exists() else None
        self.assertNotIn(PRIVATE, process.stdout + process.stderr + json.dumps(report))
        self.assertFalse(list((self.root / 'runner').glob('petish-cancellation-production.*')))
        return process, events, report

    def test_success_requires_all_phases_and_cleanup_before_publication(self):
        process, events, report = self.execute()
        self.assertEqual(process.returncode, 0, process.stderr)
        phases = [name for name in events if name in {'inspect', 'staging', 'apply', 'faq', 'verify', 'resume', 'purge', 'health', 'commit'}]
        self.assertEqual(phases, ['staging', 'inspect', 'apply', 'faq', 'verify', 'resume', 'purge', 'health', 'commit'])
        self.assertEqual(events[-2:], ['remove', 'publish'])
        self.assertEqual(report['status'], 'success')
        self.assertEqual(report['target'], 'production')
        self.assertTrue(report['staging_source_verified'])
        self.assertTrue(report['remote_tools_removed'])
        self.assertFalse(report['rollback_attempted'])
        self.assertEqual(set(report['source_hashes']), set(self.files))
        self.assertEqual(len(report['source_hashes']), 27)

    def test_inspect_never_mutates_or_probes_new_faq(self):
        process, events, report = self.execute(mode='inspect')
        self.assertEqual(process.returncode, 0, process.stderr)
        self.assertIn('inspect', events)
        self.assertFalse(set(events) & {'apply', 'faq', 'verify', 'resume', 'health', 'commit', 'rollback'})
        self.assertEqual(report['mode'], 'inspect')
        self.assertFalse(report['rollback_attempted'])

    def test_failed_evidence_prevents_every_host_connection(self):
        process, events, report = self.execute('evidence')
        self.assertNotEqual(process.returncode, 0)
        self.assertEqual(events, ['evidence', 'publish'])
        self.assertEqual(report['category'], 'evidence')

    def test_prerequisite_and_source_failure_prevent_host_connection(self):
        for failure, prerequisites in [('', 'failure'), ('head', 'success'), ('manifest-extra', 'success'),
                                       ('symlink', 'success'), ('oversized', 'success'), ('blobdrift', 'success')]:
            with self.subTest(failure=failure, prerequisites=prerequisites):
                process, events, report = self.execute(failure, prerequisites=prerequisites)
                self.assertNotEqual(process.returncode, 0)
                self.assertFalse(set(events) & {'setup', 'upload', 'inspect', 'staging', 'apply'})
                self.assertEqual(report['status'], 'failure')
                for path in (self.root / 'events', self.root / 'report.json'):
                    path.unlink(missing_ok=True)

    def test_staging_hash_drift_never_applies(self):
        process, events, report = self.execute('staging')
        self.assertNotEqual(process.returncode, 0)
        self.assertNotIn('apply', events)
        self.assertNotIn('rollback', events)
        self.assertEqual(events[-2:], ['remove', 'publish'])
        self.assertFalse(report['staging_source_verified'])

    def test_failures_after_apply_restore_before_cleanup(self):
        for failure in ('apply', 'apply-invalid', 'apply-exit', 'apply-json', 'faq', 'faq-invalid', 'verify', 'verify-private', 'resume', 'purge', 'health', 'commit'):
            with self.subTest(failure=failure):
                process, events, report = self.execute(failure)
                self.assertNotEqual(process.returncode, 0)
                self.assertIn(failure.split('-')[0], events)
                self.assertIn('rollback', events)
                self.assertLess(events.index('rollback'), events.index('remove'))
                self.assertLess(events.index('rollback-purge'), events.index('rollback-health'))
                self.assertLess(events.index('rollback-health'), events.index('rollback-finalize'))
                self.assertEqual(events[-1], 'publish')
                self.assertTrue(report['rollback_attempted'])
                self.assertTrue(report['rollback_verified'])
                self.assertEqual(report['status'], 'failure')
                for path in (self.root / 'events', self.root / 'report.json'):
                    path.unlink(missing_ok=True)

    def test_private_inspect_result_never_reaches_apply_or_public_receipt(self):
        process, events, report = self.execute('inspect-private')
        self.assertNotEqual(process.returncode, 0)
        self.assertNotIn('apply', events)
        self.assertNotIn('rollback', events)
        self.assertNotIn('inspect', report['phases'])
        self.assertEqual(events[-2:], ['remove', 'publish'])

    def test_failed_rollback_retains_private_recovery_tools(self):
        process, events, report = self.execute('rollback')
        self.assertNotEqual(process.returncode, 0)
        self.assertNotIn('remove', events)
        self.assertTrue(report['rollback_attempted'])
        self.assertFalse(report['rollback_verified'])
        self.assertFalse(report['remote_tools_removed'])
        self.assertEqual(report['category'], 'recovery_required')

    def test_failed_rollback_purge_health_or_finalize_retains_recovery_tools(self):
        for failure in ('rollback-purge', 'rollback-health', 'rollback-finalize'):
            with self.subTest(failure=failure):
                process, events, report = self.execute(failure)
                self.assertNotEqual(process.returncode, 0)
                self.assertNotIn('remove', events)
                self.assertTrue(report['rollback_attempted'])
                self.assertFalse(report['rollback_verified'])
                self.assertEqual(report['category'], 'recovery_required')

    def test_cleanup_failure_cannot_publish_success(self):
        process, events, report = self.execute('cleanup')
        self.assertNotEqual(process.returncode, 0)
        self.assertEqual(report['status'], 'failure')
        self.assertFalse(report['remote_tools_removed'])
        self.assertIn('remove', events)

    def test_apply_from_an_unapproved_branch_is_rejected_before_transport(self):
        process, events, report = self.execute(branch='main')
        self.assertNotEqual(process.returncode, 0)
        self.assertFalse(events)
        self.assertIsNone(report)

    def test_publication_failure_does_not_expose_private_output(self):
        process, events, _ = self.execute('publish')
        self.assertNotEqual(process.returncode, 0)
        self.assertEqual(events[-2:], ['remove', 'publish'])

    def test_fixture_blocks_every_unmocked_python_network_connection(self):
        env = dict(os.environ, PYTHONPATH=str(self.root))
        process = subprocess.run(['python3', '-c', "import socket; socket.create_connection(('petish.biz',443))"],
                                 cwd=self.root, env=env, capture_output=True, text=True, timeout=5)
        self.assertNotEqual(process.returncode, 0)
        self.assertIn('Network disabled in production driver fixture.', process.stderr)


class WorkflowBoundaries(unittest.TestCase):
    def test_production_workflow_is_manually_scoped_and_serialized(self):
        value = yaml.load((ROOT / '.github/workflows/cancellation-production.yml').read_text(), Loader=yaml.BaseLoader)
        self.assertEqual(set(value['on']), {'push', 'workflow_dispatch'})
        self.assertEqual(set(value['on']['push']['branches']), {'inspect-cancellation-production', 'deploy-cancellation-production'})
        self.assertEqual(value['concurrency']['group'], 'petish-production-backup-mail')
        self.assertEqual(value['concurrency']['cancel-in-progress'], 'false')
        self.assertEqual(value['permissions'], {'contents': 'write'})
        self.assertEqual(len(value['jobs']), 1)
        job = next(iter(value['jobs'].values()))
        checkout = job['steps'][0]
        self.assertRegex(checkout['uses'], r'^actions/checkout@[a-f0-9]{40}$')
        self.assertEqual(checkout['with']['ref'], '${{ github.sha }}')
        self.assertIn('always()', job['steps'][-1]['if'])
        self.assertNotIn('upload-artifact', json.dumps(value))


class FiniteProductionReports(unittest.TestCase):
    def setUp(self):
        self.screen = module('sanitize-cancellation-production')
        self.temporary = tempfile.TemporaryDirectory(prefix='cancellation-production-report-')
        self.addCleanup(self.temporary.cleanup)
        self.path = Path(self.temporary.name) / 'report.json'

    def save(self, value):
        self.path.write_text(json.dumps(value))
        return self.path

    def phase(self, phase):
        allowed, required, _, count = self.screen.deploy_schema()
        return {'status': 'success', 'category': 'complete', 'changed_file_count': count,
                'checks': {key: key in required[phase] for key in allowed}}

    def test_each_remote_phase_requires_every_one_of_its_checks(self):
        for phase in sorted(self.screen.DEPLOY_PHASES):
            good = self.phase(phase)
            self.assertEqual(self.screen.sanitize(phase, self.save(good)), good)
            for required in self.screen.deploy_schema()[1][phase]:
                with self.subTest(phase=phase, required=required):
                    value = self.phase(phase)
                    value['checks'][required] = False
                    with self.assertRaises(ValueError):
                        self.screen.sanitize(phase, self.save(value))
                    del value['checks'][required]
                    with self.assertRaises(ValueError):
                        self.screen.sanitize(phase, self.save(value))

    def test_private_fields_unknown_checks_and_non_boolean_flags_are_rejected(self):
        mutations = [lambda v: v.update(customer_email=PRIVATE), lambda v: v.update(private_path=PRIVATE),
                     lambda v: v['checks'].update(private_customer=True), lambda v: v.update(category=PRIVATE)]
        key = next(iter(self.screen.deploy_schema()[1]['apply']))
        mutations += [lambda v, item=item: v['checks'].update({key: item}) for item in (1, 'true', None, {'private': PRIVATE})]
        for mutate in mutations:
            value = self.phase('apply')
            mutate(value)
            with self.subTest(mutate=mutate), self.assertRaises(ValueError):
                self.screen.sanitize('apply', self.save(value))

    def test_file_counts_are_bounded_integers(self):
        for count in (True, -1, 28, '27', 27.0, None):
            value = self.phase('apply')
            value['changed_file_count'] = count
            with self.subTest(count=count), self.assertRaises(ValueError):
                self.screen.sanitize('apply', self.save(value))
        self.assertEqual(len(self.screen.release_files()), 27)
        self.assertEqual(sum(name.startswith('plugins/petish-fulfilment/') for name in self.screen.release_files()), 24)

    def test_linked_duplicate_and_oversized_reports_are_rejected(self):
        self.save(self.phase('inspect'))
        symlink = self.path.with_name('symlink')
        symlink.symlink_to(self.path)
        with self.assertRaises(ValueError):
            self.screen.sanitize('inspect', symlink)
        symlink.unlink()
        hardlink = self.path.with_name('hardlink')
        os.link(self.path, hardlink)
        with self.assertRaises(ValueError):
            self.screen.sanitize('inspect', self.path)
        hardlink.unlink()
        for body in ('{"status":"success","status":"failure"}', ' ' * 65537):
            self.path.write_text(body)
            with self.subTest(body=body[:32]), self.assertRaises(ValueError):
                self.screen.sanitize('inspect', self.path)

    def test_health_requires_storefront_and_policy_checks_but_rollback_excludes_new_faq(self):
        required = self.screen.HEALTH_REQUIRED | self.screen.FAQ_HEALTH
        good = {'status': 'success', 'category': 'complete', 'checks': dict.fromkeys(required, True)}
        self.assertEqual(self.screen.sanitize('health', self.save(good)), good)
        for name in required:
            value = json.loads(json.dumps(good))
            value['checks'][name] = False
            with self.subTest(name=name), self.assertRaises(ValueError):
                self.screen.sanitize('health', self.save(value))
        rollback = {'status': 'success', 'category': 'complete', 'checks': dict.fromkeys(self.screen.HEALTH_REQUIRED, True)}
        self.assertEqual(self.screen.sanitize('rollback-health', self.save(rollback)), rollback)
        with self.assertRaises(ValueError):
            self.screen.sanitize('rollback-health', self.save(good))

    def test_public_faq_probe_is_fixed_path_and_never_exposes_html(self):
        requested = []
        html = ('<section class="petish-faqs" id="change-of-mind">14 days after delivery; unused, complete and in their original packaging; '
                'change-of-mind return postage; payment-processing costs; confirm the refund amount before processing; '
                'Australian consumer law. ' + PRIVATE + '</section>')
        def verify(checks):
            checks.update(dict.fromkeys(self.screen.HEALTH_REQUIRED, True))
        def fetch(path):
            requested.append(path)
            if path == '/delivery-shipping/':
                return html.encode()
            self.assertTrue(path.startswith('/wp-content/plugins/'))
            name, digest = path.split('?ver=', 1)
            content = (ROOT / ('wordpress' + name)).read_bytes()
            import hashlib
            self.assertEqual(hashlib.sha256(content).hexdigest(), digest)
            return content
        with patch.object(self.screen, 'module', return_value=SimpleNamespace(verify=verify, fetch=fetch)):
            result = self.screen.probe_health()
            self.assertEqual(result['status'], 'success')
            self.assertEqual(requested[0], '/delivery-shipping/')
            self.assertEqual(len(requested), 5)
            self.assertNotIn(PRIVATE, json.dumps(result))
            html = html.replace('14 days after delivery', '30 days after dispatch')
            self.assertEqual(self.screen.probe_health()['status'], 'failure')

    def test_signed_purge_probe_uses_one_fixed_get_and_keeps_token_private(self):
        token = self.path.with_name('token')
        token.write_text('b' * 64 + '\n')
        token.chmod(0o600)
        calls = []
        class Response:
            status = 200
            headers = {'X-Petish-Cancellation-Cache-Purge': 'dispatched'}
            def __enter__(self): return self
            def __exit__(self, *args): return False
            def geturl(self): return calls[-1].full_url
            def read(self, size): return b'Finite signed probe.'
        class Opener:
            def open(self, request, timeout):
                calls.append(request)
                return Response()
        with patch.object(self.screen, 'module', return_value=SimpleNamespace()):
            value = self.screen.probe_purge('123-1', token, Opener())
        self.assertEqual(value['status'], 'success')
        self.assertEqual(len(calls), 1)
        self.assertEqual(calls[0].full_url, 'https://petish.biz/wp-admin/admin-post.php?action=petish_cancellation_release&run=123-1')
        self.assertEqual(calls[0].get_method(), 'GET')
        headers = {key.lower(): value for key, value in calls[0].header_items()}
        self.assertEqual(headers['x-petish-release'], 'b' * 64)
        self.assertNotIn('cookie', headers)
        self.assertNotIn('b' * 64, json.dumps(value))
        for run in ('123-1&action=delete', '../private'):
            calls.clear()
            self.assertEqual(self.screen.probe_purge(run, token, Opener())['status'], 'failure')
            self.assertEqual(calls, [])
        token.chmod(0o644)
        self.assertEqual(self.screen.probe_purge('123-1', token, Opener())['status'], 'failure')
        self.assertEqual(calls, [])

    def test_purge_rejects_redirected_oversized_or_unconfirmed_responses(self):
        token = self.path.with_name('token')
        token.write_text('b' * 64 + '\n')
        token.chmod(0o600)
        for bad in ('redirect', 'oversized', 'unconfirmed'):
            class Response:
                status = 200
                headers = {'X-Petish-Cancellation-Cache-Purge': 'failed' if bad == 'unconfirmed' else 'dispatched'}
                def __enter__(self): return self
                def __exit__(self, *args): return False
                def geturl(self): return 'https://other.invalid/' if bad == 'redirect' else 'https://petish.biz/wp-admin/admin-post.php?action=petish_cancellation_release&run=123-1'
                def read(self, size): return b'x' * 1025 if bad == 'oversized' else b'Probe.'
            class Opener:
                def open(self, request, timeout): return Response()
            with self.subTest(bad=bad), patch.object(self.screen, 'module', return_value=SimpleNamespace()):
                self.assertEqual(self.screen.probe_purge('123-1', token, Opener())['status'], 'failure')


class GeneratedCacheProbe(unittest.TestCase):
    def run_generated(self, mode='valid'):
        php = shutil.which('php') or '/workspace/.petish-dev/bin/php'
        with tempfile.TemporaryDirectory(prefix='cancellation-generated-cache-') as directory:
            base = Path(directory)
            shutil.copyfile(ROOT / 'scripts/cancellation-production-cache.php', base / 'cache.php')
            (base / '.probe-token').write_text('a' * 64 + '\n')
            (base / '.probe-token').chmod(0o600)
            if mode in {'expired', 'future'}:
                timestamp = time.time() + (-3601 if mode == 'expired' else 600)
                os.utime(base / '.probe-token', (timestamp, timestamp))
            (base / 'inspection.json').write_text(json.dumps({'run': '123-1', 'delivery_id': 55}))
            (base / 'inspection.json').chmod(0o600)
            source = r'''<?php
namespace LiteSpeed {
 class Tag {public static function get_uri_tag($path){return 'URL.'.md5($path);}}
 class Purge {
  public static $urls=[];
  public static function cls(){return new self();}
  public function purge_url($path,$a,$b){self::$urls[]=$path;}
  public static function output(){
   if($GLOBALS['fixture_mode']==='wildcard')return 'X-LiteSpeed-Purge: public,*';
   $tags=array_map(static fn($path)=>'abc_'.Tag::get_uri_tag($path),self::$urls);
   if($GLOBALS['fixture_mode']==='foreign')$tags[]='abc_URL.'.str_repeat('0',32);
   return 'X-LiteSpeed-Purge: public,'.implode(',',$tags);
  }
 }
}
namespace PetishCacheProbeFixture {function header($value){$GLOBALS['fixture_headers'][]=$value;}}
namespace {
 define('ARRAY_A','ARRAY_A');define('LSWCP_TAG_PREFIX','abc');define('ABSPATH',$argv[3]==='wrong-root'?'/tmp/owned-fixture/':'/home/u839205597/domains/petish.biz/public_html/');
 $GLOBALS['fixture_mode']=$argv[3];$GLOBALS['fixture_headers']=[];$GLOBALS['fixture_filters']=[];$GLOBALS['fixture_actions']=[];$GLOBALS['fixture_cleaned']=[];
 class WP_Error {public function __construct(...$args){}}
 class WP_Post {public $ID=55;public $post_name='delivery-shipping';public $post_content='Owned delivery HTML with FAQ.';}
 function cxl_release_fail(){throw new \RuntimeException('fixture failure');}
 function cxl_release_path($path){if(!is_file($path)||is_link($path))cxl_release_fail();return$path;}
 function cxl_release_json($path){return json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);}
 function add_filter($hook,$callback,...$args){$GLOBALS['fixture_filters'][$hook]=$callback;}
 function remove_filter($hook,...$args){unset($GLOBALS['fixture_filters'][$hook]);}
 function add_action($hook,$callback,...$args){$GLOBALS['fixture_actions'][$hook]=$callback;if($hook==='wp_loaded')$callback();}
 function remove_all_actions($hook){unset($GLOBALS['fixture_actions'][$hook]);}
 function remove_all_filters($hook){unset($GLOBALS['fixture_filters'][$hook]);}
 function clean_post_cache($id){$GLOBALS['fixture_cleaned'][]=$id;}
 function get_post($id){$post=new WP_Post();if($GLOBALS['fixture_mode']==='stale')$post->post_content='Stale page cache';return$post;}
 function wp_get_environment_type(){return $GLOBALS['fixture_mode']==='wrong-environment'?'staging':'production';}
 $wpdb=new class {
  public $posts='wp_posts';
  public function prepare($sql,$id,$slug){return str_replace(['%d','%s'],[(string)$id,"'".$slug."'"],$sql);}
  public function get_row($sql,$mode){if(!str_contains($sql,'ID=55')||!str_contains($sql,"post_name='delivery-shipping'"))throw new \RuntimeException();return ['ID'=>55,'post_name'=>'delivery-shipping','post_type'=>'page','post_status'=>'publish','post_content'=>'Owned delivery HTML with FAQ.'];}
 };
 register_shutdown_function(static function()use($argv){
  $query=$GLOBALS['fixture_filters']['query']??null;$http=$GLOBALS['fixture_filters']['pre_http_request']??null;$mail=$GLOBALS['fixture_actions']['phpmailer_init']??null;$mail_blocked=false;
  if($mail){try{$mail();}catch(\Throwable$ignored){$mail_blocked=true;}}
  file_put_contents($argv[2],json_encode(['urls'=>\LiteSpeed\Purge::$urls,'cleaned'=>$GLOBALS['fixture_cleaned'],'headers'=>$GLOBALS['fixture_headers'],
   'sql_write_blocked'=>$query&&$query('UPDATE wp_options SET option_value=1')==='SELECT 0 WHERE 1=0'&&$query("SELECT * INTO OUTFILE '/tmp/file' FROM wp_posts")==='SELECT 0 WHERE 1=0',
   'select_allowed'=>$query&&$query('SELECT ID FROM wp_posts')==='SELECT ID FROM wp_posts',
   'http_blocked'=>$http&&$http() instanceof WP_Error,'mail_blocked'=>$mail_blocked]));
 });
 $_SERVER['REQUEST_METHOD']='GET';$_SERVER['REQUEST_URI']='/wp-admin/admin-post.php?action=petish_cancellation_release&run=123-1';$_SERVER['HTTP_X_PETISH_RELEASE']=$GLOBALS['fixture_mode']==='bad-token'?str_repeat('b',64):str_repeat('a',64);
 if($GLOBALS['fixture_mode']==='cookie')$_SERVER['HTTP_COOKIE']='owned_session=present';
 $_GET=['action'=>'petish_cancellation_release','run'=>'123-1'];
 if($GLOBALS['fixture_mode']==='wrong-path')$_SERVER['REQUEST_URI']='/wp-admin/admin.php';
 if($GLOBALS['fixture_mode']==='extra-query')$_GET['unexpected']='1';
 require $argv[1];$generated=cxl_release_cache_code('123-1');
 if(substr_count($generated,"PHP_SAPI === 'cli'")!==1)throw new \RuntimeException('fixed CLI guard changed');
 $generated=str_replace("PHP_SAPI === 'cli'",'false',$generated);
 // A lexical header import captures CLI-discarded headers; the generated body
 // changes only its one fixed SAPI guard and uses real namespaced class calls.
 eval('use function PetishCacheProbeFixture\\header;'.substr($generated,5));
}
'''
            fixture = base / 'runtime.php'
            fixture.write_text(source)
            report = base / 'facts.json'
            process = subprocess.run([php, str(fixture), str(base / 'cache.php'), str(report), mode],
                                     capture_output=True, text=True, timeout=10)
            self.assertEqual(process.returncode, 0, process.stderr)
            self.assertNotIn('a' * 64, process.stdout + process.stderr)
            return json.loads(report.read_text())

    def test_namespaced_probe_purges_only_fixed_urls_and_clears_only_target_page(self):
        value = self.run_generated()
        self.assertEqual(value['urls'], ['/delivery-shipping/', '/my-account/', '/my-account/?my-reviews=1',
                         '/wp-content/plugins/petish-fulfilment/assets/cancellation-faq.css',
                         '/wp-content/plugins/petish-fulfilment/assets/order-cancellation.css',
                         '/wp-content/plugins/petish-fulfilment/assets/shipment-tracking.css',
                         '/wp-content/plugins/petish-passport/assets/customer-reviews.css'])
        self.assertEqual(value['cleaned'], [55])
        self.assertIn('X-Petish-Cancellation-Cache-Purge: dispatched', value['headers'])
        self.assertEqual(sum(header.startswith('X-LiteSpeed-Purge:') for header in value['headers']), 1)
        self.assertTrue(value['sql_write_blocked'])
        self.assertTrue(value['select_allowed'])
        self.assertTrue(value['http_blocked'])
        self.assertTrue(value['mail_blocked'])

    def test_wildcard_foreign_tags_or_stale_page_cache_cannot_report_purge_success(self):
        for mode in ('wildcard', 'foreign', 'stale'):
            with self.subTest(mode=mode):
                value = self.run_generated(mode)
                self.assertIn('X-Petish-Cancellation-Cache-Purge: failed', value['headers'])
                self.assertFalse(any(header.startswith('X-LiteSpeed-Purge:') for header in value['headers']))

    def test_untrusted_expired_or_nonproduction_requests_have_no_effect(self):
        for mode in ('bad-token', 'cookie', 'expired', 'future', 'wrong-root', 'wrong-environment', 'wrong-path', 'extra-query'):
            with self.subTest(mode=mode):
                value = self.run_generated(mode)
                self.assertEqual(value['urls'], [])
                self.assertEqual(value['cleaned'], [])
                self.assertEqual(value['headers'], [])


if __name__ == '__main__':
    unittest.main()
