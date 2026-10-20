<?php
/** Narrow production Pet Club release. Standalone CLI; never boots WordPress. */
if (PHP_SAPI !== 'cli') exit(1);
ini_set('display_errors','0'); ini_set('log_errors','0'); error_reporting(0);

// Exact reviewed Pet Club files. All application hashes must be frozen before release.
const PETISH_PET_CLUB_FILES = [
    'includes/class-petish-passport-moments.php'=>'5c24f7f0d6c71df2c422f02eb6401fdca1365f2fbf99ac43f2e4124f2944881c',
    'includes/class-petish-passport-community.php'=>'2d87be101757ed29a28eec2bdf9a59c04ec3a14d5037870f4a585601839f4f34',
    'assets/pet-club.css'=>'6871a9d7d2a87f7bc47be1d179e2cdb816228f70249379ff2adbd5c3b364be0f',
    'assets/passport.js'=>'97e0a4ecaddfd555ca779edec6a01685da268e9ee39e8a4555908d9becc26f49',
    'templates/public.php'=>'e73b60eb9a86affa924376a71c0356760cf4610ee3526e985ea29442c8a61dc8',
    'templates/community.php'=>'6e8c5ede57400b14f282df0d3fac3785b92b3cc8fd7e11ad640d03345823a947',
    'templates/moment-form.php'=>'31028698f8698d4362434938ca8282a2aacfe5f7023047cd1fdcf9fcaece6d31',
    'includes/class-petish-passport-media.php'=>'f45b48ad7239ed484c24438394f96cefac533de600daa7ccbe98f8404e3b4176',
    'includes/class-petish-passport-store.php'=>'7b3b144f5e5ab983a471fb1945300dfdee7d354724ed5cd386a6fcd07fabad45',
    'includes/class-petish-passport-ui.php'=>'d8d9729a6aa64e937f075dbd655e6e037b0e76d4224004eb354660d9ff15d00c',
    'templates/dashboard.php'=>'72854c7e61fd1b8ddfba4717bfab31b220608432eac36f44a61bbdd15b3f7039',
    'templates/form.php'=>'70974cf0e56a383c49bcce39498e77a10900504f7840b68ef120a8497f5656a1',
    'templates/delete.php'=>'6067c76a295ff52d9f65d8f51a639a38af77d9ee532e91527c4fb1ad32eca1de',
    'petish-passport.php'=>'daedeb5dfd4b995641f11ad2386d95967bfefa1c0c2e3b0e3b2d16086e6dd56c',
];
const PETISH_PET_CLUB_BASELINES = [
    'assets/passport.js'=>'7deabb5cc00a265322b20ed6ff267125f8faa9bddb521a8a246c0109201b4cda',
    'includes/class-petish-passport-media.php'=>'675099fbdd2bb2e8899de9f9fc7f82ca1613c033e10a1b2023a8510ef38da782',
    'includes/class-petish-passport-store.php'=>'cf55dddcd3394783ca1b9bcccb25ae1682eea130c01139088a6b0a0b94955b46',
    'includes/class-petish-passport-ui.php'=>'d5abfc66c9735470b7ed7e3dae728221bb28c78636b06e4bba5efa56dbfe58d5',
    'templates/dashboard.php'=>'3bcd60132cbcad98accc599eaa9956b93a052fd4ecaef742bc504fa4ae77f34d',
    'templates/form.php'=>'2c07cf4eac8e9ca31c0c6a497ddfe5d498ec3c61a0ede3d757a81dcbb2cd2381',
    'templates/delete.php'=>'75653acccfa39af742480405b377ae7b4887643a27e369d93dba6069a8074c3a',
    'petish-passport.php'=>'e74a310a1fc9bfc8d56ae62de973a75eb8f728885d585b8ba78bca488bb4c8bb',
];

