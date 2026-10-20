<?php
/** Offline functional checks use real Petish shipping validators with native Woo boundary stubs. */
error_reporting(E_ALL);
set_error_handler(static function($severity,$message,$file,$line) { throw new ErrorException($message,0,$severity,$file,$line); });
define('ABSPATH',__DIR__.'/'); define('DAY_IN_SECONDS',86400);
function add_action(...$args) {} function add_filter(...$args) {}
function __($text,...$args) { return $text; } function esc_attr__($text,...$args) { return esc_attr($text); }
function esc_attr($text) { return htmlspecialchars($text,ENT_QUOTES); } function esc_html($text) { return esc_attr($text); }
function esc_url($text) { return $text; } function number_format_i18n($n) { return number_format($n); }
function get_stylesheet_directory_uri() { return 'https://petish.invalid/theme'; }
function get_option($key,$fallback=false) { return $GLOBALS['options'][$key]??$fallback; }
function update_option(...$args) { throw new Exception('unexpected_write'); }
function apply_filters($name,$value,...$args) { return $name==='woocommerce_shipping_prices_include_tax' ? $GLOBALS['inclusive_shipping'] : $value; }
function wc_string_to_bool($value) { return in_array($value,array(true,1,'1','yes'),true); }
function wp_json_encode($value) { return json_encode($value); } function wp_parse_url($value) { return parse_url($value); }
function get_woocommerce_currency() { return 'AUD'; } function get_woocommerce_currencies() { return array('AUD'=>'Australian dollar','USD'=>'US dollar'); }
function wc_get_base_location() { return array('country'=>'AU','state'=>'NSW'); }
function wc_normalize_postcode($value) { return strtoupper(trim($value)); }
function wc_get_price_to_display($product,$args=array()) { return $args['price']*$product->tax_factor; }
function wc_get_product($id) { return $GLOBALS['products'][$id]??false; }
function WC() { return $GLOBALS['woo']; }
class WP_Error { public function __construct(public $code,public $message='') {} public function get_error_code() { return $this->code; } }
function is_wp_error($value) { return $value instanceof WP_Error; }
class WC_Product_Attribute {}
class WC_Meta_Data {
    public function __construct(private $data) {}
    public function get_data() { return $this->data; }
}
class WC_Product {
    public $meta=array(),$children=array(),$visible=true,$purchasable=true,$stock=true,$status='publish',$price='20',$tax_factor=1,$shipping=true;
    public function __construct(public $id,public $type='simple',public $parent=0) { $this->meta['_petish_supplier_product_url']='https://supplier.invalid/item/'.$id; }
    public function get_id() { return $this->id; } public function get_parent_id() { return $this->parent; }
    public function get_meta($key,$single=true,$context='view') {
        if(!$single) return array_filter($this->get_meta_data(),static fn($metadata)=>$metadata->get_data()['key']===$key);
        return $this->meta[$key]??'';
    }
    public function get_meta_data() {
        $result=array();$index=10;
        foreach($this->meta as $key=>$value) {
            foreach($key==='_petish_supplier_free_delivery_evidence'?$value:array($value) as $entry) {
                $result[$index]=new WC_Meta_Data(array('id'=>$index,'key'=>$key,'value'=>$entry));$index+=3;
            }
        }
        return $result;
    }
    public function is_type($type) { return in_array($this->type,(array)$type,true); } public function get_type() { return $this->type; }
    public function get_status(...$args) { return $this->status; } public function is_visible() { return $this->type==='variation'?false:$this->visible; }
    public function variation_is_visible() { return $this->visible; }
    public function is_purchasable() { return $this->purchasable; } public function is_in_stock() { return $this->stock; }
    public function needs_shipping() { return $this->shipping; } public function get_price() { return $this->price; }
    public function get_sku(...$args) { return 'SKU-'.$this->id; } public function get_attributes(...$args) { return array(); }
    public function get_default_attributes(...$args) { return array(); }
    public function get_length(...$args) { return '10'; } public function get_width(...$args) { return '10'; }
    public function get_height(...$args) { return '10'; } public function get_weight(...$args) { return '1'; }
    public function get_shipping_class_id() { return 0; } public function get_virtual(...$args) { return !$this->shipping; }
    public function has_enough_stock($quantity) { return $this->stock; } public function managing_stock() { return false; }
    public function get_available_variations($mode) { return array_map('wc_get_product',$this->children); }
}
class WC_Countries {
    public function get_countries() { return array('AU'=>'Australia','US'=>'United States'); }
    public function get_shipping_countries() { return $this->get_countries(); }
    public function get_states($country) { return $country==='AU'?array('NSW'=>'New South Wales','VIC'=>'Victoria'):array('CA'=>'California'); }
}
class WC_Customer {
    public $country='AU',$state='NSW',$postcode='2000',$city='Sydney';
    public function get_shipping_country() { return $this->country; } public function get_shipping_state() { return $this->state; }
    public function get_shipping_postcode() { return $this->postcode; } public function get_shipping_city() { return $this->city; }
}
class WC_Shipping_Method { public $id,$enabled='yes',$instance_id=1; public function get_instance_id() { return $this->instance_id; } }
class WC_Shipping_Zones {
    public static function get_zone_matching_package($package) {
        $GLOBALS['last_package']=$package;
        return new class {
            public function get_shipping_methods($enabled) {
                if($GLOBALS['quote_pending']) {
                    require_once dirname(__DIR__).'/wordpress/wp-content/plugins/petish-fulfilment/includes/class-petish-supplier-shipping.php';
                    $method=(new ReflectionClass('Petish_Supplier_Shipping_Method'))->newInstanceWithoutConstructor();
                    $method->id='petish_supplier_shipping';$GLOBALS['zone_methods']=array($method);$GLOBALS['quote_pending']=false;
                }
                return $GLOBALS['zone_methods'];
            }
        };
    }
}
class Petish_Shipping_Quote_Requests { public static function quote_package(...$args) { throw new Exception('personal_approval_lookup'); } }
class WC_Validation { public static function is_postcode($value,$country) { return $value!==''; } }
$root=dirname(__DIR__);
require $root.'/wordpress/wp-content/plugins/petish-fulfilment/includes/class-petish-quote-currency.php';
require $root.'/wordpress/wp-content/plugins/petish-fulfilment/includes/class-petish-supplier-quotes.php';
require $root.'/wordpress/wp-content/plugins/petish-fulfilment/includes/class-petish-automatic-shipping.php';
require $root.'/wordpress/wp-content/themes/dt-the7-child/includes/supplier-popularity.php';

