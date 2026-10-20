<?php
/** Narrow production account-navigation release. Standalone CLI; never boots WordPress. */
if (PHP_SAPI !== 'cli') exit(1);
ini_set('display_errors','0'); ini_set('log_errors','0'); error_reporting(0);

const PETISH_NAVIGATION_FILES = [
    'assets/account-navigation.js'=>'5885712bde19288a62d9660ced39f98d48c27067fea7179e42b2bad48f311e75',
    'assets/passport.css'=>'e5c41cec8708f7d0e6ccddf8de76e43b807f66a612e3eac95871ab6c8a581076',
    'includes/class-petish-passport-ui.php'=>'d5abfc66c9735470b7ed7e3dae728221bb28c78636b06e4bba5efa56dbfe58d5',
];
const PETISH_NAVIGATION_BASELINES = [
    'assets/account-navigation.js'=>'5885712bde19288a62d9660ced39f98d48c27067fea7179e42b2bad48f311e75',
    'assets/passport.css'=>'5afea46cec5eb11e35048180b00b934b1130439e5928f997769feb5d04155882',
    'includes/class-petish-passport-ui.php'=>'d5abfc66c9735470b7ed7e3dae728221bb28c78636b06e4bba5efa56dbfe58d5',
];

function navigation_release_fail() { throw new RuntimeException(); }
function navigation_release_path($path, $directory=false) {
    if (!is_string($path) || $path==='' || $path[0]!=='/' || strpos($path,"\0")!==false) navigation_release_fail();
    $cursor='';
    foreach(explode('/',substr($path,1)) as $part) {
        if($part==='' || $part==='.' || $part==='..') navigation_release_fail();
        $cursor.='/'.$part;
        if(is_link($cursor) || realpath($cursor)!==$cursor) navigation_release_fail();
    }
    $info=lstat($path);
    if(!$info || ($directory ? !is_dir($path) : (!is_file($path) || $info['nlink']!==1))) navigation_release_fail();
    return $path;
}
function navigation_release_json($path) {
    navigation_release_path($path);
    if((fileperms($path)&07777)!==0600 || filesize($path)>1048576) navigation_release_fail();
    $value=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
    if(!is_array($value)) navigation_release_fail();
    return $value;
}
function navigation_release_write($path,$content,$mode=0600) {
    navigation_release_path(dirname($path),true);
    if(file_exists($path) || is_link($path)) navigation_release_fail();
    $handle=fopen($path,'xb'); if(!$handle) navigation_release_fail();
    try {
        if(!chmod($path,$mode) || fwrite($handle,$content)!==strlen($content) || !fflush($handle)) navigation_release_fail();
    } finally { fclose($handle); }
}
function navigation_release_atomic($path,$content,$mode,$run) {
    navigation_release_path(dirname($path),true);
    if(file_exists($path) || is_link($path)) navigation_release_path($path);
    $temporary=dirname($path).'/.petish-account-navigation-'.$run.'-'.bin2hex(random_bytes(8));
    try {
        navigation_release_write($temporary,$content,$mode);
        if(!rename($temporary,$path)) navigation_release_fail();
    } finally { if(is_file($temporary) && !is_link($temporary)) unlink($temporary); }
}
function navigation_release_tree($root,$exclude=[]) {
    navigation_release_path($root,true); $files=[];
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
    foreach($iterator as$file) {
        $path=$file->getPathname();
        if($file->isLink() || (!$file->isFile()&&!$file->isDir())) navigation_release_fail();
        navigation_release_path($path,$file->isDir());
        if($file->isFile()) {
            $relative=substr($path,strlen($root)+1);
            if(!in_array($relative,$exclude,true)) $files[$relative]=hash_file('sha256',$path);
            if(count($files)>25000) navigation_release_fail();
        }
    }
    ksort($files); return $files;
}
function navigation_release_protected($root,$plugin) {
    $state=['passport'=>navigation_release_tree($plugin,array_keys(PETISH_NAVIGATION_FILES)),
        'child_theme'=>navigation_release_tree($root.'/wp-content/themes/dt-the7-child'),
        'mu'=>navigation_release_tree($root.'/wp-content/mu-plugins')];
    foreach(['wp-config.php','.htaccess','.user.ini'] as$name) {
        $path=$root.'/'.$name;
        if(file_exists($path)||is_link($path)) { navigation_release_path($path); $state[$name]=hash_file('sha256',$path); }
        elseif($name==='wp-config.php') navigation_release_fail();
    }
    return $state;
}
function navigation_release_payload($payload) {
    navigation_release_path($payload,true);
    if((fileperms($payload)&07777)!==0700) navigation_release_fail();
    $manifest=navigation_release_json($payload.'/manifest.json');
    $expected=PETISH_NAVIGATION_FILES; ksort($expected); ksort($manifest);
    if($manifest!==$expected) navigation_release_fail();
    $found=navigation_release_tree($payload,['manifest.json']);
    if($found!==$expected) navigation_release_fail();
    foreach(PETISH_NAVIGATION_FILES as$name=>$hash) if(pathinfo($name,PATHINFO_EXTENSION)==='php') token_get_all(file_get_contents($payload.'/'.$name),TOKEN_PARSE);
}
function navigation_release_live($plugin) {
    $state=[]; $changed=0;
    foreach(PETISH_NAVIGATION_FILES as$name=>$expected) {
        $path=$plugin.'/'.$name;
        if(file_exists($path)||is_link($path)) {
            navigation_release_path($path); $hash=hash_file('sha256',$path);
            if($hash!==$expected && $hash!==(PETISH_NAVIGATION_BASELINES[$name]??null)) navigation_release_fail();
            $state[$name]=['present'=>true,'sha256'=>$hash,'mode'=>fileperms($path)&0777];
        } else {
            if(array_key_exists($name,PETISH_NAVIGATION_BASELINES)) navigation_release_fail();
            // Existing ancestors must also be canonical, even for absent files.
            $parent=dirname($path);
            while(!file_exists($parent) && !is_link($parent)) $parent=dirname($parent);
            navigation_release_path($parent,true);
            $state[$name]=['present'=>false,'sha256'=>null,'mode'=>null];
        }
        if(!$state[$name]['present']||$state[$name]['sha256']!==$expected) $changed++;
    }
    return [$state,$changed];
}
function navigation_release_require_candidates($plugin) {
    foreach(PETISH_NAVIGATION_FILES as$name=>$expected) {
        navigation_release_path($plugin.'/'.$name);
        if(!hash_equals($expected,hash_file('sha256',$plugin.'/'.$name))) navigation_release_fail();
    }
}
function navigation_release_lock($base,$run,$acquire=false) {
    $lock=$base.'/deploy.lock';
    if($acquire) {
        if(file_exists($lock)||is_link($lock)||!mkdir($lock,0700)) navigation_release_fail();
        navigation_release_write($lock.'/owner',$run."\n");
    }
    navigation_release_path($lock,true); navigation_release_path($lock.'/owner');
    if((fileperms($lock)&07777)!==0700 || trim(file_get_contents($lock.'/owner'))!==$run) navigation_release_fail();
    return $lock;
}
function navigation_release_unlock($lock) {
    if(!unlink($lock.'/owner')||!rmdir($lock)) navigation_release_fail();
}
function navigation_release_journal($release,$run) {
    $journal=navigation_release_json($release.'/journal.json');
    if(($journal['run']??null)!==$run || !is_array($journal['files']??null)
        || array_keys($journal['files'])!==array_keys(PETISH_NAVIGATION_FILES)
        || !is_array($journal['protected']??null) || !is_array($journal['created_directories']??null)) navigation_release_fail();
    foreach($journal['files'] as$name=>$entry) {
        if(!is_array($entry)||array_keys($entry)!==['present','sha256','mode']||!is_bool($entry['present'])) navigation_release_fail();
        if($entry['present']) {
            $allowed=[PETISH_NAVIGATION_FILES[$name]];
            if(isset(PETISH_NAVIGATION_BASELINES[$name])) $allowed[]=PETISH_NAVIGATION_BASELINES[$name];
            if(!in_array($entry['sha256'],$allowed,true)||!is_int($entry['mode'])||$entry['mode']<0||$entry['mode']>0777) navigation_release_fail();
            $backup=$release.'/backup/'.array_search($name,array_keys(PETISH_NAVIGATION_FILES),true);
            navigation_release_path($backup);
            if(!hash_equals($entry['sha256'],hash_file('sha256',$backup))) navigation_release_fail();
        }elseif($entry['sha256']!==null||$entry['mode']!==null) navigation_release_fail();
    }
    if($journal['created_directories']!==[]) navigation_release_fail();
    return $journal;
}
function navigation_release_originals_verified($plugin,$root,$release,$run) {
    $journal=navigation_release_journal($release,$run);
    if(navigation_release_protected($root,$plugin)!==$journal['protected']) navigation_release_fail();
    foreach($journal['files'] as$name=>$entry) {
        $path=$plugin.'/'.$name;
        if($entry['present']) {
            navigation_release_path($path);
            if(hash_file('sha256',$path)!==$entry['sha256']||(fileperms($path)&0777)!==$entry['mode']) navigation_release_fail();
        }elseif(file_exists($path)||is_link($path)) navigation_release_fail();
    }
    foreach($journal['created_directories'] as$relative) if(file_exists($plugin.'/'.$relative)||is_link($plugin.'/'.$relative)) navigation_release_fail();
}
function navigation_release_rollback($plugin,$root,$release,$run) {
    $journal=navigation_release_journal($release,$run);
    if(navigation_release_protected($root,$plugin)!==$journal['protected']) navigation_release_fail();
    // First validate every destination, then restore the UI class before its assets.
    foreach($journal['files'] as$name=>$entry) {
        $path=$plugin.'/'.$name;
        if(file_exists($path)||is_link($path)) {
            navigation_release_path($path); $hash=hash_file('sha256',$path);
            if($hash!==PETISH_NAVIGATION_FILES[$name] && (!$entry['present']||$hash!==$entry['sha256'])) navigation_release_fail();
        }elseif($entry['present']) navigation_release_fail();
    }
    foreach(array_reverse(array_keys(PETISH_NAVIGATION_FILES)) as$name) {
        $path=$plugin.'/'.$name; $entry=$journal['files'][$name];
        if($entry['present']) {
            $backup=$release.'/backup/'.array_search($name,array_keys(PETISH_NAVIGATION_FILES),true);
            navigation_release_atomic($path,file_get_contents($backup),$entry['mode'],$run);
        }elseif(file_exists($path)&&!unlink($path)) navigation_release_fail();
    }
    foreach(array_reverse($journal['created_directories']) as$relative) {
        $directory=$plugin.'/'.$relative;
        if(is_dir($directory)) { navigation_release_path($directory,true); if(!rmdir($directory)) navigation_release_fail(); }
    }
    navigation_release_originals_verified($plugin,$root,$release,$run);
    if(!file_exists($release.'/rolled-back')) navigation_release_write($release.'/rolled-back',"verified\n");
}

