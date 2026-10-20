<?php
/** Private, owner-scoped Passport records. No profile fields are exposed in REST. */
defined( 'ABSPATH' ) || exit;
final class Petish_Passport_Store {
 const TYPE='petish_pet'; const ACTIVE='_petish_active_pet'; const MAX_PETS=30;
 public static function boot() { add_action('init',array(__CLASS__,'register')); add_filter('wp_privacy_personal_data_exporters',array(__CLASS__,'exporters')); add_filter('wp_privacy_personal_data_erasers',array(__CLASS__,'erasers')); add_action('delete_user',array(__CLASS__,'delete_user'),1); add_action('wpmu_delete_user',array(__CLASS__,'delete_user'),1); }
 public static function register() {
 register_post_type(self::TYPE,array('label'=>'Private Pet Passports','public'=>false,'publicly_queryable'=>false,'exclude_from_search'=>true,'show_ui'=>false,'show_in_menu'=>false,'show_in_nav_menus'=>false,'show_in_admin_bar'=>false,'show_in_rest'=>false,'query_var'=>false,'rewrite'=>false,'has_archive'=>false,'can_export'=>false,'delete_with_user'=>false,'supports'=>array(),'map_meta_cap'=>false,'capabilities'=>array('edit_post'=>'do_not_allow','read_post'=>'do_not_allow','delete_post'=>'do_not_allow','edit_posts'=>'do_not_allow','edit_others_posts'=>'do_not_allow','publish_posts'=>'do_not_allow','read_private_posts'=>'do_not_allow','create_posts'=>'do_not_allow','delete_posts'=>'do_not_allow')));
 foreach(array('species','age','interest','photo') as $field) register_post_meta(self::TYPE,'_petish_'.$field,array('type'=>'string','single'=>true,'show_in_rest'=>false,'auth_callback'=>'__return_false'));
 }
 public static function species(){return array('rabbit'=>'Rabbit','hamster'=>'Hamster','guinea-pig'=>'Guinea pig','chinchilla'=>'Chinchilla','other'=>'Other small pet');}
 public static function ages(){return array('under-1'=>'Under 1 year','1-3'=>'1–3 years','over-3'=>'Over 3 years','unsure'=>'Unsure');}
 public static function interests(){return array('enrichment'=>'Enrichment toys','habitat'=>'Habitat accessories','essentials'=>'Everyday essentials','browsing'=>'Just browsing');}
 private static function missing(){return new WP_Error('petish_not_found','This Passport could not be found.',array('status'=>404));}
 private static function ids($uid){$uid=absint($uid);if(!$uid)return array();return get_posts(array('post_type'=>self::TYPE,'post_status'=>'any','author'=>$uid,'posts_per_page'=>-1,'fields'=>'ids','orderby'=>'ID','order'=>'ASC','suppress_filters'=>true,'no_found_rows'=>true));}
 private static function record($post){return array('id'=>(int)$post->ID,'name'=>$post->post_title,'species'=>(string)get_post_meta($post->ID,'_petish_species',true),'age'=>(string)get_post_meta($post->ID,'_petish_age',true),'interest'=>(string)get_post_meta($post->ID,'_petish_interest',true),'photo'=>(string)get_post_meta($post->ID,'_petish_photo',true),'created'=>$post->post_date_gmt,'updated'=>$post->post_modified_gmt);}
 public static function all_for_user($uid){$pets=array();foreach(self::ids($uid) as $id){$pet=self::get($id,$uid);if(!is_wp_error($pet))$pets[]=$pet;}return $pets;}
 public static function get($id,$uid){$id=absint($id);$uid=absint($uid);if(!$id||!$uid)return self::missing();$post=get_post($id);if(!$post||self::TYPE!==$post->post_type||'private'!==$post->post_status||$uid!==(int)$post->post_author)return self::missing();return self::record($post);}
 private static function validate($data){
 if(!is_array($data))return new WP_Error('petish_invalid','Please check your pet details.');foreach(array('name','species','age','interest') as $key)if(isset($data[$key])&&!is_string($data[$key]))return new WP_Error('petish_invalid','Please check your pet details.');
 $name=sanitize_text_field(isset($data['name'])?$data['name']:'');$length=preg_match_all('/./us',$name);if(''===$name||false===$length||$length>80)return new WP_Error('petish_name','Enter a pet name of 1 to 80 characters.');
 $species=isset($data['species'])?$data['species']:'';$age=isset($data['age'])?$data['age']:'unsure';$interest=isset($data['interest'])?$data['interest']:'browsing';if(!isset(self::species()[$species]))return new WP_Error('petish_species','Please choose a species.');if(!isset(self::ages()[$age]))return new WP_Error('petish_age','Please choose an age range.');if(!isset(self::interests()[$interest]))return new WP_Error('petish_interest','Please choose a shopping interest.');return compact('name','species','age','interest');}
 private static function has_file($file){return is_array($file)&&isset($file['error'])&&UPLOAD_ERR_NO_FILE!==$file['error'];}
 public static function create($uid,$data,$file=null){
 $uid=absint($uid);if(!$uid||!get_user_by('id',$uid))return self::missing();if(count(self::ids($uid))>=self::MAX_PETS)return new WP_Error('petish_limit','Your account can hold up to 30 pet Passports.');$fields=self::validate($data);if(is_wp_error($fields))return $fields;$photo='';if(self::has_file($file)){$photo=Petish_Passport_Media::save($file,$uid);if(is_wp_error($photo))return $photo;}
 $id=wp_insert_post(wp_slash(array('post_type'=>self::TYPE,'post_status'=>'private','post_author'=>$uid,'post_title'=>$fields['name'],'post_content'=>'','post_excerpt'=>'','comment_status'=>'closed','ping_status'=>'closed','meta_input'=>array('_petish_species'=>$fields['species'],'_petish_age'=>$fields['age'],'_petish_interest'=>$fields['interest'],'_petish_photo'=>$photo))),true);
 if(is_wp_error($id)||!$id){if($photo)Petish_Passport_Media::remove($photo);return new WP_Error('petish_save','Your Passport could not be saved. Please try again.');}if(!self::active($uid))update_user_meta($uid,self::ACTIVE,$id);return self::get($id,$uid);}
 public static function update($id,$uid,$data,$file=null){
 $pet=self::get($id,$uid);if(is_wp_error($pet))return $pet;$fields=self::validate($data);if(is_wp_error($fields))return $fields;$photo=$pet['photo'];$replacement=false;
 if(self::has_file($file)){$photo=Petish_Passport_Media::save($file,$uid);if(is_wp_error($photo))return $photo;$replacement=true;}elseif(!empty($data['remove_photo']))$photo='';
 if($pet['photo']&&$pet['photo']!==$photo&&!Petish_Passport_Media::remove($pet['photo'])){if($replacement)Petish_Passport_Media::remove($photo);return new WP_Error('petish_photo_delete','The private photo could not be removed. Please try again or contact the site administrator.');}
 $result=wp_update_post(wp_slash(array('ID'=>$pet['id'],'post_title'=>$fields['name'],'post_modified'=>current_time('mysql'),'post_modified_gmt'=>current_time('mysql',true),'meta_input'=>array('_petish_species'=>$fields['species'],'_petish_age'=>$fields['age'],'_petish_interest'=>$fields['interest'],'_petish_photo'=>$photo))),true);
 if(is_wp_error($result)||!$result){if($replacement)Petish_Passport_Media::remove($photo);return new WP_Error('petish_save','Your changes could not be saved. Please try again.');}return self::get($pet['id'],$uid);}
 public static function delete($id,$uid){$pet=self::get($id,$uid);if(is_wp_error($pet))return $pet;if($pet['photo']&&!Petish_Passport_Media::remove($pet['photo']))return new WP_Error('petish_photo_delete','The private photo could not be removed. Please try again or contact the site administrator.');if(!wp_delete_post($pet['id'],true))return new WP_Error('petish_delete','This Passport could not be deleted. Please try again.');if($pet['id']===(int)get_user_meta($uid,self::ACTIVE,true))delete_user_meta($uid,self::ACTIVE);self::active($uid);return true;}
 public static function set_active($id,$uid){$pet=self::get($id,$uid);if(is_wp_error($pet))return $pet;update_user_meta($uid,self::ACTIVE,$pet['id']);return true;}
 public static function active($uid){$uid=absint($uid);if(!$uid)return null;$pet=self::get(get_user_meta($uid,self::ACTIVE,true),$uid);if(!is_wp_error($pet))return $pet;foreach(self::ids($uid) as $id){$pet=self::get($id,$uid);if(!is_wp_error($pet)){update_user_meta($uid,self::ACTIVE,$pet['id']);return $pet;}}delete_user_meta($uid,self::ACTIVE);return null;}
 public static function exporters($exporters){$exporters['petish-passport']=array('exporter_friendly_name'=>'Petish Passport','callback'=>array(__CLASS__,'export'));return $exporters;}
 public static function erasers($erasers){$erasers['petish-passport']=array('eraser_friendly_name'=>'Petish Passport','callback'=>array(__CLASS__,'erase'));return $erasers;}
 public static function export($email,$page=1){
 $user=get_user_by('email',$email);$rows=array();$pets=$user?self::all_for_user($user->ID):array();foreach(array_slice($pets,(max(1,(int)$page)-1)*20,20) as $pet){$data=array();foreach(array('name'=>'Pet name','species'=>'Species','age'=>'Age range','interest'=>'Shopping interest','created'=>'Created (UTC)','updated'=>'Updated (UTC)') as $key=>$label){$value=$pet[$key];if('species'===$key)$value=self::species()[$value]??$value;if('age'===$key)$value=self::ages()[$value]??$value;if('interest'===$key)$value=self::interests()[$value]??$value;$data[]=array('name'=>$label,'value'=>$value);}
 $data[]=array('name'=>'Visibility','value'=>'Private');$data[]=array('name'=>'Active pet','value'=>(int)get_user_meta($user->ID,self::ACTIVE,true)===$pet['id']?'Yes':'No');$data[]=array('name'=>'Photo','value'=>$pet['photo']?Petish_Passport_Media::url($pet['id']).' (sign in as the owner to download)':'No photo stored');$rows[]=array('group_id'=>'petish-passport','group_label'=>'Petish Passport','item_id'=>'petish-pet-'.$pet['id'],'data'=>$data);}
 return array('data'=>$rows,'done'=>(int)$page*20>=count($pets));}
 public static function erase($email,$page=1){
 $user=get_user_by('email',$email);$removed=false;$retained=false;$messages=array();if($user){foreach(array_slice(self::ids($user->ID),0,20) as $id){$result=self::delete($id,$user->ID);if(is_wp_error($result)){$retained=true;$messages[]='A Passport could not be removed. Please retry erasure or contact the site administrator.';}else $removed=true;}}
 return array('items_removed'=>$removed,'items_retained'=>$retained,'messages'=>array_unique($messages),'done'=>!$user||$retained||!self::ids($user->ID));}
 public static function delete_user($uid){
  // Abort before WordPress deletes/reassigns the account if a private file cannot be removed.
  foreach(self::ids($uid) as $id){
   $result=self::delete($id,$uid);
   if(is_wp_error($result)) wp_die(esc_html__('The account could not be deleted because private pet data could not be removed. Please retry or contact the site administrator.','petish-passport'),esc_html__('Account deletion paused','petish-passport'),array('response'=>500));
  }
  if(!Petish_Passport_Media::cleanup_user($uid))wp_die(esc_html__('The account could not be deleted because private photo cleanup requires administrator attention. Please retry or contact the site administrator.','petish-passport'),'Account deletion paused',array('response'=>500));
  delete_user_meta($uid,self::ACTIVE);
 }
}
