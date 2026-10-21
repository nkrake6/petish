<?php
/** A single reviewed, editable WordPress page update. No generic SQL or content input. */
function petish_delivery_fail() { throw new RuntimeException('Delivery page check failed.'); }
function petish_delivery_check($name,$valid) {
    global $report;
    $report['checks'][$name]=$valid===true;
    if ($valid!==true) petish_delivery_fail();
}
/** Each write is the exact prepared statement approved by the owning operation. */
function petish_delivery_query_guard($sql) {
    if (!is_string($sql)) petish_delivery_fail();
    if (($GLOBALS['petish_delivery_next_sql']??null)===$sql) {
        $GLOBALS['petish_delivery_next_sql']=null;
        if (!empty($GLOBALS['petish_delivery_native_permit'])) {
            $GLOBALS['petish_delivery_observations']['approved_statement_executed']=true;
            $GLOBALS['petish_delivery_native_permit']=false;
        }
        return $sql;
    }
    $probe=preg_replace('~\A(?:\s+|/\*.*?\*/|--[^\r\n]*(?:\r?\n|$)|\#[^\r\n]*(?:\r?\n|$))*~s','',$sql);
    if (preg_match('/\A(?:SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i',$probe)
        && !preg_match('/\bINTO\s+(?:OUTFILE|DUMPFILE)\b/i',$probe)
        && !preg_match('/;\s*\S/s',rtrim($probe))) return $sql;
    $GLOBALS['petish_delivery_blocked']=($GLOBALS['petish_delivery_blocked']??0)+1;
    if (!empty($GLOBALS['petish_delivery_strict'])) {
        global $wpdb;
        preg_match('/\A\s*([A-Z]+)/i',$probe,$verb);
        $kind=strtolower($verb[1]??'other');
        $GLOBALS['petish_delivery_observations']['blocked_statement_kind']=in_array($kind,['update','insert','delete','replace','set'],true)?$kind:'other';
        $GLOBALS['petish_delivery_observations']['blocked_table']='other';
        foreach (['posts','postmeta','options'] as $name) {
            $table=$wpdb->$name??'';
            if (is_string($table)&&$table!==''&&preg_match('/\b'.preg_quote($table,'/').'\b/',$probe)) {
                $GLOBALS['petish_delivery_observations']['blocked_table']=$name;break;
            }
        }
        $GLOBALS['petish_delivery_observations']['write_permit_pending']=($GLOBALS['petish_delivery_next_sql']??null)!==null;
        petish_delivery_fail();
    }
    return 'SELECT 0 WHERE 1=0';
}
function petish_delivery_once($sql) {
    global $wpdb;
    if (($GLOBALS['petish_delivery_next_sql']??null)!==null) petish_delivery_fail();
    $GLOBALS['petish_delivery_next_sql']=$sql;
    $result=$wpdb->query($sql);
    if (($GLOBALS['petish_delivery_next_sql']??null)!==null || $result===false || $wpdb->last_error!=='') petish_delivery_fail();
    return $result;
}
function petish_delivery_row_equal($left,$right) {
    if (!is_array($left)||!is_array($right)) return false;
    ksort($left); ksort($right);
    return $left===$right;
}
function petish_delivery_row($lock=false) {
    global $wpdb;
    if (!preg_match('/\A[A-Za-z0-9_]+\z/D',$wpdb->posts)) petish_delivery_fail();
    $row=$wpdb->get_row("SELECT * FROM `{$wpdb->posts}` WHERE ID=1002".($lock?' FOR UPDATE':''),ARRAY_A);
    if (!is_array($row)||$wpdb->last_error!==''||$row['post_type']!=='page'||$row['post_name']!=='delivery-shipping'
        ||$row['post_status']!=='publish'||$row['post_password']!==''||(int)$row['post_parent']!==0) petish_delivery_fail();
    return $row;
}
function petish_delivery_manifest() {
    $manifest=petish_club_verify_read_json(__DIR__.'/delivery-page-release.json');
    $content=file_get_contents(__DIR__.'/delivery-page-content.html');
    if (array_keys($manifest)!==['page_id','old_content_sha256','new_content_sha256','inspection_run','inspection_source','old_public_copy_sha256']
        ||$manifest['page_id']!==1002
        ||$manifest['old_content_sha256']!=='a6be7ca22b83ce301bf3fa0969f4559f3fa539f716ecaf7236472fa06ac9c000'
        ||$manifest['inspection_run']!=='39541001811-1'
        ||$manifest['inspection_source']!=='116accc6286e8ccb1b52810ef0bbb1e1171e637e'
        ||!is_string($content)||strlen($content)>16384||strlen($content)<1000
        ||!hash_equals($manifest['new_content_sha256'],hash('sha256',$content))) petish_delivery_fail();
    return [$manifest,$content];
}
/** Install before wp-load: unrelated lazy writes, mail, HTTP and sync stay isolated. */
function petish_delivery_bootstrap() {
    $home=getenv('HOME');
    if (!petish_club_verify_path($home,true)) petish_delivery_fail();
    $plugin=$home.'/domains/petish.biz/public_html/wp-includes/plugin.php';
    if (!petish_club_verify_path($plugin)) petish_delivery_fail();
    require_once $plugin;
    $false=static fn()=>false; $true=static fn()=>true;
    $filters=['query'=>'petish_delivery_query_guard','jetpack_sync_listener_should_load'=>$false,
        'jetpack_sync_sender_should_load'=>$false,'pre_option_jetpack_sync_settings_disable'=>$true];
    foreach ($filters as $name=>$callback) add_filter($name,$callback,PHP_INT_MAX);
    add_action('all',static function($hook)use($filters){
        if (isset($filters[$hook])) { remove_filter($hook,$filters[$hook],PHP_INT_MAX);add_filter($hook,$filters[$hook],PHP_INT_MAX); }
    },PHP_INT_MAX);
    $root=petish_club_verify_bootstrap('production',false);
    $sync='Automattic\\Jetpack\\Sync\\Actions';
    petish_delivery_check('jetpack_sync_isolated',apply_filters('jetpack_sync_listener_should_load',true)===false
        &&apply_filters('jetpack_sync_sender_should_load',true)===false
        &&(!class_exists($sync)||($sync::$listener===null&&$sync::$sender===null)));
    petish_delivery_check('no_persistent_cache_dropins',!file_exists($root.'/wp-content/object-cache.php')&&!file_exists($root.'/wp-content/advanced-cache.php'));
    return $root;
}
/** WordPress still performs its native cache invalidation; external save hooks are request-local disabled. */
function petish_delivery_bound_hooks() {
    $hooks=['pre_post_update','edit_post_page','edit_post','post_updated','save_post_page','save_post','wp_insert_post',
        'wp_after_insert_post','transition_post_status','publish_page','publish_to_publish','clean_post_cache','clean_page_cache'];
    foreach ($hooks as $hook) remove_all_actions($hook);
    add_filter('wp_revisions_to_keep',static fn()=>0,PHP_INT_MAX);
    add_filter('add_trashed_suffix_to_trashed_posts',static fn()=>false,PHP_INT_MAX);
    foreach (get_object_taxonomies('page','objects') as $taxonomy) if (!empty($taxonomy->default_term)) petish_delivery_fail();
    if (is_object_in_taxonomy('page','category')||is_object_in_taxonomy('page','post_tag')) petish_delivery_fail();
}
/** Reconstruct the exact native wpdb::update statement after validating every merged field. */
function petish_delivery_update_sql($data,$before,$content) {
    global $wpdb;
    $expected=['post_author','post_date','post_date_gmt','post_content','post_content_filtered','post_title','post_excerpt',
        'post_status','post_type','comment_status','ping_status','post_password','post_name','to_ping','pinged',
        'post_modified','post_modified_gmt','post_parent','menu_order','post_mime_type','guid'];
    $observed=array_keys($data);$sorted=$observed;$sorted_expected=$expected;sort($sorted);sort($sorted_expected);
    $observations=&$GLOBALS['petish_delivery_observations'];
    $observations['native_field_count']=min(count($observed),100);
    $observations['expected_field_set']=$sorted===$sorted_expected;
    $observations['expected_field_order']=$observed===$expected;
    if (!$observations['expected_field_order']) petish_delivery_fail();
    foreach (['content_matches','unchanged_fields','valid_modified_dates','valid_field_types'] as $key) $observations[$key]=true;
    $fields=[];$values=[];
    foreach ($data as $field=>$value) {
        if (!is_scalar($value)) { $observations['valid_field_types']=false;continue; }
        if ($field==='post_content') { if ($value!==$content) $observations['content_matches']=false; }
        elseif (in_array($field,['post_modified','post_modified_gmt'],true)) {
            if (!is_string($value)||!preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/D',$value)) $observations['valid_modified_dates']=false;
        } elseif ((string)$value!==$before[$field]) $observations['unchanged_fields']=false;
        $format=$wpdb->field_types[$field]??'%s';
        if (!in_array($format,['%d','%s'],true)) $observations['valid_field_types']=false;
        $fields[]='`'.$field.'` = '.$format;$values[]=$value;
    }
    foreach (['content_matches','unchanged_fields','valid_modified_dates','valid_field_types'] as $key) if (!$observations[$key]) petish_delivery_fail();
    $values[]=1002;
    return $wpdb->prepare('UPDATE `'.$wpdb->posts.'` SET '.implode(', ',$fields).' WHERE `ID` = %d',$values);
}
function petish_delivery_journal_path($run,$create) {
    $dir=getenv('HOME').'/.petish-delivery-page-backups';
    if (!file_exists($dir)&&$create&&!mkdir($dir,0700)) petish_delivery_fail();
    if (!petish_club_verify_path($dir,true)||(fileperms($dir)&07777)!==0700) petish_delivery_fail();
    return $dir.'/'.$run.'.json';
}
function petish_delivery_table_ready() {
    global $wpdb;
    $table=$wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s',$wpdb->esc_like($wpdb->posts)),ARRAY_A);
    petish_delivery_check('transactional_page_table',is_array($table)&&strtoupper($table['Engine']??'')==='INNODB');
    $triggers=$wpdb->get_results($wpdb->prepare('SHOW TRIGGERS WHERE `Table`=%s',$wpdb->posts),ARRAY_A);
    petish_delivery_check('page_table_has_no_triggers',$wpdb->last_error===''&&$triggers===[]);
}
function petish_delivery_change($mode,$run) {
    global $wpdb,$report;
    [$manifest,$content]=petish_delivery_manifest();
    $GLOBALS['petish_delivery_observations']=[];
    $root=petish_delivery_bootstrap();
    $report['category']='mutation_scope';
    petish_delivery_bound_hooks();
    petish_delivery_table_ready();
    $GLOBALS['petish_delivery_strict']=true;
    $transaction=false;
    try {
        petish_delivery_once('START TRANSACTION'); $transaction=true;
        $before=petish_delivery_row(true);
        $report['category']='expected_content';
        petish_delivery_check('expected_page_content',hash_equals($manifest['old_content_sha256'],hash('sha256',$before['post_content'])));
        if (get_post_meta(1002,'_wp_page_template',true)!==''||get_post_meta(1002,'_elementor_edit_mode',true)==='builder'
            ||get_post_meta(1002,'_elementor_data',true)!=='') petish_delivery_fail();
        $after=$before;$updates=0;
        add_action('pre_post_update',static function($id,$data)use($before,$content,&$after,&$updates){
            $GLOBALS['petish_delivery_observations']['native_pre_update_reached']=true;
            $GLOBALS['petish_delivery_observations']['native_target_valid']=$id===1002&&$updates===0;
            if ($id!==1002||$updates!==0) petish_delivery_fail();
            $sql=petish_delivery_update_sql($data,$before,$content);
            foreach ($data as $key=>$value) $after[$key]=(string)$value;
            $GLOBALS['petish_delivery_next_sql']=$sql;$GLOBALS['petish_delivery_native_permit']=true;
            $GLOBALS['petish_delivery_observations']['prepared_statement_approved']=true;$updates++;
        },PHP_INT_MAX,2);
        $report['category']='native_update';
        $result=wp_update_post(['ID'=>1002,'post_content'=>wp_slash($content)],true,false);
        $GLOBALS['petish_delivery_observations']['native_result_valid']=$result===1002;
        petish_delivery_check('native_editable_content_saved',$result===1002&&$updates===1
            &&($GLOBALS['petish_delivery_next_sql']??null)===null&&petish_delivery_row_equal(petish_delivery_row(),$after));
        if ($mode==='rehearse') {
            $report['category']='rollback';
            petish_delivery_once('ROLLBACK');$transaction=false;
            clean_post_cache(1002);
            petish_delivery_check('rehearsal_rolled_back',petish_delivery_row_equal(petish_delivery_row(),$before));
        } else {
            $report['category']='snapshot';
            $journal=['run_id'=>$run,'page_id'=>1002,'before'=>$before,'after'=>$after];
            $path=petish_delivery_journal_path($run,true);
            petish_club_verify_write_json($path,$journal);
            $handle=fopen($path,'r+b');
            if (!$handle) petish_delivery_fail();
            try { if (!fsync($handle)) petish_delivery_fail(); } finally { fclose($handle); }
            if (petish_club_verify_read_json($path)!==$journal) petish_delivery_fail();
            petish_delivery_check('private_before_snapshot_saved',true);
            $report['category']='commit';
            petish_delivery_once('COMMIT');$transaction=false;
            petish_delivery_check('committed_row_verified',petish_delivery_row_equal(petish_delivery_row(),$after));
        }
        $report['page']=['id'=>1002,'content_sha256'=>hash('sha256',($mode==='rehearse'?$before:$after)['post_content'])];
    } finally {
        $GLOBALS['petish_delivery_next_sql']=null;
        $GLOBALS['petish_delivery_native_permit']=false;
        if ($transaction) {
            petish_delivery_once('ROLLBACK');
            if ($mode==='rehearse'&&isset($before)) {
                clean_post_cache(1002);
                petish_delivery_check('rehearsal_rolled_back',petish_delivery_row_equal(petish_delivery_row(),$before));
            }
        }
        $GLOBALS['petish_delivery_strict']=false;
    }
}
function petish_delivery_rollback($run) {
    global $wpdb,$report;
    [$manifest,$content]=petish_delivery_manifest();
    petish_delivery_bootstrap();petish_delivery_bound_hooks();
    $report['category']='rollback';
    petish_delivery_table_ready();
    $path=petish_delivery_journal_path($run,true);
    if (!file_exists($path)&&!is_link($path)) {
        // A failed upload/bootstrap/update cannot commit without a durable journal.
        $row=petish_delivery_row();
        petish_delivery_check('rollback_owns_current_row',hash_equals($manifest['old_content_sha256'],hash('sha256',$row['post_content'])));
        petish_delivery_check('original_row_restored',true);
        $report['page']=['id'=>1002,'content_sha256'=>$manifest['old_content_sha256']];
        return;
    }
    $journal=petish_club_verify_read_json($path);
    if (array_keys($journal)!==['run_id','page_id','before','after']||$journal['run_id']!==$run||$journal['page_id']!==1002
        ||!hash_equals($manifest['old_content_sha256'],hash('sha256',$journal['before']['post_content']))
        ||!hash_equals($manifest['new_content_sha256'],hash('sha256',$journal['after']['post_content']))) petish_delivery_fail();
    $GLOBALS['petish_delivery_strict']=true;$transaction=false;
    try {
        petish_delivery_once('START TRANSACTION');$transaction=true;
        $row=petish_delivery_row(true);
        if (!petish_delivery_row_equal($row,$journal['before'])) {
            petish_delivery_check('rollback_owns_current_row',petish_delivery_row_equal($row,$journal['after']));
            $fields=[];$values=[];
            foreach ($journal['before'] as $key=>$value) {
                if (!preg_match('/\A[a-zA-Z_]+\z/D',$key)||!is_string($value)) petish_delivery_fail();
                $fields[]='`'.$key.'` = %s';$values[]=$value;
            }
            $values[]=1002;
            if (petish_delivery_once($wpdb->prepare('UPDATE `'.$wpdb->posts.'` SET '.implode(', ',$fields).' WHERE `ID` = %d',$values))!==1) petish_delivery_fail();
        } else petish_delivery_check('rollback_owns_current_row',true);
        petish_delivery_check('original_row_restored',petish_delivery_row_equal(petish_delivery_row(),$journal['before']));
        petish_delivery_once('COMMIT');$transaction=false;clean_post_cache(1002);
        $report['page']=['id'=>1002,'content_sha256'=>$manifest['old_content_sha256']];
    } finally {
        $GLOBALS['petish_delivery_next_sql']=null;
        if ($transaction) petish_delivery_once('ROLLBACK');
        $GLOBALS['petish_delivery_strict']=false;
    }
}
