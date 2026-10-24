"""Pet Chat proof boundaries; no hosted site, account or network is used."""
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

import yaml

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('pet_chat_screen', ROOT/'scripts/sanitize-pet-chat-result.py')
SCREEN = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(SCREEN)


def command(args, cwd, **kwargs):
    return subprocess.run(args, cwd=cwd, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, **kwargs)


def proof():
    return {'schema_version':1,'target':'staging','mode':'verify-enable','status':'success','category':'complete',
            'checks':dict.fromkeys(SCREEN.CHECKS,True),'observations':dict.fromkeys(SCREEN.OBSERVATIONS,True),
            'source_hashes':dict.fromkeys(SCREEN.REQUIRED_SOURCE,'a'*64)}


class ReportBoundary(unittest.TestCase):
    def screen(self, value):
        with tempfile.TemporaryDirectory() as directory:
            path=Path(directory)/'report.json';path.write_text(json.dumps(value))
            return SCREEN.screen(path)

    def test_complete_result_has_every_required_finite_fact(self):
        self.assertTrue(self.screen(proof())[1])
        for group in ('checks','observations'):
            for name in proof()[group]:
                with self.subTest(group=group,name=name):
                    value=proof();del value[group][name];self.assertFalse(self.screen(value)[1])
                    value=proof();value[group][name]=False;self.assertFalse(self.screen(value)[1])

    def test_private_fields_unknown_facts_and_weak_types_are_discarded(self):
        changes=[lambda v:v.update(customer_email='PRIVATE@example.com'),
                 lambda v:v['checks'].update(raw_message='PRIVATE'),
                 lambda v:v['checks'].update(flag_enabled=1),lambda v:v['checks'].update(flag_enabled='true'),
                 lambda v:v['observations'].update(account_id=123),
                 lambda v:v['source_hashes'].update({'../wp-config.php':'a'*64}),
                 lambda v:v['source_hashes'].update({'includes/private.php':'PRIVATE'}),
                 lambda v:v.update(target='production'),lambda v:v.update(mode='apply'),
                 lambda v:v.update(schema_version=True)]
        for change in changes:
            value=proof();change(value)
            safe,passed=self.screen(value)
            self.assertFalse(passed);self.assertEqual(safe['category'],'invalid_report')
            self.assertNotIn('PRIVATE',json.dumps(safe))
        for name in SCREEN.REQUIRED_SOURCE:
            value=proof();del value['source_hashes'][name];self.assertFalse(self.screen(value)[1])

    def test_symlink_hardlink_duplicate_and_oversized_receipts_are_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory);source=root/'source';source.write_text(json.dumps(proof()))
            link=root/'link';link.symlink_to(source);self.assertFalse(SCREEN.screen(link)[1])
            os.link(source,root/'hardlink');self.assertFalse(SCREEN.screen(source)[1])
            duplicate=root/'duplicate';duplicate.write_text('{"status":"success","status":"failure"}')
            self.assertFalse(SCREEN.screen(duplicate)[1])
            large=root/'large';large.write_text(' '*65537);self.assertFalse(SCREEN.screen(large)[1])

    def test_php_guard_grants_one_exact_statement_and_rejects_other_writes(self):
        php=shutil.which('php') or str(ROOT.parent/'.petish-dev/bin/php')
        script=r'''<?php
define('PETISH_CHAT_VERIFY_LIBRARY',true);require $argv[1];
$checks=[];
$checks['reads_allowed']=petish_chat_proof_query_guard('SELECT 1')==='SELECT 1';
$checks['comment_reads_allowed']=petish_chat_proof_query_guard("/* fixture */\nSHOW TABLES")==="/* fixture */\nSHOW TABLES";
$sql="UPDATE wp_options SET option_value='yes' WHERE option_name='petish_chat_enabled_v1'";
$checks['ungranted_flag_write_blocked']=petish_chat_proof_query_guard($sql)==='SELECT 0 WHERE 1=0';
$GLOBALS['petish_chat_proof_next_sql']=$sql;
$checks['one_exact_grant']=petish_chat_proof_query_guard($sql)===$sql&&$GLOBALS['petish_chat_proof_next_sql']===null;
$checks['no_replay']=petish_chat_proof_query_guard($sql)==='SELECT 0 WHERE 1=0';
$checks['other_write_blocked']=petish_chat_proof_query_guard('DELETE FROM wp_users')==='SELECT 0 WHERE 1=0';
$checks['select_outfile_blocked']=petish_chat_proof_query_guard("SELECT 1 INTO OUTFILE '/tmp/private'")==='SELECT 0 WHERE 1=0';
$checks['multiple_statement_blocked']=petish_chat_proof_query_guard('SELECT 1; DELETE FROM wp_users')==='SELECT 0 WHERE 1=0';
$GLOBALS['petish_chat_proof_strict']=true;
try {petish_chat_proof_query_guard('UPDATE wp_posts SET post_title=1');$checks['strict_write_denied']=false;}
catch(RuntimeException $error){$checks['strict_write_denied']=true;}
echo json_encode($checks);
'''
        with tempfile.TemporaryDirectory() as directory:
            path=Path(directory)/'fixture.php';path.write_text(script)
            result=command([php,str(path),str(ROOT/'scripts/verify-pet-chat.php')],ROOT)
            self.assertEqual(result.stderr,b'');checks=json.loads(result.stdout)
            self.assertEqual(len(checks),9);self.assertTrue(all(checks.values()))

    def test_activation_is_one_flag_only_and_preserves_existing_autoload(self):
        php=shutil.which('php') or str(ROOT.parent/'.petish-dev/bin/php')
        script=r'''<?php
define('PETISH_CHAT_VERIFY_LIBRARY',true);define('PETISH_STAGING_GUARD_ACTIVE',true);define('ARRAY_A','ARRAY_A');
require $argv[1];
class Petish_Chat {const OPTION_ENABLED='petish_chat_enabled_v1';static function enabled(){global $wpdb;return ($wpdb->row['option_value']??'no')==='yes';}}
function wp_get_environment_type(){return $GLOBALS['environment'];}
function home_url($path){return 'https://staging.petish.biz'.$path;}
function wp_cache_delete($key,$group){}
class FlagDB {
 public $options='wp_options';public $last_error='';public $row=null;public $writes=0;
 function prepare($sql,...$values){return preg_replace_callback('/%[sd]/',static function($m)use(&$values){$v=array_shift($values);return $m[0]==='%d'?(string)(int)$v:"'".str_replace("'","''",$v)."'";},$sql);}
 function get_results($sql,$kind){return $this->row===null?[]:[$this->row];}
 function query($sql){
  if(petish_chat_proof_query_guard($sql)!==$sql)throw new RuntimeException();
  $insert="INSERT INTO `wp_options` (`option_name`, `option_value`, `autoload`) VALUES ('petish_chat_enabled_v1', 'yes', 'off')";
  if($sql===$insert&&$this->row===null){$this->row=['option_id'=>'9','option_name'=>'petish_chat_enabled_v1','option_value'=>'yes','autoload'=>'off'];$this->writes++;return 1;}
  $update="UPDATE `wp_options` SET `option_value` = 'yes' WHERE `option_id` = 9 AND `option_name` = 'petish_chat_enabled_v1' AND `option_value` = 'no'";
  if($sql===$update&&($this->row['option_value']??'')==='no'){$this->row['option_value']='yes';$this->writes++;return 1;}
  throw new RuntimeException();
 }
}
$checks=[];$GLOBALS['environment']='staging';$GLOBALS['petish_chat_proof_transaction']=true;
$wpdb=new FlagDB();$report=['checks'=>[]];petish_chat_proof_enable();
$checks['missing_flag_added_once_nonautoloaded']=$wpdb->writes===1&&$wpdb->row['autoload']==='off';
$wpdb=new FlagDB();$wpdb->row=['option_id'=>'9','option_name'=>'petish_chat_enabled_v1','option_value'=>'no','autoload'=>'yes'];$report=['checks'=>[]];petish_chat_proof_enable();
$checks['existing_flag_only_value_changes']=$wpdb->writes===1&&$wpdb->row===['option_id'=>'9','option_name'=>'petish_chat_enabled_v1','option_value'=>'yes','autoload'=>'yes'];
$wpdb->writes=0;$report=['checks'=>[]];petish_chat_proof_enable();$checks['already_enabled_is_idempotent']=$wpdb->writes===0;
$GLOBALS['environment']='production';$wpdb=new FlagDB();
try {petish_chat_proof_enable();$checks['production_denied_before_write']=false;}catch(RuntimeException $e){$checks['production_denied_before_write']=$wpdb->writes===0&&$wpdb->row===null;}
$GLOBALS['environment']='staging';$GLOBALS['petish_chat_proof_transaction']=false;
try {petish_chat_proof_enable();$checks['unguarded_transaction_denied']=false;}catch(RuntimeException $e){$checks['unguarded_transaction_denied']=$wpdb->writes===0;}
$GLOBALS['petish_chat_proof_transaction']=true;$wpdb->row=['option_id'=>'9','option_name'=>'petish_chat_enabled_v1','option_value'=>'unexpected','autoload'=>'yes'];
try {petish_chat_proof_enable();$checks['unexpected_prior_flag_denied']=false;}catch(RuntimeException $e){$checks['unexpected_prior_flag_denied']=$wpdb->writes===0;}
echo json_encode($checks);
'''
        with tempfile.TemporaryDirectory() as directory:
            path=Path(directory)/'fixture.php';path.write_text(script)
            result=command([php,str(path),str(ROOT/'scripts/verify-pet-chat.php')],ROOT)
            self.assertEqual(result.stderr,b'');checks=json.loads(result.stdout)
            self.assertEqual(len(checks),6);self.assertTrue(all(checks.values()))


