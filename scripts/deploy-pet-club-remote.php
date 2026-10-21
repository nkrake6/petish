<?php
/** Narrow production Pet Club release. Standalone CLI; never boots WordPress. */
if (PHP_SAPI !== 'cli') exit(1);
ini_set('display_errors','0'); ini_set('log_errors','0'); error_reporting(0);

// Exact reviewed Pet Club files. All application hashes must be frozen before release.
const PETISH_PET_CLUB_FILES = [
    'includes/class-petish-passport-video-inspector.php'=>'b5a5a585a0bd2925614ff324af63c0d20ceca4daf8fa2aaff37b7bc038919cab',
    'includes/class-petish-passport-video.php'=>'da5ea805f7956d201985fe5ae4892ba4ef2aa4c857f43e98c5e63b29ac4d5f78',
    'includes/class-petish-passport-moments.php'=>'02964cd3f2a2f56cc317888d8c238da1ce40559e4e36fbce40605465dfab56bf',
    'includes/class-petish-passport-community.php'=>'93763310909b9ba6838c3f89931148f88bbff60b728ccefe17e8655084d05b74',
    'assets/pet-club.css'=>'eae68551ca76942ba500470cc1d8871f3ad3c8920b343dd8c488c9ced6f03497',
    'assets/passport.css'=>'7bfaba1d23ea4ca07fbdb009aceec36efbefb194e1bdafff60c46f012c8a237d',
    'assets/catalogue.css'=>'de7fe4e27cec268236905ead836b542476940b93d29e496c462d22e0cac9f03a',
    'assets/passport.js'=>'74a949e388871f852b674a4cd8029d0846e34122647d82759575417f41fd7ec4',
    'templates/public.php'=>'edb30db3a0502788caafab121abe18a1d7e2199d4f5802033f1295236675852c',
    'templates/community.php'=>'de1950da32483cac543ea276b8ce9d0214db58e37d060e8d515a1c90b1a9c23c',
    'templates/moment-form.php'=>'19bb82196515510d09c22ded39768834d39cba713c91f77857d9a9b670731c0e',
    'includes/class-petish-passport-media.php'=>'f45b48ad7239ed484c24438394f96cefac533de600daa7ccbe98f8404e3b4176',
    'includes/class-petish-passport-store.php'=>'d2b0cdf86cd2215d2c3ed5e76a5d4d3b4e2db4f66ce05fdc7be1bff576078908',
    'includes/class-petish-passport-ui.php'=>'f3b878854d38a6a4fc83c5b5908a2429a84da2a7d1155609f83fae55a37dceac',
    'templates/dashboard.php'=>'9f089bfeb6e50d572f1f42bf4ffb9ccd4a92fc978d4a72c5c430ea042b60f23c',
    'templates/form.php'=>'b8e11840b306bfc90c40df62bbf807f37e095f5d537166bd4279038ab68f66ed',
    'templates/delete.php'=>'6067c76a295ff52d9f65d8f51a639a38af77d9ee532e91527c4fb1ad32eca1de',
    'petish-passport.php'=>'6c8ebd35ab44dbb1474548cff11b2391cc7204316f1a7296b21723c2b8caef2d',
];
const PETISH_PET_CLUB_BASELINES = [
    'includes/class-petish-passport-moments.php'=>'5c24f7f0d6c71df2c422f02eb6401fdca1365f2fbf99ac43f2e4124f2944881c',
    'includes/class-petish-passport-community.php'=>'5d5aaab5d19c7c55d6fdbeb0f39bcd8ad4c4bcf0b79e90a07177bbdb9b0dde61',
    'assets/pet-club.css'=>'736b25ddd199bb12d4d01a320c9fa06969ed2084f449f56d3e090153102619f8',
    'assets/passport.css'=>'e5c41cec8708f7d0e6ccddf8de76e43b807f66a612e3eac95871ab6c8a581076',
    'assets/catalogue.css'=>'de7fe4e27cec268236905ead836b542476940b93d29e496c462d22e0cac9f03a',
    'assets/passport.js'=>'764d78a7896cfa4291f09990a864c86a7b2569367a5bb39bc1660a5ab6222fe4',
    'templates/public.php'=>'825b138f5e40a9b7b2d4040d7ff8e6a60598b17be0f743988449e9c0ad5ff84c',
    'templates/community.php'=>'891ec1209ca12aba4fe7565c4bae06379caf8b2cb5c854cb16377fb952a5c286',
    'templates/moment-form.php'=>'31028698f8698d4362434938ca8282a2aacfe5f7023047cd1fdcf9fcaece6d31',
    'includes/class-petish-passport-media.php'=>'f45b48ad7239ed484c24438394f96cefac533de600daa7ccbe98f8404e3b4176',
    'includes/class-petish-passport-store.php'=>'4a06a143e438e390456f6e279602a1341bf5615aa78aad7f1f049d500ed05fe9',
    'includes/class-petish-passport-ui.php'=>'ce9d547ea004b8d96c98f850b99a0e03d68ea108d74bc7cd38988032b0f00138',
    'templates/dashboard.php'=>'f3a47ee7cc7d5772b60ae891b42c3e6cfb0677be8cd5c6b10ee6c828f6f4611b',
    'templates/form.php'=>'b8e11840b306bfc90c40df62bbf807f37e095f5d537166bd4279038ab68f66ed',
    'templates/delete.php'=>'6067c76a295ff52d9f65d8f51a639a38af77d9ee532e91527c4fb1ad32eca1de',
    'petish-passport.php'=>'432f3cbb73f19142178fbfc63fbbf95b2b0ccc16f0fd43b89e3c65ad0ea5d496',
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
        || !is_array($journal['protected']??null) || !is_array($journal['created_directories']??null)
        || !is_bool($journal['index_migration_required']??null)) pet_club_release_fail();
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

