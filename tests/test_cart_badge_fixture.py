"""Exercise the staging fixture's row and request boundaries without site access."""
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = shutil.which('php') or '/workspace/.petish-dev/bin/php'
SCRIPT = ROOT / 'scripts/cart-badge-staging-proof.php'


class CartBadgeFixtureTests(unittest.TestCase):
    def php(self, payload):
        code = '''define('CART_BADGE_PROOF_LIBRARY',true);require $argv[1];
        $v=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
        $owner=static fn($id)=>$id===22?['user_id'=>7,'meta_key'=>'session_tokens']:($id===23?['user_id'=>8,'meta_key'=>'session_tokens']:['user_id'=>7,'meta_key'=>'wp_capabilities']);
        $state=['customer'=>['id'=>7,'login'=>'owned-login','password'=>'owned-password'],'product'=>['id'=>31,'path'=>'/product/owned-fixture/']];
        $out=[];foreach($v as$item){
          if($item['kind']==='sql')$out[]=cart_badge_runtime_sql_allowed($item['sql'],'wp_',$item['uid']??7,$owner);
          elseif($item['kind']==='request')$out[]=cart_badge_request_allowed($state,$item['method'],$item['path'],$item['query']??[],$item['post']??[],$item['json']??[],$item['uid']??7);
          else throw new RuntimeException();
        }echo json_encode($out,JSON_THROW_ON_ERROR);'''
        result = subprocess.run([PHP, '-d', 'display_errors=0', '-r', code, str(SCRIPT)],
                                input=json.dumps(payload), text=True, capture_output=True, timeout=10)
        self.assertEqual(result.returncode, 0, result.stderr)
        return json.loads(result.stdout)

    def test_native_owned_session_queries_are_allowed(self):
        statements = [
            "SELECT option_value FROM wp_options WHERE option_name = 'example'",
            "SELECT @@SESSION.sql_mode",
            "SHOW TABLES LIKE 'wp_woocommerce_sessions'",
            "SELECT GET_LOCK('petish-cart-owned', 15)",
            "INSERT INTO wp_woocommerce_sessions (`session_key`, `session_value`, `session_expiry`) VALUES ('7', 'cart', 123456) ON DUPLICATE KEY UPDATE `session_value` = VALUES(`session_value`), `session_expiry` = VALUES(`session_expiry`)",
            "UPDATE wp_woocommerce_sessions SET session_expiry = 123456 WHERE session_key = '7'",
            "DELETE FROM wp_woocommerce_sessions WHERE session_key = '7'",
            "INSERT INTO wp_usermeta (user_id,meta_key,meta_value) VALUES (7,'session_tokens','owned')",
            "UPDATE wp_usermeta SET meta_value = 'owned' WHERE umeta_id = 22",
            "UPDATE wp_usermeta SET meta_value = 'owned' WHERE user_id = 7 AND meta_key = 'session_tokens'",
            "DELETE FROM wp_usermeta WHERE user_id = 7 AND meta_key = '_woocommerce_persistent_cart_1'",
        ]
        self.assertEqual(self.php([{'kind': 'sql', 'sql': sql} for sql in statements]), [True] * len(statements))

    def test_foreign_users_orders_settings_stock_and_ambiguous_sql_are_denied(self):
        statements = [
            "INSERT INTO wp_woocommerce_sessions (session_key,session_value,session_expiry) VALUES ('8','foreign',123)",
            "UPDATE wp_woocommerce_sessions SET session_value='bad' WHERE session_key='8'",
            "UPDATE wp_woocommerce_sessions SET session_key='8' WHERE session_key='7'",
            "DELETE FROM wp_woocommerce_sessions WHERE session_expiry < 123",
            "INSERT INTO wp_woocommerce_sessions (session_key,session_value,session_expiry) VALUES ('7','a',123),('8','b',123)",
            "INSERT INTO wp_usermeta (user_id,meta_key,meta_value) VALUES (8,'session_tokens','bad')",
            "UPDATE wp_usermeta SET meta_value='bad' WHERE umeta_id=23",
            "UPDATE wp_usermeta SET meta_value='bad' WHERE umeta_id=24",
            "UPDATE wp_usermeta SET meta_value='bad' WHERE user_id=7 AND meta_key='wp_capabilities'",
            "DELETE FROM wp_usermeta WHERE user_id=7",
            "DELETE FROM wp_usermeta WHERE user_id=7 OR user_id=8",
            "DELETE FROM wp_usermeta WHERE umeta_id IN (22,23)",
            "UPDATE wp_users SET user_pass='bad' WHERE ID=8",
            "INSERT INTO wp_wc_orders (customer_id) VALUES (7)",
            "UPDATE wp_postmeta SET meta_value=0 WHERE post_id=31 AND meta_key='_stock'",
            "UPDATE wp_options SET option_value='bad' WHERE option_name='smtp_password'",
            "SELECT 1; DELETE FROM wp_users", "SELECT 1 INTO OUTFILE '/tmp/file'",
            "SELECT SLEEP(10)", "SELECT 1 /* mutation */", "SELECT 1 -- mutation",
            "UPDATE wp_usermeta SET meta_value='x' WHERE umeta_id=22;DELETE FROM wp_users",
        ]
        self.assertEqual(self.php([{'kind': 'sql', 'sql': sql} for sql in statements]), [False] * len(statements))

    def test_guest_sql_is_read_only_even_for_the_fixture_customer(self):
        statements = ["SELECT ID FROM wp_users WHERE ID=7",
                      "INSERT INTO wp_usermeta (user_id,meta_key,meta_value) VALUES (7,'session_tokens','x')",
                      "INSERT INTO wp_woocommerce_sessions (session_key,session_value,session_expiry) VALUES ('guest','x',123)"]
        self.assertEqual(self.php([{'kind': 'sql', 'sql': sql, 'uid': 0} for sql in statements]), [True, False, False])

    def test_quotes_do_not_turn_user_values_into_sql_commands(self):
        allowed = "UPDATE wp_usermeta SET meta_value = 'quoted; DELETE FROM wp_users' WHERE umeta_id = 22"
        escaped = "UPDATE wp_usermeta SET meta_value = 'escaped\\\'; OR user_id=8' WHERE umeta_id = 22"
        self.assertEqual(self.php([{'kind': 'sql', 'sql': sql} for sql in [allowed, escaped]]), [True, True])

    def test_only_owned_wordpress_login_and_anonymous_count_are_permitted(self):
        requests = [
            {'method': 'GET', 'path': '/wp-login.php', 'uid': 0,
             'query': {'redirect_to': 'https://staging.petish.biz/my-account/'}},
            {'method': 'POST', 'path': '/wp-login.php', 'uid': 0,
             'post': {'log': 'owned-login', 'pwd': 'owned-password', 'wp-submit': 'Log In'}},
            {'method': 'POST', 'path': '/', 'uid': 0, 'query': {'wc-ajax': 'petish_cart_count'}},
            {'method': 'GET', 'path': '/', 'uid': 0, 'query': {'wc-ajax': 'petish_cart_count'}},
            {'method': 'POST', 'path': '/', 'uid': 0, 'query': {'wc-ajax': 'petish_cart_count'}, 'post': {'product_id': '31'}},
            {'method': 'POST', 'path': '/wp-login.php', 'uid': 0, 'post': {'log': 'other-login', 'pwd': 'owned-password'}},
            {'method': 'POST', 'path': '/wp-login.php', 'uid': 0, 'post': {'log': 'owned-login', 'pwd': 'wrong'}},
            {'method': 'GET', 'path': '/wp-login.php', 'uid': 0, 'query': {'action': 'logout'}},
            {'method': 'GET', 'path': '/cart/', 'uid': 0},
        ]
        self.assertEqual(self.php([{'kind': 'request', **r} for r in requests]), [True, True, True, False, False, False, False, False, False])

    def test_owned_cart_quantity_two_three_and_remove_routes(self):
        import hashlib
        key = hashlib.md5(b'31').hexdigest()
        requests = [
            {'method': 'POST', 'path': '/product/owned-fixture/', 'post': {'quantity': '2', 'add-to-cart': '31'}},
            {'method': 'POST', 'path': '/cart/', 'post': {'cart': {key: {'qty': '3'}}, 'update_cart': 'Update cart', 'woocommerce-cart-nonce': '1234567890'}},
            {'method': 'GET', 'path': '/cart/', 'query': {'remove_item': key, '_wpnonce': '1234567890'}},
            {'method': 'GET', 'path': '/wp-json/wc/store/v1/cart'},
            {'method': 'POST', 'path': '/wp-json/wc/store/v1/cart/update-item', 'json': {'quantity': 3, 'key': key}},
            {'method': 'POST', 'path': '/wp-json/wc/store/v1/cart/remove-item', 'json': {'key': key}},
            {'method': 'POST', 'path': '/wp-json/wc/store/v1/batch', 'json': {'requests': [{'method': 'POST', 'path': '/wc/store/v1/cart/update-item', 'body': {'key': key, 'quantity': 3}}]}},
        ]
        self.assertEqual(self.php([{'kind': 'request', **r} for r in requests]), [True] * len(requests))

    def test_checkout_foreign_products_cart_keys_and_batch_escalation_are_denied(self):
        requests = [
            {'method': 'GET', 'path': '/checkout/'},
            {'method': 'POST', 'path': '/checkout/', 'post': {'place_order': '1'}},
            {'method': 'POST', 'path': '/product/owned-fixture/', 'post': {'quantity': '2', 'add-to-cart': '99'}},
            {'method': 'POST', 'path': '/product/owned-fixture/', 'post': {'quantity': '2', 'add-to-cart': '31', 'product_id': '99'}},
            {'method': 'POST', 'path': '/product/owned-fixture/', 'post': {'quantity': '20', 'add-to-cart': '31'}},
            {'method': 'POST', 'path': '/wp-json/wc/store/v1/checkout', 'json': {}},
            {'method': 'POST', 'path': '/wp-json/wc/store/v1/cart/remove-item', 'json': {'key': 'foreign'}},
            {'method': 'POST', 'path': '/wp-json/wc/store/v1/batch', 'json': {'requests': [{'method': 'POST', 'path': '/wc/store/v1/checkout', 'body': {}}]}},
            {'method': 'POST', 'path': '/wp-json/wc/store/v1/batch', 'json': {'requests': [{'method': 'POST', 'path': '/wc/store/v1/batch', 'body': {'requests': []}}]}},
            {'method': 'GET', 'path': '/', 'query': {'wc-api': 'dsers'}},
            {'method': 'POST', 'path': '/wp-admin/admin-ajax.php', 'post': {'action': 'anything'}},
            {'method': 'GET', 'path': '/my-account/customer-logout/'},
        ]
        self.assertEqual(self.php([{'kind': 'request', **r} for r in requests]), [False] * len(requests))

    def test_native_batch_nonce_headers_are_allowed_without_expanding_cart_scope(self):
        import hashlib
        key = hashlib.md5(b'31').hexdigest()
        requests = []
        for headers in [{}, {'Nonce': '0123456789'}, {'X-WC-Store-API-Nonce': 'abcdef0123'},
                        {'nonce': '0123456789', 'Content-Type': 'application/json',
                         'x-wc-store-api-nonce': 'abcdef0123'}]:
            requests.append({'kind': 'request', 'method': 'POST', 'path': '/wp-json/wc/store/v1/batch',
                             'json': {'requests': [{'method': 'POST', 'path': '/wc/store/v1/cart/update-item',
                                                    'body': {'key': key, 'quantity': 3}, 'headers': headers}]}})
        self.assertEqual(self.php(requests), [True] * len(requests))
        for body in [{'key': 'foreign', 'quantity': 3}, {'key': key, 'quantity': 4}]:
            requests = [{'kind': 'request', 'method': 'POST', 'path': '/wp-json/wc/store/v1/batch',
                         'json': {'requests': [{'method': 'POST', 'path': '/wc/store/v1/cart/update-item',
                                                'body': body, 'headers': {'Nonce': '0123456789'}}]}}]
            self.assertEqual(self.php(requests), [False])

    def test_batch_foreign_headers_and_malformed_values_are_denied(self):
        import hashlib
        key = hashlib.md5(b'31').hexdigest()
        invalid = [{'Authorization': 'Bearer token'}, {'Cookie': 'other=identity'},
                   {'X-WP-Nonce': '0123456789'}, {'Host': 'foreign.example'},
                   {'Nonce': '0123456789', 'nonce': 'abcdef0123'}, {'nonce': ['0123456789']},
                   {'nonce': None}, {'nonce': 123}, {'nonce': ''}, {'nonce': ' '},
                   {'nonce': 'a' * 257}, {'nonce': '0123456789\r\nCookie: injected'},
                   {'content-type': 'application/json\x00'}, ['0123456789'], None]
        requests = [{'kind': 'request', 'method': 'POST', 'path': '/wp-json/wc/store/v1/batch',
                     'json': {'requests': [{'method': 'POST', 'path': '/wc/store/v1/cart/update-item',
                                            'body': {'key': key, 'quantity': 3}, 'headers': headers}]}}
                    for headers in invalid]
        self.assertEqual(self.php(requests), [False] * len(requests))


if __name__ == '__main__':
    unittest.main()
