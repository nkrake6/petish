<?php
/** Behavioral fixture for private Passport records; no WordPress/network required. */
error_reporting(E_ALL);
set_error_handler(static function($severity,$message,$file,$line){throw new ErrorException($message,0,$severity,$file,$line);});
$fixture=sys_get_temp_dir().'/petish-passport-store-'.bin2hex(random_bytes(8));
mkdir($fixture,0700);mkdir($fixture.'/public_html',0700);
define('ABSPATH',$fixture.'/public_html/');
class WP_Error {public $code;public $message;public $data;function __construct($code,$message='',$data=null){$this->code=$code;$this->message=$message;$this->data=$data;}function get_error_message(){return $this->message;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function absint($v){return abs((int)$v);}
function sanitize_text_field($v){return trim(preg_replace('/[\r\n\t]+/',' ',strip_tags($v)));}
function sanitize_textarea_field($v){return trim(strip_tags($v));}
function add_action(...$args){}
function add_filter(...$args){}
function remove_filter(...$args){}
function register_post_type($type,$args){$GLOBALS['types'][$type]=$args;}
function register_post_meta($type,$key,$args){$GLOBALS['meta_types'][$type][$key]=$args;}
function untrailingslashit($v){return rtrim($v,'/');}
function wp_mkdir_p($p){return mkdir($p,0700,true);}
function wp_delete_file($p){if(!in_array($p,$GLOBALS['deny_delete'],true)&&file_exists($p))unlink($p);}
function wp_slash($v){return $v;}
function current_time($type,$gmt=false){return '2026-10-21 01:00:00';}
function admin_url($path){return 'https://petish.invalid/wp-admin/'.$path;}
function add_query_arg($q,$url){return $url.'?'.http_build_query($q);}
function get_user_by($field,$value){foreach([11,22,33]as$id)if(($field==='id'&&(int)$value===$id)||($field==='email'&&$value==='person'.$id.'@example.invalid'))return(object)['ID'=>$id];return false;}
function get_user_meta($uid,$key,$single=true){return $GLOBALS['user_meta'][$uid][$key]??'';}
function update_user_meta($uid,$key,$v){$GLOBALS['user_meta'][$uid][$key]=$v;return true;}
function delete_user_meta($uid,$key){unset($GLOBALS['user_meta'][$uid][$key]);return true;}
function clean_user_cache($id){}
function clean_post_cache($id){unset($GLOBALS['post_cache'][$id],$GLOBALS['meta_cache'][$id]);$GLOBALS['cleaned'][]=(int)$id;}
function wp_cache_delete($id,$group){if($group==='post_meta')unset($GLOBALS['meta_cache'][$id]);return true;}
function get_post($id){if(array_key_exists($id,$GLOBALS['post_cache']))return $GLOBALS['post_cache'][$id];return $GLOBALS['post_cache'][$id]=isset($GLOBALS['posts'][$id])?clone$GLOBALS['posts'][$id]:null;}
function get_post_meta($id,$key,$single=true){if(!array_key_exists($id,$GLOBALS['meta_cache']))$GLOBALS['meta_cache'][$id]=$GLOBALS['meta'][$id]??[];return $GLOBALS['meta_cache'][$id][$key]??'';}
function update_post_meta($id,$key,$v){if($GLOBALS['fail_meta']===$key)return false;$GLOBALS['meta'][$id][$key]=$v;unset($GLOBALS['meta_cache'][$id]);return true;}
function get_posts($q){$rows=[];foreach($GLOBALS['posts']as$id=>$p){if($p->post_type!==$q['post_type']||(int)$p->post_author!==(int)$q['author'])continue;if(isset($q['post_parent'])&&(int)$p->post_parent!==(int)$q['post_parent'])continue;if($q['post_status']!=='any'&&$q['post_status']!==$p->post_status)continue;$rows[]=$id;}sort($rows);if(($q['order']??'ASC')==='DESC')$rows=array_reverse($rows);return $q['posts_per_page']<0?$rows:array_slice($rows,0,$q['posts_per_page']);}
function wp_insert_post($args,$error=false){if($GLOBALS['fail_insert'])return new WP_Error('fixture_insert');$id=++$GLOBALS['last_id'];$meta=$args['meta_input']??[];unset($args['meta_input']);$GLOBALS['posts'][$id]=(object)array_merge(['ID'=>$id,'post_parent'=>0,'post_date_gmt'=>'2026-10-20 00:00:00','post_modified_gmt'=>'2026-10-20 00:00:00'],$args);foreach($meta as$k=>$v)update_post_meta($id,$k,$v);clean_post_cache($id);return$id;}
function wp_update_post($args,$error=false){if($GLOBALS['fail_update'])return new WP_Error('fixture_update');$id=$args['ID'];if(!isset($GLOBALS['posts'][$id]))return new WP_Error('fixture_missing');$meta=$args['meta_input']??[];unset($args['meta_input']);foreach($args as$k=>$v)$GLOBALS['posts'][$id]->$k=$v;foreach($meta as$k=>$v)update_post_meta($id,$k,$v);clean_post_cache($id);return$id;}
function wp_delete_post($id,$force=false){if($GLOBALS['fail_delete'])return false;unset($GLOBALS['posts'][$id],$GLOBALS['meta'][$id]);clean_post_cache($id);return true;}
class FixtureDB {public $prefix='wp_fixture_';public $available=true;public $acquired=0;public $released=0;public $on_acquire=null;function prepare($q,...$a){return[$q,$a];}function get_var($v){if(str_contains($v[0],'GET_LOCK')){++$this->acquired;if(!$this->available)return '0';if($this->on_acquire){$cb=$this->on_acquire;$this->on_acquire=null;$cb();}return '1';}if(str_contains($v[0],'RELEASE_LOCK')){++$this->released;return '1';}throw new RuntimeException('Unexpected SQL');}}
class Petish_Passport_Community {public static $fail=false;public static $events=[];public static function unpublish($id,$uid){self::$events[]=['id'=>$id,'uid'=>$uid,'photo'=>get_post_meta($id,'_petish_photo',true)];return self::$fail?new WP_Error('fixture_unpublish'):true;}}
$GLOBALS['posts']=$GLOBALS['meta']=$GLOBALS['post_cache']=$GLOBALS['meta_cache']=$GLOBALS['user_meta']=$GLOBALS['types']=$GLOBALS['meta_types']=$GLOBALS['cleaned']=$GLOBALS['deny_delete']=[];
$GLOBALS['fail_meta']='';$GLOBALS['fail_insert']=$GLOBALS['fail_update']=$GLOBALS['fail_delete']=false;$GLOBALS['last_id']=100;$wpdb=new FixtureDB();
require dirname(__DIR__).'/wordpress/wp-content/plugins/petish-passport/includes/class-petish-passport-media.php';
require dirname(__DIR__).'/wordpress/wp-content/plugins/petish-passport/includes/class-petish-passport-store.php';
require dirname(__DIR__).'/wordpress/wp-content/plugins/petish-passport/includes/class-petish-passport-moments.php';
$checks=[];
function check($name,$value){global$checks;if(!$value)throw new RuntimeException('Failed: '.$name);$checks[$name]=true;}
function fields($extra=[]){return array_merge(['name'=>'Clover','species'=>'rabbit','age'=>'1-3','interest'=>'browsing'],$extra);}
function photo($uid,$letter='a'){$root=dirname(rtrim(ABSPATH,'/')).'/private/petish-passport';foreach([dirname($root),$root,$root.'/'.$uid]as$d)if(!is_dir($d))mkdir($d,0700);$key=$uid.'/'.str_repeat($letter,48).'.png';file_put_contents($root.'/'.$key,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aN0sAAAAASUVORK5CYII='));chmod($root.'/'.$key,0600);return$key;}
function photo_path($key){return dirname(rtrim(ABSPATH,'/')).'/private/petish-passport/'.$key;}
function cleanup_tree($dir){foreach(scandir($dir)as$n){if($n==='.'||$n==='..')continue;$p=$dir.'/'.$n;if(is_dir($p)&&!is_link($p))cleanup_tree($p);else unlink($p);}rmdir($dir);}
try{
 Petish_Passport_Store::register();Petish_Passport_Moments::register();
 foreach(['petish_pet','petish_pet_moment']as$type){check($type.'_not_public',$GLOBALS['types'][$type]['public']===false&&$GLOBALS['types'][$type]['show_in_rest']===false&&$GLOBALS['types'][$type]['publicly_queryable']===false);check($type.'_meta_not_rest',count(array_filter($GLOBALS['meta_types'][$type],fn($x)=>$x['show_in_rest']!==false))===0);}
 $pet=Petish_Passport_Store::create(11,fields());$id=$pet['id'];
 check('legacy_optional_fields_default',$pet['bio']===''&&$pet['traits']===[]);
 unset($GLOBALS['meta'][$id]['_petish_variety'],$GLOBALS['meta'][$id]['_petish_variety_other']);clean_post_cache($id);
 $legacy_pet=Petish_Passport_Store::get($id,11);
 check('legacy_variety_fields_empty',$legacy_pet['variety']===''&&$legacy_pet['variety_other']===''&&Petish_Passport_Store::variety_label($legacy_pet)==='');
 check('legacy_variety_read_does_not_migrate',!array_key_exists('_petish_variety',$GLOBALS['meta'][$id])&&!array_key_exists('_petish_variety_other',$GLOBALS['meta'][$id]));
 $maps=Petish_Passport_Store::varieties();
 check('variety_species_maps_match',array_keys($maps)===array_keys(Petish_Passport_Store::species()));
 check('unknown_variety_species_empty',Petish_Passport_Store::varieties('dog')===[]&&Petish_Passport_Store::varieties([])===[]);
 foreach($maps as$species=>$choices)check('variety_common_choices_'.$species,isset($choices['mixed-unknown'],$choices['other']));
 check('other_species_has_only_optional_choices',array_keys($maps['other'])===['mixed-unknown','other']);
 check('variety_meta_private_strings',$GLOBALS['meta_types']['petish_pet']['_petish_variety']['type']==='string'&&$GLOBALS['meta_types']['petish_pet']['_petish_variety_other']['type']==='string');
 $breed=Petish_Passport_Store::create(33,fields(['name'=>'Breed fixture','variety'=>'mini-lop']));$bid=$breed['id'];
 check('variety_created_and_saved',$breed['variety']==='mini-lop'&&$breed['variety_other']===''&&get_post_meta($bid,'_petish_variety',true)==='mini-lop');
 check('variety_display_label',Petish_Passport_Store::variety_label($breed)==='Mini Lop');
 $breed=Petish_Passport_Store::update($bid,33,fields(['name'=>'Existing integration']));
 check('variety_legacy_update_preserved',$breed['variety']==='mini-lop');
 check('variety_wrong_species_rejected',is_wp_error(Petish_Passport_Store::update($bid,33,fields(['species'=>'hamster','variety'=>'mini-lop']))));
 check('variety_wrong_species_rejection_no_write',Petish_Passport_Store::get($bid,33)['species']==='rabbit'&&Petish_Passport_Store::get($bid,33)['variety']==='mini-lop');
 check('variety_arbitrary_slug_rejected',is_wp_error(Petish_Passport_Store::update($bid,33,fields(['variety'=>'made-up']))));
 foreach(['variety','variety_other']as$field)foreach([null,12,false,['bad']]as$index=>$value)
  check('variety_nonstring_rejected_'.$field.'_'.$index,is_wp_error(Petish_Passport_Store::update($bid,33,fields([$field=>$value]))));
 check('variety_other_requires_text',is_wp_error(Petish_Passport_Store::update($bid,33,fields(['variety'=>'other','variety_other'=>' <b> </b> ']))));
 $breed=Petish_Passport_Store::update($bid,33,fields(['variety'=>'other','variety_other'=>' <b>Harlequin</b> ']));
 check('variety_custom_sanitized',$breed['variety_other']==='Harlequin'&&Petish_Passport_Store::variety_label($breed)==='Harlequin');
 $breed=Petish_Passport_Store::update($bid,33,fields());
 check('variety_custom_legacy_update_preserved',$breed['variety']==='other'&&$breed['variety_other']==='Harlequin');
 $breed=Petish_Passport_Store::update($bid,33,fields(['variety'=>'other','variety_other'=>str_repeat('é',60)]));
 check('variety_custom_60_unicode_allowed',!is_wp_error($breed)&&$breed['variety_other']===str_repeat('é',60));
 check('variety_custom_61_unicode_rejected',is_wp_error(Petish_Passport_Store::update($bid,33,fields(['variety'=>'other','variety_other'=>str_repeat('é',61)]))));
 $breed=Petish_Passport_Store::update($bid,33,fields(['variety'=>'mini-lop','variety_other'=>'Stale custom text']));
 check('variety_known_choice_clears_custom',$breed['variety']==='mini-lop'&&$breed['variety_other']==='');
 $breed=Petish_Passport_Store::update($bid,33,fields(['variety'=>'']));
 check('variety_explicit_empty_clears',$breed['variety']===''&&$breed['variety_other']==='');
 Petish_Passport_Store::update($bid,33,fields(['variety'=>'other','variety_other'=>'Harlequin']));
 $breed=Petish_Passport_Store::update($bid,33,fields(['species'=>'hamster']));
 check('variety_species_change_omission_clears',$breed['species']==='hamster'&&$breed['variety']===''&&$breed['variety_other']==='');
 $breed=Petish_Passport_Store::update($bid,33,fields(['species'=>'hamster','variety'=>'syrian']));
 check('variety_species_specific_save',$breed['variety']==='syrian'&&Petish_Passport_Store::variety_label($breed)==='Syrian');
 $breed=Petish_Passport_Store::update($bid,33,fields(['species'=>'chinchilla','variety'=>'standard-grey']));
 check('variety_species_change_explicit_valid',$breed['variety']==='standard-grey'&&Petish_Passport_Store::variety_label($breed)==='Standard grey');
 $breed=Petish_Passport_Store::update($bid,33,fields(['species'=>'other','variety'=>'mixed-unknown']));
 check('variety_mixed_unknown_available',Petish_Passport_Store::variety_label($breed)==='Mixed or unknown');
 $breed=Petish_Passport_Store::update($bid,33,fields(['species'=>'other','variety'=>'other','variety_other'=>'Degu']));
 $breed_export=Petish_Passport_Store::export('person33@example.invalid');$breed_data=array_column($breed_export['data'][0]['data'],'value','name');
 check('variety_privacy_export_label',($breed_data['Breed or variety']??null)==='Degu');
 check('variety_display_ignores_invalid_data',Petish_Passport_Store::variety_label(['species'=>'hamster','variety'=>'mini-lop'])===''&&Petish_Passport_Store::variety_label(['species'=>'rabbit','variety'=>'other','variety_other'=>[]])==='');
 check('variety_fixture_deleted',Petish_Passport_Store::delete($bid,33)===true);
 check('created_private',$GLOBALS['posts'][$id]->post_status==='private');
 check('active_preserved_on_create',(int)get_user_meta(11,Petish_Passport_Store::ACTIVE,true)===$id);
 check('owner_read',$pet===Petish_Passport_Store::get($id,11));
 check('cross_owner_read_denied',is_wp_error(Petish_Passport_Store::get($id,22)));
 check('anonymous_read_denied',is_wp_error(Petish_Passport_Store::get($id,0)));
 check('unknown_user_create_denied',is_wp_error(Petish_Passport_Store::create(44,fields())));
 $other=Petish_Passport_Store::create(22,fields(['name'=>'Other']));
 check('owner_list_filtered',count(Petish_Passport_Store::all_for_user(11))===1);
 $extended=Petish_Passport_Store::update($id,11,fields(['bio'=>'A <b>curious</b> rabbit.','traits'=>['Curious','Cosy','Gentle']]));
 check('biography_sanitized',$extended['bio']==='A curious rabbit.');
 check('traits_preserved',$extended['traits']===['Curious','Cosy','Gentle']);
 check('unicode_bio_400_allowed',!is_wp_error(Petish_Passport_Store::update($id,11,fields(['bio'=>str_repeat('é',400)]))));
 check('unicode_bio_401_rejected',is_wp_error(Petish_Passport_Store::update($id,11,fields(['bio'=>str_repeat('é',401)]))));
 check('four_traits_rejected',is_wp_error(Petish_Passport_Store::update($id,11,fields(['traits'=>['a','b','c','d']]))));
 check('trait_array_injection_rejected',is_wp_error(Petish_Passport_Store::update($id,11,fields(['traits'=>[['bad']]]))));
 check('long_trait_rejected',is_wp_error(Petish_Passport_Store::update($id,11,fields(['traits'=>[str_repeat('é',25)]]))));
 $extended=Petish_Passport_Store::update($id,11,fields(['bio'=>'Hello','traits'=>'Playful, Playful, <b>Cosy</b>']));
 check('comma_tags_sanitized_deduplicated',$extended['traits']===['Playful','Cosy']);
 $legacy=Petish_Passport_Store::update($id,11,fields(['name'=>'Legacy edit']));
 check('legacy_edit_preserves_new_fields',$legacy['bio']==='Hello'&&$legacy['traits']===['Playful','Cosy']);
 $cleared=Petish_Passport_Store::update($id,11,fields(['bio'=>'','traits'=>[]]));
 check('explicit_optional_clear',$cleared['bio']===''&&$cleared['traits']===[]);
 check('cross_owner_update_denied',is_wp_error(Petish_Passport_Store::update($id,22,fields())));
 $a=$wpdb->acquired;$r=$wpdb->released;$v=Petish_Passport_Store::with_pet_lock($id,fn()=>Petish_Passport_Store::with_pet_lock($id,fn()=>17));
 check('lock_reentrant_single_acquire',$v===17&&$wpdb->acquired===$a+1&&$wpdb->released===$r+1);
 $r=$wpdb->released;try{Petish_Passport_Store::with_pet_lock($id,fn()=>throw new RuntimeException('fixture'));}catch(RuntimeException$e){}
 check('lock_released_on_exception',$wpdb->released===$r+1);
 $wpdb->available=false;$called=false;$busy=Petish_Passport_Store::with_pet_lock($id,function()use(&$called){$called=true;});$wpdb->available=true;
 check('lock_failure_does_not_write',is_wp_error($busy)&&!$called);
 $a=$wpdb->acquired;$r=$wpdb->released;$v=Petish_Passport_Store::with_user_lock(11,fn()=>Petish_Passport_Store::with_user_lock(11,fn()=>23));
 check('account_lock_reentrant_single_acquire',$v===23&&$wpdb->acquired===$a+1&&$wpdb->released===$r+1);
 $wpdb->available=false;$called=false;$busy=Petish_Passport_Store::with_user_lock(11,function()use(&$called){$called=true;});$wpdb->available=true;
 check('account_lock_timeout_does_not_write',is_wp_error($busy)&&!$called);
 $retiring=Petish_Passport_Store::create(33,fields(['name'=>'Retiring account']));$retiring_id=$retiring['id'];
 $retiring_moment=Petish_Passport_Moments::create($retiring_id,33,['kind'=>'journal','caption'=>'Retiring entry']);
 update_user_meta(33,'_petish_club_deleting','1');
 check('deleting_account_create_denied',is_wp_error(Petish_Passport_Store::create(33,fields())));
 check('deleting_account_update_denied',is_wp_error(Petish_Passport_Store::update($retiring_id,33,fields())));
 check('deleting_account_active_write_denied',is_wp_error(Petish_Passport_Store::set_active($retiring_id,33))&&Petish_Passport_Store::active(33)===null);
 check('deleting_account_moment_create_denied',is_wp_error(Petish_Passport_Moments::create($retiring_id,33,['kind'=>'journal','caption'=>'New entry'])));
 check('deleting_account_moment_update_denied',is_wp_error(Petish_Passport_Moments::update($retiring_moment['id'],33,['kind'=>'journal','caption'=>'Edited'])));
 check('deleting_account_cleanup_allowed',Petish_Passport_Store::delete($retiring_id,33)===true&&!isset($GLOBALS['posts'][$retiring_moment['id']]));
 delete_user_meta(33,'_petish_club_deleting');
 $wpdb->on_acquire=function(){update_user_meta(33,'_petish_club_deleting','1');};
 check('waiting_create_checks_deletion_marker',is_wp_error(Petish_Passport_Store::create(33,fields()))&&Petish_Passport_Store::all_for_user(33)===[]);
 delete_user_meta(33,'_petish_club_deleting');
 Petish_Passport_Store::get($id,11);$wpdb->on_acquire=function()use($id){$GLOBALS['posts'][$id]->post_author=22;};
 check('waited_lock_refreshes_owner',is_wp_error(Petish_Passport_Store::update($id,11,fields())));
 $GLOBALS['posts'][$id]->post_author=11;clean_post_cache($id);
 $journal=Petish_Passport_Moments::create($id,11,['kind'=>'journal','caption'=>'A <script>plain</script> note.']);$mid=$journal['id'];
 check('journal_private',$GLOBALS['posts'][$mid]->post_status==='private'&&$journal['photo']==='');
 check('journal_caption_sanitized',$journal['caption']==='A plain note.');
 check('journal_parent_and_owner',$journal['pet_id']===$id&&$GLOBALS['posts'][$mid]->post_author===11);
 check('moment_owner_read',Petish_Passport_Moments::get($mid,11)===$journal);
 check('moment_cross_owner_read_denied',is_wp_error(Petish_Passport_Moments::get($mid,22)));
 check('moment_cross_owner_create_denied',is_wp_error(Petish_Passport_Moments::create($id,22,['kind'=>'journal','caption'=>'No'])));
 check('moment_cross_owner_update_denied',is_wp_error(Petish_Passport_Moments::update($mid,22,['kind'=>'journal','caption'=>'No'])));
 check('moment_cross_owner_delete_denied',is_wp_error(Petish_Passport_Moments::delete($mid,22)));
 check('moment_cross_owner_list_empty',Petish_Passport_Moments::all_for_pet($id,22)===[]);
 check('journal_requires_text',is_wp_error(Petish_Passport_Moments::create($id,11,['kind'=>'journal','caption'=>''])));
 check('caption_limit',is_wp_error(Petish_Passport_Moments::create($id,11,['kind'=>'journal','caption'=>str_repeat('x',241)])));
 check('moment_kind_allowlist',is_wp_error(Petish_Passport_Moments::create($id,11,['kind'=>'public','caption'=>'x'])));
 check('photo_requires_upload',is_wp_error(Petish_Passport_Moments::create($id,11,['kind'=>'photo','caption'=>'x'])));
 $key=photo(11);$path=photo_path($key);update_post_meta($id,'_petish_photo',$key);
 check('private_file_owner_only',Petish_Passport_Media::file_for_owner($key,11)===$path&&Petish_Passport_Media::file_for_owner($key,22)===false);
 check('private_path_traversal_denied',Petish_Passport_Media::file_for_owner('11/../../anything.png',11)===false);
 check('fake_upload_rejected',is_wp_error(Petish_Passport_Media::save(['error'=>UPLOAD_ERR_OK,'tmp_name'=>$path,'name'=>'x.png'],11)));
 $fake=['error'=>UPLOAD_ERR_OK,'tmp_name'=>$path,'name'=>'x.png'];
 check('invalid_create_upload_leaves_no_pet',is_wp_error(Petish_Passport_Store::create(11,fields(),$fake))&&count(Petish_Passport_Store::all_for_user(11))===1);
 check('invalid_create_upload_leaves_no_moment',is_wp_error(Petish_Passport_Moments::create($id,11,['kind'=>'photo','caption'=>'Bad upload'],$fake))&&count(Petish_Passport_Moments::all_for_pet($id,11))===1);
 $copy=Petish_Passport_Media::duplicate($key,11);
 check('duplicate_distinct_private_file',is_string($copy)&&$copy!==$key&&is_file(photo_path($copy)));
 check('duplicate_bytes_preserved',hash_file('sha256',$path)===hash_file('sha256',photo_path($copy)));
 check('duplicate_mode_private',(fileperms(photo_path($copy))&0777)===0600);
 check('duplicate_owner_mismatch_rejected',is_wp_error(Petish_Passport_Media::duplicate($key,22)));
 $reserved='';$reject=Petish_Passport_Media::duplicate($key,11,function($v)use(&$reserved){$reserved=$v;return false;});
 check('duplicate_prejournal_failure_no_file',is_wp_error($reject)&&$reserved!==''&&!file_exists(photo_path($reserved)));
 $reserved='';$copy2=Petish_Passport_Media::duplicate($key,11,function($v)use(&$reserved){$reserved=$v;return true;});
 check('duplicate_prejournal_key_matches_file',$copy2===$reserved&&is_file(photo_path($copy2)));
 $reserved='';$pending_copy=Petish_Passport_Media::duplicate($key,11,function($v)use(&$reserved,$id){$reserved=$v;check('pending_key_saved_before_file',!file_exists(photo_path($v)));return Petish_Passport_Media::queue_removal($id,11,$v);});
 check('pending_replacement_key_persisted',$pending_copy===$reserved&&in_array($pending_copy,get_post_meta($id,'_petish_photo_retirements',true),true));
 $GLOBALS['deny_delete']=[photo_path($pending_copy)];$cleanup=Petish_Passport_Media::flush_removals($id,11,$key);
 check('failed_replacement_cleanup_remains_reachable',is_wp_error($cleanup)&&is_file(photo_path($pending_copy))&&in_array($pending_copy,get_post_meta($id,'_petish_photo_retirements',true),true));
 $GLOBALS['deny_delete']=[];$cleanup=Petish_Passport_Media::flush_removals($id,11,$key);
 check('pending_replacement_cleanup_retry',!is_wp_error($cleanup)&&!file_exists(photo_path($pending_copy))&&is_file($path));
 $linkkey='11/'.str_repeat('b',48).'.png';symlink($path,photo_path($linkkey));
 check('symlink_file_rejected',Petish_Passport_Media::file_for_owner($linkkey,11)===false&&is_wp_error(Petish_Passport_Media::duplicate($linkkey,11)));unlink(photo_path($linkkey));
 $hardkey='11/'.str_repeat('b',48).'.png';link($path,photo_path($hardkey));
 check('hardlink_copy_rejected',is_wp_error(Petish_Passport_Media::duplicate($hardkey,11)));unlink(photo_path($hardkey));clearstatcache();
 $GLOBALS['fail_meta']='_petish_photo_retirements';$result=Petish_Passport_Store::update($id,11,fields(['remove_photo'=>true]));$GLOBALS['fail_meta']='';
 check('journal_write_failure_keeps_photo',is_wp_error($result)&&Petish_Passport_Store::get($id,11)['photo']===$key&&is_file($path));
 $GLOBALS['fail_update']=true;$result=Petish_Passport_Store::update($id,11,fields(['remove_photo'=>true]));$GLOBALS['fail_update']=false;
 check('database_failure_keeps_original',is_wp_error($result)&&Petish_Passport_Store::get($id,11)['photo']===$key&&is_file($path));
 check('database_failure_old_key_remains_reachable',in_array($key,get_post_meta($id,'_petish_photo_retirements',true),true));
 $GLOBALS['fail_meta']='_petish_photo';$result=Petish_Passport_Store::update($id,11,fields(['remove_photo'=>true]));$GLOBALS['fail_meta']='';
 check('partial_meta_failure_keeps_original',is_wp_error($result)&&Petish_Passport_Store::get($id,11)['photo']===$key&&is_file($path));
 $GLOBALS['deny_delete']=[$path];$result=Petish_Passport_Store::update($id,11,fields(['remove_photo'=>true]));
 check('filesystem_failure_retains_reachable_old_key',is_wp_error($result)&&Petish_Passport_Store::get($id,11)['photo']===''&&in_array($key,get_post_meta($id,'_petish_photo_retirements',true),true)&&is_file($path));
 $GLOBALS['deny_delete']=[];$result=Petish_Passport_Store::update($id,11,fields());
 check('cleanup_retry_removes_old_photo',!is_wp_error($result)&&!file_exists($path)&&get_post_meta($id,'_petish_photo_retirements',true)===[]);
 check('unpublish_precedes_photo_removal',count(Petish_Passport_Community::$events)>=1);
 $key=photo(11,'c');$path=photo_path($key);update_post_meta($mid,'_petish_moment_photo',$key);update_post_meta($mid,'_petish_moment_kind','photo');
 $GLOBALS['fail_update']=true;$result=Petish_Passport_Moments::update($mid,11,['kind'=>'journal','caption'=>'Still here']);$GLOBALS['fail_update']=false;
 check('moment_database_failure_keeps_photo',is_wp_error($result)&&Petish_Passport_Moments::get($mid,11)['photo']===$key&&is_file($path));
 $GLOBALS['deny_delete']=[$path];$result=Petish_Passport_Moments::update($mid,11,['kind'=>'journal','caption'=>'New note']);
 check('moment_filesystem_failure_keeps_journal',is_wp_error($result)&&Petish_Passport_Moments::get($mid,11)['photo']===''&&in_array($key,get_post_meta($mid,'_petish_photo_retirements',true),true));
 $GLOBALS['deny_delete']=[];$result=Petish_Passport_Moments::update($mid,11,['kind'=>'journal','caption'=>'New note']);
 check('moment_cleanup_retry',!is_wp_error($result)&&!file_exists($path));
 $wpdb->on_acquire=function()use($mid){$GLOBALS['posts'][$mid]->post_author=22;};
 check('waited_moment_lock_refreshes_child_owner',is_wp_error(Petish_Passport_Moments::update($mid,11,['kind'=>'journal','caption'=>'No'])));
 $GLOBALS['posts'][$mid]->post_author=11;clean_post_cache($mid);
 for($i=1;$i<12;$i++)check('moment_capacity_'.$i,!is_wp_error(Petish_Passport_Moments::create($id,11,['kind'=>'journal','caption'=>'Entry '.$i])));
 check('moment_capacity_enforced',is_wp_error(Petish_Passport_Moments::create($id,11,['kind'=>'journal','caption'=>'Too many'])));
 check('moment_list_bound',count(Petish_Passport_Moments::all_for_pet($id,11))===12);
 $export=Petish_Passport_Store::export('person11@example.invalid');
 check('export_includes_private_moments',count($export['data'])===13&&$export['done']===true);
 check('export_has_bio_traits',in_array('Introduction',array_column($export['data'][0]['data'],'name'),true)&&in_array('Personality tags',array_column($export['data'][0]['data'],'name'),true));
 check('export_does_not_include_other_owner',!str_contains(json_encode($export),'Other'));
 Petish_Passport_Community::$fail=true;
 check('unpublish_failure_blocks_pet_delete',is_wp_error(Petish_Passport_Store::delete($id,11))&&isset($GLOBALS['posts'][$id])&&count(Petish_Passport_Moments::all_for_pet($id,11))===12);
 check('unpublish_failure_blocks_moment_delete',is_wp_error(Petish_Passport_Moments::delete($mid,11))&&isset($GLOBALS['posts'][$mid]));
 Petish_Passport_Community::$fail=false;
 $key=photo(11,'d');$path=photo_path($key);update_post_meta($mid,'_petish_moment_photo',$key);update_post_meta($mid,'_petish_moment_kind','photo');
 $deleted=Petish_Passport_Store::delete($id,11);
 check('pet_delete_cascades_moments',$deleted===true&&!isset($GLOBALS['posts'][$id])&&!isset($GLOBALS['posts'][$mid])&&!file_exists($path));
 check('other_owner_survives_delete',!is_wp_error(Petish_Passport_Store::get($other['id'],22)));
 check('active_cleared_after_last_pet',get_user_meta(11,Petish_Passport_Store::ACTIVE,true)==='');
 $erase=Petish_Passport_Store::erase('person22@example.invalid');
 check('privacy_erasure_removes_owned_pet',$erase['items_removed']===true&&$erase['items_retained']===false&&$erase['done']===true);
 check('all_locks_released',$wpdb->acquired===$wpdb->released+2); // Deliberate pet and account lock timeouts.
 echo json_encode(['status'=>'success','checks'=>$checks],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
}finally{cleanup_tree($fixture);}