/** Retain the exact private migration receipt beside the release's code backups. */
function pet_club_release_archive_migration($home,$release,$run) {
    $tools=$home.'/.petish-pet-club-deploy-'.$run;
    pet_club_release_path($tools,true); pet_club_release_path($tools.'/.pet-club-owned');
    if((fileperms($tools)&07777)!==0700||(fileperms($tools.'/.pet-club-owned')&07777)!==0600
        ||file_get_contents($tools.'/.pet-club-owned')!==$run."\n") pet_club_release_fail();
    $source=$tools.'/pet-club-index-migration.json'; $value=pet_club_release_json($source);
    if(array_keys($value)!==['format','run','scope','status','entries','plan_hash','before','inserted']
        ||$value['format']!=='petish-club-index-migration-v1'||$value['run']!==$run
        ||$value['scope']!=='production'||$value['status']!=='applied'
        ||!is_array($value['entries'])||count($value['entries'])>100||!is_array($value['before'])
        ||!is_array($value['inserted'])||count($value['inserted'])>300
        ||!is_string($value['plan_hash'])||!preg_match('/\A[a-f0-9]{64}\z/D',$value['plan_hash'])) pet_club_release_fail();
    $raw=file_get_contents($source); $target=$release.'/index-migration.json';
    if(file_exists($target)||is_link($target)) {
        pet_club_release_json($target);
        if(!hash_equals(hash('sha256',$raw),hash_file('sha256',$target))) pet_club_release_fail();
    }else pet_club_release_write($target,$raw);
}

$report=['status'=>'failure','category'=>'input','checks'=>array_fill_keys([
    'paths_verified','candidate_manifest_verified','candidate_syntax_verified',
    'production_config_present','live_files_compatible','protected_state_unchanged',
    'rollback_snapshot_created','files_deployed','rollback_completed','release_committed','lock_owned',
    'maintenance_guard_created','maintenance_guard_removed','maintenance_guard_retained','migration_journal_archived'],false),'changed_file_count'=>0];
