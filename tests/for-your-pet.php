<?php
/** Exercise real recommendation policy with isolated WordPress/Woo boundary stubs. */
error_reporting( E_ALL );
set_error_handler( static function( $severity, $message, $file, $line ) { throw new ErrorException( $message, 0, $severity, $file, $line ); } );
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error { public function __construct( public $code, public $message = '', public $data = array() ) {} }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function add_action( ...$args ) { $GLOBALS['hooks'][] = $args; } function add_filter( ...$args ) { $GLOBALS['hooks'][] = $args; }
function absint( $value ) { return abs( (int) $value ); }
function get_current_user_id() { return $GLOBALS['uid']; }
function get_user_by( $field, $id ) { return $GLOBALS['users'][ $id ] ?? false; }
function get_user_meta( $id, $key, $single ) { return $GLOBALS['usermeta'][ $id ][ $key ] ?? ''; }
function update_user_meta( ...$args ) { throw new Exception( 'unexpected private write' ); }
function is_email( $email ) { return filter_var( $email, FILTER_VALIDATE_EMAIL ); }
function sanitize_text_field( $text ) { return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( $text ) ) ); }
function current_user_can( $cap, ...$args ) { return $GLOBALS['caps'][ $cap ] ?? false; }
function wp_unslash( $value ) { return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( $value ); }
function wp_verify_nonce( $value, $action ) { return $value === 'nonce:' . $action; }
function wp_is_post_revision( $id ) { return false; }
function register_post_meta( ...$args ) { $GLOBALS['registered'][] = $args; }
function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES ); } function esc_attr( $text ) { return esc_html( $text ); }
function esc_html__( $text, ...$args ) { return esc_html( $text ); }
function esc_url( $url ) { return preg_match( '/^https?:\/\//', $url ) ? htmlspecialchars( $url, ENT_QUOTES ) : ''; }
function wp_kses_post( $html ) { return preg_replace( '/<script\b[^>]*>.*?<\/script>/is', '', preg_replace( '/\s+on[a-z]+="[^"]*"/i', '', $html ) ); }
function home_url( $path ) { return 'https://petish.invalid' . $path; }
function wc_get_page_permalink( $page ) { return 'https://petish.invalid/my-account/'; }
function wc_get_is_paid_statuses() { return $GLOBALS['paid']; }
function get_metadata_by_mid( $type, $id ) { return $GLOBALS['metadata'][ $id ] ?? false; }
function wp_nonce_field( $action, $field ) { echo '<input name="' . esc_attr( $field ) . '" value="nonce:' . esc_attr( $action ) . '">'; }
function checked( $expected, $actual, $echo ) { return $expected === $actual ? 'checked="checked"' : ''; }
class WC_Admin_Meta_Boxes { public static function add_error( $message ) { $GLOBALS['admin_errors'][] = $message; } }
class WC_Meta_Data { public function __construct( private $data ) {} public function get_data() { return $this->data; } }
class WC_Product {
    public $meta = array(), $status = 'publish', $visible = true, $purchasable = true, $stock = true, $name, $url, $price = '<b>$20</b>', $image = '<img src="https://petish.invalid/item.jpg">', $review = 'eligible';
    public function __construct( public $id, public $date = 100, public $type = 'simple', public $parent = 0 ) { $this->name = 'Item ' . $id; $this->url = 'https://petish.invalid/product/' . $id; }
    public function get_id() { return $this->id; } public function get_parent_id() { return $this->parent; }
    public function get_meta( $key, ...$args ) { return $this->meta[ $key ] ?? ''; }
    public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
    public function get_status() { return $this->status; } public function is_type( $type ) { return in_array( $this->type, (array) $type, true ); }
    public function is_visible() { return $this->visible; } public function is_purchasable() { return $this->purchasable; } public function is_in_stock() { return $this->stock; }
    public function get_name() { return $this->name; } public function get_permalink() { return $this->url; } public function get_price_html() { return $this->price; }
    public function get_image( ...$args ) { return $this->image; }
}
class WC_Order_Item_Product {
    public function __construct( public $product_id, public $variation_id = 0 ) {}
    public function get_product_id() { return $this->product_id; } public function get_variation_id() { return $this->variation_id; }
}
class WC_Order {
    public function __construct( public $id, public $customer, public $email, public $status, public $items ) {}
    public function get_id() { return $this->id; } public function get_customer_id() { return $this->customer; }
    public function get_billing_email() { return $this->email; } public function get_status() { return $this->status; }
    public function get_items( $type ) { if ( $type !== 'line_item' ) { throw new Exception( 'wrong items type' ); } return $this->items; }
}
class Petish_Passport_Store {
    const ACTIVE = '_petish_active_pet';
    public static function get( $id, $uid ) { return isset( $GLOBALS['pets'][ $id ] ) && $GLOBALS['pets'][ $id ]['owner'] === $uid ? $GLOBALS['pets'][ $id ] : new WP_Error( 'missing' ); }
    public static function active( $uid ) { throw new Exception( 'mutating active fallback was used' ); }
}
class Petish_Product_Review {
    public static function species() { return array( 'rabbit' => 'Rabbit', 'hamster' => 'Hamster', 'guinea-pig' => 'Guinea pig', 'chinchilla' => 'Chinchilla' ); }
    public static function record( $product, $species ) { $GLOBALS['reviews'][] = array( $product->id, $species ); return array( 'status' => $product->review ); }
}
class Petish_Catalogue {
    public static function url() { return 'https://petish.invalid/my-account/for-my-pet/'; }
    public static function reviews_visible() { return $GLOBALS['reviews_visible']; }
    public static function label( $product, $species ) { return '<review> ' . $species; }
}
class Petish_Passport_UI { public static function url() { return 'https://petish.invalid/my-account/my-pets/'; } }
function petish_supplier_free_delivery_badge( $product ) { return '<span class="free">Free delivery</span>'; }
function wc_get_product( $id ) {
    if ( $GLOBALS['product_error'] ) { throw new Exception( 'product read failed' ); }
    return $GLOBALS['products'][ $id ] ?? false;
}
function wc_get_orders( $query ) {
    $GLOBALS['order_queries'][] = $query;
    if ( $GLOBALS['order_error'] ) { return new WP_Error( 'db' ); }
    if ( $GLOBALS['order_exception'] ) { throw new Exception( 'db' ); }
    $orders = array_values( array_filter( $GLOBALS['orders'], static function( $order ) use ( $query ) {
        return $order->customer === $query['customer_id'] && ( ! isset( $query['billing_email'] ) || 0 === strcasecmp( $order->email, $query['billing_email'] ) ) && in_array( $order->status, $query['status'], true );
    } ) );
    usort( $orders, static fn( $a, $b ) => $a->id <=> $b->id );
    $result = (object) array( 'orders' => array_slice( $orders, ( $query['page'] - 1 ) * $query['limit'], $query['limit'] ), 'total' => count( $orders ), 'max_num_pages' => (int) ceil( count( $orders ) / $query['limit'] ) );
    return $GLOBALS['order_mutation'] ? ( $GLOBALS['order_mutation'] )( $result, $query, count( $GLOBALS['order_queries'] ) ) : $result;
}
function wc_get_products( $query ) {
    $GLOBALS['product_queries'][] = $query;
    if ( $GLOBALS['products_error'] ) { return new WP_Error( 'db' ); }
    // Deliberately leave all other filters to the real helper's defensive runtime checks.
    $products = array_values( array_filter( $GLOBALS['products'], static fn( $p ) => ! in_array( $p->id, $query['exclude'], true ) ) );
    usort( $products, static fn( $a, $b ) => ( $b->date <=> $a->date ) ?: ( $b->id <=> $a->id ) );
    return (object) array( 'products' => array_slice( $products, ( $query['page'] - 1 ) * $query['limit'], $query['limit'] ), 'total' => count( $products ), 'max_num_pages' => (int) ceil( count( $products ) / $query['limit'] ) );
}
require dirname( __DIR__ ) . '/wordpress/wp-content/themes/dt-the7-child/includes/for-your-pet.php';
function fixture() {
    $GLOBALS['uid'] = 1; $GLOBALS['users'] = array( 1 => (object) array( 'ID' => 1, 'user_email' => 'owner@example.test' ), 2 => (object) array( 'ID' => 2, 'user_email' => 'member@example.test' ) );
    $GLOBALS['usermeta'] = array( 1 => array( Petish_Passport_Store::ACTIVE => 11 ), 2 => array( Petish_Passport_Store::ACTIVE => 12 ) );
    $GLOBALS['pets'] = array( 11 => array( 'owner' => 1, 'id' => 11, 'name' => 'PRIVATE PET NAME', 'species' => 'rabbit', 'bio' => 'PRIVATE BIO. Loves digging and exploring.', 'traits' => array( 'Curious' ) ), 12 => array( 'owner' => 2, 'id' => 12, 'species' => 'hamster', 'bio' => 'Loves sleeping.', 'traits' => array( 'Shy' ) ) );
    $GLOBALS['products'] = array(); $GLOBALS['orders'] = array(); $GLOBALS['paid'] = array( 'processing', 'completed' );
    $GLOBALS['caps'] = array( 'manage_woocommerce' => true, 'edit_post' => true );
    $GLOBALS['order_error'] = false; $GLOBALS['order_exception'] = false; $GLOBALS['products_error'] = false; $GLOBALS['product_error'] = false; $GLOBALS['order_mutation'] = null;
    $GLOBALS['order_queries'] = array(); $GLOBALS['product_queries'] = array(); $GLOBALS['reviews'] = array(); $GLOBALS['reviews_visible'] = false; $GLOBALS['metadata'] = array(); $GLOBALS['admin_errors'] = array(); $_GET = array(); $_POST = array();
}
function product( $id, $date = 100, $species = array( 'rabbit' ), $keywords = array( 'curious' ) ) {
    $product = new WC_Product( $id, $date );
    $product->meta = array( Petish_For_Your_Pet::ENABLED => '1', Petish_For_Your_Pet::SPECIES => $species, Petish_For_Your_Pet::KEYWORDS => $keywords );
    $GLOBALS['products'][ $id ] = $product; return $product;
}
function order( $id, $products, $status = 'completed', $uid = 1, $email = 'owner@example.test' ) {
    $items = array_map( static fn( $id ) => new WC_Order_Item_Product( $id ), $products );
    $order = new WC_Order( $id, $uid, $email, $status, $items ); $GLOBALS['orders'][] = $order; return $order;
}
function ids( $selection ) { return array_map( static fn( $p ) => $p->id, $selection['products'] ); }
function check( $name, $value ) { if ( ! $value ) { throw new Exception( 'Failed: ' . $name ); } $GLOBALS['checks'][ $name ] = true; }
$checks = array(); fixture();
$pet = array( 'bio' => 'Loves digging. Playful and CURIOUS!', 'traits' => array( 'Gentle' ) );
check( 'bio_whole_word_casefold', Petish_For_Your_Pet::matches( $pet, array( 'curious' ) ) );
check( 'exact_trait_match', Petish_For_Your_Pet::matches( $pet, array( 'gentle' ) ) );
check( 'whole_phrase_match', Petish_For_Your_Pet::matches( $pet, array( 'loves digging' ) ) );
check( 'word_not_substring', ! Petish_For_Your_Pet::matches( array( 'bio' => 'Inactive.', 'traits' => array() ), array( 'active' ) ) );
check( 'trait_not_substring', ! Petish_For_Your_Pet::matches( array( 'bio' => '', 'traits' => array( 'Very curious' ) ), array( 'curious' ) ) );
check( 'unicode_word_casefold', Petish_For_Your_Pet::matches( array( 'bio' => 'Très CURIEUX. ÉNERGIQUE.', 'traits' => array() ), array( 'énergique' ) ) );
check( 'unicode_word_boundaries', ! Petish_For_Your_Pet::matches( array( 'bio' => 'éactiveé', 'traits' => array() ), array( 'active' ) ) );
check( 'phrase_spacing_normalized', Petish_For_Your_Pet::matches( array( 'bio' => 'Loves   exploring tunnels.', 'traits' => array() ), array( 'exploring tunnels' ) ) );
foreach ( array( 'Does not like digging.', 'Never digging.', 'Avoids digging.', "Doesn't like digging, chewing or running.", 'Not digging but climbing.', 'Rarely digging.', 'Afraid of digging.', 'Dislikes digging.' ) as $index => $bio ) {
    check( 'negative_sentence_' . $index, ! Petish_For_Your_Pet::matches( array( 'bio' => $bio, 'traits' => array() ), array( 'digging' ) ) );
}
check( 'negative_conjunction_does_not_reset', ! Petish_For_Your_Pet::matches( array( 'bio' => "Doesn’t like chewing, digging or running.", 'traits' => array() ), array( 'running' ) ) );
check( 'contradiction_vetoes_trait', ! Petish_For_Your_Pet::matches( array( 'bio' => 'Not curious.', 'traits' => array( 'Curious' ) ), array( 'curious' ) ) );
check( 'separate_positive_sentence_allowed', Petish_For_Your_Pet::matches( array( 'bio' => 'Does not like digging. Loves exploring.', 'traits' => array() ), array( 'digging', 'exploring' ) ) );
check( 'staff_aliases_are_explicit', Petish_For_Your_Pet::matches( array( 'bio' => '', 'traits' => array( 'Inquisitive' ) ), array( 'curious', 'inquisitive' ) ) );
check( 'no_semantic_alias_inference', ! Petish_For_Your_Pet::matches( array( 'bio' => '', 'traits' => array( 'Inquisitive' ) ), array( 'curious' ) ) );
check( 'invalid_utf8_fails_closed', ! Petish_For_Your_Pet::matches( array( 'bio' => "bad\xff", 'traits' => array() ), array( 'bad' ) ) );
check( 'malformed_profile_fails_closed', ! Petish_For_Your_Pet::matches( array( 'bio' => array(), 'traits' => array() ), array( 'curious' ) ) );
check( 'oversized_profile_fails_closed', ! Petish_For_Your_Pet::matches( array( 'bio' => str_repeat( 'a', 401 ), 'traits' => array( 'curious' ) ), array( 'curious' ) ) );