function reset_fixture() {
    $GLOBALS['woo']=(object)array('countries'=>new WC_Countries(),'customer'=>new WC_Customer());
    $GLOBALS['products']=array(1=>new WC_Product(1));
    $GLOBALS['inclusive_shipping']=false;
    $GLOBALS['options']=array('woocommerce_currency'=>'AUD','woocommerce_dimension_unit'=>'cm','woocommerce_weight_unit'=>'kg','petish_shipping_mode'=>'automatic');
    set_method(true); return $GLOBALS['products'][1];
}
function set_method($automatic) {
    $GLOBALS['quote_pending']=!$automatic;
    if(!$automatic) { $GLOBALS['zone_methods']=array();return; }
    $class=$automatic?'Petish_Automatic_Shipping_Method':'Petish_Supplier_Shipping_Method';
    $method=(new ReflectionClass($class))->newInstanceWithoutConstructor();
    $method->id=$automatic?'petish_automatic_shipping':'petish_supplier_shipping';
    $GLOBALS['zone_methods']=array($method);
}
function proof($product) {
    $parent=$product->parent?wc_get_product($product->parent):$product;
    return array('schema_version'=>1,'reviewed'=>true,'supplier_url'=>$parent->get_meta('_petish_supplier_product_url'),
        'shipping_fingerprint'=>Petish_Supplier_Quotes::fingerprint($product),'country'=>'AU','destination_scope'=>array(),
        'country_scope_verified'=>true,'supplier_shipping_amount'=>'0','currency'=>'USD','quantity'=>1,
        'observed_utc'=>gmdate('Y-m-d\TH:i:s\Z',time()-3600),'expires_utc'=>gmdate('Y-m-d\TH:i:s\Z',time()+3600));
}
function identity($product) {
    $parent=$product->parent?wc_get_product($product->parent):$product;
    return array('product_id'=>$parent->id,'variation_id'=>$product->parent?$product->id:0,
        'expected_sku'=>$product->get_sku(),'expected_parent_sku'=>$parent->get_sku(),
        'shipping_fingerprint'=>Petish_Supplier_Quotes::fingerprint($product),'country'=>'AU','destination_scope'=>array());
}
function automatic_rule($product,$amount='0') {
    return identity($product)+array('per_unit_customer_shipping_ex_tax_aud'=>$amount,'free_shipping'=>true,'expires_utc'=>gmdate('Y-m-d\TH:i:s\Z',time()+3600));
}
function make_eligible($product) {
    $product->meta['_petish_supplier_free_delivery_evidence']=array(proof($product));
    $GLOBALS['options'][Petish_Automatic_Shipping::OPTION]=array('schema_version'=>1,'rules'=>array(automatic_rule($product)));
}
function check($name,$condition) { if(!$condition) throw new Exception($name); $GLOBALS['checks'][$name]=true; }
$checks=array();

