"""Bounded homepage release evidence and public HTTP fixtures; no remote access."""
import copy
from datetime import datetime,timezone,timedelta
import hashlib
import io
import importlib.util
import json
from pathlib import Path
import tempfile
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
        if phase in {'inspect','apply','commit','rollback'}:value['changed_file_count']=4
        if phase=='verify':value['checks']['free_delivery_products_available']=False
        if phase=='browser':value['section_present']=False
        return value

    def screen(self,phase,value):
        path=self.directory/'raw.json';path.write_text(json.dumps(value));return safe.screen(phase,path)

    def test_hidden_section_is_a_successful_verified_state(self):
        for phase in ('inspect','apply','verify','browser','health','assets','commit','rollback'):
            with self.subTest(phase=phase):
                value=self.raw(phase);report,passed=self.screen(phase,value)
                self.assertTrue(passed);self.assertEqual(report,value)

    def test_early_native_failure_keeps_its_finite_category(self):
        value={'status':'failure','category':'assets','checks':{'target_paths_verified':True}}
        report,passed=self.screen('verify',value)
        self.assertFalse(passed);self.assertEqual(report,value)

    def test_browser_diagnostics_preserve_only_fixed_boolean_observations(self):
        value=self.raw('browser');value['status']='failure';value['category']='assets'
        value['checks']['badge_png_image_matches']=False
        value['diagnostics']=dict.fromkeys(safe.BROWSER_DIAGNOSTICS,False)
        value['diagnostics']['badge_response_signature_png']=True
        value['image_dimensions']={'expected_width':2061,'expected_height':763,'response_width':1,'response_height':1}
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
        value=self.raw('apply');value['changed_file_count']=5;bad.append(('apply',value))
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
        stage={'status':'success','mode':'staging','staging_source_verified':True,'source_commit':revision,
            'source_hashes':{name:hashlib.sha256((ROOT/(evidence.PREFIX+name)).read_bytes()).hexdigest() for name in evidence.FILES},
            'phases':{phase:self.raw(phase) for phase in ('verify','browser')}}
        stage['phases']['preflight']={'status':'success','category':'complete','checks':dict.fromkeys(safe.PREFLIGHT,True)}
        backup={'AUTOMATION_KIND':'backup','AUTOMATION_RESULT':'success','GITHUB_RUN_ID':'12345','GITHUB_RUN_ATTEMPT':'1',
            'generated_utc':datetime.now(timezone.utc).isoformat(),
            'restore_check':{'restore_smoke_test_passed':True,'database_import_verified':True}}
        checks={'wordpress_application_recovery_passed','database_import_verified','wordpress_bootstrap_verified',
            'homepage_http_verified','shop_http_verified','product_http_verified','cart_http_verified','account_http_verified'}
        recovery={'AUTOMATION_KIND':'recovery','AUTOMATION_RESULT':'success','backup_branch':request['backup_branch'],
            'recovery_check':dict.fromkeys(checks,True)}
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
        for arguments in ({'changed':'assets/css/home.css'},{'changed':'assets/images/ui/free-delivery-van.png'},
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
