"""Proof-publication boundaries; no credentials, hosted WordPress or network required."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import shutil
import tempfile
import unittest

import yaml

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('cancellation_requests_sanitizer', ROOT / 'scripts/sanitize-cancellation-requests-result.py')
SCREEN = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(SCREEN)


def command(args, cwd, **kwargs):
    return subprocess.run(args, cwd=cwd, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, **kwargs)


def manifest():
    return {name: 'a' * 64 for name in SCREEN.REQUIRED_SOURCE}


def proof(mode='verify',kind=None):
    kind=kind or ('current' if mode=='prepare' else 'owned')
    observations=dict.fromkeys(SCREEN.OBSERVATIONS-SCREEN.DELIVERY_OBSERVATIONS,True)
    observations.update({'faq_appended':mode=='prepare' and kind!='owned','faq_already_owned':kind=='owned',
      'delivery_copy_aligned':mode=='prepare' and kind=='legacy','delivery_counterpart_created':mode=='prepare' and kind=='absent',
      'delivery_page_present':kind not in {'unchecked','absent'},'delivery_page_unique':kind not in {'unchecked','absent','duplicate'},
      'delivery_page_structure_known':kind in {'current','legacy','observed_staging_copy','owned','manual_content','marker_unknown'},
      'delivery_copy_current':kind=='current','delivery_copy_legacy':kind=='legacy','delivery_copy_observed':kind=='observed_staging_copy'})
    return {'schema_version':1,'target':'staging','mode':mode,'status':'success','category':'complete',
            'checks':dict.fromkeys(SCREEN.COMMON | (SCREEN.PREPARE if mode=='prepare' else SCREEN.VERIFY),True),
            'observations':observations,
            'source_hashes':manifest(),'theme_source_hashes':dict.fromkeys(SCREEN.REQUIRED_THEME,'b'*64),
            'delivery_page':{'classification':kind,'content_sha256':None if kind in {'unchecked','absent','duplicate'} else SCREEN.OBSERVED_STAGING_SHA if kind=='observed_staging_copy' else 'c'*64}}


class ReportBoundary(unittest.TestCase):
    def screen(self,value,mode='verify'):
        with tempfile.TemporaryDirectory() as directory:
            path=Path(directory)/'report.json';path.write_text(json.dumps(value))
            return SCREEN.screen(path,mode)

    def test_prepare_and_verify_have_distinct_complete_results(self):
        self.assertTrue(self.screen(proof())[1])
        self.assertTrue(self.screen(proof('prepare'),'prepare')[1])
        self.assertFalse(self.screen(proof('prepare'))[1])
        self.assertFalse(self.screen(proof(),'prepare')[1])
        value=proof('prepare','owned')
        self.assertTrue(self.screen(value,'prepare')[1])

    def test_never_accept_private_fields_strings_counts_or_unknown_checks(self):
        mutations=[lambda v:v.update(customer_email='PRIVATE@example.com'),
          lambda v:v['checks'].update(private_note='PRIVATE'),lambda v:v['checks'].update(feature_flags_disabled=1),
          lambda v:v['checks'].update(feature_flags_disabled='true'),lambda v:v['observations'].update(order_id=123),
          lambda v:v['observations'].update(preview_html='PRIVATE'),lambda v:v['source_hashes'].update({'../wp-config.php':'a'*64}),
          lambda v:v['source_hashes'].update({'includes/private.php':'PRIVATE'}),lambda v:v.update(target='production'),
          lambda v:v.update(schema_version=True),lambda v:v.update(mode='production')]
        for mutate in mutations:
            with self.subTest(mutate=mutate):
                value=proof();mutate(value);screened,passed=self.screen(value)
                self.assertFalse(passed);self.assertEqual(screened['category'],'invalid_report')
                self.assertNotIn('PRIVATE',json.dumps(screened))

    def test_missing_current_legacy_and_owned_delivery_states_are_explicit(self):
        for kind in ['absent','current','legacy','observed_staging_copy','owned']:
            with self.subTest(kind=kind):
                value=proof('prepare',kind)
                self.assertTrue(self.screen(value,'prepare')[1])
        for kind in ['unchecked','duplicate','structure_unknown','manual_content','marker_unknown']:
            value=proof('prepare',kind)
            safe,passed=self.screen(value,'prepare')
            self.assertFalse(passed)
            self.assertEqual(safe['delivery_page']['classification'],kind)
        value=proof('prepare','absent');value['observations']['delivery_counterpart_created']=False
        self.assertFalse(self.screen(value,'prepare')[1])
        value=proof('prepare','observed_staging_copy');value['delivery_page']['content_sha256']='d'*64
        self.assertFalse(self.screen(value,'prepare')[1])
        for mutate in [lambda v:v['delivery_page'].update(content_sha256='PRIVATE'),
                       lambda v:v['delivery_page'].update(raw_content='PRIVATE'),
                       lambda v:v['delivery_page'].update(classification='PRIVATE')]:
            value=proof();mutate(value);safe,passed=self.screen(value)
            self.assertFalse(passed);self.assertEqual(safe['category'],'invalid_report')
            self.assertNotIn('PRIVATE',json.dumps(safe))

    def test_all_mode_guards_and_explicit_external_limitations_are_mandatory(self):
        for mode,names in [('verify',SCREEN.COMMON|SCREEN.VERIFY),('prepare',SCREEN.COMMON|SCREEN.PREPARE)]:
            for name in names:
                value=proof(mode);del value['checks'][name]
                self.assertFalse(self.screen(value,mode)[1],name)
                value['checks'][name]=False;self.assertFalse(self.screen(value,mode)[1],name)
        for name in SCREEN.OBSERVATIONS-SCREEN.DELIVERY_OBSERVATIONS:
            value=proof();value['observations'][name]=False;self.assertFalse(self.screen(value)[1])
        for name in SCREEN.REQUIRED_SOURCE:
            value=proof();del value['source_hashes'][name];self.assertFalse(self.screen(value)[1])
        for name in SCREEN.REQUIRED_THEME:
            value=proof();del value['theme_source_hashes'][name];self.assertFalse(self.screen(value)[1])
        for mutate in [lambda v:v['observations'].update(faq_appended=True),lambda v:v.update(source_hashes={}),
                       lambda v:v.update(status='failure')]:
            value=proof();mutate(value);self.assertFalse(self.screen(value)[1])

    def test_symlinks_hardlinks_oversized_and_duplicate_json_are_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            base=Path(directory);source=base/'source';source.write_text(json.dumps(proof()))
            link=base/'link';link.symlink_to(source);self.assertFalse(SCREEN.screen(link,'verify')[1])
            os.link(source,base/'hardlink');self.assertFalse(SCREEN.screen(source,'verify')[1])
            duplicate=base/'duplicate';duplicate.write_text('{"status":"success","status":"failure"}')
            self.assertFalse(SCREEN.screen(duplicate,'verify')[1])
            large=base/'large';large.write_text(' '*65537);self.assertFalse(SCREEN.screen(large,'verify')[1])

    def test_faq_preparation_grants_only_one_exact_statement_and_validates_fixed_page(self):
        php=shutil.which('php') or str(ROOT.parent/'.petish-dev/bin/php')
        source=r'''<?php
define('PETISH_CANCELLATION_VERIFY_LIBRARY',true);
require $argv[1];
$GLOBALS['petish_cancel_strict']=false;
$checks=[];
$checks['read_allowed']=petish_cancel_prepare_guard('SELECT GET_LOCK(\'fixture\',5)')==='SELECT GET_LOCK(\'fixture\',5)';
$checks['ungranted_write_blocked']=petish_cancel_prepare_guard('UPDATE wp_options SET option_value=1')==='SELECT 0 WHERE 1=0';
$sql='INSERT INTO `wp_posts` (`post_content`) VALUES (\'[petish_faqs]\')';
$GLOBALS['petish_cancel_next_sql']=$sql;
$checks['exact_grant_once']=petish_cancel_prepare_guard($sql)===$sql&&$GLOBALS['petish_cancel_next_sql']===null;
$checks['grant_not_replayed']=petish_cancel_prepare_guard($sql)==='SELECT 0 WHERE 1=0';
$GLOBALS['petish_cancel_strict']=true;
try {petish_cancel_prepare_guard('DELETE FROM wp_users');$checks['strict_write_throws']=false;}
catch(RuntimeException $error){$checks['strict_write_throws']=true;}
$row=['ID'=>'123','post_type'=>'page','post_status'=>'publish','post_name'=>'delivery-shipping','post_title'=>'Delivery & shipping','post_content'=>petish_cancel_faq_base().PETISH_CANCEL_FAQ_CONTENT,'post_password'=>'','post_parent'=>'0'];
$checks['fixed_owned_content']=petish_cancel_faq_valid($row);
$row['post_content']='Existing unrelated FAQ copy';$checks['unmanaged_content_refused']=!petish_cancel_faq_valid($row);
$row['post_content']=petish_cancel_faq_base().PETISH_CANCEL_FAQ_CONTENT;$row['post_type']='shop_order';$checks['other_record_type_refused']=!petish_cancel_faq_valid($row);
$sample="<p>Retain this existing copy — 🐇, 'quotes', \\\\slashes\\\\ and &amp; entities.</p>\n";
$sample_hash=hash('sha256',$sample);$reviewed=petish_cancel_faq_base();
$plan=petish_cancel_faq_plan($sample,$reviewed,$sample_hash);
$checks['observed_copy_exact_append']=$plan['content']===$sample.PETISH_CANCEL_FAQ_CONTENT&&$plan['prefix_sha256']===$sample_hash&&$plan['aligned']===false;
$checks['observed_copy_exact_prefix']=petish_cancel_faq_prefix($plan['content'],$sample_hash)===['content'=>$sample,'sha256'=>$sample_hash];
$checks['owner_binds_actual_prefix']=petish_cancel_faq_owner($plan['content'],$sample_hash)===PETISH_CANCEL_FAQ_OWNER.'|'.$sample_hash;
$checks['arbitrary_prefix_still_refused']=petish_cancel_faq_plan($sample,$reviewed)===false&&petish_cancel_faq_prefix($plan['content'])===false;
$checks['tamper_still_refused']=petish_cancel_faq_plan($sample.'changed',$reviewed,$sample_hash)===false&&petish_cancel_faq_prefix($plan['content'].PETISH_CANCEL_FAQ_CONTENT,$sample_hash)===false;
define('ARRAY_A','ARRAY_A');
$wpdb=new class {public $posts='wp_posts';public $last_error='';public $sql='';function get_results($sql,$mode){$this->sql=$sql;return [];} };
petish_cancel_faq_rows(true);
$checks['locked_delivery_read']=str_contains($wpdb->sql,'FORCE INDEX (`post_name`)')&&str_ends_with($wpdb->sql,' FOR UPDATE');
echo json_encode($checks);
'''
        with tempfile.TemporaryDirectory() as directory:
            fixture=Path(directory)/'boundary.php';fixture.write_text(source)
            helper=Path(directory)/'verify-cancellation-requests.php';helper.write_bytes((ROOT/'scripts/verify-cancellation-requests.php').read_bytes())
            for name in ['verify-pet-club.php','delivery-page-content.html']:
                original=ROOT/('operations' if name.endswith('.html') else 'scripts')/name
                (Path(directory)/name).write_bytes(original.read_bytes())
            result=command([php,str(fixture),str(helper)],ROOT)
            self.assertEqual(result.stderr,b'')
            checks=json.loads(result.stdout)
            self.assertEqual(len(checks),14)
            self.assertTrue(all(checks.values()))


class GitSourceBoundary(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        command(['git', 'init', '-q'], self.root)
        command(['git', 'config', 'user.name', 'Fixture'], self.root)
        command(['git', 'config', 'user.email', 'fixture@example.com'], self.root)
        for name in SCREEN.REQUIRED_SOURCE:
            path = self.root / SCREEN.PREFIX / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text('<?php /* reviewed synthetic source */\n')
        for name in SCREEN.REQUIRED_THEME:
            path=self.root/SCREEN.THEME_PREFIX/name;path.parent.mkdir(parents=True,exist_ok=True)
            path.write_text('/* reviewed synthetic header source */')
        self.revision = self.commit()

    def tearDown(self):
        self.temp.cleanup()

    def commit(self):
        command(['git', 'add', '.'], self.root)
        command(['git', 'commit', '-qm', 'Fixture'], self.root)
        return command(['git', 'rev-parse', 'HEAD'], self.root).stdout.decode().strip()

    def build(self, revision):
        old = Path.cwd()
        try:
            os.chdir(self.root)
            return SCREEN.build_manifest(revision)
        finally:
            os.chdir(old)

    def theme(self,revision):
        old=Path.cwd()
        try:
            os.chdir(self.root)
            return SCREEN.build_theme_manifest(revision)
        finally:
            os.chdir(old)

    def test_header_manifest_is_fixed_git_source_not_working_tree(self):
        expected=self.theme(self.revision)
        self.assertEqual(set(expected),SCREEN.REQUIRED_THEME)
        file=self.root/SCREEN.THEME_PREFIX/'includes/account-dropdown.php'
        file.write_text('Unreviewed working tree change')
        self.assertEqual(self.theme(self.revision),expected)
        file.unlink();file.symlink_to('/etc/passwd')
        with self.assertRaises(ValueError):self.theme(self.commit())

    def test_header_manifest_rejects_missing_and_extra_private_paths(self):
        value=dict.fromkeys(SCREEN.REQUIRED_THEME,'b'*64)
        value['includes/private-credentials.php']='c'*64
        with self.assertRaises(ValueError):SCREEN.theme_manifest(value)
        file=self.root/SCREEN.THEME_PREFIX/'assets/js/account-dropdown.js';file.unlink()
        with self.assertRaises(ValueError):self.theme(self.commit())

    def test_hashes_git_objects_and_covers_every_regular_plugin_source(self):
        expected = self.build(self.revision)
        file = self.root / SCREEN.PREFIX / 'petish-fulfilment.php'
        file.write_text('Unreviewed working-tree change')
        self.assertEqual(self.build(self.revision), expected)
        extra = self.root / SCREEN.PREFIX / 'assets/new.css'
        extra.parent.mkdir(exist_ok=True)
        extra.write_text('body{}')
        revision = self.commit()
        self.assertIn('assets/new.css', self.build(revision))

    def test_missing_required_source_symlinks_and_unknown_file_types_rejected(self):
        original = self.root / SCREEN.PREFIX / 'petish-fulfilment.php'
        original.unlink()
        with self.assertRaises(ValueError):
            self.build(self.commit())
        original.symlink_to('/etc/passwd')
        with self.assertRaises(ValueError):
            self.build(self.commit())
        original.unlink()
        original.write_text('<?php')
        (self.root / SCREEN.PREFIX / 'private.sql').write_text('PRIVATE DATABASE')
        with self.assertRaises(ValueError):
            self.build(self.commit())


class StagingPipeline(unittest.TestCase):
    def run_pipeline(self, mode='success', branch='verify-cancellation-requests'):
        with tempfile.TemporaryDirectory() as directory:
            base = Path(directory)
            repo = base / 'repo'
            repo.mkdir()
            origin = base / 'origin.git'
            command(['git', 'init', '--bare', '-q', str(origin)], base)
            command(['git', 'init', '-q'], repo)
            command(['git', 'config', 'user.name', 'Fixture'], repo)
            command(['git', 'config', 'user.email', 'fixture@example.com'], repo)
            command(['git', 'remote', 'add', 'origin', str(origin)], repo)
            (repo / 'scripts').mkdir()
            for name in ['hostinger-cancellation-requests.sh', 'sanitize-cancellation-requests-result.py',
                         'hostinger-ssh-common.sh', 'verify-cancellation-requests.php',
                         'verify-pet-club.php', 'email-branding-preview-remote.php']:
                (repo / 'scripts' / name).write_bytes((ROOT / 'scripts' / name).read_bytes())
            for name in SCREEN.REQUIRED_SOURCE:
                path = repo / SCREEN.PREFIX / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text('<?php /* synthetic reviewed source */\n')
            for name in SCREEN.REQUIRED_THEME:
                path=repo/SCREEN.THEME_PREFIX/name;path.parent.mkdir(parents=True,exist_ok=True)
                path.write_text('/* reviewed synthetic header source */')
            (repo/'operations').mkdir()
            (repo/'operations/delivery-page-content.html').write_bytes((ROOT/'operations/delivery-page-content.html').read_bytes())
            command(['git', 'add', '.'], repo)
            command(['git', 'commit', '-qm', 'Synthetic source'], repo)
            revision = command(['git', 'rev-parse', 'HEAD'], repo).stdout.decode().strip()
            raw = proof('prepare' if branch == 'prepare-cancellation-requests' else 'verify')
            old = Path.cwd()
            try:
                os.chdir(repo)
                raw['source_hashes'] = SCREEN.build_manifest(revision)
                raw['theme_source_hashes']=SCREEN.build_theme_manifest(revision)
            finally:
                os.chdir(old)
            if mode == 'private_failure':
                raw['private_customer'] = 'PRIVATE-CUSTOMER-SENTINEL'
            report = base / 'remote-report.json'
            report.write_text(json.dumps(raw))
            binary = base / 'bin'
            binary.mkdir()
            (binary / 'sshpass').write_text('#!/bin/sh\nshift\nexec "$@"\n')
            (binary / 'ssh-keygen').write_text('#!/bin/sh\ncat >/dev/null\nprintf "256 SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA fixture\\n"\n')
            (binary / 'ssh').write_text('''#!/usr/bin/env python3
import io,json,os,sys,tarfile
from pathlib import Path
command=sys.argv[-1]
base=Path(os.environ['FIXTURE_BASE'])
with (base/'commands.jsonl').open('a') as stream:stream.write(json.dumps(command)+'\\n')
if 'tar --extract' in command:
 archive=tarfile.open(fileobj=io.BytesIO(sys.stdin.buffer.read()))
 names=[member.name for member in archive.getmembers()]
 assert sorted(names)==sorted(['verify-cancellation-requests.php','verify-pet-club.php','email-branding-preview-remote.php','source-manifest.json','theme-manifest.json','delivery-page-content.html','tools.sha256'])
 assert all(member.isfile() for member in archive.getmembers())
elif 'php -d display_errors=0' in command:
 assert 'staging-rehearsal-remote' not in command and ' config get DB_NAME ' in command
 print((base/'remote-report.json').read_text())
 print('PRIVATE-CUSTOMER-SENTINEL',file=sys.stderr)
elif 'rm -rf -- "$tools"' in command:
 if os.environ['FIXTURE_MODE']=='cleanup_failure':raise SystemExit(1)
else:raise SystemExit(1)
''')
            for path in binary.iterdir():
                path.chmod(0o700)
            runner = base / 'runner'
            runner.mkdir()
            (runner / 'petish-candidate-host-keys').write_text('fixture-key\n')
            env = os.environ | {'PATH': str(binary) + ':' + os.environ['PATH'], 'RUNNER_TEMP': str(runner),
                                'GITHUB_RUN_ID': '123', 'GITHUB_RUN_ATTEMPT': '1', 'GITHUB_SHA': revision,
                                'GITHUB_REF_NAME': branch, 'SSH_HOST': '82.29.81.104', 'SSH_PORT': '65002',
                                'SSH_USER': 'u839205597', 'SSHPASS': 'PRIVATE-SECRET-SENTINEL',
                                'SSH_EXPECTED_FINGERPRINT': 'SHA256:' + 'A' * 43,
                                'CANCELLATION_REQUESTS_PREREQUISITES': 'success',
                                'CANCELLATION_REQUESTS_PREPARATION_STAGE': 'complete',
                                'FIXTURE_BASE': str(base), 'FIXTURE_MODE': mode}
            completed = subprocess.run(['bash', 'scripts/hostinger-cancellation-requests.sh'], cwd=repo, env=env,
                                       stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            receipt = subprocess.run(['git', '--git-dir=' + str(origin), 'show',
                                      'refs/heads/automation-status/cancellation-requests-123-1:status.json'],
                                     stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            public = json.loads(receipt.stdout) if receipt.returncode == 0 else None
            commands = (base / 'commands.jsonl').read_text() if (base / 'commands.jsonl').exists() else ''
            self.assertNotIn('PRIVATE-', completed.stdout.decode() + completed.stderr.decode() + receipt.stdout.decode())
            self.assertFalse(list(runner.glob('petish-cancellation-requests.*')))
            return completed.returncode, public, commands

    def test_success_publishes_finite_receipt_after_remote_cleanup(self):
        status, receipt, commands = self.run_pipeline()
        self.assertEqual(status, 0)
        self.assertEqual(receipt['status'], 'success')
        self.assertTrue(receipt['remote_tools_removed'])
        self.assertEqual(receipt['proof']['source_hashes'], receipt['source_hashes'])
        self.assertEqual(receipt['proof']['theme_source_hashes'],receipt['theme_source_hashes'])
        self.assertEqual(len(commands.splitlines()), 3)

    def test_prepare_branch_is_a_separate_explicit_operation(self):
        status,receipt,commands=self.run_pipeline(branch='prepare-cancellation-requests')
        self.assertEqual(status,0)
        self.assertEqual(receipt['mode'],'prepare')
        self.assertEqual(receipt['proof']['mode'],'prepare')
        self.assertTrue(receipt['proof']['observations']['faq_appended'])
        self.assertIn('prepare',commands)

    def test_private_or_malformed_output_is_discarded_and_still_cleaned(self):
        status, receipt, commands = self.run_pipeline('private_failure')
        self.assertNotEqual(status, 0)
        self.assertEqual(receipt['status'], 'failure')
        self.assertEqual(receipt['proof']['category'], 'invalid_report')
        self.assertTrue(receipt['remote_tools_removed'])
        self.assertIn('rm -rf', commands)

    def test_cleanup_failure_can_never_publish_success(self):
        status, receipt, _ = self.run_pipeline('cleanup_failure')
        self.assertNotEqual(status, 0)
        self.assertEqual(receipt['category'], 'cleanup')
        self.assertEqual(receipt['status'], 'failure')
        self.assertFalse(receipt['remote_tools_removed'])

    def test_main_branch_has_no_transport_or_publication(self):
        status, receipt, commands = self.run_pipeline(branch='main')
        self.assertNotEqual(status, 0)
        self.assertIsNone(receipt)
        self.assertEqual(commands, '')

    def test_workflow_is_staging_only_and_checks_out_event_commit(self):
        value = yaml.load((ROOT / '.github/workflows/cancellation-requests.yml').read_text(), Loader=yaml.BaseLoader)
        self.assertEqual(value['on']['push']['branches'], ['prepare-cancellation-requests','verify-cancellation-requests'])
        self.assertEqual(set(value['on']), {'push', 'workflow_dispatch'})
        job = value['jobs']['private-staging']
        self.assertIn("github.ref_name == 'verify-cancellation-requests'", job['if'])
        self.assertEqual(job['steps'][0]['with']['ref'], '${{ github.sha }}')
        self.assertEqual(value['concurrency']['group'], 'petish-staging-code')
        self.assertNotIn('production',value['jobs']['private-staging']['if'])
        self.assertIn("github.ref_name == 'prepare-cancellation-requests'",job['if'])


if __name__ == '__main__':
    unittest.main()
