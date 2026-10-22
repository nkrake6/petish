"""Reviewable Cart release boundaries and recovery; no remote calls."""
import importlib.util
import json
from pathlib import Path
import re
import subprocess
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[1]
def module(name):
    spec=importlib.util.spec_from_file_location(name.replace('-','_'),ROOT/'scripts'/f'{name}.py')
    value=importlib.util.module_from_spec(spec);spec.loader.exec_module(value);return value

class CartReleaseBoundaryTests(unittest.TestCase):
    def test_fixture_and_browser_check_sets_match_release_gate(self):
        safe=module('sanitize-homepage-result')
        php=(ROOT/'scripts/cart-badge-staging-proof.php').read_text()
        for variable,constant in [('CART_PREPARE','CART_BADGE_FIXTURE_CHECKS'),('CART_CLEANUP','CART_BADGE_CLEANUP_CHECKS')]:
            text=re.search(r'const '+constant+r' = \[(.*?)\];',php,re.S).group(1)
            self.assertEqual(getattr(safe,variable),set(re.findall(r"'([a-z0-9_]+)'",text)))
        browser=(ROOT/'tools/homepage/cart-badge-browser.mjs').read_text()
        checks=re.search(r'CHECK_KEYS=Object.freeze\(\[(.*?)\]\)',browser,re.S).group(1)
        self.assertEqual(safe.CART_BROWSER,set(re.findall(r"'([a-z0-9_]+)'",checks)))
        diagnostics=re.search(r'DIAGNOSTIC_KEYS=Object.freeze\(\[(.*?)\]\)',browser,re.S).group(1)
        self.assertEqual(safe.CART_BROWSER_DIAGNOSTICS,set(re.findall(r"'([a-z0-9_]+)'",diagnostics)))
        with tempfile.TemporaryDirectory() as folder:
            path=Path(folder)/'report.json'
            for phase in ['cart_prepare','cart_browser','cart_cleanup']:
                raw={'status':'success','category':'complete','checks':dict.fromkeys(safe.REQUIRED[phase],True)}
                path.write_text(json.dumps(raw));self.assertTrue(safe.screen(phase,path)[1])
                raw['private_path']='private-sentinel';path.write_text(json.dumps(raw))
                report,passed=safe.screen(phase,path);self.assertFalse(passed);self.assertNotIn('private-sentinel',json.dumps(report))

    def test_update_failure_diagnostics_are_boolean_and_private_data_is_rejected(self):
        safe=module('sanitize-homepage-result')
        raw={'status':'failure','category':'update','checks':{'add_two_quantity_verified':True},
            'diagnostics':dict.fromkeys(safe.CART_BROWSER_DIAGNOSTICS,False)}
        raw['diagnostics'].update(update_request_seen=True,update_request_allowed=True,update_response_403=True,update_server_count_two=True)
        with tempfile.TemporaryDirectory() as folder:
            path=Path(folder)/'report.json';path.write_text(json.dumps(raw));report,passed=safe.screen('cart_browser',path)
            self.assertFalse(passed);self.assertEqual(report,raw)
            for key,value in [('private_response','sentinel'),('update_response_403',403),('update_server_count_two','2')]:
                changed=json.loads(json.dumps(raw));changed['diagnostics'][key]=value;path.write_text(json.dumps(changed))
                report,passed=safe.screen('cart_browser',path);self.assertFalse(passed);self.assertEqual(report['category'],'invalid_report')
                self.assertNotIn('sentinel',json.dumps(report))

    def test_only_four_theme_files_change_and_dependencies_precede_header(self):
        evidence=module('validate-homepage-release')
        changed={'includes/header-navigation.php','includes/cart-badge.php','assets/css/cart-badge.css','assets/js/cart-badge.js'}
        actual=set()
        for name in evidence.FILES:
            prior=subprocess.run(['git','show','f8be162:'+evidence.PREFIX+name],cwd=ROOT,capture_output=True)
            if prior.returncode or prior.stdout!=(ROOT/(evidence.PREFIX+name)).read_bytes():actual.add(name)
        self.assertEqual(actual,changed)
        for dependency in changed-{'includes/header-navigation.php'}:
            self.assertLess(evidence.FILES.index(dependency),evidence.FILES.index('includes/header-navigation.php'))

    def test_cart_cleanup_failure_retains_remote_recovery_tools(self):
        source=(ROOT/'scripts/hostinger-homepage.sh').read_text()
        function=source[source.index('finish() {'):source.index('\ntrap finish EXIT')]
        for success in (True,False):
            with self.subTest(success=success),tempfile.TemporaryDirectory() as folder:
                work=Path(folder)/'work';work.mkdir();log=Path(folder)/'calls'
                script='\n'.join([
                    'work=$1; log=$2; outcome=0; mode=staging; ssh_ready=1; rollback_needed=0',
                    'fixture_cleanup_needed=0; fixture_cleanup_verified=0; cart_cleanup_needed=1; cart_cleanup_verified=0',
                    'operation_verified=0; failure_category=cart_browser; result_status=unavailable',
                    'cart_fixture_phase() { printf "cart_cleanup\\n" >> "$log"; '+('return 0;' if success else 'return 1;')+' }',
                    'remote_cleanup() { printf "remove_tools\\n" >> "$log"; }',
                    'publish_result() { printf "publish:%s:%s\\n" "$result_status" "$failure_category" >> "$log"; }',
                    function,'false','finish'])
                completed=subprocess.run(['bash','-c',script,'proof',str(work),str(log)],capture_output=True)
                self.assertEqual(completed.returncode,1)
                self.assertEqual(log.read_text().splitlines(),['cart_cleanup']+(['remove_tools'] if success else [])+
                    ['publish:failure:'+('cart_browser' if success else 'cart_cleanup')])
                self.assertFalse(completed.stderr)

    def test_real_cart_phase_is_required_and_only_staging_can_reach_it(self):
        source=(ROOT/'scripts/hostinger-homepage.sh').read_text()
        self.assertLess(source.index('cart_cleanup_needed=1'),source.index('    cart_fixture_phase prepare'))
        self.assertLess(source.index('    cart_fixture_phase cleanup'),source.index('\nfailure_category=inspect'))
        self.assertIn('cart-badge-staging-proof.php',source[source.index('if [[ $mode == staging ]]; then\n    cp'):source.index('\nelse\n    for name in deploy-homepage')])
        self.assertNotIn('cart-badge-staging-proof.php',source[source.index('\nelse\n    for name in deploy-homepage'):source.index('\nfailure_category=transport')])

    def test_fixed_asset_get_retries_preserve_http_and_hash_requirements(self):
        completed=subprocess.run(['node','tests/homepage-cart-badge-assets.mjs'],cwd=ROOT,capture_output=True,timeout=15)
        self.assertEqual(completed.returncode,0,completed.stderr.decode())
        self.assertEqual(completed.stdout,b'Cart badge fixed asset GET checks passed.\n')

    def test_native_login_precedes_asset_gets_and_guest_warmup_never_posts(self):
        source=(ROOT/'tools/homepage/cart-badge-browser.mjs').read_text()
        self.assertLess(source.index("mark(report,'customer_login_verified'"),source.index('await fetchCartAsset(context.request'))
        warm=source[source.index("guest=await browser.newContext"):source.index("const denied=await guest.request.get")]
        self.assertIn("request.method()==='GET'",warm)
        self.assertIn("cookie=>!/^wordpress_",warm)
        self.assertNotIn('.click(',warm)
        workflow=(ROOT/'.github/workflows/homepage.yml').read_text()
        self.assertIn('id: prepare\n        timeout-minutes: 8\n        continue-on-error: true',workflow)
        self.assertIn('timeout-minutes: 20',workflow)

    def test_new_browser_report_and_request_guard_are_finite(self):
        completed=subprocess.run(['node','tests/homepage-cart-badge-scope.mjs'],cwd=ROOT,capture_output=True,timeout=15)
        self.assertEqual(completed.returncode,0,completed.stderr.decode())
        self.assertEqual(completed.stdout,b'Cart badge request boundaries passed.\n')

if __name__=='__main__':unittest.main()