function pet_club_release_fail() { throw new RuntimeException(); }
function pet_club_release_path($path, $directory=false) {
    if (!is_string($path) || $path==='' || $path[0]!=='/' || strpos($path,"\0")!==false) pet_club_release_fail();
    $cursor='';
    foreach(explode('/',substr($path,1)) as $part) {
        if($part==='' || $part==='.' || $part==='..') pet_club_release_fail();
        $cursor.='/'.$part;
        if(is_link($cursor) || realpath($cursor)!==$cursor) pet_club_release_fail();
    }
    $info=lstat($path);
    if(!$info || ($directory ? !is_dir($path) : (!is_file($path) || $info['nlink']!==1))) pet_club_release_fail();
    return $path;
}
function pet_club_release_json($path) {
    pet_club_release_path($path);
    if((fileperms($path)&07777)!==0600 || filesize($path)>1048576) pet_club_release_fail();
    $value=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
    if(!is_array($value)) pet_club_release_fail();
    return $value;
}
function pet_club_release_write($path,$content,$mode=0600) {
    pet_club_release_path(dirname($path),true);
    if(file_exists($path) || is_link($path)) pet_club_release_fail();
    $handle=fopen($path,'xb'); if(!$handle) pet_club_release_fail();
    try {
        if(!chmod($path,$mode) || fwrite($handle,$content)!==strlen($content) || !fflush($handle)) pet_club_release_fail();
    } finally { fclose($handle); }
}
function pet_club_release_atomic($path,$content,$mode,$run) {
    pet_club_release_path(dirname($path),true);
    if(file_exists($path) || is_link($path)) pet_club_release_path($path);
    $temporary=dirname($path).'/.petish-pet-club-'.$run.'-'.bin2hex(random_bytes(8));
    try {
        pet_club_release_write($temporary,$content,$mode);
        if(!rename($temporary,$path)) pet_club_release_fail();
    } finally { if(is_file($temporary) && !is_link($temporary)) unlink($temporary); }
}
function pet_club_release_tree($root,$exclude=[]) {
    pet_club_release_path($root,true); $files=[];
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
    foreach($iterator as$file) {
        $path=$file->getPathname();
        if($file->isLink() || (!$file->isFile()&&!$file->isDir())) pet_club_release_fail();
        pet_club_release_path($path,$file->isDir());
        if($file->isFile()) {
            $relative=substr($path,strlen($root)+1);
            if(!in_array($relative,$exclude,true)) $files[$relative]=hash_file('sha256',$path);
            if(count($files)>25000) pet_club_release_fail();
        }
    }
    ksort($files); return $files;
}
function pet_club_release_protected($root,$plugin) {
    $state=['child_theme'=>pet_club_release_tree($root.'/wp-content/themes/dt-the7-child'),
        'passport'=>pet_club_release_tree($plugin,array_keys(PETISH_PET_CLUB_FILES)),
        'fulfilment'=>pet_club_release_tree($root.'/wp-content/plugins/petish-fulfilment'),
        'mu'=>pet_club_release_tree($root.'/wp-content/mu-plugins')];
    $private=dirname($root).'/private/petish-passport';
    $state['private_passport']=file_exists($private)||is_link($private)?pet_club_release_tree($private):null;
    foreach(['wp-config.php','.htaccess','.user.ini'] as$name) {
        $path=$root.'/'.$name;
        if(file_exists($path)||is_link($path)) { pet_club_release_path($path); $state[$name]=hash_file('sha256',$path); }
        elseif($name==='wp-config.php') pet_club_release_fail();
    }
    return $state;
}
function pet_club_release_payload($payload) {
    pet_club_release_path($payload,true);
    if((fileperms($payload)&07777)!==0700) pet_club_release_fail();
    $manifest=pet_club_release_json($payload.'/manifest.json');
    $expected=PETISH_PET_CLUB_FILES; ksort($expected); ksort($manifest);
    if($manifest!==$expected) pet_club_release_fail();
    $found=pet_club_release_tree($payload,['manifest.json']);
    if($found!==$expected) pet_club_release_fail();
    foreach(PETISH_PET_CLUB_FILES as$name=>$hash) if(pathinfo($name,PATHINFO_EXTENSION)==='php') token_get_all(file_get_contents($payload.'/'.$name),TOKEN_PARSE);

}
function pet_club_release_live($plugin) {
    $state=[]; $changed=0;
    foreach(PETISH_PET_CLUB_FILES as$name=>$expected) {
        $path=$plugin.'/'.$name;
        if(file_exists($path)||is_link($path)) {
            pet_club_release_path($path); $hash=hash_file('sha256',$path);
            if($hash!==$expected && $hash!==(PETISH_PET_CLUB_BASELINES[$name]??null)) pet_club_release_fail();
            $state[$name]=['present'=>true,'sha256'=>$hash,'mode'=>fileperms($path)&0777];
        } else {
            if(array_key_exists($name,PETISH_PET_CLUB_BASELINES)) pet_club_release_fail();
            // Existing ancestors must also be canonical, even for absent files.
            $parent=dirname($path);
            while(!file_exists($parent) && !is_link($parent)) $parent=dirname($parent);
            pet_club_release_path($parent,true);
            $state[$name]=['present'=>false,'sha256'=>null,'mode'=>null];
        }
        if(!$state[$name]['present']||$state[$name]['sha256']!==$expected) $changed++;
    }
    return [$state,$changed];
}
/** Refuse an edit made since the private snapshot was captured. */
function pet_club_release_preimage($path,$entry) {
    clearstatcache(true,$path);
    if($entry['present']) {
        pet_club_release_path($path);
        if(!hash_equals($entry['sha256'],hash_file('sha256',$path))
            || (fileperms($path)&0777)!==$entry['mode']) pet_club_release_fail();
    }elseif(file_exists($path)||is_link($path)) pet_club_release_fail();
}
/** A rollback may replace only this release's candidate or its exact preimage. */
function pet_club_release_rollback_destination($path,$name,$entry) {
    clearstatcache(true,$path);
    if(file_exists($path)||is_link($path)) {
        pet_club_release_path($path); $hash=hash_file('sha256',$path);
        if($hash!==PETISH_PET_CLUB_FILES[$name] && (!$entry['present']||$hash!==$entry['sha256'])) pet_club_release_fail();
    }elseif($entry['present']) pet_club_release_fail();
}
function pet_club_release_require_candidates($plugin) {
    foreach(PETISH_PET_CLUB_FILES as$name=>$expected) {
        pet_club_release_path($plugin.'/'.$name);
        if(!hash_equals($expected,hash_file('sha256',$plugin.'/'.$name))) pet_club_release_fail();
    }
}
function pet_club_release_lock($base,$run,$acquire=false) {
    $lock=$base.'/deploy.lock';
    if($acquire) {
        if(file_exists($lock)||is_link($lock)||!mkdir($lock,0700)) pet_club_release_fail();
        pet_club_release_write($lock.'/owner',$run."\n");
    }
    pet_club_release_path($lock,true); pet_club_release_path($lock.'/owner');
    if((fileperms($lock)&07777)!==0700 || trim(file_get_contents($lock.'/owner'))!==$run) pet_club_release_fail();
    return $lock;
}
function pet_club_release_unlock($lock) {
    if(!unlink($lock.'/owner')||!rmdir($lock)) pet_club_release_fail();
}
function pet_club_release_journal($release,$run) {
    $journal=pet_club_release_json($release.'/journal.json');
    if(($journal['run']??null)!==$run || !is_array($journal['files']??null)
        || array_keys($journal['files'])!==array_keys(PETISH_PET_CLUB_FILES)
        || !is_array($journal['protected']??null) || !is_array($journal['created_directories']??null)) pet_club_release_fail();
    foreach($journal['files'] as$name=>$entry) {
        if(!is_array($entry)||array_keys($entry)!==['present','sha256','mode']||!is_bool($entry['present'])) pet_club_release_fail();
        if($entry['present']) {
            $allowed=[PETISH_PET_CLUB_FILES[$name]];
            if(isset(PETISH_PET_CLUB_BASELINES[$name])) $allowed[]=PETISH_PET_CLUB_BASELINES[$name];
            if(!in_array($entry['sha256'],$allowed,true)||!is_int($entry['mode'])||$entry['mode']<0||$entry['mode']>0777) pet_club_release_fail();
            $backup=$release.'/backup/'.array_search($name,array_keys(PETISH_PET_CLUB_FILES),true);
            pet_club_release_path($backup);
            if(!hash_equals($entry['sha256'],hash_file('sha256',$backup))) pet_club_release_fail();
        }elseif($entry['sha256']!==null||$entry['mode']!==null) pet_club_release_fail();
    }
    if($journal['created_directories']!==[]) pet_club_release_fail();
    return $journal;
}
function pet_club_release_originals_verified($plugin,$root,$release,$run,$protected_expected=null) {
    $journal=pet_club_release_journal($release,$run);
    $current=pet_club_release_protected($root,$plugin);
    if($protected_expected===null) {
        $protected_expected=$journal['protected'];
        $protected_expected['private_passport']=$current['private_passport'];
    }
    if($current!==$protected_expected) pet_club_release_fail();
    foreach($journal['files'] as$name=>$entry) {
        $path=$plugin.'/'.$name;
        if($entry['present']) {
            pet_club_release_path($path);
            if(hash_file('sha256',$path)!==$entry['sha256']||(fileperms($path)&0777)!==$entry['mode']) pet_club_release_fail();
        }elseif(file_exists($path)||is_link($path)) pet_club_release_fail();
    }
    foreach($journal['created_directories'] as$relative) if(file_exists($plugin.'/'.$relative)||is_link($plugin.'/'.$relative)) pet_club_release_fail();
}
/** A short owned WordPress maintenance guard hides mixed application versions. */
function pet_club_release_maintenance($root,$run,$release,$remove=false,$adopt=false) {
    static $owned=[];
    $path=$root.'/.maintenance'; $receipt=$release.'/maintenance.json';
    pet_club_release_path($root,true); pet_club_release_path($release,true);
    $record=null;
    if(file_exists($receipt)||is_link($receipt)) {
        $record=pet_club_release_json($receipt);
        if(array_keys($record)!==['run','sha256']||$record['run']!==$run
            ||!is_string($record['sha256'])||!preg_match('/\A[a-f0-9]{64}\z/D',$record['sha256'])) pet_club_release_fail();
    }
    if($remove) {
        if(!isset($owned[$path])||$record===null||!hash_equals($owned[$path],$record['sha256'])) pet_club_release_fail();
        pet_club_release_path($path);
        if((fileperms($path)&07777)!==0600 || !hash_equals($owned[$path],hash_file('sha256',$path))) pet_club_release_fail();
        if(!unlink($path)) pet_club_release_fail();
        unset($owned[$path]);
        return;
    }
    if(file_exists($path)||is_link($path)) {
        if(!$adopt||$record===null) pet_club_release_fail();
        pet_club_release_path($path);
        if((fileperms($path)&07777)!==0600||!hash_equals($record['sha256'],hash_file('sha256',$path))) pet_club_release_fail();
        $owned[$path]=$record['sha256'];
        return;
    }
    $content="<?php /* Petish Pet Club release ".$run." */\n\$upgrading = time();\n";
    $digest=hash('sha256',$content);
    $raw=json_encode(['run'=>$run,'sha256'=>$digest],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    if($record===null) pet_club_release_write($receipt,$raw);
    else pet_club_release_atomic($receipt,$raw,0600,$run);
    // Persist proof before creation so a later same-run rollback can recover it.
    pet_club_release_write($path,$content); $owned[$path]=$digest;
}
function pet_club_release_coherent($plugin,$release,$run) {
    try { pet_club_release_require_candidates($plugin); return true; }catch(Throwable $ignored) {}
    try {
        $journal=pet_club_release_journal($release,$run);
        foreach($journal['files']as$name=>$entry) pet_club_release_preimage($plugin.'/'.$name,$entry);
        return true;
    }catch(Throwable $ignored) { return false; }
}

function pet_club_release_rollback($plugin,$root,$release,$run) {
    $journal=pet_club_release_journal($release,$run);
    $protected=pet_club_release_protected($root,$plugin);
    $expected=$journal['protected']; $expected['private_passport']=$protected['private_passport'];
    if($protected!==$expected) pet_club_release_fail();
    // Private photos may have changed legitimately; this transaction never writes them.
    // Validate every destination, then restore the bootstrap before removing new helper classes.
    foreach($journal['files'] as$name=>$entry) {
        $path=$plugin.'/'.$name;
        pet_club_release_rollback_destination($path,$name,$entry);
    }
    foreach(array_reverse(array_keys(PETISH_PET_CLUB_FILES)) as$name) {
        $path=$plugin.'/'.$name; $entry=$journal['files'][$name];
        pet_club_release_rollback_destination($path,$name,$entry);
        if($entry['present']) {
            $backup=$release.'/backup/'.array_search($name,array_keys(PETISH_PET_CLUB_FILES),true);
            pet_club_release_atomic($path,file_get_contents($backup),$entry['mode'],$run);
        }elseif(file_exists($path)&&!unlink($path)) pet_club_release_fail();
    }
    foreach(array_reverse($journal['created_directories']) as$relative) {
        $directory=$plugin.'/'.$relative;
        if(is_dir($directory)) { pet_club_release_path($directory,true); if(!rmdir($directory)) pet_club_release_fail(); }
    }
    pet_club_release_originals_verified($plugin,$root,$release,$run,$protected);
    if(!file_exists($release.'/rolled-back')) pet_club_release_write($release.'/rolled-back',"verified\n");
}

$report=['status'=>'failure','category'=>'input','checks'=>array_fill_keys([
    'paths_verified','candidate_manifest_verified','candidate_syntax_verified',
    'production_config_present','live_files_compatible','protected_state_unchanged',
    'rollback_snapshot_created','files_deployed','rollback_completed','release_committed','lock_owned',
    'maintenance_guard_created','maintenance_guard_removed'],false),'changed_file_count'=>0];
$rollback_on_exit=false; $live_mutation_started=false; $maintenance_owned=false; $lock=null; $plugin=null; $root=null; $release=null; $run=null;
$finish=static function()use(&$report,&$rollback_on_exit,&$maintenance_owned,&$lock,&$plugin,&$root,&$release,&$run) {
    if($rollback_on_exit) {
        try {
            pet_club_release_rollback($plugin,$root,$release,$run);
            $report['checks']['rollback_completed']=true;
            if($lock!==null) { pet_club_release_unlock($lock); $lock=null; }
            $rollback_on_exit=false;
        }catch(Throwable $ignored) { $report['category']='rollback'; }
    }
    if($maintenance_owned && (!$rollback_on_exit || pet_club_release_coherent($plugin,$release,$run))) {
        try { pet_club_release_maintenance($root,$run,$release,true); $maintenance_owned=false; $report['checks']['maintenance_guard_removed']=true; }
        catch(Throwable $ignored) { $report['status']='failure'; $report['category']='maintenance'; }
    }
};
register_shutdown_function(static function()use($finish,&$report) {
    $finish(); echo json_encode($report,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
});

try {
    if($argc!==4||!in_array($argv[1],['inspect','apply','rollback','commit'],true)||!preg_match('/\A[1-9][0-9]{0,31}-[1-9][0-9]{0,8}\z/D',$argv[2])) pet_club_release_fail();
    [$script,$operation,$run,$payload]=$argv;
    $report['category']='paths';
    $home=getenv('HOME'); pet_club_release_path($home,true);
    $root=$home.'/domains/petish.biz/public_html'; $plugin=$root.'/wp-content/plugins/petish-passport';
    pet_club_release_path($root,true); pet_club_release_path($plugin,true); pet_club_release_path($root.'/wp-config.php');
    $report['checks']['paths_verified']=true; $report['checks']['production_config_present']=true;
    $report['category']='payload'; pet_club_release_payload($payload);
    $report['checks']['candidate_manifest_verified']=true; $report['checks']['candidate_syntax_verified']=true;
    $report['category']='drift'; [$live,$changed]=pet_club_release_live($plugin);
    $report['changed_file_count']=$changed; $report['checks']['live_files_compatible']=true;
    $base=$home.'/.petish-pet-club-releases'; $release=$base.'/'.$run;
    if($operation==='inspect') { pet_club_release_protected($root,$plugin); }
    elseif($operation==='apply') {
        $report['category']='maintenance';
        if(file_exists($root.'/.maintenance')||is_link($root.'/.maintenance')) pet_club_release_fail();
        $report['category']='lock';
        if(!file_exists($base)&&!is_link($base)&&!mkdir($base,0700)) pet_club_release_fail();
        pet_club_release_path($base,true); if((fileperms($base)&07777)!==0700) pet_club_release_fail();
        $lock=pet_club_release_lock($base,$run,true); $report['checks']['lock_owned']=true;
        $report['category']='snapshot';
        if(file_exists($release)||is_link($release)||!mkdir($release,0700)||!mkdir($release.'/backup',0700)) pet_club_release_fail();
        $protected=pet_club_release_protected($root,$plugin); $created=[];
        // All destination directories belong to the existing Passport plugin.
        foreach(['includes','templates','assets'] as$relative) pet_club_release_path($plugin.'/'.$relative,true);
        foreach($live as$name=>$entry) if($entry['present']) {
            pet_club_release_write($release.'/backup/'.array_search($name,array_keys(PETISH_PET_CLUB_FILES),true),file_get_contents($plugin.'/'.$name));
        }
        $journal=['run'=>$run,'files'=>$live,'protected'=>$protected,'created_directories'=>$created];
        pet_club_release_write($release.'/journal.json',json_encode($journal,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        pet_club_release_journal($release,$run); $report['checks']['rollback_snapshot_created']=true;
        $report['category']='maintenance';
        pet_club_release_maintenance($root,$run,$release); $maintenance_owned=true; $report['checks']['maintenance_guard_created']=true;
        $report['category']='deployment';
        foreach($live as$name=>$entry) pet_club_release_preimage($plugin.'/'.$name,$entry);
        if(pet_club_release_protected($root,$plugin)!==$protected) pet_club_release_fail();
        $rollback_on_exit=true;
        foreach($created as$relative) if(!mkdir($plugin.'/'.$relative,0755)) pet_club_release_fail();
        foreach(PETISH_PET_CLUB_FILES as$name=>$hash) {
            pet_club_release_preimage($plugin.'/'.$name,$live[$name]);
            if($live[$name]['present']&&$live[$name]['sha256']===$hash) continue;
            $live_mutation_started=true;
            pet_club_release_atomic($plugin.'/'.$name,file_get_contents($payload.'/'.$name),0644,$run);
        }
        pet_club_release_require_candidates($plugin); $report['checks']['files_deployed']=true;
        if(pet_club_release_protected($root,$plugin)!==$protected) pet_club_release_fail();
        $report['checks']['protected_state_unchanged']=true;
        pet_club_release_write($release.'/pending',"verified\n");
        pet_club_release_maintenance($root,$run,$release,true); $maintenance_owned=false; $report['checks']['maintenance_guard_removed']=true;
        $rollback_on_exit=false;
    }else {
        $report['category']='lock'; pet_club_release_path($base,true); pet_club_release_path($release,true);
        $report['category']=$operation;
        if(file_exists($release.'/committed')||is_link($release.'/committed')) pet_club_release_fail();
        $already_rolled_back=$operation==='rollback'&&(file_exists($release.'/rolled-back')||is_link($release.'/rolled-back'));
        if(!$already_rolled_back || file_exists($base.'/deploy.lock') || is_link($base.'/deploy.lock')) {
            $report['category']='lock';
            $lock=pet_club_release_lock($base,$run); $report['checks']['lock_owned']=true;
        }
        $report['category']=$operation;
        $journal=pet_club_release_journal($release,$run); $report['checks']['rollback_snapshot_created']=true;
        if($operation==='rollback') {
            if($already_rolled_back) {
                pet_club_release_path($release.'/rolled-back');
                if((fileperms($release.'/rolled-back')&07777)!==0600||file_get_contents($release.'/rolled-back')!=="verified\n") pet_club_release_fail();
                pet_club_release_originals_verified($plugin,$root,$release,$run);
                if(file_exists($root.'/.maintenance')||is_link($root.'/.maintenance')) {
                    pet_club_release_maintenance($root,$run,$release,false,true); $maintenance_owned=true;
                    $report['checks']['maintenance_guard_created']=true;
                    pet_club_release_maintenance($root,$run,$release,true); $maintenance_owned=false;
                }
                $report['checks']['maintenance_guard_removed']=true;
            }else {
                $report['category']='maintenance';
                pet_club_release_maintenance($root,$run,$release,false,true); $maintenance_owned=true; $report['checks']['maintenance_guard_created']=true;
                $report['category']='rollback'; $rollback_on_exit=true; pet_club_release_rollback($plugin,$root,$release,$run);
                $rollback_on_exit=false;
                pet_club_release_maintenance($root,$run,$release,true); $maintenance_owned=false; $report['checks']['maintenance_guard_removed']=true;
            }
            $report['checks']['rollback_completed']=true; $report['checks']['protected_state_unchanged']=true;
        }else {
            if(file_exists($root.'/.maintenance')||is_link($root.'/.maintenance')) pet_club_release_fail();
            $report['checks']['maintenance_guard_removed']=true;
            pet_club_release_path($release.'/pending'); pet_club_release_require_candidates($plugin);
            if(pet_club_release_protected($root,$plugin)!==$journal['protected']) pet_club_release_fail();
            $report['checks']['protected_state_unchanged']=true; $report['checks']['files_deployed']=true;
            pet_club_release_write($release.'/committed',"verified\n");
            $report['checks']['release_committed']=true;
        }
        if($lock!==null) { pet_club_release_unlock($lock); $lock=null; }
    }
    $report['status']='success'; $report['category']='complete';
}catch(Throwable $ignored) {
    $finish();
    // An aborted preparation or preimage check changed no live application file.
    if(($operation??null)==='apply'&&!$rollback_on_exit&&$lock!==null&&!$live_mutation_started) {
        try { pet_club_release_unlock($lock); $lock=null; }catch(Throwable $ignored_again) {}
    }
}
exit($report['status']==='success'?0:1);
