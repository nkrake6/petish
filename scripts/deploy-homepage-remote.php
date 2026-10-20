<?php
/** Narrow production homepage release. Standalone CLI; never boots WordPress. */
if (PHP_SAPI !== 'cli') exit(1);
ini_set('display_errors','0'); ini_set('log_errors','0'); error_reporting(0);

// Exact reviewed homepage source and the unchanged generated delivery badge.
const PETISH_HOMEPAGE_FILES = [
    'assets/images/ui/free-delivery-van.png'=>'bfd1227330732cf1f1c56f2ef6b08794d15cd25221115e6de4baeefe0efa5972',
    'assets/css/home.css'=>'11aa02b5a3890e3023753bb2acfd024cf9e520a74737ee1d4ac8a9b1c2dca557',
    'includes/supplier-popularity.php'=>'389c1079f59f7a81882914561be69ef737d361d02b1b3ee4fdb80177b6c35145',
    'front-page.php'=>'50828b31df5a89a5a31308f3fb4bac6d01412afcab379d4b53bbecb45c46280c',
];
const PETISH_HOMEPAGE_BASELINES = [
    'assets/css/home.css'=>'81139fc111d1cdc85c412b9ecfe86dbf32cc1a2b4658ffb14088c0b9ab9e026a',
    'includes/supplier-popularity.php'=>'74b02a8407b64f407f69cc776a500d5e68872d5acf5382ef09bae3de4ee403f2',
    'front-page.php'=>'ee5c1425f0bf746471886a88bf185e176b12f8fdf3827831e51a947417dee863',
];

