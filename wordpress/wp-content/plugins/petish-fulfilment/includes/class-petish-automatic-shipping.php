<?php
defined('ABSPATH') || exit;

/** Merchant-set delivery prices, independent of customer quote requests or supplier APIs. */
final class Petish_Automatic_Shipping {
    const OPTION='petish_automatic_shipping_rates';
    const METHOD='petish_automatic_shipping';
    private static $initialized=false;
    private static function error($code) { return new WP_Error('petish_automatic_'.$code,'Delivery is not available for every item at this destination.'); }
    private static function id($value,$zero=false) {
        if((!is_int($value)&&!is_string($value)) || !preg_match('/^(0|[1-9][0-9]{0,9})$/D',(string)$value)) return false;
        $value=(int)$value; return $value>0 || ($zero && $value===0)?$value:false;
    }
    private static function normalized($value,$postcode=false) {
        if(!is_string($value)) return '';
        return $postcode?wc_normalize_postcode($value):strtoupper(trim(preg_replace('/\s+/u',' ',$value)));
    }
    private static function decimal($micros) {
        return rtrim(rtrim(intdiv($micros,1000000).'.'.str_pad((string)($micros%1000000),6,'0',STR_PAD_LEFT),'0'),'.');
    }
    private static function micros($amount) {
        $parts=explode('.',$amount,2); return ((int)$parts[0])*1000000+(int)str_pad($parts[1]??'',6,'0');
    }
    public static function init() {
        if(self::$initialized) return;
        self::$initialized=true;
        add_filter('woocommerce_cart_shipping_packages',array(__CLASS__,'cache_packages'),PHP_INT_MAX);
        add_filter('woocommerce_shipping_packages',array(__CLASS__,'guard_cached_packages'),PHP_INT_MAX);
    }
    /** Optional expiry is policy validity, without the quote workflow's seven-day limit. */
    public static function validate_rule($row,$now=null) {
        if(!is_array($row)) return self::error('rule');
        $now=$now??time(); $parent=self::id($row['product_id']??null); $child=self::id($row['variation_id']??null,true);
        if($parent===false || $child===false) return self::error('identity');
        foreach(array('expected_sku','expected_parent_sku') as $key) if(!is_string($row[$key]??null) || trim($row[$key])==='' || strlen($row[$key])>300) return self::error('sku');
        if(!is_string($row['shipping_fingerprint']??null)||!preg_match('/^[a-f0-9]{64}$/D',$row['shipping_fingerprint'])) return self::error('fingerprint');
        $country=$row['country']??null;
        if(!is_string($country)||!preg_match('/^[A-Z]{2}$/D',$country)||!isset(WC()->countries->get_countries()[$country])) return self::error('country');
        $amount=$row['per_unit_customer_shipping_ex_tax_aud']??null;
        if(!is_string($amount)||!preg_match('/^(0|[1-9][0-9]{0,6})(\.[0-9]{1,6})?$/D',$amount)) return self::error('amount');
        if(self::micros($amount)===0 && ($row['free_shipping']??false)!==true) return self::error('zero_requires_explicit_free');
        $scope=$row['destination_scope']??array();
        if(!is_array($scope)||array_diff(array_keys($scope),array('states','postcodes'))) return self::error('scope');
        foreach(array('states','postcodes') as $key) {
            $values=$scope[$key]??array(); if(!is_array($values)||!array_is_list($values)||count($values)>1000) return self::error('scope');
            $normalized=array();
            foreach($values as $value) {
                if(!is_string($value)||trim($value)===''||strlen($value)>100||strpbrk($value,'*?%')!==false) return self::error('scope');
                $value=self::normalized($value,$key==='postcodes');
                if($value==='') return self::error('scope');
                if($key==='states') { $states=WC()->countries->get_states($country); if(is_array($states)&&$states&&!isset($states[$value])) return self::error('state'); }
                elseif(!WC_Validation::is_postcode($value,$country)) return self::error('postcode');
                $normalized[]=$value;
            }
            $scope[$key]=array_values(array_unique($normalized)); sort($scope[$key],SORT_STRING);
        }
        $expiry=$row['expires_utc']??null;
        if($expiry!==null && $expiry!=='') {
            if(!is_string($expiry)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$expiry)) return self::error('expiry');
            $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$expiry,new DateTimeZone('UTC'));
            if(!$date||$date->format('Y-m-d\TH:i:s\Z')!==$expiry) return self::error('expiry');
            if($date->getTimestamp()<=$now) return self::error('expired');
        } else $expiry=null;
        return array('product_id'=>$parent,'variation_id'=>$child,'expected_sku'=>$row['expected_sku'],'expected_parent_sku'=>$row['expected_parent_sku'],'shipping_fingerprint'=>$row['shipping_fingerprint'],'country'=>$country,'destination_scope'=>$scope,'per_unit_customer_shipping_ex_tax_aud'=>self::decimal(self::micros($amount)),'free_shipping'=>($row['free_shipping']??false)===true,'expires_utc'=>$expiry);
    }
    /** Invalid/expired rows remain diagnostic errors, never rates. No defaults are inserted. */
    public static function validate_book($book,$now=null) {
        if(!is_array($book)||($book['schema_version']??null)!==1||!is_array($book['rules']??null)||!array_is_list($book['rules'])||count($book['rules'])>10000) return self::error('schema');
        $result=array('rules'=>array(),'errors'=>array(),'revision'=>hash('sha256',wp_json_encode($book)));
        foreach($book['rules'] as $index=>$row) {
            $valid=self::validate_rule($row,$now);
            if(is_wp_error($valid)) $result['errors'][$index]=$valid->get_error_code();
            else $result['rules'][$index]=$valid;
        }
        return $result;
    }
    /** Fresh native stock checks happen after duplicate cart lines aggregate. */
    public static function snapshot_lines($package) {
        if(!is_array($package)||!is_array($package['contents']??null)||count($package['contents'])>100) return self::error('package');
        $groups=array();
        foreach($package['contents'] as $line) {
            $product=is_array($line)?($line['data']??null):null;
            if(!$product instanceof WC_Product) return self::error('product');
            if(!$product->needs_shipping()) continue;
            if(!$product->is_type(array('simple','variation'))) return self::error('type');
            $quantity=self::id($line['quantity']??null); if($quantity===false||$quantity>10000) return self::error('quantity');
            $parent=$product->is_type('variation')?wc_get_product($product->get_parent_id()):$product;
            $parent_id=$product->is_type('variation')?$product->get_parent_id():$product->get_id(); $child_id=$product->is_type('variation')?$product->get_id():0;
            if(!$parent || self::id($line['product_id']??null)!==$parent_id || self::id($line['variation_id']??null,true)!==$child_id || ($child_id&&!$parent->is_type('variable'))) return self::error('identity');
            if($parent->get_status('edit')!=='publish'||$product->get_status('edit')!=='publish'||!$product->is_purchasable()||!$product->is_in_stock()) return self::error('unavailable');
            $fingerprint=Petish_Supplier_Quotes::fingerprint($product);
            if(is_wp_error($fingerprint)||!$product->get_sku('edit')||!$parent->get_sku('edit')) return self::error('supplier');
            $identity=array('product_id'=>$parent_id,'variation_id'=>$child_id,'expected_sku'=>$product->get_sku('edit'),'expected_parent_sku'=>$parent->get_sku('edit'),'shipping_fingerprint'=>$fingerprint);
            $key=$parent_id.':'.$child_id;
            if(isset($groups[$key])&&$groups[$key]['identity']!==$identity) return self::error('identity');
            if(!isset($groups[$key])) $groups[$key]=array('identity'=>$identity,'product'=>$product,'quantity'=>0);
            $groups[$key]['quantity']+=$quantity;
        }
        if(!$groups) return self::error('no_physical_items');
        ksort($groups,SORT_STRING); $result=array(); $stock_managers=array();
        foreach($groups as $group) {
            if($group['quantity']>10000||!$group['product']->has_enough_stock($group['quantity'])) return self::error('stock');
            if($group['product']->managing_stock()) {
                $manager=$group['product']->get_stock_managed_by_id();
                $stock_managers[$manager]=($stock_managers[$manager]??0)+$group['quantity'];
            }
            $result[]=array_merge($group['identity'],array('quantity'=>$group['quantity']));
        }
        foreach($stock_managers as $id=>$quantity) { $manager=wc_get_product($id); if(!$manager||!$manager->has_enough_stock($quantity)) return self::error('stock'); }
        return $result;
    }
    /** Canonical ex-tax AUD sum. Guest customers are supported; no account/approval lookup. */
    public static function rate_package($package,$book=null,$now=null) {
        if(get_option('woocommerce_currency')!=='AUD') return self::error('base_currency');
        $validated=self::validate_book($book??get_option(self::OPTION,array('schema_version'=>1,'rules'=>array())),$now);
        if(is_wp_error($validated)) return $validated;
        $lines=self::snapshot_lines($package); if(is_wp_error($lines)) return $lines;
        $destination=$package['destination']??array(); $country=self::normalized($destination['country']??'');
        if(!$country || !isset(WC()->countries->get_shipping_countries()[$country])) return self::error('destination');
        $total=0; $matches=array();
        foreach($lines as $line) {
            $candidates=array();
            foreach($validated['rules'] as $index=>$rule) {
                if($country!==$rule['country']) continue;
                $same=true;
                foreach(array('product_id','variation_id','expected_sku','expected_parent_sku','shipping_fingerprint') as $key) if($rule[$key]!==$line[$key]) $same=false;
                foreach(array('states'=>'state','postcodes'=>'postcode') as $scope=>$field) if($rule['destination_scope'][$scope]&&!in_array(self::normalized($destination[$field]??'',$scope==='postcodes'),$rule['destination_scope'][$scope],true)) $same=false;
                if($same) $candidates[$index]=$rule;
            }
            if(count($candidates)!==1) return self::error(count($candidates)?'ambiguous':'unassigned');
            $index=array_key_first($candidates); $micros=self::micros($candidates[$index]['per_unit_customer_shipping_ex_tax_aud']);
            if($micros>intdiv(PHP_INT_MAX-$total,$line['quantity'])) return self::error('amount_overflow');
            $total+=$micros*$line['quantity']; $matches[]=$index;
        }
        return array('cost'=>self::decimal($total),'revision'=>$validated['revision'],'lines'=>$lines,'matched_rule_indexes'=>$matches);
    }
    private static function tag($package,$result,$context,$book=null) {
        return hash('sha256',wp_json_encode(array('mode'=>get_option('petish_shipping_mode','quote'),'policy'=>$book??get_option(self::OPTION,array('schema_version'=>1,'rules'=>array())),'result'=>is_wp_error($result)?$result->get_error_code():$result,'currency'=>is_wp_error($context)?$context->get_error_code():$context,'destination'=>$package['destination']??array())));
    }
    /** Optional explicit context is for trusted process-only tests; the method always uses native context. */
    public static function rate_args($package,$book=null,$now=null,$context=null) {
        $result=self::rate_package($package,$book,$now); if(is_wp_error($result)) return $result;
        $context=$context??Petish_Quote_Currency::context(); if(is_wp_error($context)) return $context;
        if(!is_array($context)||!preg_match('/^[A-Z]{3}$/D',$context['code']??'')||!is_numeric($context['rate']??null)||!is_finite((float)$context['rate'])||(float)$context['rate']<=0||!is_int($context['decimals']??null)||$context['decimals']<0||$context['decimals']>6) return self::error('currency');
        $cost=Petish_Quote_Currency::convert($result['cost'],$context); if(is_wp_error($cost)) return $cost;
        return array('cost'=>$cost,'price_decimals'=>$context['code']==='AUD'?6:$context['decimals'],'meta_data'=>array('_petish_auto_currency'=>$context['code'],'_petish_auto_fx_context'=>Petish_Quote_Currency::fingerprint($context),'_petish_auto_revision'=>$result['revision'],'_petish_auto_package'=>self::tag($package,$result,$context,$book)));
    }
    public static function cache_packages($packages,$now=null) {
        if(!is_array($packages)) return $packages;
        foreach($packages as &$package) if(is_array($package)) $package['petish_automatic_shipping_cache']=self::tag($package,self::rate_package($package,null,$now),Petish_Quote_Currency::context());
        unset($package); return $packages;
    }
    public static function guard_cached_packages($packages,$now=null) {
        if(!is_array($packages)) return $packages;
        foreach($packages as &$package) {
            if(!is_array($package)||empty($package['rates'])) continue;
            $expected=null;
            foreach($package['rates'] as $key=>$rate) {
                if(!$rate instanceof WC_Shipping_Rate||$rate->get_method_id()!==self::METHOD) continue;
                if(get_option('petish_shipping_mode','quote')!=='automatic') { unset($package['rates'][$key]); continue; }
                if($expected===null) $expected=self::rate_args($package,null,$now);
                if(is_wp_error($expected)||abs((float)$rate->get_cost()-(float)$expected['cost'])>0.000001) { unset($package['rates'][$key]); continue; }
                $meta=$rate->get_meta_data(); foreach($expected['meta_data'] as $name=>$value) if(($meta[$name]??null)!==$value) { unset($package['rates'][$key]); break; }
            }
        }
        unset($package); return $packages;
    }
}