class GitSourceBoundary(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.addCleanup(self.temp.cleanup)
        self.root=Path(self.temp.name)
        command(['git','init','-q'],self.root)
        command(['git','config','user.name','Fixture'],self.root)
        command(['git','config','user.email','fixture@example.com'],self.root)
        for name in SCREEN.REQUIRED_SOURCE:
            path=self.root/SCREEN.PREFIX/name;path.parent.mkdir(parents=True,exist_ok=True)
            path.write_text('<?php /* synthetic reviewed source */\n')
        self.revision=self.commit()

    def commit(self):
        command(['git','add','.'],self.root);command(['git','commit','-qm','Fixture'],self.root)
        return command(['git','rev-parse','HEAD'],self.root).stdout.decode().strip()

    def build(self, revision):
        old=Path.cwd()
        try:
            os.chdir(self.root);return SCREEN.build_manifest(revision)
        finally:os.chdir(old)

    def test_immutable_source_includes_every_regular_tracked_plugin_file(self):
        expected=self.build(self.revision)
        (self.root/SCREEN.PREFIX/'petish-passport.php').write_text('Unreviewed worktree changes')
        path=self.root/SCREEN.PREFIX/'assets/extra.css';path.write_text('body{}')
        self.assertEqual(self.build(self.revision),expected)
        self.assertIn('assets/extra.css',self.build(self.commit()))

    def test_missing_required_symlinks_and_database_files_cannot_be_manifested(self):
        path=self.root/SCREEN.PREFIX/'petish-passport.php';path.unlink()
        with self.assertRaises(ValueError):self.build(self.commit())
        path.symlink_to('/etc/passwd')
        with self.assertRaises(ValueError):self.build(self.commit())
        path.unlink();path.write_text('<?php')
        (self.root/SCREEN.PREFIX/'private.sql').write_text('PRIVATE DATABASE')
        with self.assertRaises(ValueError):self.build(self.commit())


class StagingPipeline(unittest.TestCase):
    def run_pipeline(self, mode='success', branch='verify-pet-chat'):
        with tempfile.TemporaryDirectory() as directory:
            base=Path(directory);repo=base/'repo';repo.mkdir();origin=base/'origin.git'
            command(['git','init','--bare','-q',str(origin)],base)
            command(['git','init','-q'],repo)
            command(['git','config','user.name','Fixture'],repo)
            command(['git','config','user.email','fixture@example.com'],repo)
            command(['git','remote','add','origin',str(origin)],repo)
            (repo/'scripts').mkdir()
            for name in ['hostinger-pet-chat.sh','sanitize-pet-chat-result.py','hostinger-ssh-common.sh',
                         'verify-pet-chat.php','verify-pet-club.php','email-branding-preview-remote.php']:
                (repo/'scripts'/name).write_bytes((ROOT/'scripts'/name).read_bytes())
            for name in SCREEN.REQUIRED_SOURCE:
                path=repo/SCREEN.PREFIX/name;path.parent.mkdir(parents=True,exist_ok=True)
                path.write_text('<?php /* synthetic reviewed source */\n')
            command(['git','add','.'],repo);command(['git','commit','-qm','Fixture source'],repo)
            revision=command(['git','rev-parse','HEAD'],repo).stdout.decode().strip()
            raw=proof();old=Path.cwd()
            try:os.chdir(repo);raw['source_hashes']=SCREEN.build_manifest(revision)
            finally:os.chdir(old)
            if mode=='private_failure':raw['private_customer']='PRIVATE-CUSTOMER-SENTINEL'
            (base/'remote-report.json').write_text(json.dumps(raw))
            binary=base/'bin';binary.mkdir()
            (binary/'sshpass').write_text('#!/bin/sh\nshift\nexec "$@"\n')
            (binary/'ssh-keygen').write_text('#!/bin/sh\ncat >/dev/null\nprintf "256 SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA fixture\\n"\n')
            (binary/'ssh').write_text('''#!/usr/bin/env python3
import io,json,os,sys,tarfile
from pathlib import Path
base=Path(os.environ['FIXTURE_BASE']);command=sys.argv[-1]
with (base/'commands.jsonl').open('a') as stream:stream.write(json.dumps(command)+'\\n')
if 'tar --extract' in command:
 archive=tarfile.open(fileobj=io.BytesIO(sys.stdin.buffer.read()))
 assert sorted(member.name for member in archive.getmembers())==sorted(['verify-pet-chat.php','verify-pet-club.php','email-branding-preview-remote.php','source-manifest.json','tools.sha256'])
 assert all(member.isfile() for member in archive.getmembers())
elif 'php -d display_errors=0' in command:
 assert 'for name in DB_NAME DB_HOST' in command and 'verify-pet-chat.php' in command
 print((base/'remote-report.json').read_text())
 print('PRIVATE-CUSTOMER-SENTINEL',file=sys.stderr)
elif 'rm -rf -- "$tools"' in command:
 if os.environ['FIXTURE_MODE']=='cleanup_failure':raise SystemExit(1)
else:raise SystemExit(1)
''')
            for path in binary.iterdir():path.chmod(0o700)
            runner=base/'runner';runner.mkdir();(runner/'petish-candidate-host-keys').write_text('fixture-key\n')
            env=os.environ|{'PATH':str(binary)+':'+os.environ['PATH'],'RUNNER_TEMP':str(runner),
                'GITHUB_RUN_ID':'123','GITHUB_RUN_ATTEMPT':'1','GITHUB_SHA':revision,'GITHUB_REF_NAME':branch,
                'SSH_HOST':'82.29.81.104','SSH_PORT':'65002','SSH_USER':'u839205597','SSHPASS':'PRIVATE-SECRET-SENTINEL',
                'SSH_EXPECTED_FINGERPRINT':'SHA256:'+('B' if mode=='fingerprint_mismatch' else 'A')*43,'PET_CHAT_PREREQUISITES':'success',
                'PET_CHAT_PREPARATION_STAGE':'complete','FIXTURE_BASE':str(base),'FIXTURE_MODE':mode}
            result=subprocess.run(['bash','scripts/hostinger-pet-chat.sh'],cwd=repo,env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
            receipt=subprocess.run(['git','--git-dir='+str(origin),'show','refs/heads/automation-status/pet-chat-123-1:status.json'],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
            public=json.loads(receipt.stdout) if receipt.returncode==0 else None
            commands=(base/'commands.jsonl').read_text() if (base/'commands.jsonl').exists() else ''
            self.assertNotIn('PRIVATE-',result.stdout.decode()+result.stderr.decode()+receipt.stdout.decode())
            self.assertFalse(list(runner.glob('petish-chat.*')))
            return result.returncode,public,commands

    def test_success_requires_cleanup_and_matching_manifest(self):
        status,receipt,commands=self.run_pipeline()
        self.assertEqual(status,0);self.assertEqual(receipt['status'],'success')
        self.assertTrue(receipt['remote_tools_removed'])
        self.assertEqual(receipt['proof']['source_hashes'],receipt['source_hashes'])
        self.assertEqual(len(commands.splitlines()),3)

    def test_private_output_is_discarded_and_remote_tools_are_cleaned(self):
        status,receipt,commands=self.run_pipeline('private_failure')
        self.assertNotEqual(status,0);self.assertEqual(receipt['status'],'failure')
        self.assertEqual(receipt['proof']['category'],'invalid_report')
        self.assertTrue(receipt['remote_tools_removed']);self.assertIn('rm -rf',commands)

    def test_cleanup_failure_is_never_success(self):
        status,receipt,_=self.run_pipeline('cleanup_failure')
        self.assertNotEqual(status,0);self.assertEqual(receipt['category'],'cleanup')
        self.assertEqual(receipt['status'],'failure');self.assertFalse(receipt['remote_tools_removed'])

    def test_mismatched_host_key_never_authenticates_or_creates_remote_tools(self):
        status,receipt,commands=self.run_pipeline('fingerprint_mismatch')
        self.assertNotEqual(status,0);self.assertEqual(receipt['status'],'failure')
        self.assertEqual(receipt['category'],'transport');self.assertIsNone(receipt['proof'])
        self.assertFalse(receipt['remote_tools_removed']);self.assertEqual(commands,'')

    def test_main_and_deploy_branches_cannot_connect_or_publish(self):
        for branch in ['main','deploy-pet-chat','deploy-homepage']:
            status,receipt,commands=self.run_pipeline(branch=branch)
            self.assertNotEqual(status,0);self.assertIsNone(receipt);self.assertEqual(commands,'')

    def test_workflow_is_staging_only_and_exact_event_source(self):
        value=yaml.load((ROOT/'.github/workflows/pet-chat.yml').read_text(),Loader=yaml.BaseLoader)
        self.assertEqual(value['on']['push']['branches'],['verify-pet-chat'])
        self.assertEqual(set(value['on']),{'push','workflow_dispatch'})
        job=value['jobs']['private-staging'];self.assertIn("github.ref_name == 'verify-pet-chat'",job['if'])
        self.assertEqual(job['steps'][0]['with']['ref'],'${{ github.sha }}')
        self.assertEqual(value['concurrency']['group'],'petish-staging-code')


if __name__=='__main__':unittest.main()