$product=reset_fixture();
check('missing_evidence_denies_section_and_badge',!petish_supplier_free_delivery_eligible($product)&&petish_supplier_free_delivery_badge($product)==='');
make_eligible($product);
check('reviewed_supplier_and_native_zero_enables',petish_supplier_free_delivery_eligible($product));
check('free_product_does_not_become_hot',petish_supplier_hot_badge($product)==='');
$product->meta['_petish_supplier_order_count']='1';
$product->meta['_petish_supplier_order_observed']=gmdate('Y-m-d');
check('low_hot_count_still_allows_free',petish_supplier_free_delivery_eligible($product)&&petish_supplier_hot_badge($product)==='');
check('badge_asset_dimensions_and_destination',str_contains(petish_supplier_free_delivery_badge($product),'width="2061" height="763"')&&str_contains(petish_supplier_free_delivery_badge($product),'free-delivery-van.png')&&str_contains(petish_supplier_free_delivery_destination_label(),'Australia (one item)'));
check('package_is_one_item_without_cart_session',($last_package['contents'][0]['quantity']??0)===1&&!isset(WC()->cart)&&!isset(WC()->session));

$valid=proof($product);
foreach(array(
    'not_reviewed'=>array('reviewed'=>false),'negative_supplier_cost'=>array('supplier_shipping_amount'=>'-1'),
    'nonzero_supplier_cost'=>array('supplier_shipping_amount'=>'0.01'),'future'=>array('observed_utc'=>gmdate('Y-m-d\TH:i:s\Z',time()+60)),
    'stale'=>array('observed_utc'=>gmdate('Y-m-d\TH:i:s\Z',time()-90000),'expires_utc'=>gmdate('Y-m-d\TH:i:s\Z',time()-1)),
    'excess_validity'=>array('expires_utc'=>gmdate('Y-m-d\TH:i:s\Z',time()+8*DAY_IN_SECONDS)),
    'malformed_time'=>array('observed_utc'=>"2026-01-01T00:00:00Z\0"),
    'changed_identity'=>array('shipping_fingerprint'=>str_repeat('0',64)),'wrong_supplier'=>array('supplier_url'=>'https://other.invalid/item'),
    'wrong_country'=>array('country'=>'US'),'unverified_country_scope'=>array('country_scope_verified'=>false),
    'wrong_quantity'=>array('quantity'=>2),'wrong_currency'=>array('currency'=>'ZZZ'),
    'wrong_state'=>array('destination_scope'=>array('states'=>array('VIC'))),
    'wrong_postcode'=>array('destination_scope'=>array('postcodes'=>array('3000'))),
    'wildcard_scope'=>array('destination_scope'=>array('postcodes'=>array('2*'))),
    'malformed_scope'=>array('destination_scope'=>array('postcodes'=>array(2000))),
) as $name=>$changes) {
    $product->meta['_petish_supplier_free_delivery_evidence']=array(array_replace($valid,$changes));
    check($name.'_denied',!petish_supplier_free_delivery_eligible($product));
}
$product->meta['_petish_supplier_free_delivery_evidence']=array($valid,$valid);
check('duplicate_supplier_proof_denied',!petish_supplier_free_delivery_eligible($product));
$product->meta['_petish_supplier_free_delivery_evidence']=array(array_replace($valid,array('destination_scope'=>array('states'=>array('NSW'),'cities'=>array('sydney'),'postcodes'=>array('2000')),'country_scope_verified'=>false)));
check('exact_destination_scope_allowed',petish_supplier_free_delivery_eligible($product));
WC()->customer->country='XX';
$destination=petish_supplier_free_delivery_destination();
check('base_fallback_has_country_only',$destination===array('country'=>'AU','state'=>'','postcode'=>'','city'=>''));
check('fallback_never_borrows_base_state_postcode',!petish_supplier_free_delivery_eligible($product));
WC()->customer=null;
check('missing_customer_does_not_initialize_session',petish_supplier_free_delivery_destination()===$destination&&!isset(WC()->session));

