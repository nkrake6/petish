#!/usr/bin/env bash
# Copy this reviewed script and its payload separately to a private server
# directory, then run: bash SCRIPT PAYLOAD.tar.gz check|apply EXPECTED_SOURCE_SHA
# Never pipes the archive into the same stdin used to supply this script.
set -Eeuo pipefail
umask 077

fail() { printf '%s\n' "$1" >&2; exit 1; }
[[ $# == 3 ]] || fail STAGING_DEPLOY_ARGUMENTS_REJECTED
payload=$1
deploy_mode=$2
expected_source_sha=$3
[[ $deploy_mode == check || $deploy_mode == apply ]] || fail STAGING_DEPLOY_MODE_REJECTED
[[ $expected_source_sha =~ ^([0-9a-f]{40}|[0-9a-f]{64})$ ]] || fail STAGING_DEPLOY_SOURCE_REJECTED
[[ ${HOME:-} == /* && $HOME != / ]] || fail STAGING_DEPLOY_HOME_REJECTED
command -v php >/dev/null 2>&1 || fail STAGING_DEPLOY_PHP_REQUIRED
destination=$HOME/domains/staging.petish.biz/public_html
cursor=$HOME
while [[ $cursor != / ]]; do
    [[ -d $cursor && ! -L $cursor ]] || fail STAGING_DEPLOY_HOME_REJECTED
    cursor=${cursor%/*}
    [[ -n $cursor ]] || cursor=/
done
releases=$HOME/.petish-staging-releases
[[ ! -L $releases ]] || fail STAGING_DEPLOY_PRIVATE_DIRECTORY_FAILED
mkdir -p -m 700 "$releases" 2>/dev/null || fail STAGING_DEPLOY_PRIVATE_DIRECTORY_FAILED
[[ -d $releases ]] || fail STAGING_DEPLOY_PRIVATE_DIRECTORY_FAILED
chmod 700 "$releases" 2>/dev/null || fail STAGING_DEPLOY_PRIVATE_DIRECTORY_FAILED
lock=$releases/deploy.lock
mkdir -m 700 "$lock" 2>/dev/null || fail STAGING_DEPLOY_LOCKED
work=$(mktemp -d "$releases/.pending.XXXXXXXX") || { rmdir "$lock"; fail STAGING_DEPLOY_PRIVATE_DIRECTORY_FAILED; }
components=(wp-content/themes/dt-the7-child wp-content/plugins/petish-passport wp-content/plugins/petish-fulfilment)
successful=0
keep_work=0
release=

finish() {
    exit_status=$?
    trap - EXIT HUP INT TERM
    if [[ ! -d $work && -n $release && -d $release ]]; then work=$release; fi
    if [[ $successful != 1 ]]; then
        rollback_ok=1
        rollback_needed=0
        for ((index=${#components[@]}-1; index>=0; index--)); do
            # Inspect completed renames, rather than state assignments that
            # could be skipped by a signal immediately after a rename.
            if [[ -d $work/backup/$index && ! -L $work/backup/$index ]]; then
                rollback_needed=1
                if [[ -e $destination/${components[index]} || -L $destination/${components[index]} ]]; then
                    mv -T -- "$destination/${components[index]}" "$work/failed-$index" 2>/dev/null || rollback_ok=0
                fi
                if [[ ! -e $destination/${components[index]} && ! -L $destination/${components[index]} ]]; then
                    mv -T -- "$work/backup/$index" "$destination/${components[index]}" 2>/dev/null || rollback_ok=0
                else
                    rollback_ok=0
                fi
            fi
        done
        if [[ $rollback_needed == 1 && $rollback_ok == 1 ]]; then
            printf '%s\n' STAGING_DEPLOY_ROLLED_BACK >&2
        elif [[ $rollback_ok != 1 ]]; then
            printf '%s\n' STAGING_DEPLOY_ROLLBACK_REQUIRES_REVIEW >&2
            keep_work=1
        fi
    fi
    if [[ $keep_work != 1 ]]; then rm -rf -- "$work" 2>/dev/null; fi
    rmdir "$lock" 2>/dev/null || true
    exit "$exit_status"
}
trap finish EXIT
trap 'exit 1' HUP INT TERM

cat > "$work/validate.php" <<'PHP'
<?php
// Standalone parsing only: never require WordPress, configuration, or a payload.
error_reporting(0);
function reject_deploy() { throw new RuntimeException('Rejected'); }
function plain_path($path, $file = false) {
    if (!is_string($path) || $path === '' || $path[0] !== '/') reject_deploy();
    $parts = explode('/', $path); $cursor = '';
    foreach ($parts as $part) {
        if ($part === '') continue;
        if ($part === '.' || $part === '..') reject_deploy();
        $cursor .= '/' . $part;
        if (is_link($cursor) || !file_exists($cursor)) reject_deploy();
    }
    if (realpath($path) !== $path || ($file && (!is_file($path) || lstat($path)['nlink'] !== 1))) reject_deploy();
}
function tree_files($root) {
    plain_path($root); if (!is_dir($root)) reject_deploy();
    $result = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $item) {
        $path = $item->getPathname();
        if ($item->isLink() || (!$item->isDir() && !$item->isFile())) reject_deploy();
        if ($item->isFile()) {
            if (lstat($path)['nlink'] !== 1) reject_deploy();
            $result[substr($path, strlen($root) + 1)] = hash_file('sha256', $path);
        }
    }
    ksort($result); return $result;
}
function literal_proof($files, $name, $expected) {
    $mentions = 0; $proven = 0;
    foreach ($files as $file) {
        $tokens = array_values(array_filter(token_get_all(file_get_contents($file), TOKEN_PARSE), function($token) {
            return !is_array($token) || !in_array($token[0], [T_WHITESPACE,T_COMMENT,T_DOC_COMMENT], true);
        }));
        $depth = 0;
        foreach ($tokens as $i => $token) {
            if ($token === '{') $depth++;
            if ($token === '}') $depth--;
            if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING || !in_array($token[1], ["'".$name."'", '"'.$name.'"'], true)) continue;
            $mentions++;
            $start = $i - 2; $previous = $tokens[$start - 1] ?? null;
            if ($depth !== 0 || !is_array($tokens[$start] ?? null) || $tokens[$start][0] !== T_STRING || strtolower($tokens[$start][1]) !== 'define' || ($tokens[$i-1] ?? null) !== '(' || ($tokens[$i+1] ?? null) !== ',' || ($tokens[$i+3] ?? null) !== ')' || ($tokens[$i+4] ?? null) !== ';') continue;
            if ($previous !== ';' && !(is_array($previous) && $previous[0] === T_OPEN_TAG)) continue;
            $value = $tokens[$i+2] ?? null;
            if ($expected === true && is_array($value) && $value[0] === T_STRING && strtolower($value[1]) === 'true') $proven++;
            if (is_string($expected) && is_array($value) && $value[0] === T_CONSTANT_ENCAPSED_STRING && in_array($value[1], ["'".$expected."'", '"'.$expected.'"'], true)) $proven++;
        }
    }
    if ($mentions !== 1 || $proven !== 1) reject_deploy();
}
function loader_proof($file,$private) {
    $tokens=array_values(array_filter(token_get_all(file_get_contents($file), TOKEN_PARSE),function($token) {
        return !is_array($token) || !in_array($token[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true);
    }));
    $depth=0; $matches=0;
    foreach($tokens as $i=>$token) {
        if($token==='{') $depth++;
        if($token==='}') $depth--;
        if(!is_array($token) || !in_array($token[0],[T_REQUIRE,T_REQUIRE_ONCE],true)) continue;
        $previous=$tokens[$i-1]??null; $value=$tokens[$i+1]??null;
        if($depth===0 && ($previous===';' || (is_array($previous) && $previous[0]===T_OPEN_TAG)) && is_array($value) && $value[0]===T_CONSTANT_ENCAPSED_STRING && in_array($value[1],["'".$private."'",'"'.$private.'"'],true) && ($tokens[$i+2]??null)===';') $matches++;
    }
    if($matches!==1) reject_deploy();
}
function protected_state($home, $root) {
    plain_path($home); plain_path($root);
    if (!is_dir($root)) reject_deploy();
    $live = $home . '/domains/petish.biz/public_html';
    if (file_exists($live)) {
        plain_path($live); $a = stat($live); $b = stat($root);
        if ($live === $root || ($a['dev'] === $b['dev'] && $a['ino'] === $b['ino'])) reject_deploy();
    }
    $config = $root . '/wp-config.php';
    $private = $home . '/.petish-staging/config.php';
    plain_path($config, true); plain_path($private, true);
    $guard = $root . '/wp-content/mu-plugins/petish-staging-guard.php';
    plain_path($guard, true);
    loader_proof($config,$private);
    if (strpos(file_get_contents($guard), 'PETISH_STAGING_GUARD_ACTIVE') === false) reject_deploy();
    literal_proof([$config, $private], 'WP_ENVIRONMENT_TYPE', 'staging');
    literal_proof([$config, $private], 'WCPAY_DEV_MODE', true);
    $state = ['wp-config.php'=>hash_file('sha256',$config), 'private-config'=>hash_file('sha256',$private), 'mu-plugins'=>tree_files($root.'/wp-content/mu-plugins')];
    foreach (['.htaccess','.user.ini'] as $name) if (file_exists($root.'/'.$name) || is_link($root.'/'.$name)) { plain_path($root.'/'.$name,true); $state[$name]=hash_file('sha256',$root.'/'.$name); }
    return json_encode($state, JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}
function safe_member($name) {
    if (!is_string($name) || !preg_match('~\A[A-Za-z0-9_. /@+\-]+\z~D', $name)) reject_deploy();
    foreach (explode('/', $name) as $part) {
        if ($part === '' || $part === '.' || $part === '..' || $part[0] === '.' || in_array(strtolower($part), ['wp-config.php','credentials.json','config.php'], true) || preg_match('/\.(sql|sqlite|db|log|pem|key|p12|pfx|zip|tar|gz|gpg|bak)$/iD',$part)) reject_deploy();
    }
}
function read_exact($stream, $length) {
    $out=''; while (strlen($out)<$length) { $chunk=gzread($stream,min(1048576,$length-strlen($out))); if ($chunk===false || $chunk==='') reject_deploy(); $out.=$chunk; } return $out;
}
function octal_field($field) {
    $field=trim($field," \0"); if ($field==='' || !preg_match('/\A[0-7]+\z/D',$field)) reject_deploy(); return octdec($field);
}
function prepare_archive($archive,$work,$source,$components) {
    plain_path($archive,true);
    if (filesize($archive)>268435456) reject_deploy();
    $stream=gzopen($archive,'rb'); if ($stream===false) reject_deploy();
    $seen=[]; $actual=[]; $manifest=null; $total=0; $php=[];
    while (true) {
        $header=read_exact($stream,512);
        if ($header===str_repeat("\0",512)) {
            if (read_exact($stream,512)!==str_repeat("\0",512)) reject_deploy();
            while (!gzeof($stream)) { $tail=gzread($stream,1048576); if ($tail===false || trim($tail,"\0")!=='') reject_deploy(); }
            break;
        }
        $checksum=octal_field(substr($header,148,8));
        $blank=substr_replace($header,str_repeat(' ',8),148,8); $sum=0;
        for($i=0;$i<512;$i++) $sum+=ord($blank[$i]);
        if ($sum!==$checksum || !in_array($header[156],["0","\0"],true) || substr($header,157,100)!==str_repeat("\0",100)) reject_deploy();
        $name=rtrim(substr($header,0,100),"\0"); $prefix=rtrim(substr($header,345,155),"\0");
        if ($prefix!=='') $name=$prefix.'/'.$name;
        safe_member($name);
        if (isset($seen[$name])) reject_deploy(); $seen[$name]=true;
        $size=octal_field(substr($header,124,12));
        if ($size>33554432 || count($seen)>20001 || ($total+=$size)>536870912) reject_deploy();
        $data=$size===0?'':read_exact($stream,$size);
        $padding=(512-($size%512))%512;
        if ($padding && read_exact($stream,$padding)!==str_repeat("\0",$padding)) reject_deploy();
        if ($name==='staging-source-manifest.json') {
            if (count($seen)!==1 || $size>4194304) reject_deploy();
            $manifest=json_decode($data,true,512,JSON_THROW_ON_ERROR);
            if (($manifest['format']??null)!=='petish-staging-source-v1' || ($manifest['source_sha']??null)!==$source || ($manifest['components']??null)!==$components || !is_array($manifest['files']??null)) reject_deploy();
            file_put_contents($work.'/manifest.json',$data); continue;
        }
        if ($manifest===null) reject_deploy();
        $allowed=false; foreach($components as $component) if(strpos($name,$component.'/')===0) $allowed=true;
        if(!$allowed) reject_deploy();
        $target=$work.'/new/'.$name; $parent=dirname($target);
        if (!is_dir($parent) && !mkdir($parent,0755,true)) reject_deploy();
        if (file_exists($target) || file_put_contents($target,$data)!==$size || !chmod($target,0644)) reject_deploy();
        $actual[$name]=['bytes'=>$size,'sha256'=>hash('sha256',$data)];
        if(strtolower(pathinfo($name,PATHINFO_EXTENSION))==='php') $php[]=$target;
    }
    gzclose($stream);
    if($manifest===null) reject_deploy();
    $expected=[];
    foreach($manifest['files'] as $entry) {
        if(!is_array($entry) || !isset($entry['path'],$entry['bytes'],$entry['sha256']) || !is_int($entry['bytes']) || !is_string($entry['sha256']) || !preg_match('/\A[0-9a-f]{64}\z/D',$entry['sha256'])) reject_deploy();
        safe_member($entry['path']); if(isset($expected[$entry['path']])) reject_deploy();
        $expected[$entry['path']]=['bytes'=>$entry['bytes'],'sha256'=>$entry['sha256']];
    }
    ksort($actual); ksort($expected); if($actual!==$expected) reject_deploy();
    foreach([$components[0].'/style.css',$components[0].'/functions.php',$components[1].'/petish-passport.php',$components[2].'/petish-fulfilment.php'] as $required) if(!isset($actual[$required])) reject_deploy();
    file_put_contents($work.'/php-files',implode("\0",$php).(count($php)?"\0":""));
}
try {
    [$script,$mode,$home,$root,$work,$archive,$source]=$argv;
    $components=['wp-content/themes/dt-the7-child','wp-content/plugins/petish-passport','wp-content/plugins/petish-fulfilment'];
    $state=protected_state($home,$root);
    if($mode==='prepare') {
        plain_path($work);
        if(stat($root)['dev']!==stat($work)['dev']) reject_deploy();
        foreach($components as $component) {
            tree_files($root.'/'.$component);
            if(stat($root.'/'.$component)['dev']!==stat($work)['dev'] || stat(dirname($root.'/'.$component))['dev']!==stat($work)['dev']) reject_deploy();
        }
        file_put_contents($work.'/protected-state.json',$state);
        prepare_archive($archive,$work,$source,$components);
    } elseif($mode==='verify') {
        if(!hash_equals(file_get_contents($work.'/protected-state.json'),$state)) reject_deploy();
    } else reject_deploy();
} catch(Throwable $error) { fwrite(STDERR,"STAGING_VALIDATION_REJECTED\n"); exit(1); }
PHP

php "$work/validate.php" prepare "$HOME" "$destination" "$work" "$payload" "$expected_source_sha" >/dev/null || fail STAGING_DEPLOY_VALIDATION_FAILED
while IFS= read -r -d '' php_file; do
    php -l "$php_file" >/dev/null 2>&1 || fail STAGING_DEPLOY_LINT_FAILED
done < "$work/php-files"
php "$work/validate.php" verify "$HOME" "$destination" "$work" "$payload" "$expected_source_sha" >/dev/null || fail STAGING_DEPLOY_GUARD_CHANGED
if [[ $deploy_mode == check ]]; then
    successful=1
    printf '%s\n' STAGING_DEPLOY_CHECK_PASSED
    exit 0
fi
mkdir -m 700 "$work/backup" 2>/dev/null
for index in "${!components[@]}"; do
    mv -T -- "$destination/${components[index]}" "$work/backup/$index" 2>/dev/null
    mv -T -- "$work/new/${components[index]}" "$destination/${components[index]}" 2>/dev/null
done
php "$work/validate.php" verify "$HOME" "$destination" "$work" "$payload" "$expected_source_sha" >/dev/null || fail STAGING_DEPLOY_GUARD_CHANGED
keep_work=1
# Successful deployment keeps the previous three directories and the manifest
# in this private, mode-0700 directory for a reviewed rollback.
rm -rf -- "$work/new"
rm -f -- "$work/validate.php" "$work/php-files"
release=$releases/$(date -u +%Y%m%dT%H%M%SZ)_${expected_source_sha}_$(basename "$work")
mv -T -- "$work" "$release" 2>/dev/null
work=$release
successful=1
printf '%s\n' STAGING_DEPLOY_APPLIED