fixture(); product( 100 ); $_GET = array( 'user_id' => 2, 'pet_id' => 12 );
check( 'forged_query_identity_ignored', ids( Petish_For_Your_Pet::selection() ) === array( 100 ) );
$GLOBALS['uid'] = 2;
check( 'other_user_uses_own_species_and_traits', Petish_For_Your_Pet::selection()['state'] === 'empty' );
$GLOBALS['uid'] = 0;
check( 'guest_cannot_read_history', is_wp_error( Petish_For_Your_Pet::purchase_history() ) );
check( 'guest_has_no_products', Petish_For_Your_Pet::selection()['state'] === 'guest' );
$GLOBALS['uid'] = 1; $GLOBALS['usermeta'][1][Petish_Passport_Store::ACTIVE] = 12;
check( 'foreign_active_pet_denied', Petish_For_Your_Pet::selection()['state'] === 'no_pet' );
unset( $GLOBALS['usermeta'][1][Petish_Passport_Store::ACTIVE] );
check( 'missing_active_does_not_mutate_fallback', Petish_For_Your_Pet::selection()['state'] === 'no_pet' );
check( 'choose_pet_links_owner_passport', str_contains( Petish_For_Your_Pet::render(), 'https://petish.invalid/my-account/my-pets/' ) );
$GLOBALS['usermeta'][1]['_petish_club_deleting'] = '1';
check( 'deleting_account_denied', Petish_For_Your_Pet::selection()['state'] === 'unavailable' );

