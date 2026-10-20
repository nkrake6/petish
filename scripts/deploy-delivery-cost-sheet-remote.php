<?php
/** Narrow production delivery cost-sheet release. Standalone CLI; never boots WordPress. */
if (PHP_SAPI !== 'cli') exit(1);
ini_set('display_errors','0'); ini_set('log_errors','0'); error_reporting(0);

// Exact reviewed cost-sheet files. The two helpers precede the bootstrap switch.
const PETISH_DELIVERY_COST_SHEET_FILES = [
    'includes/class-petish-delivery-cost-sheet.php'=>'f994698c143841cc7cf0a741e46d13cd5c7194ca1370e6eafe16f20b084f4d0d',
    'includes/class-petish-delivery-cost-sheet-admin.php'=>'4abd44f9544ce6f911af4cf33ba0197029d1f420d00595260abde8044742983e',
    'petish-fulfilment.php'=>'3506e5e4e1afa802ccda41a17a9ddaae61f3a0a715b53fc9097fde374c8133d0',
];
const PETISH_DELIVERY_COST_SHEET_BASELINES = [
    'petish-fulfilment.php'=>'45a68476d064eb51ff6d1329d305f7c1e8823f9b57cccf672c4e8017270d30c4',
];

function delivery_cost_sheet_release_fail() { throw new RuntimeException(); }
function delivery_cost_sheet_release_path($path, $directory=false) {
    if (!is_string($path) || $path==='' || $path[0]!=='/' || strpos($path,"\0")!==false) delivery_cost_sheet_release_fail();
    $cursor='';
    foreach(explode('/',substr($path,1)) as $part) {
        if($part==='' || $part==='.' || $part==='..') delivery_cost_sheet_release_fail();
        $cursor.='/'.$part;
        if(is_link($cursor) || realpath($cursor)!==$cursor) delivery_cost_sheet_release_fail();
    }
    $info=lstat($path);
    if(!$info || ($directory ? !is_dir($path) : (!is_file($path) || $info['nlink']!==1))) delivery_cost_sheet_release_fail();
    return $path;
}
function delivery_cost_sheet_release_json($path) {
    delivery_cost_sheet_release_path($path);
    if((fileperms($path)&07777)!==0600 || filesize($path)>1048576) delivery_cost_sheet_release_fail();
    $value=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
    if(!is_array($value)) delivery_cost_sheet_release_fail();
    return $value;
}
function delivery_cost_sheet_release_write($path,$content,$mode=0600) {
    delivery_cost_sheet_release_path(dirname($path),true);
    if(file_exists($path) || is_link($path)) delivery_cost_sheet_release_fail();
    $handle=fopen($path,'xb'); if(!$handle) delivery_cost_sheet_release_fail();
    try {
        if(!chmod($path,$mode) || fwrite($handle,$content)!==strlen($content) || !fflush($handle)) delivery_cost_sheet_release_fail();
    } finally { fclose($handle); }
}
function delivery_cost_sheet_release_atomic($path,$content,$mode,$run) {
    delivery_cost_sheet_release_path(dirname($path),true);
    if(file_exists($path) || is_link($path)) delivery_cost_sheet_release_path($path);
    $temporary=dirname($path).'/.petish-delivery-cost-sheet-'.$run.'-'.bin2hex(random_bytes(8));
    try {
        delivery_cost_sheet_release_write($temporary,$content,$mode);
        if(!rename($temporary,$path)) delivery_cost_sheet_release_fail();
    } finally { if(is_file($temporary) && !is_link($temporary)) unlink($temporary); }
}
function delivery_cost_sheet_release_tree($root,$exclude=[]) {
    delivery_cost_sheet_release_path($root,true); $files=[];
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
    foreach($iterator as$file) {
        $path=$file->getPathname();
        if($file->isLink() || (!$file->isFile()&&!$file->isDir())) delivery_cost_sheet_release_fail();
        delivery_cost_sheet_release_path($path,$file->isDir());
        if($file->isFile()) {
            $relative=substr($path,strlen($root)+1);
            if(!in_array($relative,$exclude,true)) $files[$relative]=hash_file('sha256',$path);
            if(count($files)>25000) delivery_cost_sheet_release_fail();
        }
    }
    ksort($files); return $files;
}
function delivery_cost_sheet_release_protected($root,$plugin) {
    $state=['child_theme'=>delivery_cost_sheet_release_tree($root.'/wp-content/themes/dt-the7-child'),
        'passport'=>delivery_cost_sheet_release_tree($root.'/wp-content/plugins/petish-passport'),
        'fulfilment'=>delivery_cost_sheet_release_tree($plugin,array_keys(PETISH_DELIVERY_COST_SHEET_FILES)),
        'mu'=>delivery_cost_sheet_release_tree($root.'/wp-content/mu-plugins')];
    foreach(['wp-config.php','.htaccess','.user.ini'] as$name) {
        $path=$root.'/'.$name;
        if(file_exists($path)||is_link($path)) { delivery_cost_sheet_release_path($path); $state[$name]=hash_file('sha256',$path); }
        elseif($name==='wp-config.php') delivery_cost_sheet_release_fail();
    }
    return $state;
}
function delivery_cost_sheet_release_payload($payload) {
    delivery_cost_sheet_release_path($payload,true);
    if((fileperms($payload)&07777)!==0700) delivery_cost_sheet_release_fail();
    $manifest=delivery_cost_sheet_release_json($payload.'/manifest.json');
    $expected=PETISH_DELIVERY_COST_SHEET_FILES; ksort($expected); ksort($manifest);
    if($manifest!==$expected) delivery_cost_sheet_release_fail();
    $found=delivery_cost_sheet_release_tree($payload,['manifest.json']);
    if($found!==$expected) delivery_cost_sheet_release_fail();
    foreach(PETISH_DELIVERY_COST_SHEET_FILES as$name=>$hash) if(pathinfo($name,PATHINFO_EXTENSION)==='php') token_get_all(file_get_contents($payload.'/'.$name),TOKEN_PARSE);

}
function delivery_cost_sheet_release_live($plugin) {
    $state=[]; $changed=0;
    foreach(PETISH_DELIVERY_COST_SHEET_FILES as$name=>$expected) {
        $path=$plugin.'/'.$name;
        if(file_exists($path)||is_link($path)) {
            delivery_cost_sheet_release_path($path); $hash=hash_file('sha256',$path);
            if($hash!==$expected && $hash!==(PETISH_DELIVERY_COST_SHEET_BASELINES[$name]??null)) delivery_cost_sheet_release_fail();
            $state[$name]=['present'=>true,'sha256'=>$hash,'mode'=>fileperms($path)&0777];
        } else {
            if(array_key_exists($name,PETISH_DELIVERY_COST_SHEET_BASELINES)) delivery_cost_sheet_release_fail();
            // Existing ancestors must also be canonical, even for absent files.
            $parent=dirname($path);
            while(!file_exists($parent) && !is_link($parent)) $parent=dirname($parent);
            delivery_cost_sheet_release_path($parent,true);
            $state[$name]=['present'=>false,'sha256'=>null,'mode'=>null];
        }
        if(!$state[$name]['present']||$state[$name]['sha256']!==$expected) $changed++;
    }
    return [$state,$changed];
}
function delivery_cost_sheet_release_require_candidates($plugin) {
    foreach(PETISH_DELIVERY_COST_SHEET_FILES as$name=>$expected) {
        delivery_cost_sheet_release_path($plugin.'/'.$name);
        if(!hash_equals($expected,hash_file('sha256',$plugin.'/'.$name))) delivery_cost_sheet_release_fail();
    }
}
function delivery_cost_sheet_release_lock($base,$run,$acquire=false) {
    $lock=$base.'/deploy.lock';
    if($acquire) {
        if(file_exists($lock)||is_link($lock)||!mkdir($lock,0700)) delivery_cost_sheet_release_fail();
        delivery_cost_sheet_release_write($lock.'/owner',$run."\n");
    }
    delivery_cost_sheet_release_path($lock,true); delivery_cost_sheet_release_path($lock.'/owner');
    if((fileperms($lock)&07777)!==0700 || trim(file_get_contents($lock.'/owner'))!==$run) delivery_cost_sheet_release_fail();
    return $lock;
}
function delivery_cost_sheet_release_unlock($lock) {
    if(!unlink($lock.'/owner')||!rmdir($lock)) delivery_cost_sheet_release_fail();
}
function delivery_cost_sheet_release_journal($release,$run) {
    $journal=delivery_cost_sheet_release_json($release.'/journal.json');
    if(($journal['run']??null)!==$run || !is_array($journal['files']??null)
        || array_keys($journal['files'])!==array_keys(PETISH_DELIVERY_COST_SHEET_FILES)
        || !is_array($journal['protected']??null) || !is_array($journal['created_directories']??null)) delivery_cost_sheet_release_fail();
    foreach($journal['files'] as$name=>$entry) {
        if(!is_array($entry)||array_keys($entry)!==['present','sha256','mode']||!is_bool($entry['present'])) delivery_cost_sheet_release_fail();
        if($entry['present']) {
            $allowed=[PETISH_DELIVERY_COST_SHEET_FILES[$name]];
            if(isset(PETISH_DELIVERY_COST_SHEET_BASELINES[$name])) $allowed[]=PETISH_DELIVERY_COST_SHEET_BASELINES[$name];
            if(!in_array($entry['sha256'],$allowed,true)||!is_int($entry['mode'])||$entry['mode']<0||$entry['mode']>0777) delivery_cost_sheet_release_fail();
            $backup=$release.'/backup/'.array_search($name,array_keys(PETISH_DELIVERY_COST_SHEET_FILES),true);
            delivery_cost_sheet_release_path($backup);
            if(!hash_equals($entry['sha256'],hash_file('sha256',$backup))) delivery_cost_sheet_release_fail();
        }elseif($entry['sha256']!==null||$entry['mode']!==null) delivery_cost_sheet_release_fail();
    }
    if($journal['created_directories']!==[]) delivery_cost_sheet_release_fail();
    return $journal;
}
function delivery_cost_sheet_release_originals_verified($plugin,$root,$release,$run) {
    $journal=delivery_cost_sheet_release_journal($release,$run);
    if(delivery_cost_sheet_release_protected($root,$plugin)!==$journal['protected']) delivery_cost_sheet_release_fail();
    foreach($journal['files'] as$name=>$entry) {
        $path=$plugin.'/'.$name;
        if($entry['present']) {
            delivery_cost_sheet_release_path($path);
            if(hash_file('sha256',$path)!==$entry['sha256']||(fileperms($path)&0777)!==$entry['mode']) delivery_cost_sheet_release_fail();
        }elseif(file_exists($path)||is_link($path)) delivery_cost_sheet_release_fail();
    }
    foreach($journal['created_directories'] as$relative) if(file_exists($plugin.'/'.$relative)||is_link($plugin.'/'.$relative)) delivery_cost_sheet_release_fail();
}
function delivery_cost_sheet_release_rollback($plugin,$root,$release,$run) {
    $journal=delivery_cost_sheet_release_journal($release,$run);
    if(delivery_cost_sheet_release_protected($root,$plugin)!==$journal['protected']) delivery_cost_sheet_release_fail();
    // Validate every destination, then restore the bootstrap before removing new helper classes.
    foreach($journal['files'] as$name=>$entry) {
        $path=$plugin.'/'.$name;
        if(file_exists($path)||is_link($path)) {
            delivery_cost_sheet_release_path($path); $hash=hash_file('sha256',$path);
            if($hash!==PETISH_DELIVERY_COST_SHEET_FILES[$name] && (!$entry['present']||$hash!==$entry['sha256'])) delivery_cost_sheet_release_fail();
        }elseif($entry['present']) delivery_cost_sheet_release_fail();
    }
    foreach(array_reverse(array_keys(PETISH_DELIVERY_COST_SHEET_FILES)) as$name) {
        $path=$plugin.'/'.$name; $entry=$journal['files'][$name];
        if($entry['present']) {
            $backup=$release.'/backup/'.array_search($name,array_keys(PETISH_DELIVERY_COST_SHEET_FILES),true);
            delivery_cost_sheet_release_atomic($path,file_get_contents($backup),$entry['mode'],$run);
        }elseif(file_exists($path)&&!unlink($path)) delivery_cost_sheet_release_fail();
    }
    foreach(array_reverse($journal['created_directories']) as$relative) {
        $directory=$plugin.'/'.$relative;
        if(is_dir($directory)) { delivery_cost_sheet_release_path($directory,true); if(!rmdir($directory)) delivery_cost_sheet_release_fail(); }
    }
    delivery_cost_sheet_release_originals_verified($plugin,$root,$release,$run);
    if(!file_exists($release.'/rolled-back')) delivery_cost_sheet_release_write($release.'/rolled-back',"verified\n");
}