function homepage_release_fail() { throw new RuntimeException(); }
function homepage_release_path($path, $directory=false) {
    if (!is_string($path) || $path==='' || $path[0]!=='/' || strpos($path,"\0")!==false) homepage_release_fail();
    $cursor='';
    foreach(explode('/',substr($path,1)) as $part) {
        if($part==='' || $part==='.' || $part==='..') homepage_release_fail();
        $cursor.='/'.$part;
        if(is_link($cursor) || realpath($cursor)!==$cursor) homepage_release_fail();
    }
    $info=lstat($path);
    if(!$info || ($directory ? !is_dir($path) : (!is_file($path) || $info['nlink']!==1))) homepage_release_fail();
    return $path;
}
function homepage_release_json($path) {
    homepage_release_path($path);
    if((fileperms($path)&07777)!==0600 || filesize($path)>1048576) homepage_release_fail();
    $value=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
    if(!is_array($value)) homepage_release_fail();
    return $value;
}
function homepage_release_write($path,$content,$mode=0600) {
    homepage_release_path(dirname($path),true);
    if(file_exists($path) || is_link($path)) homepage_release_fail();
    $handle=fopen($path,'xb'); if(!$handle) homepage_release_fail();
    try {
        if(!chmod($path,$mode) || fwrite($handle,$content)!==strlen($content) || !fflush($handle)) homepage_release_fail();
    } finally { fclose($handle); }
}
function homepage_release_atomic($path,$content,$mode,$run) {
    homepage_release_path(dirname($path),true);
    if(file_exists($path) || is_link($path)) homepage_release_path($path);
    $temporary=dirname($path).'/.petish-homepage-'.$run.'-'.bin2hex(random_bytes(8));
    try {
        homepage_release_write($temporary,$content,$mode);
        if(!rename($temporary,$path)) homepage_release_fail();
    } finally { if(is_file($temporary) && !is_link($temporary)) unlink($temporary); }
}
function homepage_release_tree($root,$exclude=[]) {
    homepage_release_path($root,true); $files=[];
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
    foreach($iterator as$file) {
        $path=$file->getPathname();
        if($file->isLink() || (!$file->isFile()&&!$file->isDir())) homepage_release_fail();
        homepage_release_path($path,$file->isDir());
        if($file->isFile()) {
            $relative=substr($path,strlen($root)+1);
            if(!in_array($relative,$exclude,true)) $files[$relative]=hash_file('sha256',$path);
            if(count($files)>25000) homepage_release_fail();
        }
    }
    ksort($files); return $files;
}
function homepage_release_protected($root,$theme) {
    $state=['child_theme'=>homepage_release_tree($theme,array_keys(PETISH_HOMEPAGE_FILES)),
        'passport'=>homepage_release_tree($root.'/wp-content/plugins/petish-passport'),
        'fulfilment'=>homepage_release_tree($root.'/wp-content/plugins/petish-fulfilment'),
        'mu'=>homepage_release_tree($root.'/wp-content/mu-plugins')];
    foreach(['wp-config.php','.htaccess','.user.ini'] as$name) {
        $path=$root.'/'.$name;
        if(file_exists($path)||is_link($path)) { homepage_release_path($path); $state[$name]=hash_file('sha256',$path); }
        elseif($name==='wp-config.php') homepage_release_fail();
    }
    return $state;
}
function homepage_release_payload($payload) {
    homepage_release_path($payload,true);
    if((fileperms($payload)&07777)!==0700) homepage_release_fail();
    $manifest=homepage_release_json($payload.'/manifest.json');
    $expected=PETISH_HOMEPAGE_FILES; ksort($expected); ksort($manifest);
    if($manifest!==$expected) homepage_release_fail();
    $found=homepage_release_tree($payload,['manifest.json']);
    if($found!==$expected) homepage_release_fail();
    foreach(PETISH_HOMEPAGE_FILES as$name=>$hash) if(pathinfo($name,PATHINFO_EXTENSION)==='php') token_get_all(file_get_contents($payload.'/'.$name),TOKEN_PARSE);
    $image=file_get_contents($payload.'/assets/images/ui/free-delivery-van.png');
    if(!is_string($image)||strlen($image)>8388608||!str_starts_with($image,"\x89PNG\r\n\x1a\n")) homepage_release_fail();
    $dimensions=getimagesizefromstring($image);
    if(!is_array($dimensions)||($dimensions['mime']??null)!=='image/png'||$dimensions[0]<1||$dimensions[0]>4096||$dimensions[1]<1||$dimensions[1]>4096) homepage_release_fail();
}
function homepage_release_live($theme) {
    $state=[]; $changed=0;
    foreach(PETISH_HOMEPAGE_FILES as$name=>$expected) {
        $path=$theme.'/'.$name;
        if(file_exists($path)||is_link($path)) {
            homepage_release_path($path); $hash=hash_file('sha256',$path);
            if($hash!==$expected && $hash!==(PETISH_HOMEPAGE_BASELINES[$name]??null)) homepage_release_fail();
            $state[$name]=['present'=>true,'sha256'=>$hash,'mode'=>fileperms($path)&0777];
        } else {
            if(array_key_exists($name,PETISH_HOMEPAGE_BASELINES)) homepage_release_fail();
            // Existing ancestors must also be canonical, even for absent files.
            $parent=dirname($path);
            while(!file_exists($parent) && !is_link($parent)) $parent=dirname($parent);
            homepage_release_path($parent,true);
            $state[$name]=['present'=>false,'sha256'=>null,'mode'=>null];
        }
        if(!$state[$name]['present']||$state[$name]['sha256']!==$expected) $changed++;
    }
    return [$state,$changed];
}
function homepage_release_require_candidates($theme) {
    foreach(PETISH_HOMEPAGE_FILES as$name=>$expected) {
        homepage_release_path($theme.'/'.$name);
        if(!hash_equals($expected,hash_file('sha256',$theme.'/'.$name))) homepage_release_fail();
    }
}
function homepage_release_lock($base,$run,$acquire=false) {
    $lock=$base.'/deploy.lock';
    if($acquire) {
        if(file_exists($lock)||is_link($lock)||!mkdir($lock,0700)) homepage_release_fail();
        homepage_release_write($lock.'/owner',$run."\n");
    }
    homepage_release_path($lock,true); homepage_release_path($lock.'/owner');
    if((fileperms($lock)&07777)!==0700 || trim(file_get_contents($lock.'/owner'))!==$run) homepage_release_fail();
    return $lock;
}
function homepage_release_unlock($lock) {
    if(!unlink($lock.'/owner')||!rmdir($lock)) homepage_release_fail();
}
function homepage_release_journal($release,$run) {
    $journal=homepage_release_json($release.'/journal.json');
    if(($journal['run']??null)!==$run || !is_array($journal['files']??null)
        || array_keys($journal['files'])!==array_keys(PETISH_HOMEPAGE_FILES)
        || !is_array($journal['protected']??null) || !is_array($journal['created_directories']??null)) homepage_release_fail();
    foreach($journal['files'] as$name=>$entry) {
        if(!is_array($entry)||array_keys($entry)!==['present','sha256','mode']||!is_bool($entry['present'])) homepage_release_fail();
        if($entry['present']) {
            $allowed=[PETISH_HOMEPAGE_FILES[$name]];
            if(isset(PETISH_HOMEPAGE_BASELINES[$name])) $allowed[]=PETISH_HOMEPAGE_BASELINES[$name];
            if(!in_array($entry['sha256'],$allowed,true)||!is_int($entry['mode'])||$entry['mode']<0||$entry['mode']>0777) homepage_release_fail();
            $backup=$release.'/backup/'.array_search($name,array_keys(PETISH_HOMEPAGE_FILES),true);
            homepage_release_path($backup);
            if(!hash_equals($entry['sha256'],hash_file('sha256',$backup))) homepage_release_fail();
        }elseif($entry['sha256']!==null||$entry['mode']!==null) homepage_release_fail();
    }
    if($journal['created_directories']!==[]) homepage_release_fail();
    return $journal;
}
function homepage_release_originals_verified($theme,$root,$release,$run) {
    $journal=homepage_release_journal($release,$run);
    if(homepage_release_protected($root,$theme)!==$journal['protected']) homepage_release_fail();
    foreach($journal['files'] as$name=>$entry) {
        $path=$theme.'/'.$name;
        if($entry['present']) {
            homepage_release_path($path);
            if(hash_file('sha256',$path)!==$entry['sha256']||(fileperms($path)&0777)!==$entry['mode']) homepage_release_fail();
        }elseif(file_exists($path)||is_link($path)) homepage_release_fail();
    }
    foreach($journal['created_directories'] as$relative) if(file_exists($theme.'/'.$relative)||is_link($theme.'/'.$relative)) homepage_release_fail();
}
function homepage_release_rollback($theme,$root,$release,$run) {
    $journal=homepage_release_journal($release,$run);
    if(homepage_release_protected($root,$theme)!==$journal['protected']) homepage_release_fail();
    // First validate every destination, then restore the homepage template before its helper and assets.
    foreach($journal['files'] as$name=>$entry) {
        $path=$theme.'/'.$name;
        if(file_exists($path)||is_link($path)) {
            homepage_release_path($path); $hash=hash_file('sha256',$path);
            if($hash!==PETISH_HOMEPAGE_FILES[$name] && (!$entry['present']||$hash!==$entry['sha256'])) homepage_release_fail();
        }elseif($entry['present']) homepage_release_fail();
    }
    foreach(array_reverse(array_keys(PETISH_HOMEPAGE_FILES)) as$name) {
        $path=$theme.'/'.$name; $entry=$journal['files'][$name];
        if($entry['present']) {
            $backup=$release.'/backup/'.array_search($name,array_keys(PETISH_HOMEPAGE_FILES),true);
            homepage_release_atomic($path,file_get_contents($backup),$entry['mode'],$run);
        }elseif(file_exists($path)&&!unlink($path)) homepage_release_fail();
    }
    foreach(array_reverse($journal['created_directories']) as$relative) {
        $directory=$theme.'/'.$relative;
        if(is_dir($directory)) { homepage_release_path($directory,true); if(!rmdir($directory)) homepage_release_fail(); }
    }
    homepage_release_originals_verified($theme,$root,$release,$run);
    if(!file_exists($release.'/rolled-back')) homepage_release_write($release.'/rolled-back',"verified\n");
}