final class Petish_Automatic_Shipping_Method extends WC_Shipping_Method {
    public function __construct($instance_id=0) {
        $this->id=Petish_Automatic_Shipping::METHOD; $this->instance_id=absint($instance_id);
        $this->method_title=__('Automatic destination delivery','petish-fulfilment');
        $this->method_description=__('Merchant-set prices for explicitly configured products and destinations. No supplier freight is fetched.','petish-fulfilment');
        $this->supports=array('shipping-zones','instance-settings','instance-settings-modal');
        $this->instance_form_fields=array('enabled'=>array('title'=>__('Enable','petish-fulfilment'),'type'=>'checkbox','default'=>'yes'),'title'=>array('title'=>__('Title','petish-fulfilment'),'type'=>'text','default'=>__('Standard delivery','petish-fulfilment')));
        $this->enabled=$this->get_option('enabled','yes'); $this->title=trim(wp_strip_all_tags($this->get_option('title',__('Standard delivery','petish-fulfilment'))));
        if($this->title==='') $this->title=__('Standard delivery','petish-fulfilment');
        $this->tax_status='taxable'; add_action('woocommerce_update_options_shipping_'.$this->id,array($this,'process_admin_options'));
    }
    public function calculate_shipping($package=array()) {
        if($this->enabled!=='yes'||get_option('petish_shipping_mode','quote')!=='automatic'||wc_string_to_bool(apply_filters('woocommerce_shipping_prices_include_tax',false))) return;
        $args=Petish_Automatic_Shipping::rate_args($package); if(is_wp_error($args)) return;
        $this->add_rate(array_merge($args,array('id'=>$this->get_rate_id(),'label'=>$this->title,'calc_tax'=>'per_order','package'=>$package)));
    }
}
