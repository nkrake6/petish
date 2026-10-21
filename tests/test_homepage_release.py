"""Bounded homepage release evidence and public HTTP fixtures; no remote access."""
import copy
from datetime import datetime,timezone,timedelta
import hashlib
import io
import importlib.util
import json
from pathlib import Path
import tempfile
import subprocess
import re
import unittest
from PIL import Image

ROOT=Path(__file__).resolve().parents[1]


def module(name):
    spec=importlib.util.spec_from_file_location(name.replace('-','_'),ROOT/'scripts'/f'{name}.py')
    value=importlib.util.module_from_spec(spec);spec.loader.exec_module(value)
    return value


safe=module('sanitize-homepage-result')
evidence=module('validate-homepage-release')
assets=module('verify-homepage-assets')


class HomepageReleaseTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory(prefix='petish-homepage-proof-')
        self.addCleanup(self.temp.cleanup);self.directory=Path(self.temp.name)

    def raw(self,phase):
        value={'status':'success','category':'complete','checks':dict.fromkeys(safe.REQUIRED[phase],True)}
        if phase in {'inspect','apply','commit','rollback'}:value['changed_file_count']=len(evidence.FILES)
        if phase=='verify':value['checks']['free_delivery_products_available']=False
        if phase=='browser':value['section_present']=False
        return value

    def screen(self,phase,value):
        path=self.directory/'raw.json';path.write_text(json.dumps(value));return safe.screen(phase,path)

    def test_hidden_section_is_a_successful_verified_state(self):
        for phase in ('inspect','apply','native','cleanup','verify','browser','health','assets','commit','rollback'):
            with self.subTest(phase=phase):
                value=self.raw(phase);report,passed=self.screen(phase,value)
                self.assertTrue(passed);self.assertEqual(report,value)

    def test_early_native_failure_keeps_its_finite_category(self):
        value={'status':'failure','category':'assets','checks':{'target_paths_verified':True}}
        report,passed=self.screen('verify',value)
        self.assertFalse(passed);self.assertEqual(report,value)

    def test_cache_failures_preserve_only_finite_diagnostics(self):
        for phase in ('native','cleanup'):
            for category in safe.CACHE_FAILURES:
                value={'status':'failure','category':category,'checks':{'private_runtime_cache_verified':False}}
                report,passed=self.screen(phase,value)
                self.assertFalse(passed);self.assertEqual(report,value)
            for category in ('cache_api_source_/home/private-sentinel','private-sentinel','cache_unknown'):
                value={'status':'failure','category':category,'checks':{'private_runtime_cache_verified':False}}
                report,passed=self.screen(phase,value)
                self.assertFalse(passed);self.assertEqual(report['category'],'invalid_report')
                self.assertNotIn('private-sentinel',json.dumps(report))
            value={'status':'failure','category':next(iter(safe.CACHE_FAILURES)),
                   'checks':{'private_runtime_cache_verified':False},'cache_path':'private-sentinel'}
            report,passed=self.screen(phase,value)
            self.assertFalse(passed);self.assertEqual(report['category'],'invalid_report')
            self.assertNotIn('private-sentinel',json.dumps(report))

    def test_fixture_steps_and_failures_preserve_only_finite_diagnostics(self):
        for category in safe.FIXTURE_FAILURES:
            value={'status':'failure','category':category,'checks':{'for_your_pet_classes_available':True}}
            report,passed=self.screen('native',value)
            self.assertFalse(passed);self.assertEqual(report,value)
        for category in ('fixture_user_private-sentinel','fixture_product_save_private-sentinel',
                         'fixture_product_save_runtime_private-sentinel'):
            value={'status':'failure','category':category,'checks':{'for_your_pet_classes_available':True}}
            report,passed=self.screen('native',value)
            self.assertFalse(passed);self.assertEqual(report['category'],'invalid_report')
            self.assertNotIn('private-sentinel',json.dumps(report))
        value={'status':'failure','category':'fixture_user_owner_create_runtime',
               'checks':{'for_your_pet_classes_available':True},'exception':'private-sentinel'}
        report,passed=self.screen('native',value)
        self.assertFalse(passed);self.assertEqual(report['category'],'invalid_report')
        self.assertNotIn('private-sentinel',json.dumps(report))

    def test_review_diagnostics_accept_only_complete_fixed_boolean_snapshot(self):
        value={'status':'failure','category':'fixture_product_review_verify',
               'checks':{'for_your_pet_classes_available':True},
               'review_diagnostics':dict.fromkeys(safe.REVIEW_DIAGNOSTICS,False)}
        value['review_diagnostics']['nonce_valid']=True
        report,passed=self.screen('native',value)
        self.assertFalse(passed);self.assertEqual(report,value)
        for mutation in ('extra','value','missing','wrong_phase'):
            raw=copy.deepcopy(value)
            if mutation=='extra':raw['review_diagnostics']['private_path']='private-sentinel'
            if mutation=='value':raw['review_diagnostics']['nonce_valid']='private-sentinel'
            if mutation=='missing':del raw['review_diagnostics']['nonce_valid']
            with self.subTest(mutation=mutation):
                report,passed=self.screen('cleanup' if mutation=='wrong_phase' else 'native',raw)
                self.assertFalse(passed);self.assertEqual(report['category'],'invalid_report')
                self.assertNotIn('private-sentinel',json.dumps(report))

    def test_browser_diagnostics_preserve_only_fixed_boolean_observations(self):
        value=self.raw('browser');value['status']='failure';value['category']='assets'
        value['checks']['badge_png_image_matches']=False
        value['diagnostics']=dict.fromkeys(safe.BROWSER_DIAGNOSTICS,False)
        value['diagnostics']['badge_response_signature_png']=True
        value['image_dimensions']={'expected_width':1600,'expected_height':592,'response_width':1,'response_height':1}
        report,passed=self.screen('browser',value)
        self.assertFalse(passed);self.assertEqual(report,value)
        for key,extra in [('private_response','private-sentinel'),('badge_response_decode_ok','private-sentinel')]:
            invalid=copy.deepcopy(value);invalid['diagnostics'][key]=extra
            report,passed=self.screen('browser',invalid)
            self.assertFalse(passed);self.assertEqual(report['category'],'invalid_report')
            self.assertNotIn('private-sentinel',json.dumps(report))
        invalid=copy.deepcopy(value);invalid['image_dimensions']['response_width']=4097
        report,passed=self.screen('browser',invalid)
        self.assertFalse(passed);self.assertEqual(report['category'],'invalid_report')

    def test_private_extras_invalid_flags_or_file_counts_refuse(self):
        bad=[]
        value=self.raw('verify');value['customer_email']='private-sentinel@petish.invalid';bad.append(('verify',value))
        value=self.raw('verify');value['checks']['mail_calls_blocked']=False;bad.append(('verify',value))
        value=self.raw('verify');value['checks']['free_delivery_products_available']='no';bad.append(('verify',value))
        value=self.raw('browser');value['section_present']='false';bad.append(('browser',value))
        value=self.raw('browser');value['checks']['private_token']=True;bad.append(('browser',value))
        value=self.raw('apply');value['changed_file_count']=len(evidence.FILES)+1;bad.append(('apply',value))
        value=self.raw('assets');value['checks']['home_stylesheet_http_verified']=1;bad.append(('assets',value))
        for phase,value in bad:
            with self.subTest(phase=phase,value=value):
                report,passed=self.screen(phase,value);self.assertFalse(passed)
                self.assertNotIn('private-sentinel',json.dumps(report))

    def fixture_evidence(self):
        revision='a'*40
        request={'format':'petish-homepage-v1','staging_source_commit':revision,
            'staging_status_branch':'automation-status/homepage-12344-1','backup_branch':'full-site-backup/12345-1',
            'backup_result_branch':'automation-status/backup-12345-1','recovery_result_branch':'automation-status/recovery-12346-1'}
        stage={'status':'success','category':'complete','mode':'staging','staging_source_verified':True,'source_commit':revision,
            'source_hashes':{name:hashlib.sha256((ROOT/(evidence.PREFIX+name)).read_bytes()).hexdigest() for name in evidence.FILES},
            'phases':{phase:self.raw(phase) for phase in ('native','cleanup','verify','browser')}}
        stage['phases']['preflight']={'status':'success','category':'complete','checks':dict.fromkeys(safe.PREFLIGHT,True)}
        backup={'AUTOMATION_KIND':'backup','AUTOMATION_RESULT':'success','GITHUB_RUN_ID':'12345','GITHUB_RUN_ATTEMPT':'1',
            'generated_utc':datetime.now(timezone.utc).isoformat(),
            'restore_check':{'restore_smoke_test_passed':True,'database_import_verified':True,'private_passport_root_restored':True}}
        checks={'wordpress_application_recovery_passed','database_import_verified','wordpress_bootstrap_verified',
            'homepage_http_verified','shop_http_verified','product_http_verified','cart_http_verified','account_http_verified',
            'anonymous_private_photo_denied','application_outbound_http_blocked','application_email_blocked',
            'application_container_removed','private_sandbox_removed'}
        recovery={'AUTOMATION_KIND':'recovery','AUTOMATION_RESULT':'success','backup_branch':request['backup_branch'],
            'recovery_check':{**dict.fromkeys(checks,True),'private_photo_references_verified':1}}
        reports={request['staging_status_branch']:stage,request['backup_result_branch']:backup,request['recovery_result_branch']:recovery}
        original_report=evidence.evidence.report;original_git=evidence.evidence.git
        self.addCleanup(setattr,evidence.evidence,'report',original_report);self.addCleanup(setattr,evidence.evidence,'git',original_git)
        evidence.evidence.report=lambda branch,label:reports[branch]
        def git(*args):
            if args[0]=='fetch':return b''
            if args[0]=='ls-remote':return (revision+'\trefs/heads/'+request['backup_branch']+'\n').encode()
            if args[0]=='show':return (ROOT/args[1].split(':',1)[1]).read_bytes()
            raise AssertionError('Unexpected evidence command')
        evidence.evidence.git=git
        path=self.directory/'request.json';path.write_text(json.dumps(request))
        return path,request,stage,backup

    def test_release_accepts_matching_hidden_native_and_browser_evidence(self):
        path,request,_,_=self.fixture_evidence()
        self.assertEqual(evidence.validate(path),{'verified':True,**{key:value for key,value in request.items() if key!='format'}})

    def test_recovery_requires_real_private_media_proof(self):
        path,request,_,backup=self.fixture_evidence()
        recovery=evidence.evidence.report(request['recovery_result_branch'],'homepage-recovery')
        for value in (True,False,0,-1,20001,'1',1.0):
            recovery['recovery_check']['private_photo_references_verified']=value
            with self.subTest(value=value),self.assertRaises(ValueError):evidence.validate(path)
        recovery['recovery_check']['private_photo_references_verified']=1
        backup['restore_check']['private_passport_root_restored']=False
        with self.assertRaises(ValueError):evidence.validate(path)

    def test_unrecognized_receipt_fields_cannot_satisfy_release_evidence(self):
        path,_,stage,_=self.fixture_evidence()
        original=copy.deepcopy(stage)
        for phase in ('preflight','native','cleanup','verify','browser'):
            stage.clear();stage.update(copy.deepcopy(original))
            stage['phases'][phase]['private_path']='private-sentinel'
            with self.subTest(phase=phase),self.assertRaises(ValueError):evidence.validate(path)

    def test_actual_order_proof_and_cleanup_are_both_required(self):
        path,_,stage,_=self.fixture_evidence()
        original=copy.deepcopy(stage)
        for phase in ('native','cleanup'):
            stage.clear();stage.update(copy.deepcopy(original));del stage['phases'][phase]
            with self.subTest(phase=phase),self.assertRaises(ValueError):evidence.validate(path)
            stage.clear();stage.update(copy.deepcopy(original))
            stage['phases'][phase]['checks'][next(iter(safe.REQUIRED[phase]))]=False
            with self.subTest(phase=phase),self.assertRaises(ValueError):evidence.validate(path)

    def test_public_contracts_match_native_browser_and_asset_helpers(self):
        native=(ROOT/'scripts/verify-homepage.php').read_text()
        self.assertEqual(safe.VERIFY,set(re.findall(r"petish_homepage_verify_mark\('([a-z_]+)'",native)))
        proof=(ROOT/'scripts/for-your-pet-proof.php').read_text()
        def names(name):
            match=re.search(r'const '+name+r'\s*=\s*\[(.*?)\];',proof,re.S)
            self.assertIsNotNone(match)
            return set(re.findall(r"'([a-z_]+)'",match.group(1)))
        common=names('PETISH_FYP_PROOF_COMMON')
        self.assertEqual(safe.NATIVE,common|names('PETISH_FYP_PROOF_VERIFY'))
        self.assertEqual(safe.CLEANUP,common)
        self.assertEqual(safe.CACHE_FAILURES,names('PETISH_FYP_CACHE_FAILURES'))
        self.assertEqual(safe.FIXTURE_STEPS,names('PETISH_FYP_FIXTURE_STEPS'))
        self.assertEqual(safe.FIXTURE_FAILURE_TYPES,names('PETISH_FYP_FIXTURE_FAILURE_TYPES'))
        self.assertEqual(safe.REVIEW_DIAGNOSTICS,names('PETISH_FYP_REVIEW_DIAGNOSTICS'))
        browser=(ROOT/'tools/homepage/browser.mjs').read_text()
        match=re.search(r'CHECK_KEYS = Object.freeze\(\[(.*?)\]\)',browser,re.S)
        self.assertEqual(safe.BROWSER,set(re.findall(r"'([a-z0-9_]+)'",match.group(1))))
        self.assertEqual(safe.ASSETS,assets.CHECKS)
        self.assertEqual(safe.FILE_COUNT,len(evidence.FILES))

    def test_recovery_helpers_survive_incomplete_rollback_or_fixture_cleanup(self):
        source=(ROOT/'scripts/hostinger-homepage.sh').read_text()
        function=source[source.index('finish() {'):source.index('\ntrap finish EXIT')]
        scenarios=(
            ('rollback_failed',1,0,0,['rollback','publish:failure:rollback']),
            ('cleanup_failed',0,1,0,['cleanup','publish:failure:cleanup']),
            ('rollback_complete',1,0,0,['rollback','remove_tools','publish:failure:prerequisites']),
            ('fixture_complete',0,1,1,['cleanup','source','remove_tools','publish:success:complete']),
            ('source_changed',0,0,1,['source','remove_tools','publish:failure:staging_source']),
        )
        for scenario,rollback,fixture,operation,expected in scenarios:
            with self.subTest(scenario=scenario):
                work=self.directory/scenario;work.mkdir();log=self.directory/(scenario+'.log')
                script='\n'.join([
                    'work=$1; log=$2; scenario=$3; rollback_needed=$4; fixture_cleanup_needed=$5; operation_verified=$6',
                    'mode=staging; ssh_ready=1; fixture_cleanup_verified=0; failure_category=prerequisites; staging_source_verified=1',
                    'remote_phase() { printf "rollback\\n" >> "$log"; [[ "$scenario" != rollback_failed ]]; }',
                    'fixture_phase() { printf "cleanup\\n" >> "$log"; [[ "$scenario" != cleanup_failed ]]; }',
                    'verify_staging_source() { printf "source\\n" >> "$log"; [[ "$scenario" != source_changed ]]; }',
                    'remote_cleanup() { printf "remove_tools\\n" >> "$log"; }',
                    'publish_result() { printf "publish:%s:%s\\n" "$result_status" "$failure_category" >> "$log"; }',
                    function,'true','finish',
                ])
                completed=subprocess.run(['bash','-c',script,'fixture',str(work),str(log),scenario,
                    str(rollback),str(fixture),str(operation)],capture_output=True)
                self.assertEqual(log.read_text().splitlines(),expected)
                self.assertEqual(completed.returncode,0 if scenario=='fixture_complete' else 1)
                self.assertFalse(work.exists())

    def test_native_browser_disagreement_or_stale_backup_refuses(self):
        path,_,stage,backup=self.fixture_evidence()
        stage['phases']['browser']['section_present']=True
        with self.assertRaises(ValueError):evidence.validate(path)
        stage['phases']['browser']['section_present']=False
        stage['phases']['verify']['checks']['free_delivery_section_matches_evidence']=False
        with self.assertRaises(ValueError):evidence.validate(path)
        stage['phases']['verify']['checks']['free_delivery_section_matches_evidence']=True
        backup['generated_utc']=(datetime.now(timezone.utc)-timedelta(hours=7)).isoformat()
        with self.assertRaises(ValueError):evidence.validate(path)

    def test_candidate_hash_mismatch_refuses(self):
        path,_,stage,_=self.fixture_evidence()
        stage['source_hashes']['assets/css/home.css']='b'*64
        with self.assertRaises(ValueError):evidence.validate(path)