$report=['status'=>'failure','category'=>'input','checks'=>array_fill_keys([
    'paths_verified','candidate_manifest_verified','candidate_syntax_verified',
    'production_config_present','live_files_compatible','protected_state_unchanged',
    'rollback_snapshot_created','files_deployed','rollback_completed','release_committed','lock_owned'],false),'changed_file_count'=>0];
$rollback_on_exit=false; $lock=null; $plugin=null; $root=null; $release=null; $run=null;
$finish=static function()use(&$report,&$rollback_on_exit,&$lock,&$plugin,&$root,&$release,&$run) {
    if($rollback_on_exit) {
        try {
            navigation_release_rollback($plugin,$root,$release,$run);
            $report['checks']['rollback_completed']=true;
            if($lock!==null) { navigation_release_unlock($lock); $lock=null; }
            $rollback_on_exit=false;
        }catch(Throwable $ignored) { $report['category']='rollback'; }
    }
};
register_shutdown_function(static function()use($finish,&$report) {
    $finish(); echo json_encode($report,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
});

try {
    if($argc!==4||!in_array($argv[1],['inspect','apply','rollback','commit'],true)||!preg_match('/\A[1-9][0-9]{0,31}-[1-9][0-9]{0,8}\z/D',$argv[2])) navigation_release_fail();
    [$script,$operation,$run,$payload]=$argv;
    $report['category']='paths';
    $home=getenv('HOME'); navigation_release_path($home,true);
    $root=$home.'/domains/petish.biz/public_html'; $plugin=$root.'/wp-content/plugins/petish-passport';
    navigation_release_path($root,true); navigation_release_path($plugin,true); navigation_release_path($root.'/wp-config.php');
    $report['checks']['paths_verified']=true; $report['checks']['production_config_present']=true;
    $report['category']='payload'; navigation_release_payload($payload);
    $report['checks']['candidate_manifest_verified']=true; $report['checks']['candidate_syntax_verified']=true;
    $report['category']='drift'; [$live,$changed]=navigation_release_live($plugin);
    $report['changed_file_count']=$changed; $report['checks']['live_files_compatible']=true;
    $base=$home.'/.petish-account-navigation-releases'; $release=$base.'/'.$run;
    if($operation==='inspect') { navigation_release_protected($root,$plugin); }
    elseif($operation==='apply') {
        $report['category']='lock';
        if(!file_exists($base)&&!is_link($base)&&!mkdir($base,0700)) navigation_release_fail();
        navigation_release_path($base,true); if((fileperms($base)&07777)!==0700) navigation_release_fail();
        $lock=navigation_release_lock($base,$run,true); $report['checks']['lock_owned']=true;
        $report['category']='snapshot';
        if(file_exists($release)||is_link($release)||!mkdir($release,0700)||!mkdir($release.'/backup',0700)) navigation_release_fail();
        $protected=navigation_release_protected($root,$plugin); $created=[];
        // Both directories belong to the existing active Passport plugin.
        foreach(['assets','includes'] as$relative) navigation_release_path($plugin.'/'.$relative,true);
        foreach($live as$name=>$entry) if($entry['present']) {
            navigation_release_write($release.'/backup/'.array_search($name,array_keys(PETISH_NAVIGATION_FILES),true),file_get_contents($plugin.'/'.$name));
        }
        $journal=['run'=>$run,'files'=>$live,'protected'=>$protected,'created_directories'=>$created];
        navigation_release_write($release.'/journal.json',json_encode($journal,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        navigation_release_journal($release,$run); $report['checks']['rollback_snapshot_created']=true;
        $rollback_on_exit=true;
        $report['category']='deployment';
        foreach($created as$relative) if(!mkdir($plugin.'/'.$relative,0755)) navigation_release_fail();
        foreach(PETISH_NAVIGATION_FILES as$name=>$hash) {
            if($live[$name]['present']&&$live[$name]['sha256']===$hash) continue;
            navigation_release_atomic($plugin.'/'.$name,file_get_contents($payload.'/'.$name),0644,$run);
        }
        navigation_release_require_candidates($plugin); $report['checks']['files_deployed']=true;
        if(navigation_release_protected($root,$plugin)!==$protected) navigation_release_fail();
        $report['checks']['protected_state_unchanged']=true;
        navigation_release_write($release.'/pending',"verified\n");
        $rollback_on_exit=false;
    }else {
        $report['category']='lock'; navigation_release_path($base,true); navigation_release_path($release,true);
        $report['category']=$operation;
        if(file_exists($release.'/committed')||is_link($release.'/committed')) navigation_release_fail();
        $already_rolled_back=$operation==='rollback'&&(file_exists($release.'/rolled-back')||is_link($release.'/rolled-back'));
        if(!$already_rolled_back || file_exists($base.'/deploy.lock') || is_link($base.'/deploy.lock')) {
            $report['category']='lock';
            $lock=navigation_release_lock($base,$run); $report['checks']['lock_owned']=true;
        }
        $report['category']=$operation;
        $journal=navigation_release_journal($release,$run); $report['checks']['rollback_snapshot_created']=true;
        if($operation==='rollback') {
            if($already_rolled_back) {
                navigation_release_path($release.'/rolled-back');
                if((fileperms($release.'/rolled-back')&07777)!==0600||file_get_contents($release.'/rolled-back')!=="verified\n") navigation_release_fail();
                navigation_release_originals_verified($plugin,$root,$release,$run);
            }else navigation_release_rollback($plugin,$root,$release,$run);
            $report['checks']['rollback_completed']=true; $report['checks']['protected_state_unchanged']=true;
        }else {
            navigation_release_path($release.'/pending'); navigation_release_require_candidates($plugin);
            if(navigation_release_protected($root,$plugin)!==$journal['protected']) navigation_release_fail();
            $report['checks']['protected_state_unchanged']=true; $report['checks']['files_deployed']=true;
            navigation_release_write($release.'/committed',"verified\n");
            $report['checks']['release_committed']=true;
        }
        if($lock!==null) { navigation_release_unlock($lock); $lock=null; }
    }
    $report['status']='success'; $report['category']='complete';
}catch(Throwable $ignored) {
    $finish();
    // If preparation failed before a complete snapshot, no live file changed.
    if(($operation??null)==='apply'&&!$rollback_on_exit&&$lock!==null&&!$report['checks']['rollback_snapshot_created']) {
        try { navigation_release_unlock($lock); $lock=null; }catch(Throwable $ignored_again) {}
    }
}
exit($report['status']==='success'?0:1);