foreach ( array( 'status' => 'draft', 'visible' => false, 'purchasable' => false, 'stock' => false, 'review' => 'unreviewed' ) as $field => $value ) {
    fixture(); $p = product( 1 ); $p->$field = $value;
    check( 'runtime_' . $field . '_denied', Petish_For_Your_Pet::selection()['state'] === 'empty' );
}
fixture(); $p = product( 1 ); $p->review = 'excluded';
check( 'excluded_review_denied', Petish_For_Your_Pet::selection()['state'] === 'empty' );
$p->review = 'eligible'; $p->meta[Petish_For_Your_Pet::ENABLED] = '0';
check( 'uncurated_product_denied', Petish_For_Your_Pet::selection()['state'] === 'empty' );
$p->meta[Petish_For_Your_Pet::ENABLED] = '1'; $p->meta[Petish_For_Your_Pet::SPECIES] = array( 'hamster' );
check( 'curation_species_required', Petish_For_Your_Pet::selection()['state'] === 'empty' );
$p->meta[Petish_For_Your_Pet::SPECIES] = array( 'rabbit' ); $p->meta[Petish_For_Your_Pet::KEYWORDS] = array( 'sleepy' );
check( 'curation_personality_match_required', Petish_For_Your_Pet::selection()['state'] === 'empty' );
$p->meta[Petish_For_Your_Pet::KEYWORDS] = array( '<script>curious</script>' );
check( 'malformed_saved_rules_rejected', ! Petish_For_Your_Pet::rules( $p )['enabled'] );

