<?php
/** Private, owner-scoped Passport records. No profile fields are exposed in REST. */
defined( 'ABSPATH' ) || exit;
final class Petish_Passport_Store {
 const TYPE='petish_pet'; const ACTIVE='_petish_active_pet'; const MAX_PETS=30;
 private static $pet_locks=array(); private static $user_locks=array();
 public static function boot() { add_action('init',array(__CLASS__,'register')); add_filter('wp_privacy_personal_data_exporters',array(__CLASS__,'exporters')); add_filter('wp_privacy_personal_data_erasers',array(__CLASS__,'erasers')); add_action('delete_user',array(__CLASS__,'delete_user'),1); add_action('wpmu_delete_user',array(__CLASS__,'delete_user'),1); }
 public static function register() {
 register_post_type(self::TYPE,array('label'=>'Private Pet Passports','public'=>false,'publicly_queryable'=>false,'exclude_from_search'=>true,'show_ui'=>false,'show_in_menu'=>false,'show_in_nav_menus'=>false,'show_in_admin_bar'=>false,'show_in_rest'=>false,'query_var'=>false,'rewrite'=>false,'has_archive'=>false,'can_export'=>false,'delete_with_user'=>false,'supports'=>array(),'map_meta_cap'=>false,'capabilities'=>array('edit_post'=>'do_not_allow','read_post'=>'do_not_allow','delete_post'=>'do_not_allow','edit_posts'=>'do_not_allow','edit_others_posts'=>'do_not_allow','publish_posts'=>'do_not_allow','read_private_posts'=>'do_not_allow','create_posts'=>'do_not_allow','delete_posts'=>'do_not_allow')));
 foreach(array('species','variety','variety_other','age','interest','photo','bio') as $field) register_post_meta(self::TYPE,'_petish_'.$field,array('type'=>'string','single'=>true,'show_in_rest'=>false,'auth_callback'=>'__return_false'));
 register_post_meta(self::TYPE,'_petish_photo_retirements',array('type'=>'array','single'=>true,'show_in_rest'=>false,'auth_callback'=>'__return_false'));
 register_post_meta(self::TYPE,'_petish_traits',array('type'=>'array','single'=>true,'show_in_rest'=>false,'auth_callback'=>'__return_false'));
 }
 public static function species(){return array('rabbit'=>'Rabbit','hamster'=>'Hamster','guinea-pig'=>'Guinea pig','chinchilla'=>'Chinchilla','other'=>'Other small pet');}
 /** Optional species-specific breeds and varieties; existing profiles are never inferred. */
 public static function varieties($species=null){
  $common=array('mixed-unknown'=>'Mixed or unknown','other'=>'Other (please specify)');
  $maps=array(
   'rabbit'=>array('mini-lop'=>'Mini Lop','dwarf-lop'=>'Dwarf Lop','holland-lop'=>'Holland Lop',
    'netherland-dwarf'=>'Netherland Dwarf','lionhead'=>'Lionhead','mini-rex'=>'Mini Rex','rex'=>'Rex',
    'dutch'=>'Dutch','flemish-giant'=>'Flemish Giant','english-angora'=>'English Angora','french-lop'=>'French Lop'),
   'hamster'=>array('syrian'=>'Syrian','roborovski'=>'Roborovski','campbells-dwarf'=>"Campbell's dwarf",
    'winter-white-dwarf'=>'Winter White dwarf','chinese'=>'Chinese','dwarf-hybrid'=>'Dwarf hybrid'),
   'guinea-pig'=>array('american-shorthaired'=>'American / shorthaired','abyssinian'=>'Abyssinian',
    'peruvian'=>'Peruvian','silkie-sheltie'=>'Silkie / Sheltie','teddy'=>'Teddy','rex'=>'Rex',
    'texel'=>'Texel','coronet'=>'Coronet','skinny'=>'Skinny','baldwin'=>'Baldwin'),
   'chinchilla'=>array('standard-grey'=>'Standard grey','black-velvet'=>'Black velvet','beige'=>'Beige',
    'white'=>'White','ebony'=>'Ebony','violet'=>'Violet','sapphire'=>'Sapphire','mosaic'=>'Mosaic'),
   'other'=>array(),
  );
  foreach($maps as $key=>$choices)$maps[$key]=$choices+$common;
  return null===$species?$maps:(is_string($species)?($maps[$species]??array()):array());
 }
 public static function variety_label($pet){
  if(!is_array($pet)||!is_string($pet['species']??null)||!is_string($pet['variety']??null))return '';
  $choices=self::varieties($pet['species']);$variety=$pet['variety'];
  if(!isset($choices[$variety]))return '';
  if('other'!==$variety)return $choices[$variety];
  if(!is_string($pet['variety_other']??null))return '';
  $custom=trim(sanitize_text_field($pet['variety_other']));$length=preg_match_all('/./us',$custom);
  return false!==$length&&$length<=60?$custom:'';
 }
 public static function ages(){return array('under-1'=>'Under 1 year','1-3'=>'1–3 years','over-3'=>'Over 3 years','unsure'=>'Unsure');}
 public static function interests(){return array('enrichment'=>'Enrichment toys','habitat'=>'Habitat accessories','essentials'=>'Everyday essentials','browsing'=>'Just browsing');}
 private static function missing(){return new WP_Error('petish_not_found','This Passport could not be found.',array('status'=>404));}
 private static function ids($uid){$uid=absint($uid);if(!$uid)return array();return get_posts(array('post_type'=>self::TYPE,'post_status'=>'any','author'=>$uid,'posts_per_page'=>-1,'fields'=>'ids','orderby'=>'ID','order'=>'ASC','suppress_filters'=>true,'no_found_rows'=>true));}
 private static function record($post){
  $bio=get_post_meta($post->ID,'_petish_bio',true);$traits=get_post_meta($post->ID,'_petish_traits',true);
  $variety=get_post_meta($post->ID,'_petish_variety',true);$variety_other=get_post_meta($post->ID,'_petish_variety_other',true);
  return array('id'=>(int)$post->ID,'name'=>$post->post_title,'species'=>(string)get_post_meta($post->ID,'_petish_species',true),'variety'=>is_string($variety)?$variety:'','variety_other'=>is_string($variety_other)?$variety_other:'','age'=>(string)get_post_meta($post->ID,'_petish_age',true),'interest'=>(string)get_post_meta($post->ID,'_petish_interest',true),'photo'=>(string)get_post_meta($post->ID,'_petish_photo',true),'bio'=>is_string($bio)?$bio:'','traits'=>is_array($traits)?array_values(array_filter($traits,'is_string')):array(),'created'=>$post->post_date_gmt,'updated'=>$post->post_modified_gmt);
 }
 /** A shared, reentrant lock serializes owner edits and community snapshots. */
 public static function with_pet_lock($pet_id,$callback){
  global $wpdb;
  $pet_id=absint($pet_id);if(!$pet_id||!is_callable($callback))return self::missing();
  if(isset(self::$pet_locks[$pet_id]))return call_user_func($callback);
  $name='petish_passport_'.substr(hash('sha256',$wpdb->prefix.'|'.$pet_id),0,40);
  if('1'!==(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)',$name)))return new WP_Error('petish_busy','This Passport is being updated. Please try again.');
  self::$pet_locks[$pet_id]=true;
  try{
   // Another request may have committed while this one waited for the lock.
   clean_post_cache($pet_id);wp_cache_delete($pet_id,'post_meta');
   return call_user_func($callback);
  }
  finally{unset(self::$pet_locks[$pet_id]);$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$name));}
 }
 /** Account writes serialize with the account-deletion marker. */
 public static function with_user_lock($uid,$callback){
  global $wpdb;$uid=absint($uid);if(!$uid||!is_callable($callback))return self::missing();
  if(isset(self::$user_locks[$uid]))return call_user_func($callback);
  $name='petish_passport_user_'.substr(hash('sha256',$wpdb->prefix.'|'.$uid),0,40);
  if('1'!==(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)',$name)))return new WP_Error('petish_busy','Your account is being updated. Please try again.');
  self::$user_locks[$uid]=true;
  try{clean_user_cache($uid);wp_cache_delete($uid,'user_meta');return call_user_func($callback);}
  finally{unset(self::$user_locks[$uid]);$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$name));}
 }
 public static function may_write($uid){
  $uid=absint($uid);wp_cache_delete($uid,'user_meta');
  return $uid&&get_user_by('id',$uid)&&'1'!==(string)get_user_meta($uid,'_petish_club_deleting',true);
 }
 private static function fields_meta($fields,$photo){
  return array('_petish_species'=>$fields['species'],'_petish_variety'=>$fields['variety'],'_petish_variety_other'=>$fields['variety_other'],'_petish_age'=>$fields['age'],'_petish_interest'=>$fields['interest'],'_petish_photo'=>$photo,'_petish_bio'=>$fields['bio'],'_petish_traits'=>$fields['traits']);
 }
 private static function unpublish($id,$uid){
  return class_exists('Petish_Passport_Community')?Petish_Passport_Community::unpublish($id,$uid):true;
 }
 public static function all_for_user($uid){$pets=array();foreach(self::ids($uid) as $id){$pet=self::get($id,$uid);if(!is_wp_error($pet))$pets[]=$pet;}return $pets;}
 public static function get($id,$uid){$id=absint($id);$uid=absint($uid);if(!$id||!$uid)return self::missing();$post=get_post($id);if(!$post||self::TYPE!==$post->post_type||'private'!==$post->post_status||$uid!==(int)$post->post_author)return self::missing();return self::record($post);}
 private static function validate($data){
  if(!is_array($data))return new WP_Error('petish_invalid','Please check your pet details.');
  foreach(array('name','species','age','interest','bio') as $key)if(isset($data[$key])&&!is_string($data[$key]))return new WP_Error('petish_invalid','Please check your pet details.');
  foreach(array('variety','variety_other') as $key)if(array_key_exists($key,$data)&&!is_string($data[$key]))return new WP_Error('petish_variety','Please check the breed or variety.');
  $name=sanitize_text_field($data['name']??'');$length=preg_match_all('/./us',$name);
  if(''===$name||false===$length||$length>80)return new WP_Error('petish_name','Enter a pet name of 1 to 80 characters.');
  $species=$data['species']??'';$age=$data['age']??'unsure';$interest=$data['interest']??'browsing';
  if(!isset(self::species()[$species]))return new WP_Error('petish_species','Please choose a species.');
  $variety=$data['variety']??'';$variety_other=trim(sanitize_text_field($data['variety_other']??''));
  if(''!==$variety&&!isset(self::varieties($species)[$variety]))return new WP_Error('petish_variety','Choose a breed or variety for this species.');
  $length=preg_match_all('/./us',$variety_other);
  if(false===$length||$length>60)return new WP_Error('petish_variety_other','Keep the breed or variety to 60 characters.');
  if('other'===$variety&&''===$variety_other)return new WP_Error('petish_variety_other','Please specify the breed or variety.');
  if('other'!==$variety)$variety_other='';
  if(!isset(self::ages()[$age]))return new WP_Error('petish_age','Please choose an age range.');
  if(!isset(self::interests()[$interest]))return new WP_Error('petish_interest','Please choose a shopping interest.');
  $bio=sanitize_textarea_field($data['bio']??'');$length=preg_match_all('/./us',$bio);
  if(false===$length||$length>400)return new WP_Error('petish_bio','Keep the introduction to 400 characters.');
  $raw_traits=$data['traits']??array();
  if(is_string($raw_traits))$raw_traits=explode(',',$raw_traits);
  if(!is_array($raw_traits)||count($raw_traits)>3)return new WP_Error('petish_traits','Choose up to three personality tags.');
  $traits=array();foreach($raw_traits as $trait){
   if(!is_string($trait))return new WP_Error('petish_traits','Please check the personality tags.');
   $trait=trim(sanitize_text_field($trait));$length=preg_match_all('/./us',$trait);
   if(false===$length||$length>24)return new WP_Error('petish_traits','Keep each personality tag to 24 characters.');
   if(''!==$trait&&!in_array($trait,$traits,true))$traits[]=$trait;
  }
  return compact('name','species','variety','variety_other','age','interest','bio','traits');
 }
 private static function has_file($file){return is_array($file)&&isset($file['error'])&&UPLOAD_ERR_NO_FILE!==$file['error'];}
 public static function create($uid,$data,$file=null){
  $uid=absint($uid);if(!$uid)return self::missing();
  return self::with_user_lock($uid,static function()use($uid,$data,$file){
   if(!self::may_write($uid))return self::missing();
   if(count(self::ids($uid))>=self::MAX_PETS)return new WP_Error('petish_limit','Your account can hold up to 30 pet Passports.');
   $fields=self::validate($data);if(is_wp_error($fields))return $fields;
   // Reserve the private record so a file key is journaled before it is written.
   $id=wp_insert_post(wp_slash(array('post_type'=>self::TYPE,'post_status'=>'private','post_author'=>$uid,
    'post_title'=>$fields['name'],'post_content'=>'','post_excerpt'=>'','comment_status'=>'closed','ping_status'=>'closed',
    'meta_input'=>self::fields_meta($fields,''))),true);
   if(is_wp_error($id)||!$id)return new WP_Error('petish_save','Your Passport could not be saved. Please try again.');
   if(self::has_file($file)){
    $photo=Petish_Passport_Media::save($file,$uid,static function($key)use($id,$uid){return Petish_Passport_Media::queue_removal($id,$uid,$key);});
    if(is_wp_error($photo)){
     $cleaned=Petish_Passport_Media::flush_removals($id,$uid,'');
     if(!is_wp_error($cleaned))wp_delete_post($id,true);
     return is_wp_error($cleaned)?$cleaned:$photo;
    }
    update_post_meta($id,'_petish_photo',$photo);
    if(get_post_meta($id,'_petish_photo',true)!==$photo){
     $cleaned=Petish_Passport_Media::flush_removals($id,$uid,'');if(!is_wp_error($cleaned))wp_delete_post($id,true);
     return is_wp_error($cleaned)?$cleaned:new WP_Error('petish_save','Your photo could not be saved. Please try again.');
    }
    $cleaned=Petish_Passport_Media::flush_removals($id,$uid,$photo);if(is_wp_error($cleaned))return $cleaned;
   }
   if(!self::active($uid))update_user_meta($uid,self::ACTIVE,$id);
   return self::get($id,$uid);
  });
 }

 public static function update($id,$uid,$data,$file=null){
  $pet=self::get($id,$uid);if(is_wp_error($pet))return $pet;
  return self::with_pet_lock($pet['id'],static function()use($id,$uid,$data,$file){
   $pet=self::get($id,$uid);if(is_wp_error($pet))return $pet;
   if(!self::may_write($uid))return self::missing();
   $cleaned=Petish_Passport_Media::flush_removals($pet['id'],$uid,$pet['photo']);if(is_wp_error($cleaned))return $cleaned;
   // Old integrations that do not submit the new optional fields preserve them.
   if(is_array($data)){
    $same_species=($data['species']??'')===$pet['species'];
    $data=array_merge(array('bio'=>$pet['bio'],'traits'=>$pet['traits'],
     'variety'=>$same_species?$pet['variety']:'','variety_other'=>$same_species?$pet['variety_other']:''),$data);
   }
   $fields=self::validate($data);if(is_wp_error($fields))return $fields;
   $photo=$pet['photo'];$replacement=false;
   if(self::has_file($file)){$photo=Petish_Passport_Media::save($file,$uid,static function($key)use($pet,$uid){return Petish_Passport_Media::queue_removal($pet['id'],$uid,$key);});if(is_wp_error($photo))return $photo;$replacement=true;}
   elseif(!empty($data['remove_photo']))$photo='';
   if($pet['photo']!==$photo){
    if($pet['photo']){$queued=Petish_Passport_Media::queue_removal($pet['id'],$uid,$pet['photo']);if(is_wp_error($queued)){if($replacement)Petish_Passport_Media::remove($photo);return $queued;}}
    $unpublished=self::unpublish($pet['id'],$uid);
    if(is_wp_error($unpublished)||true!==$unpublished){if($replacement)Petish_Passport_Media::remove($photo);return is_wp_error($unpublished)?$unpublished:new WP_Error('petish_unpublish','The public profile could not be removed. Please try again.');}
   }
   $result=wp_update_post(wp_slash(array('ID'=>$pet['id'],'post_title'=>$fields['name'],'post_modified'=>current_time('mysql'),'post_modified_gmt'=>current_time('mysql',true),'meta_input'=>self::fields_meta($fields,$photo))),true);
   $stored=self::get($pet['id'],$uid);
   if(is_wp_error($result)||!$result||is_wp_error($stored)||$stored['photo']!==$photo){
    if($replacement&&!is_wp_error($stored)&&$stored['photo']!==$photo)Petish_Passport_Media::remove($photo);
    return new WP_Error('petish_save','Your changes could not be saved. Please try again.');
   }
   // Both keys remain recorded until old-file removal and its journal update succeed.
   $cleaned=Petish_Passport_Media::flush_removals($pet['id'],$uid,$photo);
   if(is_wp_error($cleaned))return $cleaned;
   return self::get($pet['id'],$uid);
  });
 }
 public static function delete($id,$uid){
  $pet=self::get($id,$uid);if(is_wp_error($pet))return $pet;
  $result=self::with_pet_lock($pet['id'],static function()use($id,$uid){
   $pet=self::get($id,$uid);if(is_wp_error($pet))return $pet;
   $unpublished=self::unpublish($pet['id'],$uid);
   if(is_wp_error($unpublished)||true!==$unpublished)return is_wp_error($unpublished)?$unpublished:new WP_Error('petish_unpublish','The public profile could not be removed. Please try again.');
   $cleaned=Petish_Passport_Media::flush_removals($pet['id'],$uid,$pet['photo']);if(is_wp_error($cleaned))return $cleaned;
   if(class_exists('Petish_Passport_Moments')){$deleted=Petish_Passport_Moments::delete_for_pet($pet['id'],$uid);if(is_wp_error($deleted))return $deleted;}
   if($pet['photo']&&!Petish_Passport_Media::remove($pet['photo']))return new WP_Error('petish_photo_delete','The private photo could not be removed. Please try again or contact the site administrator.');
   if(!wp_delete_post($pet['id'],true))return new WP_Error('petish_delete','This Passport could not be deleted. Please try again.');
   if($pet['id']===(int)get_user_meta($uid,self::ACTIVE,true))delete_user_meta($uid,self::ACTIVE);
   return true;
  });
  if(true===$result)self::active($uid);
  return $result;
 }
 public static function set_active($id,$uid){
  return self::with_user_lock($uid,static function()use($id,$uid){
   if(!self::may_write($uid))return self::missing();clean_post_cache(absint($id));
   $pet=self::get($id,$uid);if(is_wp_error($pet))return $pet;
   update_user_meta($uid,self::ACTIVE,$pet['id']);return true;
  });
 }
 public static function active($uid){
  $uid=absint($uid);if(!$uid)return null;
  $result=self::with_user_lock($uid,static function()use($uid){
   if(!self::may_write($uid))return null;
   $pet=self::get(get_user_meta($uid,self::ACTIVE,true),$uid);if(!is_wp_error($pet))return $pet;
   foreach(self::ids($uid) as $id){$pet=self::get($id,$uid);if(!is_wp_error($pet)){update_user_meta($uid,self::ACTIVE,$id);return $pet;}}
   delete_user_meta($uid,self::ACTIVE);return null;
  });
  return is_wp_error($result)?null:$result;
 }

 public static function exporters($exporters){$exporters['petish-passport']=array('exporter_friendly_name'=>'Petish Passport','callback'=>array(__CLASS__,'export'));return $exporters;}
 public static function erasers($erasers){$erasers['petish-passport']=array('eraser_friendly_name'=>'Petish Passport','callback'=>array(__CLASS__,'erase'));return $erasers;}
 public static function export($email,$page=1){
 $user=get_user_by('email',$email);$rows=array();$pets=$user?self::all_for_user($user->ID):array();foreach(array_slice($pets,(max(1,(int)$page)-1)*20,20) as $pet){$data=array();foreach(array('name'=>'Pet name','species'=>'Species','age'=>'Age range','interest'=>'Shopping interest','bio'=>'Introduction','created'=>'Created (UTC)','updated'=>'Updated (UTC)') as $key=>$label){$value=$pet[$key];if('species'===$key)$value=self::species()[$value]??$value;if('age'===$key)$value=self::ages()[$value]??$value;if('interest'===$key)$value=self::interests()[$value]??$value;$data[]=array('name'=>$label,'value'=>$value);}
 $data[]=array('name'=>'Breed or variety','value'=>self::variety_label($pet));$data[]=array('name'=>'Personality tags','value'=>implode(', ',$pet['traits']));$data[]=array('name'=>'Private passport record','value'=>'Owner only');$data[]=array('name'=>'Active pet','value'=>(int)get_user_meta($user->ID,self::ACTIVE,true)===$pet['id']?'Yes':'No');$data[]=array('name'=>'Photo','value'=>$pet['photo']?Petish_Passport_Media::url($pet['id']).' (sign in as the owner to download)':'No photo stored');$rows[]=array('group_id'=>'petish-passport','group_label'=>'Petish Passport','item_id'=>'petish-pet-'.$pet['id'],'data'=>$data);
 if(class_exists('Petish_Passport_Moments'))$rows=array_merge($rows,Petish_Passport_Moments::export_for_pet($pet['id'],$user->ID));
 }
 return array('data'=>$rows,'done'=>(int)$page*20>=count($pets));}
 public static function erase($email,$page=1){
 $user=get_user_by('email',$email);$removed=false;$retained=false;$messages=array();if($user){foreach(array_slice(self::ids($user->ID),0,20) as $id){$result=self::delete($id,$user->ID);if(is_wp_error($result)){$retained=true;$messages[]='A Passport could not be removed. Please retry erasure or contact the site administrator.';}else $removed=true;}
  // A failed upload can leave a journaled clip after its record has been removed. Only
  // sweep this user's private folder once no owned pets remain, including on a retry.
  $cleaned=self::with_user_lock($user->ID,static function()use($user){
   return self::ids($user->ID)||!class_exists('Petish_Passport_Video')?true:Petish_Passport_Video::cleanup_user($user->ID);
  });
  if(is_wp_error($cleaned)||true!==$cleaned){$retained=true;$messages[]='A private video could not be removed. Please retry erasure or contact the site administrator.';}
 }
 return array('items_removed'=>$removed,'items_retained'=>$retained,'messages'=>array_unique($messages),'done'=>!$user||$retained||!self::ids($user->ID));}
 public static function delete_user($uid){
  // Abort before WordPress deletes/reassigns the account if a private file cannot be removed.
  foreach(self::ids($uid) as $id){
   $result=self::delete($id,$uid);
   if(is_wp_error($result)) wp_die(esc_html__('The account could not be deleted because private pet data could not be removed. Please retry or contact the site administrator.','petish-passport'),esc_html__('Account deletion paused','petish-passport'),array('response'=>500));
  }
  if(!Petish_Passport_Media::cleanup_user($uid))wp_die(esc_html__('The account could not be deleted because private photo cleanup requires administrator attention. Please retry or contact the site administrator.','petish-passport'),'Account deletion paused',array('response'=>500));
  if(class_exists('Petish_Passport_Video')&&!Petish_Passport_Video::cleanup_user($uid))wp_die(esc_html__('The account could not be deleted because private video cleanup requires administrator attention. Please retry or contact the site administrator.','petish-passport'),'Account deletion paused',array('response'=>500));
  delete_user_meta($uid,self::ACTIVE);
 }
}
