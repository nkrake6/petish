<?php
/** Isolated domain tests. No WordPress, database, network, account or real photo writes. */
define('ABSPATH', __DIR__ . '/fixture/'); define('ARRAY_A', 'ARRAY_A');
class WP_Error { public function __construct(public $code='', public $message='', public $data=null){} public function get_error_message(){return $this->message;} }
function is_wp_error($x){return $x instanceof WP_Error;} function __($s,$d=''){return $s;}
function absint($v){return abs((int)$v);} function wp_slash($x){return is_array($x)?array_map('wp_slash',$x):(is_string($x)?addslashes($x):$x);}
function wp_unslash($x){return is_array($x)?array_map('wp_unslash',$x):(is_string($x)?stripslashes($x):$x);}
function get_current_user_id(){return $GLOBALS['actor'];} function is_user_logged_in(){return get_current_user_id()>0;}
function current_user_can($c){return !empty($GLOBALS['manager']);} function wp_verify_nonce($nonce,$action){return hash_equals('valid:'.$action,$nonce);}
function wp_cache_delete($id,$group){return true;} function get_user_by($field,$value){foreach($GLOBALS['users']as$user)if(($field==='email'?$user->user_email:$user->ID)===$value || ($field==='id'&&(int)$value===$user->ID))return $user;return false;}
function get_user_meta($uid,$key,$single=true){return $GLOBALS['usermeta'][$uid][$key]??'';} function update_user_meta($uid,$key,$value){$GLOBALS['usermeta'][$uid][$key]=$value;return true;}
function get_post($id){return $GLOBALS['posts'][(int)$id]??null;}
function get_post_meta($id,$key,$single=true){return $GLOBALS['meta'][$id][$key]??'';}
function update_post_meta($id,$key,$value){if(($GLOBALS['fail_meta']??'')===$key)return false;$GLOBALS['meta'][$id][$key]=wp_unslash($value);return true;}
function delete_post_meta($id,$key){if(($GLOBALS['fail_delete_meta']??'')===$key)return false;unset($GLOBALS['meta'][$id][$key]);return true;}
function get_posts($args){$ids=[];foreach($GLOBALS['posts']as$id=>$p){if(($args['post_type']??'')!==$p->post_type||($args['post_status']??'')!==$p->post_status)continue;if(isset($args['meta_key'])){if(!isset($GLOBALS['meta'][$id][$args['meta_key']]))continue;if(isset($args['meta_value'])&&$GLOBALS['meta'][$id][$args['meta_key']]!==$args['meta_value'])continue;}$ids[]=$id;}sort($ids);if(($args['order']??'')==='DESC')$ids=array_reverse($ids);return array_slice($ids,0,$args['posts_per_page']);}
function add_option($key,$value,$deprecated='',$autoload=false){if(isset($GLOBALS['options'][$key]))return false;$GLOBALS['options'][$key]=$value;$GLOBALS['autoload'][$key]=$autoload;return true;}
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function delete_option($key){$had=isset($GLOBALS['options'][$key]);unset($GLOBALS['options'][$key]);return $had;}
function home_url($p='/'){return 'https://petish.invalid'.$p;} function admin_url($p=''){return 'https://petish.invalid/wp-admin/'.$p;}
function add_query_arg($a,$b=null,$c=null){$args=is_array($a)?$a:[$a=>$b];$url=is_array($a)?$b:$c;return $url.(str_contains($url,'?')?'&':'?').http_build_query($args);}
function like_match($s,$p){$regex='';for($i=0;$i<strlen($p);$i++){if($p[$i]==='\\'&&$i+1<strlen($p))$regex.=preg_quote($p[++$i],'~');elseif($p[$i]==='%')$regex.='.*';elseif($p[$i]==='_')$regex.='.';else$regex.=preg_quote($p[$i],'~');}return preg_match('~^'.$regex.'$~D',$s)===1;}
class FakeDB {
 public $options='wp_options',$prefix='wp_';
 public function prepare($sql,...$args){return [$sql,$args];}public function esc_like($s){return addcslashes($s,'_%\\');}
 public function get_var($q){[$sql,$a]=$q;if(str_contains($sql,'GET_LOCK'))return $GLOBALS['lock_fail']?'0':'1';if(str_contains($sql,'RELEASE_LOCK'))return '1';if(str_contains($sql,'COUNT(*)'))return count(array_filter(array_keys($GLOBALS['options']),fn($key)=>like_match($key,$a[0])));throw new Exception('Unexpected query');}
 public function get_col($q){[$sql,$a]=$q;return array_slice(array_values(array_filter(array_keys($GLOBALS['options']),fn($key)=>like_match($key,$a[0])||like_match($key,$a[1]))),$a[3]??0,$a[2]);}
}
class Petish_Passport_Store {
 const TYPE='petish_pet';
 public static function with_pet_lock($id,$cb){if($GLOBALS['lock_fail'])return new WP_Error('busy');return $cb();}
 public static function with_user_lock($id,$cb){if($GLOBALS['lock_fail'])return new WP_Error('busy');return $cb();}
 public static function get($id,$uid){$p=get_post($id);return $p&&$p->post_author===$uid&&$p->post_type===self::TYPE&&$p->post_status==='private'?$GLOBALS['pets'][$id]:new WP_Error('missing');}
 public static function species(){return ['rabbit'=>'Rabbit','hamster'=>'Hamster'];}public static function ages(){return ['under-1'=>'Under1','1-3'=>'1–3years','unsure'=>'Unsure'];}
 public static function variety_label($pet){return ($pet['variety']??'')==='other'?($pet['variety_other']??''):(['mini-lop'=>'Mini Lop','rex'=>'Rex'][$pet['variety']??'']??'');}
 public static function all_for_user($uid){return array_values(array_filter($GLOBALS['pets'],fn($p)=>get_post($p['id'])->post_author===$uid));}
}
class Petish_Passport_Moments {public static function get($id,$uid){$m=$GLOBALS['moments'][$id]??null;return $m&&!is_wp_error(Petish_Passport_Store::get($m['pet_id'],$uid))?$m:new WP_Error('missing');}}
class Petish_Passport_Media {
 public static function duplicate($key,$uid,$before=null){if(!isset($GLOBALS['files'][$key])||!str_starts_with($key,$uid.'/'))return new WP_Error('photo');$copy=$uid.'/'.bin2hex(random_bytes(24)).'.jpg';if($before){$r=$before($copy);if($r!==true)return $r instanceof WP_Error?$r:new WP_Error('photo');}$GLOBALS['files'][$copy]='copy';return $copy;}
 public static function remove($key){if(in_array($key,$GLOBALS['fail_remove'],true))return false;unset($GLOBALS['files'][$key]);return true;}
 public static function file_for_owner($key,$uid){return isset($GLOBALS['files'][$key])&&str_starts_with($key,$uid.'/')?'/private/'.$key:false;}
}
function reset_fixture(){
 $GLOBALS['actor']=11;$GLOBALS['manager']=false;$GLOBALS['lock_fail']=false;$GLOBALS['fail_meta']='';$GLOBALS['fail_delete_meta']='';$GLOBALS['fail_remove']=[];$GLOBALS['meta']=[];$GLOBALS['options']=[];$GLOBALS['autoload']=[];$GLOBALS['usermeta']=[];$GLOBALS['wpdb']=new FakeDB();
 $GLOBALS['users']=[(object)['ID'=>11,'user_email'=>'owner@example.invalid'],(object)['ID'=>22,'user_email'=>'member@example.invalid'],(object)['ID'=>33,'user_email'=>'other@example.invalid']];
 $GLOBALS['posts']=[101=>(object)['ID'=>101,'post_author'=>11,'post_type'=>'petish_pet','post_status'=>'private'],102=>(object)['ID'=>102,'post_author'=>33,'post_type'=>'petish_pet','post_status'=>'private']];
 $GLOBALS['pets']=[101=>['id'=>101,'name'=>'Clover','species'=>'rabbit','age'=>'1-3','bio'=>'A backslash \\ and "quoted" introduction.','traits'=>['Curious'],'photo'=>'11/'.str_repeat('a',48).'.jpg'],102=>['id'=>102,'name'=>'Miso','species'=>'hamster','age'=>'under-1','bio'=>'Other owner','traits'=>[],'photo'=>'']];
 $GLOBALS['moments']=[201=>['id'=>201,'pet_id'=>101,'kind'=>'photo','caption'=>'A \\ "quoted" moment','photo'=>'11/'.str_repeat('b',48).'.jpg','created'=>'2026-10-21 00:00:00'],202=>['id'=>202,'pet_id'=>102,'kind'=>'journal','caption'=>'Private other owner','photo'=>'','created'=>'2026-10-21 00:00:00']];
 $GLOBALS['files']=array_fill_keys([$GLOBALS['pets'][101]['photo'],$GLOBALS['moments'][201]['photo']],'original');$_SERVER['REQUEST_METHOD']='POST';
}
require __DIR__.'/../wordpress/wp-content/plugins/petish-passport/includes/class-petish-passport-community.php';
$checks=0;function check($condition,$label){global$checks;if(!$condition)throw new Exception($label);$checks++;}
function shared(){return Petish_Passport_Community::publish(101,11,true,[201]);}
reset_fixture();
check(is_wp_error(Petish_Passport_Community::public_profile(str_repeat('a',32))),'Legacy private pet cannot be read');
check(Petish_Passport_Community::discover()===[],'No fictional or private pets in discovery');
check(is_wp_error(Petish_Passport_Community::publish(101,22)),'Other user cannot publish');
check(is_wp_error(Petish_Passport_Community::publish(101,11,true,[202])),'Cannot publish another pet moment');
check(is_wp_error(Petish_Passport_Community::publish(101,11,true,['1 OR 1'])),'Reject malformed moment ids');
$s=shared();check(!is_wp_error($s)&&$s['shared']&&strlen($s['token'])===32,'Explicit publication creates snapshot');$token=$s['token'];
$p=Petish_Passport_Community::public_profile($token);
check($p['bio']===$GLOBALS['pets'][101]['bio']&&$p['moments'][0]['caption']===$GLOBALS['moments'][201]['caption'],'Backslash and quote roundtrip');
check($p['age']==='1-3'&&$s['moment_ids']===[201],'Only selected optional fields publish');
check(!isset($p['id'],$p['uid'],$p['photo'],$p['owner'])&&!str_contains(json_encode($p),$GLOBALS['pets'][101]['photo']),'Public projection excludes owners and file keys');
check(count($GLOBALS['files'])===4,'Separate public copies made');
check(!is_wp_error(Petish_Passport_Community::resolve_photo($token)),'Published photo resolves');
check(is_wp_error(Petish_Passport_Community::resolve_photo($token,['bad'])),'Malformed photo selector denied');
check(count(Petish_Passport_Community::discover())===1,'Only published pet discovered');
$GLOBALS['pets'][101]['name']='Changed private name';
check(Petish_Passport_Community::public_profile($token)['name']==='Clover','Private edits do not silently republish');
$GLOBALS['actor']=0;check(is_wp_error(Petish_Passport_Community::set_heart($token,22,true)),'Anonymous cannot impersonate member');
$GLOBALS['actor']=11;check(is_wp_error(Petish_Passport_Community::set_heart($token,11,true)),'No self hearts');
$GLOBALS['actor']=22;
check(Petish_Passport_Community::set_heart($token,22,true)['hearts']===1,'Member heart');
check(Petish_Passport_Community::set_heart($token,22,true)['hearts']===1&&count($GLOBALS['options'])===1,'Replay remains one heart');
check($GLOBALS['autoload']['petish_club_heart_101_22']===false,'Heart not autoloaded');
check(Petish_Passport_Community::set_heart($token,22,false)['hearts']===0,'Undo heart');
check(Petish_Passport_Community::set_heart($token,22,false)['hearts']===0,'Undo replay idempotent');
check(Petish_Passport_Community::report($token,22,'privacy')===true&&Petish_Passport_Community::report($token,22,'spam')===true,'Report duplicate accepted once');
check(count($GLOBALS['options'])===1,'Reports deduplicate');
check(is_wp_error(Petish_Passport_Community::report($token,22,'<script>')),'Report input allowlist');
check(!Petish_Passport_Community::authorized('heart',$token,'wrong'),'Bad nonce denied');
check(Petish_Passport_Community::authorized('heart',$token,'valid:petish_club_heart_'.$token),'Authenticated POST nonce accepted');
$_SERVER['REQUEST_METHOD']='GET';check(!Petish_Passport_Community::authorized('heart',$token,'valid:petish_club_heart_'.$token),'GET mutation denied');$_SERVER['REQUEST_METHOD']='POST';
check(is_wp_error(Petish_Passport_Community::moderate(101,'hide')),'Nonmanager moderation denied');
$GLOBALS['manager']=true;check(Petish_Passport_Community::moderate(101,'hide')===true,'Manager hides profile');
check(is_wp_error(Petish_Passport_Community::public_profile($token))&&is_wp_error(Petish_Passport_Community::resolve_photo($token)),'Hide revokes profile and photo');
check(count($GLOBALS['files'])===2&&count($GLOBALS['options'])===0,'Unpublish removes only copies and community actions');
$GLOBALS['actor']=11;check(is_wp_error(shared()),'Moderated owner cannot immediately republish');
check(Petish_Passport_Community::moderate(101,'unblock')===true&&!Petish_Passport_Community::published_for_owner(101,11)['shared'],'Unblock does not publish');
$s=shared();check($s['shared']&&$s['token']!==$token,'Republishing after revocation gets fresh token');
reset_fixture();$s=shared();$token=$s['token'];$copy=get_post_meta(101,Petish_Passport_Community::META,true)['photo'];$GLOBALS['fail_remove']=[$copy];
check(is_wp_error(Petish_Passport_Community::unpublish(101,11)),'Deletion failure reported');
check(is_wp_error(Petish_Passport_Community::public_profile($token))&&is_wp_error(Petish_Passport_Community::resolve_photo($token)),'Deletion failure remains revoked');
check(in_array($copy,get_post_meta(101,'_petish_club_cleanup',true),true),'Failed deletion remains journalled');
$export=Petish_Passport_Community::export('owner@example.invalid');check(str_contains(json_encode($export),'Off; cleanup pending')&&str_contains(json_encode($export),'Clover')&&!str_contains(json_encode($export),$copy),'Owner export includes retained revoked snapshot without file keys');
$GLOBALS['fail_remove']=[];check(Petish_Passport_Community::unpublish(101,11)===true&&count($GLOBALS['files'])===2,'Cleanup retry removes retained derivative');
reset_fixture();$GLOBALS['fail_meta']='_petish_club_cleanup';check(is_wp_error(shared())&&count($GLOBALS['files'])===2,'Journal failure creates no derivative');
reset_fixture();$GLOBALS['fail_meta']=Petish_Passport_Community::META;check(is_wp_error(shared())&&count($GLOBALS['files'])===2&&Petish_Passport_Community::discover()===[],'Snapshot write failure cleans copies and never publishes');
reset_fixture();$s=shared();$oldtoken=$s['token'];$oldcopy=get_post_meta(101,Petish_Passport_Community::META,true)['photo'];$GLOBALS['fail_remove']=[$oldcopy];
check(is_wp_error(shared())&&is_wp_error(Petish_Passport_Community::public_profile($oldtoken)),'Replacement cleanup failure revokes pending publication');
check(in_array($oldcopy,get_post_meta(101,'_petish_club_cleanup',true),true),'Replacement cannot lose old cleanup key');
$GLOBALS['fail_remove']=[];check(!is_wp_error(shared())&&count($GLOBALS['files'])===4,'Replacement retry preserves only active public copies');
reset_fixture();$s=shared();$GLOBALS['actor']=22;Petish_Passport_Community::set_heart($s['token'],22,true);Petish_Passport_Community::report($s['token'],22,'other');
$export=Petish_Passport_Community::export('member@example.invalid');check(count($export['data'])===2,'Member export contains hearts and reports on another owner pet');
$export=Petish_Passport_Community::export('owner@example.invalid');check(str_contains(json_encode($export),'Shared moment photos')&&str_contains(json_encode($export),'Traits'),'Owner export includes public snapshot media and traits');
$erase=Petish_Passport_Community::erase('member@example.invalid');check(!$erase['items_retained']&&count($GLOBALS['options'])===0&&Petish_Passport_Community::public_profile($s['token'])['hearts']===0,'User erasure removes interactions on others pets');
$GLOBALS['usermeta'][22]['_petish_club_deleting']='1';check(is_wp_error(Petish_Passport_Community::set_heart($s['token'],22,true)),'Deleting user cannot create community data');
check(is_wp_error(Petish_Passport_Community::report($s['token'],22,'other')),'Deleting user cannot create reports');
$GLOBALS['actor']=11;$GLOBALS['lock_fail']=true;check(is_wp_error(shared()),'Lock failure prevents publication');
reset_fixture();for($i=1;$i<=203;$i++)add_option('petish_club_heart_'.$i.'_22','2026-10-21 00:00:00','',false);
$page1=Petish_Passport_Community::export('member@example.invalid',1);$page2=Petish_Passport_Community::export('member@example.invalid',2);$page3=Petish_Passport_Community::export('member@example.invalid',3);
check(count($page1['data'])===100&&count($page2['data'])===100&&count($page3['data'])===3&&!$page1['done']&&!$page2['done']&&$page3['done'],'Privacy export pages all member interactions');
check(count(array_unique(array_column(array_merge($page1['data'],$page2['data'],$page3['data']),'item_id')))===203,'Privacy export pages do not duplicate interactions');
reset_fixture();$GLOBALS['pets'][101]['variety']='mini-lop';$s=shared();$token=$s['token'];
check($s['profile']['variety']==='Mini Lop'&&get_post_meta(101,Petish_Passport_Community::META,true)['variety']==='Mini Lop','Explicit publication saves breed display label');
$GLOBALS['pets'][101]['variety']='rex';
check(Petish_Passport_Community::public_profile($token)['variety']==='Mini Lop','Private breed edit does not change shared snapshot');
$snapshot=get_post_meta(101,Petish_Passport_Community::META,true);unset($snapshot['variety']);update_post_meta(101,Petish_Passport_Community::META,wp_slash($snapshot));
check(Petish_Passport_Community::public_profile($token)['variety']===''&&count(Petish_Passport_Community::discover())===1,'Legacy snapshot without breed stays readable and does not expose private breed');
$s=shared();check($s['profile']['variety']==='Rex','Explicit republish updates breed from private record');
$export=Petish_Passport_Community::export('owner@example.invalid');check(str_contains(json_encode($export),'Breed \/ variety')&&str_contains(json_encode($export),'Rex'),'Privacy export includes saved breed label');
$valid=get_post_meta(101,Petish_Passport_Community::META,true);
foreach([null,[],str_repeat('a',61)]as$invalid){$bad=$valid;$bad['variety']=$invalid;update_post_meta(101,Petish_Passport_Community::META,wp_slash($bad));check(is_wp_error(Petish_Passport_Community::public_profile($token)),'Invalid shared breed type or length is rejected');}
update_post_meta(101,Petish_Passport_Community::META,wp_slash($valid));$GLOBALS['pets'][101]['variety']='other';$GLOBALS['pets'][101]['variety_other']=str_repeat('é',60);
$s=shared();check($s['profile']['variety']===str_repeat('é',60),'Breed limit counts Unicode characters');
$GLOBALS['pets'][101]['variety_other']=str_repeat('é',61);check(is_wp_error(shared())&&Petish_Passport_Community::public_profile($token)['variety']===str_repeat('é',60),'Oversized breed cannot replace current public snapshot');
echo json_encode(['checks'=>$checks,'status'=>'success'])."\n";