fixture(); for ( $i = 1; $i <= 8; $i++ ) { product( $i, $i > 4 ? 200 : 300 ); }
order( 1, array( 4, 3 ), 'processing' );
check( 'purchases_excluded_before_limit_and_date_id_order', ids( Petish_For_Your_Pet::selection() ) === array( 2, 1, 8, 7 ) );
check( 'live_review_checked_each_candidate', count( $GLOBALS['reviews'] ) === 4 );
$query = Petish_For_Your_Pet::product_query( array( 'meta_query' => array( 'old' => 'retained' ) ), array( 'petish_for_your_pet' => true ) );
check( 'query_has_date_id_order_and_existing_clause', $query['orderby'] === array( 'date' => 'DESC', 'ID' => 'DESC' ) && $query['cache_results'] === false && $query['meta_query'][0] === array( 'old' => 'retained' ) );
check( 'unrelated_product_query_unchanged', Petish_For_Your_Pet::product_query( array( 'x' => 1 ), array() ) === array( 'x' => 1 ) );

fixture(); foreach ( array( 'processing', 'completed', 'refunded', 'pending', 'failed', 'cancelled', 'on-hold' ) as $i => $status ) { order( $i + 1, array( $i + 100 ), $status ); }
check( 'only_paid_and_refunded_are_prior_purchases', Petish_For_Your_Pet::purchase_history() === array( 100, 101, 102 ) );
$GLOBALS['paid'] = array( 'wc-processing', 'completed', 'paid-custom', 'failed', 'pending', 'cancelled' ); order( 9, array( 109 ), 'paid-custom' );
check( 'custom_paid_status_and_forbidden_statuses', Petish_For_Your_Pet::purchase_history() === array( 100, 101, 102, 109 ) );
fixture(); order( 1, array( 101 ), 'completed', 0, 'OWNER@example.test' ); order( 2, array( 102 ), 'completed', 0, 'other@example.test' ); order( 3, array( 103 ), 'completed', 2, 'owner@example.test' ); order( 4, array( 104 ), 'completed', 1, 'old@example.test' );
$history = Petish_For_Your_Pet::purchase_history(); sort( $history );
check( 'guest_email_and_account_identity_scoped', $history === array( 101, 104 ) );
$guest_queries = array_values( array_filter( $GLOBALS['order_queries'], static fn( $q ) => $q['customer_id'] === 0 ) );
check( 'guest_query_requires_zero_customer_and_account_email', count( $guest_queries ) === 2 && $guest_queries[0]['billing_email'] === 'owner@example.test' );
$GLOBALS['uid'] = 2;
check( 'history_switches_current_session_only', Petish_For_Your_Pet::purchase_history() === array( 103 ) );