$product=reset_fixture();make_eligible($product);
$GLOBALS['options'][Petish_Automatic_Shipping::OPTION]['rules'][0]['per_unit_customer_shipping_ex_tax_aud']='2';
check('supplier_free_but_checkout_paid_denied',!petish_supplier_free_delivery_eligible($product));
$GLOBALS['options'][Petish_Automatic_Shipping::OPTION]['rules'][0]['per_unit_customer_shipping_ex_tax_aud']='0';
$GLOBALS['options'][Petish_Automatic_Shipping::OPTION]['rules'][]=$GLOBALS['options'][Petish_Automatic_Shipping::OPTION]['rules'][0];
check('duplicate_native_rates_denied',!petish_supplier_free_delivery_eligible($product));
make_eligible($product);$zone_methods[0]->enabled='no';
check('disabled_zone_method_denied',!petish_supplier_free_delivery_eligible($product));
$zone_methods[0]->enabled='yes';$zone_methods[0]->instance_id=0;
check('noninstance_method_denied',!petish_supplier_free_delivery_eligible($product));
$options['petish_shipping_mode']='quote';
set_method(true);
check('wrong_mode_method_denied',!petish_supplier_free_delivery_eligible($product));
check('quote_method_initially_unloaded',!class_exists('Petish_Supplier_Shipping_Method'));
set_method(false);
$quote=identity($product)+array('quantity'=>1,'customer_shipping_ex_tax_aud'=>'0','carrier'=>'Fixture tracked service',
    'source_url'=>'https://supplier.invalid/quote','reference'=>'Offline reviewed evidence','country_scope_verified'=>true,
    'quote_utc'=>gmdate('Y-m-d\TH:i:s\Z',time()-3600),'expires_utc'=>gmdate('Y-m-d\TH:i:s\Z',time()+3600));
$options[Petish_Supplier_Quotes::OPTION]=array('schema_version'=>1,'quotes'=>array($quote));
check('generic_quote_zero_without_personal_lookup',petish_supplier_free_delivery_eligible($product));
check('native_zone_loads_quote_method',class_exists('Petish_Supplier_Shipping_Method'));
$options[Petish_Supplier_Quotes::OPTION]['quotes'][0]['customer_shipping_ex_tax_aud']='1';
check('generic_quote_paid_denied',!petish_supplier_free_delivery_eligible($product));

$parent=reset_fixture();$parent->type='variable';$parent->children=array(2,3);
$products[2]=new WC_Product(2,'variation',1);$products[3]=new WC_Product(3,'variation',1);
make_eligible($products[2]);
check('one_variant_does_not_badge_parent',!petish_supplier_free_delivery_eligible($parent));
$products[3]->meta['_petish_supplier_free_delivery_evidence']=array(proof($products[3]));
$options[Petish_Automatic_Shipping::OPTION]['rules'][]=automatic_rule($products[3]);
check('all_available_variants_qualify_parent',petish_supplier_free_delivery_eligible($parent));
$products[3]->meta['_petish_supplier_free_delivery_evidence']=array();$products[3]->stock=false;
check('unavailable_variation_not_offered',petish_supplier_free_delivery_eligible($parent));
$products[2]->stock=false;
check('no_available_variation_denies_parent',!petish_supplier_free_delivery_eligible($parent));

$products=array();
foreach(array(array(9,'0','100'),array(8,'broken','1000'),array(7,'-1','1000'),array(6,'10','2'),array(5,'0010','3'),array(4,'10','3'),array(3,'99999999999999999999','1'),array(2,'100000000000000000000','1')) as [$id,$count,$price]) {
    $item=new WC_Product($id);$item->meta['_petish_supplier_order_count']=$count;$item->price=$price;$products[$id]=$item;
}
$ranked=array_values($products);usort($ranked,'petish_supplier_free_delivery_compare');
check('rank_count_then_price_then_id_unknown_after_zero',array_map(static fn($p)=>$p->id,$ranked)===array(2,3,4,5,6,9,7,8));
$products[6]->tax_factor=2;
check('price_uses_current_native_display_tax',petish_supplier_free_delivery_compare($products[6],$products[5])<0);
$products[6]->price='';
check('unknown_price_below_actual_zero',petish_supplier_free_delivery_compare($products[6],$products[5])>0);

echo json_encode(array('status'=>'success','checks'=>$checks),JSON_PRETTY_PRINT)."\n";