$report=['status'=>'failure','category'=>'input','checks'=>array_fill_keys([
    'paths_verified','candidate_manifest_verified','candidate_syntax_verified',
    'production_config_present','live_files_compatible','protected_state_unchanged',
    'rollback_snapshot_created','files_deployed','rollback_completed','release_committed','lock_owned'],false),'changed_file_count'=>0];
$rollback_on_exit=false; $lock=null; $plugin=null; $root=null; $release=null; $run=null;
$finish=static function()use(&$report,&$rollback_on_exit,&$lock,&$plugin,&$root,&$release,&$run) {
    if($rollback_on_exit) {
        try {
            delivery_cost_sheet_release_rollback($plugin,$root,$release,$run);
            $report['checks']['rollback_completed']=true;
            if($lock!==null) { delivery_cost_sheet_release_unlock($lock); $lock=null; }
            $rollback_on_exit=false;
        }catch(Throwable $ignored) { $report['category']='rollback'; }
    }
};
register_shutdown_function(static function()use($finish,&$report) {
    $finish(); echo json_encode($report,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
});

try {
    if($argc!==4||!in_array($argv[1],['inspect','apply','rollback','commit'],true)||!preg_match('/\A[1-9][0-9]{0,31}-[1-9][0-9]{0,8}\z/D',$argv[2])) delivery_cost_sheet_release_fail();
    [$script,$operation,$run,$payload]=$argv;
    $report['category']='paths';
    $home=getenv('HOME'); delivery_cost_sheet_release_path($home,true);
    $root=$home.'/domains/petish.biz/public_html'; $plugin=$root.'/wp-content/plugins/petish-fulfilment';
    delivery_cost_sheet_release_path($root,true); delivery_cost_sheet_release_path($plugin,true); delivery_cost_sheet_release_path($root.'/wp-config.php');
    $report['checks']['paths_verified']=true; $report['checks']['production_config_present']=true;
    $report['category']='payload'; delivery_cost_sheet_release_payload($payload);
    $report['checks']['candidate_manifest_verified']=true; $report['checks']['candidate_syntax_verified']=true;
    $report['category']='drift'; [$live,$changed]=delivery_cost_sheet_release_live($plugin);
    $report['changed_file_count']=$changed; $report['checks']['live_files_compatible']=true;
    $base=$home.'/.petish-delivery-cost-sheet-releases'; $release=$base.'/'.$run;
    if($operation==='inspect') { delivery_cost_sheet_release_protected($root,$plugin); }
    elseif($operation==='apply') {
        $report['category']='lock';
        if(!file_exists($base)&&!is_link($base)&&!mkdir($base,0700)) delivery_cost_sheet_release_fail();
        delivery_cost_sheet_release_path($base,true); if((fileperms($base)&07777)!==0700) delivery_cost_sheet_release_fail();
        $lock=delivery_cost_sheet_release_lock($base,$run,true); $report['checks']['lock_owned']=true;
        $report['category']='snapshot';
        if(file_exists($release)||is_link($release)||!mkdir($release,0700)||!mkdir($release.'/backup',0700)) delivery_cost_sheet_release_fail();
        $protected=delivery_cost_sheet_release_protected($root,$plugin); $created=[];
        // The helper directory already belongs to the existing Fulfilment plugin.
        foreach(['includes'] as$relative) delivery_cost_sheet_release_path($plugin.'/'.$relative,true);
        foreach($live as$name=>$entry) if($entry['present']) {
            delivery_cost_sheet_release_write($release.'/backup/'.array_search($name,array_keys(PETISH_DELIVERY_COST_SHEET_FILES),true),file_get_contents($plugin.'/'.$name));
        }
        $journal=['run'=>$run,'files'=>$live,'protected'=>$protected,'created_directories'=>$created];
        delivery_cost_sheet_release_write($release.'/journal.json',json_encode($journal,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        delivery_cost_sheet_release_journal($release,$run); $report['checks']['rollback_snapshot_created']=true;
        $rollback_on_exit=true;
        $report['category']='deployment';
        foreach($created as$relative) if(!mkdir($plugin.'/'.$relative,0755)) delivery_cost_sheet_release_fail();
        foreach(PETISH_DELIVERY_COST_SHEET_FILES as$name=>$hash) {
            if($live[$name]['present']&&$live[$name]['sha256']===$hash) continue;
            delivery_cost_sheet_release_atomic($plugin.'/'.$name,file_get_contents($payload.'/'.$name),0644,$run);
        }
        delivery_cost_sheet_release_require_candidates($plugin); $report['checks']['files_deployed']=true;
        if(delivery_cost_sheet_release_protected($root,$plugin)!==$protected) delivery_cost_sheet_release_fail();
        $report['checks']['protected_state_unchanged']=true;
        delivery_cost_sheet_release_write($release.'/pending',"verified\n");
        $rollback_on_exit=false;
    }else {
        $report['category']='lock'; delivery_cost_sheet_release_path($base,true); delivery_cost_sheet_release_path($release,true);
        $report['category']=$operation;
        if(file_exists($release.'/committed')||is_link($release.'/committed')) delivery_cost_sheet_release_fail();
        $already_rolled_back=$operation==='rollback'&&(file_exists($release.'/rolled-back')||is_link($release.'/rolled-back'));
        if(!$already_rolled_back || file_exists($base.'/deploy.lock') || is_link($base.'/deploy.lock')) {
            $report['category']='lock';
            $lock=delivery_cost_sheet_release_lock($base,$run); $report['checks']['lock_owned']=true;
        }
        $report['category']=$operation;
        $journal=delivery_cost_sheet_release_journal($release,$run); $report['checks']['rollback_snapshot_created']=true;
        if($operation==='rollback') {
            if($already_rolled_back) {
                delivery_cost_sheet_release_path($release.'/rolled-back');
                if((fileperms($release.'/rolled-back')&07777)!==0600||file_get_contents($release.'/rolled-back')!=="verified\n") delivery_cost_sheet_release_fail();
                delivery_cost_sheet_release_originals_verified($plugin,$root,$release,$run);
            }else delivery_cost_sheet_release_rollback($plugin,$root,$release,$run);
            $report['checks']['rollback_completed']=true; $report['checks']['protected_state_unchanged']=true;
        }else {
            delivery_cost_sheet_release_path($release.'/pending'); delivery_cost_sheet_release_require_candidates($plugin);
            if(delivery_cost_sheet_release_protected($root,$plugin)!==$journal['protected']) delivery_cost_sheet_release_fail();
            $report['checks']['protected_state_unchanged']=true; $report['checks']['files_deployed']=true;
            delivery_cost_sheet_release_write($release.'/committed',"verified\n");
            $report['checks']['release_committed']=true;
        }
        if($lock!==null) { delivery_cost_sheet_release_unlock($lock); $lock=null; }
    }
    $report['status']='success'; $report['category']='complete';
}catch(Throwable $ignored) {
    $finish();
    // If preparation failed before a complete snapshot, no live file changed.
    if(($operation??null)==='apply'&&!$rollback_on_exit&&$lock!==null&&!$report['checks']['rollback_snapshot_created']) {
        try { delivery_cost_sheet_release_unlock($lock); $lock=null; }catch(Throwable $ignored_again) {}
    }
}
exit($report['status']==='success'?0:1);