fixture(); product( 200 ); $variation = product( 201 ); $variation->type = 'variation'; $variation->parent = 200;
$o = order( 1, array() ); $o->items = array( new WC_Order_Item_Product( 199, 201 ) );
$history = Petish_For_Your_Pet::purchase_history(); sort( $history );
check( 'line_parent_variation_and_current_parent_excluded', $history === array( 199, 200, 201 ) );
check( 'purchased_variation_hides_parent_recommendation', Petish_For_Your_Pet::selection()['state'] === 'empty' );

fixture(); for ( $i = 1; $i <= 121; $i++ ) { order( $i, array( 1000 + $i ) ); }
$history = Petish_For_Your_Pet::purchase_history();
check( 'history_pages_beyond_first_hundred_complete', count( $history ) === 121 && in_array( 1121, $history, true ) );
$pages = array_map( static fn( $q ) => $q['page'], array_filter( $GLOBALS['order_queries'], static fn( $q ) => $q['customer_id'] === 1 ) );
check( 'history_uses_pagination_and_final_recheck', $pages === array( 1, 2, 3, 1 ) );
fixture(); $GLOBALS['order_error'] = true; product( 1 );
check( 'history_wp_error_fails_closed', Petish_For_Your_Pet::selection()['state'] === 'unavailable' );
$GLOBALS['order_error'] = false; $GLOBALS['order_exception'] = true;
check( 'history_exception_fails_closed', is_wp_error( Petish_For_Your_Pet::purchase_history() ) );
fixture(); order( 1, array( 1 ) ); $GLOBALS['product_error'] = true;
check( 'variation_parent_lookup_error_fails_closed', is_wp_error( Petish_For_Your_Pet::purchase_history() ) );
fixture(); $GLOBALS['order_mutation'] = static function( $r ) { $r->total = 1001; $r->max_num_pages = 21; return $r; };
check( 'over_history_bound_fails_closed', is_wp_error( Petish_For_Your_Pet::purchase_history() ) );
fixture(); order( 1, array( 1 ) ); $GLOBALS['order_mutation'] = static function( $r ) { $r->orders = array(); return $r; };
check( 'truncated_history_fails_closed', is_wp_error( Petish_For_Your_Pet::purchase_history() ) );
fixture(); order( 1, array( 1 ) ); $GLOBALS['order_mutation'] = static function( $r, $q, $call ) { if ( $call === 2 ) { $r->total++; } return $r; };
check( 'history_changed_during_read_fails_closed', is_wp_error( Petish_For_Your_Pet::purchase_history() ) );
fixture(); order( 1, array( 1 ) ); order( 2, array( 2 ) ); $GLOBALS['order_mutation'] = static function( $r ) { $r->orders[1] = $r->orders[0]; return $r; };
check( 'duplicate_history_page_fails_closed', is_wp_error( Petish_For_Your_Pet::purchase_history() ) );
fixture(); order( 1, array( 1 ) ); $GLOBALS['order_mutation'] = static function( $r ) { $r->orders[0]->customer = 2; return $r; };
check( 'wrong_customer_from_query_fails_closed', is_wp_error( Petish_For_Your_Pet::purchase_history() ) );
fixture(); $GLOBALS['products_error'] = true;
check( 'product_read_error_fails_closed', Petish_For_Your_Pet::selection()['state'] === 'unavailable' );
fixture(); for ( $i = 1; $i <= 110; $i++ ) { product( $i, 300, array( 'rabbit' ), array( 'no match' ) ); } product( 111, 100 );
check( 'product_scan_can_reach_later_eligible_page', ids( Petish_For_Your_Pet::selection() ) === array( 111 ) );
fixture(); for ( $i = 1; $i <= 510; $i++ ) { product( $i, 300, array( 'rabbit' ), array( 'no match' ) ); }
Petish_For_Your_Pet::selection(); check( 'product_scan_is_bounded', count( $GLOBALS['product_queries'] ) === 10 );

