"""Attest actual Reflection callbacks while preserving every unrelated guard."""
import hashlib
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[1]
PHP=shutil.which('php') or '/workspace/.petish-dev/bin/php'
HARNESS=r'''
define('CART_BADGE_PROOF_LIBRARY',true);require $argv[1];
$wp_filter=[];$removed=0;
function add_action($hook,$callback,$priority=10,$accepted=1){
 global$wp_filter;if(!isset($wp_filter[$hook]))$wp_filter[$hook]=(object)['callbacks'=>[]];
 $key=is_object($callback)?spl_object_id($callback):$callback;
 $wp_filter[$hook]->callbacks[$priority][$key]=['function'=>$callback,'accepted_args'=>$accepted];
}
function add_filter(...$args){add_action(...$args);}
function remove_action($hook,$callback,$priority){
 global$wp_filter,$removed;$key=is_object($callback)?spl_object_id($callback):$callback;
 if(!isset($wp_filter[$hook]->callbacks[$priority][$key]))return false;
 unset($wp_filter[$hook]->callbacks[$priority][$key]);$removed++;return true;
}
require $argv[2];
if($argv[3]==='priority'){
 $entries=$wp_filter['template_redirect']->callbacks[-1000];unset($wp_filter['template_redirect']->callbacks[-1000]);
 $wp_filter['template_redirect']->callbacks[7]=$entries;
}
if($argv[3]==='accepted')foreach($wp_filter['template_redirect']->callbacks as&$entries)foreach($entries as&$entry)$entry['accepted_args']=0;
$before=hash_file('sha256',$argv[2]);$status=cart_badge_guest_auth_exempt();
echo json_encode(['status'=>$status,'reason'=>$GLOBALS['cart_badge_guest_auth_reason'],'removed'=>$removed,'source_unchanged'=>hash_equals($before,hash_file('sha256',$argv[2])),
 'outbound_guard_preserved'=>isset($wp_filter['pre_http_request']->callbacks[10]['__return_false'])],JSON_THROW_ON_ERROR);
'''


class CartGuestAuthTests(unittest.TestCase):
    def trial(self,guard,case='normal'):
        guard += "\nadd_filter('pre_http_request','__return_false');\n"
        with tempfile.TemporaryDirectory(prefix='petish-cart-auth-') as folder:
            root=Path(folder);site=root/'site';mu=site/'wp-content/mu-plugins';mu.mkdir(parents=True)
            file=mu/'petish-staging-guard.php';file.write_text(guard);file.chmod(0o600)
            helper=(ROOT/'scripts/cart-badge-staging-proof.php').read_text()
            helper=helper.replace("const CART_BADGE_HOME = '/home/u839205597';",f"const CART_BADGE_HOME = '{root}';")
            helper=helper.replace("const CART_BADGE_ROOT = '/home/u839205597/domains/staging.petish.biz/public_html';",f"const CART_BADGE_ROOT = '{site}';")
            old="const CART_BADGE_GUARD_HASH = 'f7615da9180aff6b8b85d81f5821ed1bd4933970adeee3462dd252a502681ee6';"
            helper=helper.replace(old,"const CART_BADGE_GUARD_HASH = '"+hashlib.sha256(file.read_bytes()).hexdigest()+"';")
            script=root/'helper.php';script.write_text(helper)
            result=subprocess.run([PHP,'-r',HARNESS,str(script),str(file),case],capture_output=True,text=True,timeout=10)
            self.assertEqual(result.returncode,0,result.stderr)
            value=json.loads(result.stdout)
            self.assertTrue(value['source_unchanged']);self.assertTrue(value['outbound_guard_preserved'])
            return value

    def test_inline_dedicated_auth_callback_only_is_removed(self):
        result=self.trial("<?php add_action('template_redirect',static function(){ if(!is_user_logged_in()){ auth_redirect(); } },-1000);")
        self.assertEqual((result['status'],result['removed']),('authorized',1))

    def test_multiline_named_auth_callback_and_read_only_conditions_are_attested(self):
        result=self.trial("""<?php
function petish_private_auth(): void {
 if (PHP_SAPI === 'cli' || (defined('WP_CLI') && WP_CLI)) return;
 if (!is_user_logged_in() && !is_admin()) auth_redirect();
}
add_action('template_redirect','petish_private_auth',-1000);
""")
        self.assertEqual((result['status'],result['removed']),('authorized',1))

    def test_mixed_write_or_outbound_callbacks_are_never_removed(self):
        for extra in ["wp_mail('private','private','private');", "update_option('private','private');", "$x=1;", "do_action('private');"]:
            with self.subTest(extra=extra):
                result=self.trial("<?php add_action('template_redirect',function(){if(!is_user_logged_in())auth_redirect();"+extra+"},-1000);")
                self.assertEqual((result['status'],result['removed']),('unsupported',0))
                self.assertEqual(result['reason'],'body')

    def test_captured_variables_or_indirect_registration_fail_closed(self):
        for source in ["<?php $secret='private-sentinel';add_action('template_redirect',function()use($secret){if(!is_user_logged_in())auth_redirect();},-1000);",
                       "<?php $callback=function(){if(!is_user_logged_in())auth_redirect();};add_action('template_redirect',$callback,-1000);"]:
            result=self.trial(source);self.assertEqual((result['status'],result['removed']),('unsupported',0))
            self.assertNotIn('private-sentinel',json.dumps(result))

    def test_runtime_priority_or_argument_changes_fail_before_removal(self):
        source="<?php add_action('template_redirect',function(){if(!is_user_logged_in())auth_redirect();},-1000);"
        for case in ['priority','accepted']:
            result=self.trial(source,case);self.assertEqual((result['status'],result['removed']),('priority_mismatch',0))

    def test_ambiguous_auth_callbacks_are_preserved(self):
        source="<?php add_action('template_redirect',function(){if(!is_user_logged_in())auth_redirect();},-1000);\nadd_action('template_redirect',function(){if(!is_user_logged_in())auth_redirect();},10);"
        result=self.trial(source);self.assertEqual((result['status'],result['removed']),('ambiguous',0))


if __name__=='__main__':unittest.main()
