<?php
/** Fixed public shipping page; private configuration and before-images stay remote. */
if (PHP_SAPI !== 'cli') exit(1);
define('PETISH_CLUB_VERIFY_LIBRARY', true);
require __DIR__.'/verify-pet-club.php';
petish_club_verify_report_start();
try {
    if (count($argv)!==3 || !in_array($argv[1],['inspect','rehearse','apply','rollback'],true) || !petish_club_verify_run($argv[2])) petish_club_verify_fail();
    if ($argv[1]!=='inspect') {
        require __DIR__.'/delivery-page-update.php';
        if ($argv[1]==='rollback') petish_delivery_rollback($argv[2]);
        else petish_delivery_change($argv[1],$argv[2]);
        $report['status']='success';$report['category']='complete';
        exit(0);
    }
    $root=petish_club_verify_bootstrap('production',true);
    $blocked=$GLOBALS['petish_club_blocked_write_count']??0;
    $report['category']='page';
    $page=get_page_by_path('delivery-shipping',OBJECT,'page');
    if (!$page instanceof WP_Post || $page->post_status!=='publish' || $page->post_password!==''
        || (int)$page->post_parent!==0 || $page->post_name!=='delivery-shipping'
        || strlen($page->post_content)>131072) petish_club_verify_fail();
    $template=get_post_meta($page->ID,'_wp_page_template',true);
    if (!is_string($template) || strlen($template)>160 || !preg_match('~\A[a-zA-Z0-9_./-]*\z~D',$template)) petish_club_verify_fail();
    $report['page']=[
        'id'=>(int)$page->ID,'content_sha256'=>hash('sha256',$page->post_content),
        'content_bytes'=>strlen($page->post_content),'template'=>$template,
        'block_markup'=>str_contains($page->post_content,'<!-- wp:'),
        'shortcodes_present'=>preg_match('/\[[a-zA-Z_][a-zA-Z0-9_-]*(?:\s|\])/',$page->post_content)===1,
        'elementor_builder'=>get_post_meta($page->ID,'_elementor_edit_mode',true)==='builder',
        'elementor_data_present'=>get_post_meta($page->ID,'_elementor_data',true)!=='',
        'object_cache_dropin_present'=>file_exists($root.'/wp-content/object-cache.php'),
        'advanced_cache_dropin_present'=>file_exists($root.'/wp-content/advanced-cache.php'),
    ];
    petish_club_verify_mark('production_read_only_verified',petish_club_verify_read_only_scope($blocked));
    $report['status']='success';$report['category']='complete';
} catch(Throwable $ignored) { $report['status']='failure'; }
exit($report['status']==='success'?0:1);