fixture(); $p = product( 1 ); $p->name = '"><img src=x onerror=alert(1)>'; $p->price = '<b>$20</b><script>evil()</script>'; $p->image = '<img src="https://petish.invalid/x" onerror="evil()"><script>evil()</script>';
$html = Petish_For_Your_Pet::render();
check( 'product_name_escaped', str_contains( $html, '&lt;img src=x onerror=alert(1)&gt;' ) && ! str_contains( $html, '<img src=x' ) );
check( 'price_and_image_sanitized', ! str_contains( $html, '<script>' ) && ! str_contains( $html, 'onerror="evil()"' ) && str_contains( $html, '<b>$20</b>' ) );
check( 'private_profile_data_never_rendered', ! str_contains( $html, 'PRIVATE PET NAME' ) && ! str_contains( $html, 'PRIVATE BIO' ) && ! str_contains( $html, 'owner@example.test' ) );
check( 'public_card_and_badge_helpers_reused', str_contains( $html, 'ph-product-grid' ) && str_contains( $html, 'class="free"' ) && str_contains( $html, 'for-my-pet/' ) );
check( 'paused_review_label_remains_hidden', ! str_contains( $html, 'ph-product-review' ) );
$GLOBALS['reviews_visible'] = true;
check( 'enabled_review_label_escaped', str_contains( Petish_For_Your_Pet::render(), '&lt;review&gt; rabbit' ) );
$p->url = 'javascript:alert(1)';
check( 'product_permalink_escaped', ! str_contains( Petish_For_Your_Pet::render(), 'javascript:' ) );
$GLOBALS['uid'] = 0; $html = Petish_For_Your_Pet::render();
check( 'guest_fragment_is_generic', str_contains( $html, 'Sign in' ) && ! str_contains( $html, 'ph-product-grid' ) && ! str_contains( $html, 'PRIVATE' ) );