class Response:
    def __init__(self,url,body,status=200):self.url=url;self.body=body;self.status=status;self.headers={}
    def __enter__(self):return self
    def __exit__(self,*args):pass
    def read(self,limit):return self.body[:limit]
    def geturl(self):return self.url


class HomepagePublicAssetsTests(unittest.TestCase):
    @staticmethod
    def png(color=(123,45,67,128),level=0):
        stream=io.BytesIO();Image.new('RGBA',(3,2),color).save(stream,format='PNG',compress_level=level)
        return stream.getvalue()

    def fixture(self,changed=None,subtitle=False,redirect=False):
        expected={name:self.png() if name.endswith('.png') else ('Reviewed '+name).encode() for name in assets.ASSETS.values()}
        class Opener:
            def open(self,request,timeout):
                url=request.full_url
                if url=='https://petish.biz/':
                    body=b'<h1 id="petish-home-title">Petish</h1>'
                    if subtitle:body+=b'<p>Selected by supplier-reported orders.</p>'
                else:
                    name=next(name for name in expected if name in url);body=expected[name]
                    if changed==name:body=HomepagePublicAssetsTests.png(color=(124,45,67,128)) if name.endswith('.png') else body+b' unexpected bytes'
                return Response('https://other.invalid/' if redirect else url,body)
        return Opener(),expected

    def test_fixed_public_assets_and_removed_subtitle_pass(self):
        opener,expected=self.fixture();result=assets.verify(opener,expected)
        self.assertEqual(result['status'],'success');self.assertTrue(all(result['checks'].values()))

    def test_wrong_bytes_redirects_or_old_subtitle_refuse(self):
        for arguments in ({'changed':'assets/js/for-your-pet.js'},{'changed':'assets/css/home.css'},{'changed':'assets/images/ui/free-delivery-van.png'},
                          {'subtitle':True},{'redirect':True}):
            opener,expected=self.fixture(**arguments);self.assertEqual(assets.verify(opener,expected)['status'],'failure')

    def test_png_recompression_preserves_exact_pixels_but_pixel_changes_refuse(self):
        original=self.png(level=0);compressed=self.png(level=9)
        self.assertNotEqual(original,compressed);self.assertTrue(assets.png_matches(original,compressed))
        self.assertFalse(assets.png_matches(original,self.png(color=(124,45,67,128),level=9)))
        self.assertFalse(assets.png_matches(original,b'not a PNG'))
        stream=io.BytesIO();Image.new('RGBA',(4,2),(123,45,67,128)).save(stream,format='PNG')
        self.assertFalse(assets.png_matches(original,stream.getvalue()))


if __name__=='__main__':unittest.main()
