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

    def test_theme_geometry_accepts_only_fixed_bounded_numeric_fields(self):
        value=self.raw('browser')
        value['theme_geometry']={view:dict.fromkeys(safe.THEME_GEOMETRY_FIELDS,0) for view in safe.THEME_GEOMETRY_VIEWS}
        report,passed=self.screen('browser',value)
        self.assertTrue(passed);self.assertEqual(report,value)
        for invalid in ('private-sentinel',True,float('nan'),float('inf'),4097,-4097):
            raw=copy.deepcopy(value);raw['theme_geometry']['320_light']['header_height']=invalid
            report,passed=self.screen('browser',raw)
            self.assertFalse(passed);self.assertEqual(report['category'],'invalid_report')

            self.assertNotIn('private-sentinel',json.dumps(report))
        for kind in ('extra_view','missing_view','extra_field','missing_field'):
            raw=copy.deepcopy(value)
            if kind=='extra_view':raw['theme_geometry']['private-sentinel']={}
            if kind=='missing_view':del raw['theme_geometry']['320_light']
            if kind=='extra_field':raw['theme_geometry']['320_light']['private-sentinel']=1
            if kind=='missing_field':del raw['theme_geometry']['320_light']['header_height']
            report,passed=self.screen('browser',raw)
            self.assertFalse(passed);self.assertEqual(report['category'],'invalid_report')

    def test_browser_geometry_export_matches_exact_fixed_numeric_schema(self):
        code="import { THEME_GEOMETRY_KEYS, DIAGNOSTIC_KEYS, CART_METRIC_KEYS, CART_METRIC_ENGINES } from './tools/homepage/browser.mjs'; process.stdout.write(JSON.stringify({geometry:THEME_GEOMETRY_KEYS,diagnostics:DIAGNOSTIC_KEYS,cart_keys:CART_METRIC_KEYS,cart_engines:CART_METRIC_ENGINES}));"
        output=subprocess.run(['node','--input-type=module','-e',code],cwd=ROOT,
            check=True,capture_output=True,text=True)
        exports=json.loads(output.stdout);fields=exports['geometry']
        self.assertEqual(len(fields),106)
        self.assertEqual(len(fields),len(set(fields)))
        self.assertEqual(set(fields),safe.THEME_GEOMETRY_FIELDS)
        self.assertEqual(len(exports['diagnostics']),83)
        self.assertEqual(len(exports['diagnostics']),len(set(exports['diagnostics'])))
        self.assertEqual(set(exports['diagnostics']),safe.BROWSER_DIAGNOSTICS)
        self.assertEqual(set(exports['cart_keys']),set(safe.CART_METRIC_LIMITS))
        self.assertEqual(set(exports['cart_engines']),safe.CART_METRIC_ENGINES)
        value=self.raw('browser')
        value['theme_geometry']={view:dict.fromkeys(fields,-1) for view in safe.THEME_GEOMETRY_VIEWS}
        value['diagnostics']=dict.fromkeys(safe.BROWSER_DIAGNOSTICS,False)
        value['cart_metrics']={engine:dict.fromkeys(safe.CART_METRIC_LIMITS,0) for engine in safe.CART_METRIC_ENGINES}
        self.assertLess(len(json.dumps(value)),65536)
        self.assertTrue(self.screen('browser',value)[1])

    def test_cart_diagnostics_cannot_replace_required_cart_or_webkit_checks(self):
        for check in ('market_flag_cart_verified','market_flag_webkit_verified'):
            value=self.raw('browser');value['diagnostics']=dict.fromkeys(safe.BROWSER_DIAGNOSTICS,True)
            value['checks'][check]=False
            self.assertFalse(self.screen('browser',value)[1])

    def test_inert_skeleton_observations_do_not_claim_an_empty_cart_view(self):
        value=self.raw('browser');value['diagnostics']=dict.fromkeys(safe.BROWSER_DIAGNOSTICS,False)
        for prefix in ('market_cart','market_webkit_cart'):
            for suffix in ('hydration_wait_completed','native_skeleton_state','real_or_unknown_rows_absent','cart_action_controls_absent'):
                value['diagnostics'][prefix+'_'+suffix]=True
        report,passed=self.screen('browser',value)
        self.assertTrue(passed);self.assertEqual(report,value)
        for prefix in ('market_cart','market_webkit_cart'):
            self.assertFalse(report['diagnostics'][prefix+'_native_empty_state'])
            self.assertFalse(report['diagnostics'][prefix+'_empty_marker'])
            self.assertFalse(report['diagnostics'][prefix+'_items_absent'])

    def test_cart_metrics_accept_only_fixed_bounded_counts(self):
        value=self.raw('browser')
        value['cart_metrics']={engine:{'http_status_class':2,'empty_marker_count':1,'visible_cart_row_count':0,
                                      'min_flag_in_view_count':1,'max_flag_offscreen_count':2}
                              for engine in ('chromium','webkit')}
        self.assertTrue(self.screen('browser',value)[1])
        for key,invalid in (('http_status_class',6),('http_status_class',True),('http_status_class',2.5),
                            ('empty_marker_count',101),('visible_cart_row_count',-1),
                            ('empty_marker_count','private-sentinel'),('min_flag_in_view_count',9),
                            ('max_flag_offscreen_count',-1),('max_flag_offscreen_count',True)):
            raw=copy.deepcopy(value);raw['cart_metrics']['chromium'][key]=invalid
            report,passed=self.screen('browser',raw)
            self.assertFalse(passed);self.assertEqual(report['category'],'invalid_report')
            self.assertNotIn('private-sentinel',json.dumps(report))
        for kind in ('extra_engine','missing_engine','extra_field','missing_field'):
            raw=copy.deepcopy(value)
            if kind=='extra_engine':raw['cart_metrics']['private-sentinel']={}
            if kind=='missing_engine':del raw['cart_metrics']['webkit']
            if kind=='extra_field':raw['cart_metrics']['chromium']['private-sentinel']=1
            if kind=='missing_field':del raw['cart_metrics']['chromium']['empty_marker_count']
            report,passed=self.screen('browser',raw)
            self.assertFalse(passed);self.assertEqual(report['category'],'invalid_report')
            self.assertNotIn('private-sentinel',json.dumps(report))

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
            ('rollback_cache_failed',1,0,0,['rollback','purge:rollback','publish:failure:rollback']),
            ('rollback_complete',1,0,0,['rollback','purge:rollback','remove_tools','publish:failure:prerequisites']),
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
                    'purge_html_cache() { printf "purge:%s\\n" "$1" >> "$log"; [[ "$scenario" != rollback_cache_failed ]]; }',
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

    def test_html_cache_pipeline_stops_before_later_phases_on_any_failure(self):
        source=(ROOT/'scripts/hostinger-homepage.sh').read_text()
        function=source[source.index('purge_html_cache() {'):source.index('\nfixture_phase() {')]
        for operation in ('apply','rollback'):
            for failure in ('none','queue','consume','screen','verify'):
                with self.subTest(operation=operation,failure=failure):
                    work=self.directory/(operation+'-'+failure);work.mkdir()
                    log=work/'trace'
                    script='\n'.join([
                        'work=$1; log=$2; failure=$3; operation=$4',
                        'cache_remote_phase() { printf "%s:%s\\n" "$1" "$2" >> "$log"; [[ "$failure" != "$2" ]]; }',
                        'python3() { printf "consume\\n" >> "$log"; [[ "$failure" != consume ]]; }',
                        'screen_phase() { printf "screen:%s\\n" "$1" >> "$log"; [[ "$failure" != screen ]]; }',
                        function,'purge_html_cache "$operation"',
                    ])
                    completed=subprocess.run(['bash','-c',script,'fixture',str(work),str(log),failure,operation],capture_output=True)
                    expected=[operation+':queue']
                    if failure!='queue':
                        expected+=['consume','screen:'+('rollback_' if operation=='rollback' else '')+'cache_consume']
                        if failure not in {'consume','screen'}:expected.append(operation+':verify')
                    self.assertEqual(log.read_text().splitlines(),expected)
                    self.assertEqual(completed.returncode,0 if failure=='none' else 1)

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
    FLAG='<svg class="petish-market__flag" aria-hidden="true" focusable="false" width="22" height="22" viewBox="0 0 512 512"><use href="https://petish.biz/wp-content/themes/dt-the7-child/assets/images/ui/country-flags.svg?ver=123#flag-au"/></svg>'
    HEADER='<div class="header-bar"><div class="mini-widgets"><div class="mini-search"></div><div data-petish-theme-switch hidden><button data-petish-theme-toggle type="button" role="switch" aria-checked="false" aria-label="Dark mode"><svg></svg></button></div><div data-petish-market>'+FLAG+'</div></div></div>'
    @staticmethod
    def png(color=(123,45,67,128),level=0):
        stream=io.BytesIO();Image.new('RGBA',(3,2),color).save(stream,format='PNG',compress_level=level)
        return stream.getvalue()

    def fixture(self,changed=None,subtitle=False,redirect=False,old_header=False,old_cart_header=False):
        expected={name:self.png() if name.endswith('.png') else ('Reviewed '+name).encode() for name in assets.ASSETS.values()}
        expected['assets/images/ui/country-flags.svg']=b'<svg><defs><symbol id="flag-au"></symbol><symbol id="flag-globe"></symbol></defs></svg>'
        class Opener:
            def open(self,request,timeout):
                if request.get_method()!='GET' or request.has_header('Cookie') or request.has_header('Authorization'):
                    raise AssertionError('Public verification must use anonymous GETs only')
                url=request.full_url
                if url=='https://petish.biz/':
                    body=b'<h1 id="petish-home-title">Petish</h1>'
                    if not old_header:body+=HomepagePublicAssetsTests.HEADER.encode()
                    if subtitle:body+=b'<p>Selected by supplier-reported orders.</p>'
                elif url=='https://petish.biz/cart/':
                    body=b'<body class="woocommerce-cart">Cart</body>'
                    if not old_cart_header:body+=HomepagePublicAssetsTests.HEADER.encode()
                else:
                    name=next(name for name in expected if name in url);body=expected[name]
                    if changed==name:body=HomepagePublicAssetsTests.png(color=(124,45,67,128)) if name.endswith('.png') else body+b' unexpected bytes'
                return Response('https://other.invalid/' if redirect else url,body)
        return Opener(),expected

    def test_fixed_public_assets_and_removed_subtitle_pass(self):
        opener,expected=self.fixture();result=assets.verify(opener,expected)
        self.assertEqual(result['status'],'success');self.assertTrue(all(result['checks'].values()))

    def test_wrong_bytes_redirects_or_old_subtitle_refuse(self):
        for arguments in ({'changed':'assets/js/theme.js'},{'changed':'assets/css/petish.css'},
                          {'changed':'assets/js/market-selector.js'},{'changed':'assets/css/market-selector.css'},
                          {'changed':'assets/images/ui/country-flags.svg'},{'changed':'assets/images/ui/country-flags.LICENSE.txt'},
                          {'changed':'assets/js/for-your-pet.js'},{'changed':'assets/css/home.css'},{'changed':'assets/images/ui/free-delivery-van.png'},
                          {'subtitle':True},{'redirect':True},{'old_header':True},{'old_cart_header':True}):
            opener,expected=self.fixture(**arguments);self.assertEqual(assets.verify(opener,expected)['status'],'failure')

    def test_public_switch_markup_requires_header_order_and_accessible_control(self):
        self.assertTrue(assets.theme_switch_markup(self.HEADER))
        self.assertTrue(assets.theme_switch_markup(self.HEADER+self.HEADER.replace('header-bar','mobile-header-bar')))
        for before,after in [('mini-search','wrong-position'),('data-petish-market','data-other'),
                             ('header-bar','footer'),('role="switch"','role="button"'),
                             ('aria-label="Dark mode"','aria-label=""'),('type="button"','type="submit"')]:
            self.assertFalse(assets.theme_switch_markup(self.HEADER.replace(before,after)))

    def test_country_flags_require_local_reviewed_symbol_and_decorative_svg(self):
        known={'flag-au','flag-globe'}
        self.assertTrue(assets.country_flag_markup(self.HEADER,known))
        self.assertTrue(assets.country_flag_markup(self.HEADER.replace('#flag-au','#flag-globe'),known))
        for before,after in [('https://petish.biz/','https://foreign.invalid/'),('country-flags.svg','other.svg'),
                             ('#flag-au','#flag-zz'),('?ver=123','?ver=123&private=sentinel'),
                             ('aria-hidden="true"','aria-hidden="false"'),('width="22"','width="0"'),
                             ('header-bar','footer'),(self.FLAG,'<span class="petish-market__flag">AU</span>')]:
            self.assertFalse(assets.country_flag_markup(self.HEADER.replace(before,after),known))

    def test_png_recompression_preserves_exact_pixels_but_pixel_changes_refuse(self):
        original=self.png(level=0);compressed=self.png(level=9)
        self.assertNotEqual(original,compressed);self.assertTrue(assets.png_matches(original,compressed))
        self.assertFalse(assets.png_matches(original,self.png(color=(124,45,67,128),level=9)))
        self.assertFalse(assets.png_matches(original,b'not a PNG'))
        stream=io.BytesIO();Image.new('RGBA',(4,2),(123,45,67,128)).save(stream,format='PNG')
        self.assertFalse(assets.png_matches(original,stream.getvalue()))


if __name__=='__main__':unittest.main()
