<?php
/** Narrow production email release. Standalone CLI; never boots WordPress. */
if (PHP_SAPI !== 'cli') exit(1);
ini_set('display_errors','0'); ini_set('log_errors','0'); error_reporting(0);

const PETISH_EMAIL_FILES = [
    'inc/petish-email-branding.php'=>'c4d6fcbb325dacb6c3778b2bac6cfa0b007ad01972599131a5d8f5aa532c70e3',
    'inc/petish-account-email-branding.php'=>'8a347cbf2fb77b10f6ce0b5ae31a5ef639a2334990f6f8f2927d48e3b012b0fd',
    'woocommerce/emails/email-header.php'=>'f60dc3839daf6282aa533897f1ee82d21331d89e029fb4257e16f3f35279b2bd',
    'woocommerce/emails/email-footer.php'=>'a156a16e09b8a2db0257ff2a3a8514a3d3095b4d222f6f657f62f3a6e0dc6f8c',
    'woocommerce/emails/email-button.php'=>'d913f0696089c47252dfb04f51f4d6988305f6702924675092af2d8f7d09d116',
    'functions.php'=>'3c89e235558d1fe17cfa8ef0b6c7bdc50b80e4ccf09326e4ee19dd30961ef824',
];
const PETISH_EMAIL_BASELINE_FUNCTIONS = '301c05283071be0df26d0f428134ca794c2f480520e2835efa20203b3f2e1fb9';
const PETISH_EMAIL_LOGO = '45b8ab50ef5fd0b4bc98fb7994d3a4583d0d013db1b253bf7f870496cd5cf615';

