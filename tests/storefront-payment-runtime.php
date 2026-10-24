<?php
/** Sanitization and request-local observer contract for the private diagnostic. */
define('PETISH_STOREFRONT_PAYMENT_RUNTIME_LIBRARY',true);
require dirname(__DIR__).'/scripts/storefront-payment-runtime.php';
$hooks=[];
function add_action($name,$callback,$priority=10){global $hooks;$hooks[]=['action',$name,$priority];}
function add_filter($name,$callback,$priority=10){global $hooks;$hooks[]=['filter',$name,$priority];}
function did_action($name){return $name==='wp'?1:0;}
final class Petish_Cart_Payment_Layout {private static $wcpay_google_pay_selected=true;}
function check($condition,$message){if(!$condition)throw new RuntimeException($message);}
Petish_Cart_Live_Diagnostic::install();
check($hooks===[['action','wp',0],['action','wp',2],['action','wp_enqueue_scripts',4],['action','woocommerce_blocks_cart_enqueue_data',1],['action','woocommerce_blocks_cart_enqueue_data',99],['filter','woocommerce_paypal_payments_googlepay_button_styles',29],['filter','woocommerce_paypal_payments_googlepay_button_styles',31]],'Only fixed native observation hooks, around existing selector/styles filter.');
$secret='PRIVATE_CANARY_MUST_NEVER_BE_REPORTED';
$styles=(object)['location'=>'cart','enabled'=>true,'methods'=>['ppcp-googlepay','ppcp-gateway',$secret],'secret'=>$secret];
$before=serialize($styles);
check(Petish_Cart_Live_Diagnostic::dto($styles,false)===$styles,'Observer preserves DTO identity.');
check(Petish_Cart_Live_Diagnostic::dto($styles,true)===$styles,'Observer never replaces native DTO.');
check(serialize($styles)===$before,'Observer never changes provider settings or values.');
$styles->location=$secret;
Petish_Cart_Live_Diagnostic::dto($styles,false);
$report=Petish_Cart_Live_Diagnostic::report();
$json=json_encode($report,JSON_THROW_ON_ERROR);
check(!str_contains($json,$secret),'No unknown location, method, secret or raw provider value escapes.');
check($report['locations']===['cart','other'],'Location strings are finite known enums.');
check($report['checks']['dto_cart_before_contains_googlepay']===true&&$report['checks']['dto_cart_after_selected_flag']===true,'Known provider flags are retained.');
foreach($report['checks']as$key=>$value){check(is_bool($value),'Only booleans leave native observer.');check(in_array($key,PETISH_STOREFRONT_PAYMENT_RUNTIME_FIELDS,true),'Every observed key is in fixed exported schema.');}
check(count(array_unique(PETISH_STOREFRONT_PAYMENT_RUNTIME_FIELDS))===count(PETISH_STOREFRONT_PAYMENT_RUNTIME_FIELDS),'Finite schema keys unique.');
echo "PASS: private runtime helper observes fixed native hooks, preserves DTOs, and exports only finite boolean flags.\n";