$rollback_on_exit=false; $live_mutation_started=false; $maintenance_owned=false; $hold_maintenance=false; $lock=null; $plugin=null; $root=null; $release=null; $run=null;
$finish=static function()use(&$report,&$rollback_on_exit,&$maintenance_owned,&$hold_maintenance,&$lock,&$plugin,&$root,&$release,&$run) {
    if($rollback_on_exit) {
        try {
            pet_club_release_rollback($plugin,$root,$release,$run);
            $report['checks']['rollback_completed']=true;
            if($lock!==null) { pet_club_release_unlock($lock); $lock=null; }
            $rollback_on_exit=false;
        }catch(Throwable $ignored) { $report['category']='rollback'; }
    }
    if($maintenance_owned && !$hold_maintenance && (!$rollback_on_exit || pet_club_release_coherent($plugin,$release,$run))) {
        try { pet_club_release_maintenance($root,$run,$release,true); $maintenance_owned=false; $report['checks']['maintenance_guard_removed']=true; }
        catch(Throwable $ignored) { $report['status']='failure'; $report['category']='maintenance'; }
    }
};
register_shutdown_function(static function()use($finish,&$report) {
    $finish(); echo json_encode($report,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
});

try {
    if($argc!==4||!in_array($argv[1],['inspect','apply','apply-index','pause','resume','rollback','commit'],true)||!preg_match('/\A[1-9][0-9]{0,31}-[1-9][0-9]{0,8}\z/D',$argv[2])) pet_club_release_fail();
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
    elseif(in_array($operation,['apply','apply-index'],true)) {
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
        $journal=['run'=>$run,'files'=>$live,'protected'=>$protected,'created_directories'=>$created,'index_migration_required'=>$operation==='apply-index'];
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
        if($operation==='apply-index') {
            // The migration helper will prove its exact row changes while this guard remains owned.
            $hold_maintenance=true; $report['checks']['maintenance_guard_retained']=true;
        }else {
            pet_club_release_maintenance($root,$run,$release,true); $maintenance_owned=false; $report['checks']['maintenance_guard_removed']=true;
        }
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
        if(in_array($operation,['pause','resume'],true)) {
            pet_club_release_path($release.'/pending'); pet_club_release_require_candidates($plugin);
            $protected_now=pet_club_release_protected($root,$plugin); $expected=$journal['protected'];
            // A later rollback must preserve legitimate customer media created after reopening.
            if($operation==='pause') $expected['private_passport']=$protected_now['private_passport'];
            if($protected_now!==$expected) pet_club_release_fail();
            $report['checks']['files_deployed']=true; $report['checks']['protected_state_unchanged']=true;
            pet_club_release_maintenance($root,$run,$release,false,true); $maintenance_owned=true;
            $report['checks']['maintenance_guard_created']=true;
            if($operation==='pause') {
                $hold_maintenance=true; $report['checks']['maintenance_guard_retained']=true;
            }else {
                pet_club_release_maintenance($root,$run,$release,true); $maintenance_owned=false;
                $report['checks']['maintenance_guard_removed']=true;
            }
        }elseif($operation==='rollback') {
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
            if($journal['index_migration_required']) {
                pet_club_release_archive_migration($home,$release,$run);
                $report['checks']['migration_journal_archived']=true;
            }
            pet_club_release_write($release.'/committed',"verified\n");
            $report['checks']['release_committed']=true;
        }
        if($lock!==null&&!in_array($operation,['pause','resume'],true)) { pet_club_release_unlock($lock); $lock=null; }
    }
    $report['status']='success'; $report['category']='complete';
}catch(Throwable $ignored) {
    $finish();
    // An aborted preparation or preimage check changed no live application file.
    if(in_array($operation??null,['apply','apply-index'],true)&&!$rollback_on_exit&&$lock!==null&&!$live_mutation_started) {
        try { pet_club_release_unlock($lock); $lock=null; }catch(Throwable $ignored_again) {}
    }
}
exit($report['status']==='success'?0:1);
