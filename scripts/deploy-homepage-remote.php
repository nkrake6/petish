<?php
/** Narrow production homepage and header release. Standalone CLI; never boots WordPress. */
if (PHP_SAPI !== 'cli') exit(1);
ini_set('display_errors','0'); ini_set('log_errors','0'); error_reporting(0);

// Exact reviewed homepage and header files. All application hashes must be frozen before release.
const PETISH_HOMEPAGE_FILES = [
    'assets/images/ui/free-delivery-van.png'=>'4a731487daec6eda89a4e08aeec2bab86bd4f7a79e1828ad8bb77069600464af',
    'assets/css/home.css'=>'aea70ca0b49d3d23cf6ffacc05e1d17a1a816f49fb33d48fbcb3dd038e28e67c',
    'assets/js/for-your-pet.js'=>'29cd41f2e0a646234fefea9d3a73bb2c53ebc11cb07f1362ca406a7ca9d22a0a',
    'includes/supplier-popularity.php'=>'54ef94d249292953c41a81291a5b5eb3f0760ef290f6ee19de79c691747b2e2b',
    'includes/for-your-pet.php'=>'eb3b9d59c63642770366db0c5c0d3375f4ce16539f0b94df01d12b7e87e54aa8',
    'front-page.php'=>'06016dc7d45ce465141b6e1d24da5deaa05e0e06aa1d073ac0229aeb4785e8a8',
    'functions.php'=>'4e199104034c877c326a0b8c714fe935bce829a4c88051f6ef97690f0ff2000a',
    'includes/cart-badge.php'=>'04987502e37e3c11ce669168c214078861906baff98add7e013c4b2b2dc14dc8',
    'assets/css/cart-badge.css'=>'e1e39ccad9b779d909946a8755363b435e2647176aa2277a7c2d58c61f7498c7',
    'assets/js/cart-badge.js'=>'3b8d1bd89142ccc846bc019383ff5f97eae0cc16270b242f0adfa5503c290bc7',
    'includes/header-navigation.php'=>'a9ede1a9a9e6c2ef82e6831ad6280f1e90d83377d47831c35218c29d7b7f884d',
    'assets/js/theme.js'=>'677d7718c3e3d7385990ba59fcb21431589a08695bd34076bbc5e6bf1b2e3852',
    'assets/css/petish.css'=>'71025e0f3c1b2fb3e70ce9be5ee85d3934269205b019be4d29e05e12331c24dc',
    'includes/market-selector.php'=>'d7675c569e4659b27c58aa686c4f372906224f8c9f0435843673dce47cf84147',
    'assets/js/market-selector.js'=>'dd59c4d4c17aa415e4c504a71fc31eb94d4349a8e69b0a838bd13d7b58a871ca',
    'assets/css/market-selector.css'=>'0ff5c3d46eb4e400a613f721a1e1e1349d4f30b13edd46875eab75c7287fcee0',
    'assets/images/ui/country-flags.svg'=>'ce61ad3847cdb156c2b5c6775921e1a8dbc2a40c94a2c7cd8b3ff93da939bf87',
    'assets/images/ui/country-flags.LICENSE.txt'=>'ca3b5f1132bd18fa0b6833d55fad881de51f25a0aac8cc8df89b56d93604c974',
    'includes/account-dropdown.php'=>'c9980b37ea54d4c315874e9f709d05377c3d73f027a0c881e39a02d0310489ff',
    'assets/css/account-dropdown.css'=>'48eab0eac5c416096658b0c5cb6f7404d2d14256b654e72a6fc9ea5454a1686b',
    'assets/js/account-dropdown.js'=>'cd3c4486728143945060cbc5a9820096cda8e67667eb7537ec802b56baccdede',
    'includes/hero-images.php'=>'d29434954109f6ac7f5a4654d8e703464eb6dae0bd63482f42c4e56f205e758a',
    'assets/js/hero-images-admin.js'=>'07e21a7594230daecc8ebfd70b575d7bd10b565d5a2dea87a6c6100f1bc5124f',
];
const PETISH_HOMEPAGE_BASELINES = [
    'assets/images/ui/free-delivery-van.png'=>'4a731487daec6eda89a4e08aeec2bab86bd4f7a79e1828ad8bb77069600464af',
    'assets/css/home.css'=>'dc4f3606341c2eb44df505c54a192e07d2771e90a9e09c102ac2a6983789bd23',
    'assets/js/for-your-pet.js'=>'29cd41f2e0a646234fefea9d3a73bb2c53ebc11cb07f1362ca406a7ca9d22a0a',
    'includes/supplier-popularity.php'=>'54ef94d249292953c41a81291a5b5eb3f0760ef290f6ee19de79c691747b2e2b',
    'includes/for-your-pet.php'=>'eb3b9d59c63642770366db0c5c0d3375f4ce16539f0b94df01d12b7e87e54aa8',
    'front-page.php'=>'7336764e7da8fc56132584564da7e4fe28da7fdec1839775dd131469b7f3f924',
    'functions.php'=>'ef3372a1d00a28eb952953eaefb3793b82361195aa0d773373006aa5dfee170b',
    'includes/cart-badge.php'=>'04987502e37e3c11ce669168c214078861906baff98add7e013c4b2b2dc14dc8',
    'assets/css/cart-badge.css'=>'e1e39ccad9b779d909946a8755363b435e2647176aa2277a7c2d58c61f7498c7',
    'assets/js/cart-badge.js'=>'3b8d1bd89142ccc846bc019383ff5f97eae0cc16270b242f0adfa5503c290bc7',
    'includes/header-navigation.php'=>'379531f871fd87aedb64f63b0b9bb2b4ba381f2e9b072eafad86edfbd90ac804',
    'assets/js/theme.js'=>'677d7718c3e3d7385990ba59fcb21431589a08695bd34076bbc5e6bf1b2e3852',
    'assets/css/petish.css'=>'71025e0f3c1b2fb3e70ce9be5ee85d3934269205b019be4d29e05e12331c24dc',
    'includes/market-selector.php'=>'d7675c569e4659b27c58aa686c4f372906224f8c9f0435843673dce47cf84147',
    'assets/js/market-selector.js'=>'dd59c4d4c17aa415e4c504a71fc31eb94d4349a8e69b0a838bd13d7b58a871ca',
    'assets/css/market-selector.css'=>'0ff5c3d46eb4e400a613f721a1e1e1349d4f30b13edd46875eab75c7287fcee0',
    'assets/images/ui/country-flags.svg'=>'ce61ad3847cdb156c2b5c6775921e1a8dbc2a40c94a2c7cd8b3ff93da939bf87',
    'assets/images/ui/country-flags.LICENSE.txt'=>'ca3b5f1132bd18fa0b6833d55fad881de51f25a0aac8cc8df89b56d93604c974',
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
    $private=dirname($root).'/private/petish-passport';
    $state['private_passport']=file_exists($private)||is_link($private)?homepage_release_tree($private):null;
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
/** Refuse an edit made since the private snapshot was captured. */
function homepage_release_preimage($path,$entry) {
    clearstatcache(true,$path);
    if($entry['present']) {
        homepage_release_path($path);
        if(!hash_equals($entry['sha256'],hash_file('sha256',$path))
            || (fileperms($path)&0777)!==$entry['mode']) homepage_release_fail();
    }elseif(file_exists($path)||is_link($path)) homepage_release_fail();
}
/** A rollback may replace only this release's candidate or its exact preimage. */
function homepage_release_rollback_destination($path,$name,$entry) {
    clearstatcache(true,$path);
    if(file_exists($path)||is_link($path)) {
        homepage_release_path($path); $hash=hash_file('sha256',$path);
        if($hash!==PETISH_HOMEPAGE_FILES[$name] && (!$entry['present']||$hash!==$entry['sha256'])) homepage_release_fail();
    }elseif($entry['present']) homepage_release_fail();
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
function homepage_release_originals_verified($theme,$root,$release,$run,$protected_expected=null) {
    $journal=homepage_release_journal($release,$run);
    $current=homepage_release_protected($root,$theme);
    if($protected_expected===null) {
        $protected_expected=$journal['protected'];
        $protected_expected['private_passport']=$current['private_passport'];
    }
    if($current!==$protected_expected) homepage_release_fail();
    foreach($journal['files'] as$name=>$entry) {
        $path=$theme.'/'.$name;
        if($entry['present']) {
            homepage_release_path($path);
            if(hash_file('sha256',$path)!==$entry['sha256']||(fileperms($path)&0777)!==$entry['mode']) homepage_release_fail();
        }elseif(file_exists($path)||is_link($path)) homepage_release_fail();
    }
    foreach($journal['created_directories'] as$relative) if(file_exists($theme.'/'.$relative)||is_link($theme.'/'.$relative)) homepage_release_fail();
}
/** A short owned WordPress maintenance guard hides mixed application versions. */
function homepage_release_maintenance($root,$run,$release,$remove=false,$adopt=false) {
    static $owned=[];
    $path=$root.'/.maintenance'; $receipt=$release.'/maintenance.json';
    homepage_release_path($root,true); homepage_release_path($release,true);
    $record=null;
    if(file_exists($receipt)||is_link($receipt)) {
        $record=homepage_release_json($receipt);
        if(array_keys($record)!==['run','sha256']||$record['run']!==$run
            ||!is_string($record['sha256'])||!preg_match('/\A[a-f0-9]{64}\z/D',$record['sha256'])) homepage_release_fail();
    }
    if($remove) {
        if(!isset($owned[$path])||$record===null||!hash_equals($owned[$path],$record['sha256'])) homepage_release_fail();
        homepage_release_path($path);
        if((fileperms($path)&07777)!==0600 || !hash_equals($owned[$path],hash_file('sha256',$path))) homepage_release_fail();
        if(!unlink($path)) homepage_release_fail();
        unset($owned[$path]);
        return;
    }
    if(file_exists($path)||is_link($path)) {
        if(!$adopt||$record===null) homepage_release_fail();
        homepage_release_path($path);
        if((fileperms($path)&07777)!==0600||!hash_equals($record['sha256'],hash_file('sha256',$path))) homepage_release_fail();
        $owned[$path]=$record['sha256'];
        return;
    }
    $content="<?php /* Petish homepage release ".$run." */\n\$upgrading = time();\n";
    $digest=hash('sha256',$content);
    $raw=json_encode(['run'=>$run,'sha256'=>$digest],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    if($record===null) homepage_release_write($receipt,$raw);
    else homepage_release_atomic($receipt,$raw,0600,$run);
    // Persist proof before creation so a later same-run rollback can recover it.
    homepage_release_write($path,$content); $owned[$path]=$digest;
}
function homepage_release_coherent($theme,$release,$run) {
    try { homepage_release_require_candidates($theme); return true; }catch(Throwable $ignored) {}
    try {
        $journal=homepage_release_journal($release,$run);
        foreach($journal['files']as$name=>$entry) homepage_release_preimage($theme.'/'.$name,$entry);
        return true;
    }catch(Throwable $ignored) { return false; }
}

function homepage_release_rollback($theme,$root,$release,$run) {
    $journal=homepage_release_journal($release,$run);
    $protected=homepage_release_protected($root,$theme);
    $expected=$journal['protected']; $expected['private_passport']=$protected['private_passport'];
    if($protected!==$expected) homepage_release_fail();
    // Private photos may have changed legitimately; this transaction never writes them.
    // Validate every destination, then restore the bootstrap before removing new helper classes.
    foreach($journal['files'] as$name=>$entry) {
        $path=$theme.'/'.$name;
        homepage_release_rollback_destination($path,$name,$entry);
    }
    foreach(array_reverse(array_keys(PETISH_HOMEPAGE_FILES)) as$name) {
        $path=$theme.'/'.$name; $entry=$journal['files'][$name];
        homepage_release_rollback_destination($path,$name,$entry);
        if($entry['present']) {
            $backup=$release.'/backup/'.array_search($name,array_keys(PETISH_HOMEPAGE_FILES),true);
            homepage_release_atomic($path,file_get_contents($backup),$entry['mode'],$run);
        }elseif(file_exists($path)&&!unlink($path)) homepage_release_fail();
    }
    foreach(array_reverse($journal['created_directories']) as$relative) {
        $directory=$theme.'/'.$relative;
        if(is_dir($directory)) { homepage_release_path($directory,true); if(!rmdir($directory)) homepage_release_fail(); }
    }
    homepage_release_originals_verified($theme,$root,$release,$run,$protected);
    if(!file_exists($release.'/rolled-back')) homepage_release_write($release.'/rolled-back',"verified\n");
}

$report=['status'=>'failure','category'=>'input','checks'=>array_fill_keys([
    'paths_verified','candidate_manifest_verified','candidate_syntax_verified','candidate_badge_png_verified',
    'production_config_present','live_files_compatible','protected_state_unchanged',
    'rollback_snapshot_created','files_deployed','rollback_completed','release_committed','lock_owned',
    'maintenance_guard_created','maintenance_guard_removed'],false),'changed_file_count'=>0];
$rollback_on_exit=false; $live_mutation_started=false; $maintenance_owned=false; $lock=null; $theme=null; $root=null; $release=null; $run=null;
$finish=static function()use(&$report,&$rollback_on_exit,&$maintenance_owned,&$lock,&$theme,&$root,&$release,&$run) {
    if($rollback_on_exit) {
        try {
            homepage_release_rollback($theme,$root,$release,$run);
            $report['checks']['rollback_completed']=true;
            if($lock!==null) { homepage_release_unlock($lock); $lock=null; }
            $rollback_on_exit=false;
        }catch(Throwable $ignored) { $report['category']='rollback'; }
    }
    if($maintenance_owned && (!$rollback_on_exit || homepage_release_coherent($theme,$release,$run))) {
        try { homepage_release_maintenance($root,$run,$release,true); $maintenance_owned=false; $report['checks']['maintenance_guard_removed']=true; }
        catch(Throwable $ignored) { $report['status']='failure'; $report['category']='maintenance'; }
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
        $report['category']='maintenance';
        if(file_exists($root.'/.maintenance')||is_link($root.'/.maintenance')) homepage_release_fail();
        $report['category']='lock';
        if(!file_exists($base)&&!is_link($base)&&!mkdir($base,0700)) homepage_release_fail();
        homepage_release_path($base,true); if((fileperms($base)&07777)!==0700) homepage_release_fail();
        $lock=homepage_release_lock($base,$run,true); $report['checks']['lock_owned']=true;
        $report['category']='snapshot';
        if(file_exists($release)||is_link($release)||!mkdir($release,0700)||!mkdir($release.'/backup',0700)) homepage_release_fail();
        $protected=homepage_release_protected($root,$theme); $created=[];
        // All destination directories belong to the existing child theme.
        foreach(['assets/css','assets/js','assets/images/ui','includes'] as$relative) homepage_release_path($theme.'/'.$relative,true);
        foreach($live as$name=>$entry) if($entry['present']) {
            homepage_release_write($release.'/backup/'.array_search($name,array_keys(PETISH_HOMEPAGE_FILES),true),file_get_contents($theme.'/'.$name));
        }
        $journal=['run'=>$run,'files'=>$live,'protected'=>$protected,'created_directories'=>$created];
        homepage_release_write($release.'/journal.json',json_encode($journal,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        homepage_release_journal($release,$run); $report['checks']['rollback_snapshot_created']=true;
        $report['category']='maintenance';
        homepage_release_maintenance($root,$run,$release); $maintenance_owned=true; $report['checks']['maintenance_guard_created']=true;
        $report['category']='deployment';
        foreach($live as$name=>$entry) homepage_release_preimage($theme.'/'.$name,$entry);
        if(homepage_release_protected($root,$theme)!==$protected) homepage_release_fail();
        $rollback_on_exit=true;
        foreach($created as$relative) if(!mkdir($theme.'/'.$relative,0755)) homepage_release_fail();
        foreach(PETISH_HOMEPAGE_FILES as$name=>$hash) {
            homepage_release_preimage($theme.'/'.$name,$live[$name]);
            if($live[$name]['present']&&$live[$name]['sha256']===$hash) continue;
            $live_mutation_started=true;
            homepage_release_atomic($theme.'/'.$name,file_get_contents($payload.'/'.$name),0644,$run);
        }
        homepage_release_require_candidates($theme); $report['checks']['files_deployed']=true;
        if(homepage_release_protected($root,$theme)!==$protected) homepage_release_fail();
        $report['checks']['protected_state_unchanged']=true;
        homepage_release_write($release.'/pending',"verified\n");
        homepage_release_maintenance($root,$run,$release,true); $maintenance_owned=false; $report['checks']['maintenance_guard_removed']=true;
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
                if(file_exists($root.'/.maintenance')||is_link($root.'/.maintenance')) {
                    homepage_release_maintenance($root,$run,$release,false,true); $maintenance_owned=true;
                    $report['checks']['maintenance_guard_created']=true;
                    homepage_release_maintenance($root,$run,$release,true); $maintenance_owned=false;
                }
                $report['checks']['maintenance_guard_removed']=true;
            }else {
                $report['category']='maintenance';
                homepage_release_maintenance($root,$run,$release,false,true); $maintenance_owned=true; $report['checks']['maintenance_guard_created']=true;
                $report['category']='rollback'; $rollback_on_exit=true; homepage_release_rollback($theme,$root,$release,$run);
                $rollback_on_exit=false;
                homepage_release_maintenance($root,$run,$release,true); $maintenance_owned=false; $report['checks']['maintenance_guard_removed']=true;
            }
            $report['checks']['rollback_completed']=true; $report['checks']['protected_state_unchanged']=true;
        }else {
            if(file_exists($root.'/.maintenance')||is_link($root.'/.maintenance')) homepage_release_fail();
            $report['checks']['maintenance_guard_removed']=true;
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
    // An aborted preparation or preimage check changed no live application file.
    if(($operation??null)==='apply'&&!$rollback_on_exit&&$lock!==null&&!$live_mutation_started) {
        try { homepage_release_unlock($lock); $lock=null; }catch(Throwable $ignored_again) {}
    }
}
exit($report['status']==='success'?0:1);