fixture(); $p = product( 1 ); $p->meta = array();
$_POST = array( 'petish_fyp_marker' => '1', 'petish_fyp_nonce' => 'nonce:petish_fyp_1', 'petish_fyp_enabled' => '1', 'petish_fyp_species' => array( 'rabbit' ), 'petish_fyp_keywords' => 'curious, inquisitive' );
$GLOBALS['caps']['manage_woocommerce'] = false; Petish_For_Your_Pet::save_editor( $p );
check( 'customer_cannot_curate', $p->meta === array() );
$GLOBALS['caps']['manage_woocommerce'] = true; $GLOBALS['caps']['edit_post'] = false; Petish_For_Your_Pet::save_editor( $p );
check( 'staff_requires_product_edit_capability', $p->meta === array() );
$GLOBALS['caps']['edit_post'] = true; $_POST['petish_fyp_nonce'] = 'bad'; Petish_For_Your_Pet::save_editor( $p );
check( 'staff_requires_nonce', $p->meta === array() );
$_POST['petish_fyp_nonce'] = 'nonce:petish_fyp_1'; Petish_For_Your_Pet::save_editor( $p );
check( 'staff_can_save_valid_rules', Petish_For_Your_Pet::rules( $p )['keywords'] === array( 'curious', 'inquisitive' ) );
check( 'saving_rules_never_creates_review_metadata', count( $p->meta ) === 3 );
$_POST['petish_fyp_species'] = array( 'dragon' ); Petish_For_Your_Pet::save_editor( $p );
check( 'invalid_staff_species_disables_matching', $p->meta[Petish_For_Your_Pet::ENABLED] === '0' && count( $GLOBALS['admin_errors'] ) === 1 );
$_POST['petish_fyp_species'] = array( 'rabbit' ); $_POST['petish_fyp_keywords'] = str_repeat( 'x', 61 ); Petish_For_Your_Pet::save_editor( $p );
check( 'oversized_staff_phrase_disabled', ! Petish_For_Your_Pet::rules( $p )['enabled'] );
$_POST['petish_fyp_keywords'] = implode( ',', range( 1, 21 ) ); Petish_For_Your_Pet::save_editor( $p );
check( 'too_many_staff_phrases_disabled', ! Petish_For_Your_Pet::rules( $p )['enabled'] );
$_POST['petish_fyp_keywords'] = 'curious'; $_POST['petish_fyp_enabled'] = ''; Petish_For_Your_Pet::save_editor( $p );
check( 'staff_can_disable_without_deleting_rules', $p->meta[Petish_For_Your_Pet::ENABLED] === '0' && $p->meta[Petish_For_Your_Pet::KEYWORDS] === array( 'curious' ) );
$before = $p->meta; $_POST = array(); Petish_For_Your_Pet::save_editor( $p );
check( 'unrelated_save_preserves_rules', $p->meta === $before );
$p->meta[Petish_For_Your_Pet::KEYWORDS] = array( '"><script>attack()</script>' ); $GLOBALS['product_object'] = $p; ob_start(); Petish_For_Your_Pet::panel(); $panel = ob_get_clean();
check( 'staff_panel_escapes_saved_keywords', ! str_contains( $panel, '<script>' ) && str_contains( $panel, '&lt;script&gt;' ) );
$GLOBALS['registered'] = array(); Petish_For_Your_Pet::register_meta();
check( 'rule_meta_private_and_no_migration', count( $GLOBALS['registered'] ) === 3 && $GLOBALS['registered'][0][2]['show_in_rest'] === false );
$request = new class { public $meta; public function get_param( $key ) { return $this->meta; } }; $request->meta = array( array( 'key' => Petish_For_Your_Pet::KEYWORDS, 'value' => array( 'curious' ) ) );
check( 'rest_cannot_bypass_staff_editor', is_wp_error( Petish_For_Your_Pet::rest_guard( $p, $request, false ) ) );
$GLOBALS['metadata'][9] = (object) array( 'meta_key' => Petish_For_Your_Pet::ENABLED ); $request->meta = array( array( 'id' => 9, 'value' => '1' ) );
check( 'rest_meta_id_bypass_denied', is_wp_error( Petish_For_Your_Pet::rest_guard( $p, $request, false ) ) );
$response = new class { public $data; public function get_data() { return $this->data; } public function set_data( $data ) { $this->data = $data; } };
$response->data = array( 'meta_data' => array( new WC_Meta_Data( array( 'key' => Petish_For_Your_Pet::KEYWORDS, 'value' => array( 'curious' ) ) ), array( 'key' => 'public', 'value' => 'ok' ) ) );
Petish_For_Your_Pet::rest_hide( $response, $p, $request );
check( 'rest_hides_staff_rule_notes', $response->data['meta_data'] === array( array( 'key' => 'public', 'value' => 'ok' ) ) );
check( 'product_duplication_does_not_copy_curation', count( Petish_For_Your_Pet::duplicate_keys( array() ) ) === 3 );
$GLOBALS['hooks'] = array(); Petish_For_Your_Pet::boot();
check( 'authenticated_and_guest_ajax_hooks_registered', in_array( 'wp_ajax_petish_for_your_pet', array_column( $GLOBALS['hooks'], 0 ), true ) && in_array( 'wp_ajax_nopriv_petish_for_your_pet', array_column( $GLOBALS['hooks'], 0 ), true ) );
echo json_encode( array( 'status' => 'success', 'checks' => $checks ), JSON_PRETTY_PRINT ) . "\n";