function email_release_fail() { throw new RuntimeException(); }
function email_release_path($path, $directory=false) {
    if (!is_string($path) || $path==='' || $path[0]!=='/' || strpos($path,"\0")!==false) email_release_fail();
    $cursor='';
    foreach(explode('/',substr($path,1)) as $part) {
        if($part==='' || $part==='.' || $part==='..') email_release_fail();
        $cursor.='/'.$part;
        if(is_link($cursor) || realpath($cursor)!==$cursor) email_release_fail();
    }
    $info=lstat($path);
    if(!$info || ($directory ? !is_dir($path) : (!is_file($path) || $info['nlink']!==1))) email_release_fail();
    return $path;
}
function email_release_json($path) {
    email_release_path($path);
    if((fileperms($path)&07777)!==0600 || filesize($path)>1048576) email_release_fail();
    $value=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
    if(!is_array($value)) email_release_fail();
    return $value;
}
function email_release_write($path,$content,$mode=0600) {
    email_release_path(dirname($path),true);
    if(file_exists($path) || is_link($path)) email_release_fail();
    $handle=fopen($path,'xb'); if(!$handle) email_release_fail();
    try {
        if(!chmod($path,$mode) || fwrite($handle,$content)!==strlen($content) || !fflush($handle)) email_release_fail();
    } finally { fclose($handle); }
}
function email_release_atomic($path,$content,$mode,$run) {
    email_release_path(dirname($path),true);
    if(file_exists($path) || is_link($path)) email_release_path($path);
    $temporary=dirname($path).'/.petish-email-'.$run.'-'.bin2hex(random_bytes(8));
    try {
        email_release_write($temporary,$content,$mode);
        if(!rename($temporary,$path)) email_release_fail();
    } finally { if(is_file($temporary) && !is_link($temporary)) unlink($temporary); }
}
function email_release_tree($root,$exclude=[]) {
    email_release_path($root,true); $files=[];
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
    foreach($iterator as$file) {
        $path=$file->getPathname();
        if($file->isLink() || (!$file->isFile()&&!$file->isDir())) email_release_fail();
        email_release_path($path,$file->isDir());
        if($file->isFile()) {
            $relative=substr($path,strlen($root)+1);
            if(!in_array($relative,$exclude,true)) $files[$relative]=hash_file('sha256',$path);
            if(count($files)>25000) email_release_fail();
        }
    }
    ksort($files); return $files;
}
function email_release_protected($root,$theme) {
    $state=['theme'=>email_release_tree($theme,array_keys(PETISH_EMAIL_FILES)),
        'mu'=>email_release_tree($root.'/wp-content/mu-plugins')];
    foreach(['wp-config.php','.htaccess','.user.ini'] as$name) {
        $path=$root.'/'.$name;
        if(file_exists($path)||is_link($path)) { email_release_path($path); $state[$name]=hash_file('sha256',$path); }
        elseif($name==='wp-config.php') email_release_fail();
    }
    return $state;
}
function email_release_logo($theme) {
    $path=$theme.'/assets/brand/repaired/combined-light-v2.png';
    email_release_path($path);
    if(!hash_equals(PETISH_EMAIL_LOGO,hash_file('sha256',$path))) email_release_fail();
}
function email_release_payload($payload) {
    email_release_path($payload,true);
    if((fileperms($payload)&07777)!==0700) email_release_fail();
    $manifest=email_release_json($payload.'/manifest.json');
    $expected=PETISH_EMAIL_FILES; ksort($expected); ksort($manifest);
    if($manifest!==$expected) email_release_fail();
    $found=email_release_tree($payload,['manifest.json']);
    if($found!==$expected) email_release_fail();
    foreach(PETISH_EMAIL_FILES as$name=>$hash) token_get_all(file_get_contents($payload.'/'.$name),TOKEN_PARSE);
}
function email_release_live($theme) {
    $state=[]; $changed=0;
    foreach(PETISH_EMAIL_FILES as$name=>$expected) {
        $path=$theme.'/'.$name;
        if(file_exists($path)||is_link($path)) {
            email_release_path($path); $hash=hash_file('sha256',$path);
            if($hash!==$expected && !($name==='functions.php' && $hash===PETISH_EMAIL_BASELINE_FUNCTIONS)) email_release_fail();
            $state[$name]=['present'=>true,'sha256'=>$hash,'mode'=>fileperms($path)&0777];
        } else {
            if($name==='functions.php') email_release_fail();
            // Existing ancestors must also be canonical, even for absent files.
            $parent=dirname($path);
            while(!file_exists($parent) && !is_link($parent)) $parent=dirname($parent);
            email_release_path($parent,true);
            $state[$name]=['present'=>false,'sha256'=>null,'mode'=>null];
        }
        if(!$state[$name]['present']||$state[$name]['sha256']!==$expected) $changed++;
    }
    return [$state,$changed];
}
function email_release_require_candidates($theme) {
    foreach(PETISH_EMAIL_FILES as$name=>$expected) {
        email_release_path($theme.'/'.$name);
        if(!hash_equals($expected,hash_file('sha256',$theme.'/'.$name))) email_release_fail();
    }
}
function email_release_lock($base,$run,$acquire=false) {
    $lock=$base.'/deploy.lock';
    if($acquire) {
        if(file_exists($lock)||is_link($lock)||!mkdir($lock,0700)) email_release_fail();
        email_release_write($lock.'/owner',$run."\n");
    }
    email_release_path($lock,true); email_release_path($lock.'/owner');
    if((fileperms($lock)&07777)!==0700 || trim(file_get_contents($lock.'/owner'))!==$run) email_release_fail();
    return $lock;
}
function email_release_unlock($lock) {
    if(!unlink($lock.'/owner')||!rmdir($lock)) email_release_fail();
}
function email_release_journal($release,$run) {
    $journal=email_release_json($release.'/journal.json');
    if(($journal['run']??null)!==$run || !is_array($journal['files']??null)
        || array_keys($journal['files'])!==array_keys(PETISH_EMAIL_FILES)
        || !is_array($journal['protected']??null) || !is_array($journal['created_directories']??null)) email_release_fail();
    foreach($journal['files'] as$name=>$entry) {
        if(!is_array($entry)||array_keys($entry)!==['present','sha256','mode']||!is_bool($entry['present'])) email_release_fail();
        if($entry['present']) {
            $allowed=[PETISH_EMAIL_FILES[$name]];
            if($name==='functions.php') $allowed[]=PETISH_EMAIL_BASELINE_FUNCTIONS;
            if(!in_array($entry['sha256'],$allowed,true)||!is_int($entry['mode'])||$entry['mode']<0||$entry['mode']>0777) email_release_fail();
            $backup=$release.'/backup/'.array_search($name,array_keys(PETISH_EMAIL_FILES),true);
            email_release_path($backup);
            if(!hash_equals($entry['sha256'],hash_file('sha256',$backup))) email_release_fail();
        }elseif($entry['sha256']!==null||$entry['mode']!==null) email_release_fail();
    }
    foreach($journal['created_directories'] as$relative) if(!in_array($relative,['inc','woocommerce','woocommerce/emails'],true)) email_release_fail();
    return $journal;
}
function email_release_originals_verified($theme,$root,$release,$run) {
    $journal=email_release_journal($release,$run);
    if(email_release_protected($root,$theme)!==$journal['protected']) email_release_fail();
    foreach($journal['files'] as$name=>$entry) {
        $path=$theme.'/'.$name;
        if($entry['present']) {
            email_release_path($path);
            if(hash_file('sha256',$path)!==$entry['sha256']||(fileperms($path)&0777)!==$entry['mode']) email_release_fail();
        }elseif(file_exists($path)||is_link($path)) email_release_fail();
    }
    foreach($journal['created_directories'] as$relative) if(file_exists($theme.'/'.$relative)||is_link($theme.'/'.$relative)) email_release_fail();
}
function email_release_rollback($theme,$root,$release,$run) {
    $journal=email_release_journal($release,$run);
    if(email_release_protected($root,$theme)!==$journal['protected']) email_release_fail();
    // First validate every destination, then restore functions before helpers.
    foreach($journal['files'] as$name=>$entry) {
        $path=$theme.'/'.$name;
        if(file_exists($path)||is_link($path)) {
            email_release_path($path); $hash=hash_file('sha256',$path);
            if($hash!==PETISH_EMAIL_FILES[$name] && (!$entry['present']||$hash!==$entry['sha256'])) email_release_fail();
        }elseif($entry['present']) email_release_fail();
    }
    foreach(array_reverse(array_keys(PETISH_EMAIL_FILES)) as$name) {
        $path=$theme.'/'.$name; $entry=$journal['files'][$name];
        if($entry['present']) {
            $backup=$release.'/backup/'.array_search($name,array_keys(PETISH_EMAIL_FILES),true);
            email_release_atomic($path,file_get_contents($backup),$entry['mode'],$run);
        }elseif(file_exists($path)&&!unlink($path)) email_release_fail();
    }
    foreach(array_reverse($journal['created_directories']) as$relative) {
        $directory=$theme.'/'.$relative;
        if(is_dir($directory)) { email_release_path($directory,true); if(!rmdir($directory)) email_release_fail(); }
    }
    email_release_originals_verified($theme,$root,$release,$run);
    if(!file_exists($release.'/rolled-back')) email_release_write($release.'/rolled-back',"verified\n");
}