$report=['status'=>'failure','category'=>'input','checks'=>array_fill_keys([
    'paths_verified','candidate_manifest_verified','candidate_syntax_verified','candidate_badge_png_verified',
    'production_config_present','live_files_compatible','protected_state_unchanged',
    'rollback_snapshot_created','files_deployed','rollback_completed','release_committed','lock_owned'],false),'changed_file_count'=>0];
$rollback_on_exit=false; $lock=null; $theme=null; $root=null; $release=null; $run=null;
$finish=static function()use(&$report,&$rollback_on_exit,&$lock,&$theme,&$root,&$release,&$run) {
    if($rollback_on_exit) {
        try {
            homepage_release_rollback($theme,$root,$release,$run);
            $report['checks']['rollback_completed']=true;
            if($lock!==null) { homepage_release_unlock($lock); $lock=null; }
            $rollback_on_exit=false;
        }catch(Throwable $ignored) { $report['category']='rollback'; }
    }
};
register_shutdown_function(static function()use($finish,&$report) {
    $finish(); echo json_encode($report,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
});

try {
    if($argc!==4||!in_array($argv[1],['inspect','apply','rollback','commit'],true)||!preg_match('/\A[1-9][0-9]{0,31}-[1-9][0-9]{0,8}\z/D',$argv[2])) homepage_release_fail();
    [$script,$operation,$run,$payload]=$argv;
    $report['category']='paths';
    $home=getenv('HOME'); homepage_release_path($home,true);
    $root=$home.'/domains/petish.biz/public_html'; $theme=$root.'/wp-content/themes/dt-the7-child';
    homepage_release_path($root,true); homepage_release_path($theme,true); homepage_release_path($root.'/wp-config.php');
    $report['checks']['paths_verified']=true; $report['checks']['production_config_present']=true;
    $report['category']='payload'; homepage_release_payload($payload);
    $report['checks']['candidate_manifest_verified']=true; $report['checks']['candidate_syntax_verified']=true; $report['checks']['candidate_badge_png_verified']=true;
    $report['category']='drift'; [$live,$changed]=homepage_release_live($theme);
    $report['changed_file_count']=$changed; $report['checks']['live_files_compatible']=true;
    $base=$home.'/.petish-homepage-releases'; $release=$base.'/'.$run;
    if($operation==='inspect') { homepage_release_protected($root,$theme); }
    elseif($operation==='apply') {
        $report['category']='lock';
        if(!file_exists($base)&&!is_link($base)&&!mkdir($base,0700)) homepage_release_fail();
        homepage_release_path($base,true); if((fileperms($base)&07777)!==0700) homepage_release_fail();
        $lock=homepage_release_lock($base,$run,true); $report['checks']['lock_owned']=true;
        $report['category']='snapshot';
        if(file_exists($release)||is_link($release)||!mkdir($release,0700)||!mkdir($release.'/backup',0700)) homepage_release_fail();
        $protected=homepage_release_protected($root,$theme); $created=[];
        // All asset/helper directories already belong to the existing child theme.
        foreach(['assets/css','assets/images/ui','includes'] as$relative) homepage_release_path($theme.'/'.$relative,true);
        foreach($live as$name=>$entry) if($entry['present']) {
            homepage_release_write($release.'/backup/'.array_search($name,array_keys(PETISH_HOMEPAGE_FILES),true),file_get_contents($theme.'/'.$name));
        }
        $journal=['run'=>$run,'files'=>$live,'protected'=>$protected,'created_directories'=>$created];
        homepage_release_write($release.'/journal.json',json_encode($journal,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        homepage_release_journal($release,$run); $report['checks']['rollback_snapshot_created']=true;
        $rollback_on_exit=true;
        $report['category']='deployment';
        foreach($created as$relative) if(!mkdir($theme.'/'.$relative,0755)) homepage_release_fail();
        foreach(PETISH_HOMEPAGE_FILES as$name=>$hash) {
            if($live[$name]['present']&&$live[$name]['sha256']===$hash) continue;
            homepage_release_atomic($theme.'/'.$name,file_get_contents($payload.'/'.$name),0644,$run);
        }
        homepage_release_require_candidates($theme); $report['checks']['files_deployed']=true;
        if(homepage_release_protected($root,$theme)!==$protected) homepage_release_fail();
        $report['checks']['protected_state_unchanged']=true;
        homepage_release_write($release.'/pending',"verified\n");
        $rollback_on_exit=false;
    }else {
        $report['category']='lock'; homepage_release_path($base,true); homepage_release_path($release,true);
        $report['category']=$operation;
        if(file_exists($release.'/committed')||is_link($release.'/committed')) homepage_release_fail();
        $already_rolled_back=$operation==='rollback'&&(file_exists($release.'/rolled-back')||is_link($release.'/rolled-back'));
        if(!$already_rolled_back || file_exists($base.'/deploy.lock') || is_link($base.'/deploy.lock')) {
            $report['category']='lock';
            $lock=homepage_release_lock($base,$run); $report['checks']['lock_owned']=true;
        }
        $report['category']=$operation;
        $journal=homepage_release_journal($release,$run); $report['checks']['rollback_snapshot_created']=true;
        if($operation==='rollback') {
            if($already_rolled_back) {
                homepage_release_path($release.'/rolled-back');
                if((fileperms($release.'/rolled-back')&07777)!==0600||file_get_contents($release.'/rolled-back')!=="verified\n") homepage_release_fail();
                homepage_release_originals_verified($theme,$root,$release,$run);
            }else homepage_release_rollback($theme,$root,$release,$run);
            $report['checks']['rollback_completed']=true; $report['checks']['protected_state_unchanged']=true;
        }else {
            homepage_release_path($release.'/pending'); homepage_release_require_candidates($theme);
            if(homepage_release_protected($root,$theme)!==$journal['protected']) homepage_release_fail();
            $report['checks']['protected_state_unchanged']=true; $report['checks']['files_deployed']=true;
            homepage_release_write($release.'/committed',"verified\n");
            $report['checks']['release_committed']=true;
        }
        if($lock!==null) { homepage_release_unlock($lock); $lock=null; }
    }
    $report['status']='success'; $report['category']='complete';
}catch(Throwable $ignored) {
    $finish();
    // If preparation failed before a complete snapshot, no live file changed.
    if(($operation??null)==='apply'&&!$rollback_on_exit&&$lock!==null&&!$report['checks']['rollback_snapshot_created']) {
        try { homepage_release_unlock($lock); $lock=null; }catch(Throwable $ignored_again) {}
    }
}
exit($report['status']==='success'?0:1);