$report=['status'=>'failure','category'=>'input','checks'=>array_fill_keys([
    'paths_verified','candidate_manifest_verified','candidate_syntax_verified','logo_verified',
    'logo_world_readable','logo_path_world_traversable',
    'production_config_present','live_files_compatible','protected_state_unchanged',
    'rollback_snapshot_created','files_deployed','rollback_completed','release_committed','lock_owned'],false),'changed_file_count'=>0];
$rollback_on_exit=false; $lock=null; $theme=null; $root=null; $release=null; $run=null;
$finish=static function()use(&$report,&$rollback_on_exit,&$lock,&$theme,&$root,&$release,&$run) {
    if($rollback_on_exit) {
        try {
            email_release_rollback($theme,$root,$release,$run);
            $report['checks']['rollback_completed']=true;
            if($lock!==null) { email_release_unlock($lock); $lock=null; }
            $rollback_on_exit=false;
        }catch(Throwable $ignored) { $report['category']='rollback'; }
    }
};
register_shutdown_function(static function()use($finish,&$report) {
    $finish(); echo json_encode($report,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
});

try {
    if($argc!==4||!in_array($argv[1],['inspect','apply','rollback','commit'],true)||!preg_match('/\A[1-9][0-9]{0,31}-[1-9][0-9]{0,8}\z/D',$argv[2])) email_release_fail();
    [$script,$operation,$run,$payload]=$argv;
    $report['category']='paths';
    $home=getenv('HOME'); email_release_path($home,true);
    $root=$home.'/domains/petish.biz/public_html'; $theme=$root.'/wp-content/themes/dt-the7-child';
    email_release_path($root,true); email_release_path($theme,true); email_release_path($root.'/wp-config.php');
    $report['checks']['paths_verified']=true; $report['checks']['production_config_present']=true;
    $report['category']='payload'; email_release_payload($payload);
    $report['checks']['candidate_manifest_verified']=true; $report['checks']['candidate_syntax_verified']=true;
    $report['category']='logo'; email_release_logo($theme); $report['checks']['logo_verified']=true;
    $report['checks']['logo_world_readable']=(fileperms($theme.'/assets/brand/repaired/combined-light-v2.png')&0004)!==0;
    $traversable=true;
    foreach(['assets','assets/brand','assets/brand/repaired'] as$relative) {
        $traversable=$traversable&&(fileperms($theme.'/'.$relative)&0001)!==0;
    }
    $report['checks']['logo_path_world_traversable']=$traversable;
    $report['category']='drift'; [$live,$changed]=email_release_live($theme);
    $report['changed_file_count']=$changed; $report['checks']['live_files_compatible']=true;
    $base=$home.'/.petish-email-releases'; $release=$base.'/'.$run;
    if($operation==='inspect') { email_release_protected($root,$theme); }
    elseif($operation==='apply') {
        $report['category']='lock';
        if(!file_exists($base)&&!is_link($base)&&!mkdir($base,0700)) email_release_fail();
        email_release_path($base,true); if((fileperms($base)&07777)!==0700) email_release_fail();
        $lock=email_release_lock($base,$run,true); $report['checks']['lock_owned']=true;
        $report['category']='snapshot';
        if(file_exists($release)||is_link($release)||!mkdir($release,0700)||!mkdir($release.'/backup',0700)) email_release_fail();
        $protected=email_release_protected($root,$theme); $created=[];
        foreach(['inc','woocommerce','woocommerce/emails'] as$relative) {
            $directory=$theme.'/'.$relative;
            if(!file_exists($directory)&&!is_link($directory)) $created[]=$relative;
            else email_release_path($directory,true);
        }
        foreach($live as$name=>$entry) if($entry['present']) {
            email_release_write($release.'/backup/'.array_search($name,array_keys(PETISH_EMAIL_FILES),true),file_get_contents($theme.'/'.$name));
        }
        $journal=['run'=>$run,'files'=>$live,'protected'=>$protected,'created_directories'=>$created];
        email_release_write($release.'/journal.json',json_encode($journal,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        email_release_journal($release,$run); $report['checks']['rollback_snapshot_created']=true;
        $rollback_on_exit=true;
        $report['category']='deployment';
        foreach($created as$relative) if(!mkdir($theme.'/'.$relative,0755)) email_release_fail();
        foreach(PETISH_EMAIL_FILES as$name=>$hash) {
            if($live[$name]['present']&&$live[$name]['sha256']===$hash) continue;
            email_release_atomic($theme.'/'.$name,file_get_contents($payload.'/'.$name),0644,$run);
        }
        email_release_require_candidates($theme); $report['checks']['files_deployed']=true;
        if(email_release_protected($root,$theme)!==$protected) email_release_fail();
        $report['checks']['protected_state_unchanged']=true;
        email_release_write($release.'/pending',"verified\n");
        $rollback_on_exit=false;
    }else {
        $report['category']='lock'; email_release_path($base,true); email_release_path($release,true);
        $report['category']=$operation;
        if(file_exists($release.'/committed')||is_link($release.'/committed')) email_release_fail();
        $already_rolled_back=$operation==='rollback'&&(file_exists($release.'/rolled-back')||is_link($release.'/rolled-back'));
        if(!$already_rolled_back || file_exists($base.'/deploy.lock') || is_link($base.'/deploy.lock')) {
            $report['category']='lock';
            $lock=email_release_lock($base,$run); $report['checks']['lock_owned']=true;
        }
        $report['category']=$operation;
        $journal=email_release_journal($release,$run); $report['checks']['rollback_snapshot_created']=true;
        if($operation==='rollback') {
            if($already_rolled_back) {
                email_release_path($release.'/rolled-back');
                if((fileperms($release.'/rolled-back')&07777)!==0600||file_get_contents($release.'/rolled-back')!=="verified\n") email_release_fail();
                email_release_originals_verified($theme,$root,$release,$run);
            }else email_release_rollback($theme,$root,$release,$run);
            $report['checks']['rollback_completed']=true; $report['checks']['protected_state_unchanged']=true;
        }else {
            email_release_path($release.'/pending'); email_release_require_candidates($theme);
            if(email_release_protected($root,$theme)!==$journal['protected']) email_release_fail();
            $report['checks']['protected_state_unchanged']=true; $report['checks']['files_deployed']=true;
            email_release_write($release.'/committed',"verified\n");
            $report['checks']['release_committed']=true;
        }
        if($lock!==null) { email_release_unlock($lock); $lock=null; }
    }
    $report['status']='success'; $report['category']='complete';
}catch(Throwable $ignored) {
    $finish();
    // If preparation failed before a complete snapshot, no live file changed.
    if(($operation??null)==='apply'&&!$rollback_on_exit&&$lock!==null&&!$report['checks']['rollback_snapshot_created']) {
        try { email_release_unlock($lock); $lock=null; }catch(Throwable $ignored_again) {}
    }
}
exit($report['status']==='success'?0:1);
