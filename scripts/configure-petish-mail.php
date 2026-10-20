<?php
/** Fixed-path, non-bootstrap deployment helper. Never emits credentials or errors. */
if (PHP_SAPI !== 'cli') exit(1);
ini_set('display_errors', '0'); ini_set('log_errors', '0'); error_reporting(0);
$category = 'configuration';
function fail_mail() { throw new RuntimeException(); }
function canonical_mail($path, $directory = false) {
    if (!is_string($path) || $path === '' || $path[0] !== '/' || str_contains($path, "\0")) fail_mail();
    $cursor = '';
    foreach (explode('/', substr($path, 1)) as $part) {
        if ($part === '' || $part === '.' || $part === '..') fail_mail();
        $cursor .= '/' . $part;
        if (is_link($cursor) || realpath($cursor) !== $cursor) fail_mail();
    }
    $stat = lstat($path);
    if (!$stat || ($directory ? !is_dir($path) : (!is_file($path) || $stat['nlink'] !== 1))) fail_mail();
    return $stat;
}
function private_write_mail($path, $value, $mode = 0600) {
    $handle = @fopen($path, 'xb'); if (!$handle) fail_mail();
    try { if (!chmod($path, $mode) || fwrite($handle, $value) !== strlen($value) || !fflush($handle)) fail_mail(); }
    finally { fclose($handle); }
}
function save_state_mail($stage, $state) {
    $temp = $stage . '/state.next'; @unlink($temp);
    private_write_mail($temp, json_encode($state, JSON_THROW_ON_ERROR));
    if (!rename($temp, $stage . '/state.json')) fail_mail();
}
function unchanged_mail($path, $hash) {
    if ($hash === null) return !file_exists($path) && !is_link($path);
    canonical_mail($path); return hash_file('sha256', $path) === $hash;
}
try {
    if ($argc !== 3 || !in_array($argv[1], ['prepare','candidate','install','rollback'], true)) fail_mail();
    $mode = $argv[1]; $stage = $argv[2]; $home = getenv('HOME');
    if (!is_string($home) || !preg_match('/\A\.petish-mail-config\.[A-Za-z0-9]+\z/D', basename($stage)) || dirname($stage) !== $home) fail_mail();
    $stage_stat = canonical_mail($stage, true);
    if (($stage_stat['mode'] & 07777) !== 0700) fail_mail();
    $root = $home . '/domains/petish.biz/public_html';
    $private_parent = dirname($root) . '/private'; $private = $private_parent . '/petish-mailer';
    $config = $private . '/smtp.json'; $mu = $root . '/wp-content/mu-plugins/petish-mailer.php';
    canonical_mail($root, true); canonical_mail(dirname($mu), true);
    $state_path = $stage . '/state.json';
    if ($mode === 'rollback') {
        if (!file_exists($state_path)) exit(0);
        canonical_mail($state_path);
        $state = json_decode(file_get_contents($state_path), true, 32, JSON_THROW_ON_ERROR);
        foreach ([[$config,'config'],[$mu,'adapter']] as [$target,$name]) {
            $old = $state[$name . '_old_hash']; $new = $state[$name . '_new_hash'];
            if (unchanged_mail($target, $old)) continue;
            if (!unchanged_mail($target, $new)) fail_mail();
            if ($old === null) { if (!unlink($target)) fail_mail(); }
            else {
                $backup = $stage . '/' . $name . '.previous'; canonical_mail($backup);
                if (hash_file('sha256', $backup) !== $old) fail_mail();
                $restore = dirname($target) . '/.petish-mail-rollback.' . bin2hex(random_bytes(8));
                private_write_mail($restore, file_get_contents($backup), $state[$name . '_old_mode']);
                if (!rename($restore, $target)) fail_mail();
            }
        }
        if (isset($state['candidate']) && dirname($state['candidate']) === $private && preg_match('/\A\.smtp-candidate\.[A-Za-z0-9]+\.json\z/D', basename($state['candidate']))) @unlink($state['candidate']);
        if (isset($state['adapter_candidate']) && dirname($state['adapter_candidate']) === dirname($mu) && preg_match('/\A\.petish-mailer\.[a-f0-9]+\.candidate\z/D', basename($state['adapter_candidate']))) @unlink($state['adapter_candidate']);
        if ($state['created_private']) @rmdir($private);
        if ($state['created_parent']) @rmdir($private_parent);
        exit(0);
    }
    if ($mode === 'candidate') {
        $state = json_decode(file_get_contents($state_path), true, 32, JSON_THROW_ON_ERROR);
        if (dirname($state['candidate']) !== $private) fail_mail();
        echo $state['candidate']; exit(0);
    }
    if ($mode === 'prepare') {
        $category = 'existing_mailer';
        $metadata = json_decode(file_get_contents($stage . '/metadata.json'), true, 16, JSON_THROW_ON_ERROR);
        if (($metadata['status'] ?? '') !== 'success' || !isset($metadata['mail_plugins'], $metadata['smtp_constant_names']) || !is_array($metadata['mail_plugins']) || $metadata['smtp_constant_names'] !== []) fail_mail();
        foreach ($metadata['mail_plugins'] as $plugin) if (($plugin['active'] ?? true) && ($plugin['slug'] ?? '') !== 'petish-mailer') fail_mail();
        $category = 'configuration';
        canonical_mail($root . '/wp-config.php');
        if (file_exists(dirname($mu) . '/petish-staging-guard.php') || is_link(dirname($mu) . '/petish-staging-guard.php')) fail_mail();
        $tokens = token_get_all(file_get_contents($root . '/wp-config.php'));
        foreach ($tokens as $i => $token) {
            if (!is_array($token) || $token[0] !== T_STRING || strtolower($token[1]) !== 'define') continue;
            $next = [];
            for ($j=$i+1; $j<count($tokens) && count($next)<5; $j++) if (!is_array($tokens[$j]) || !in_array($tokens[$j][0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)) $next[]=$tokens[$j];
            if (($next[0]??null)!=='(' || !is_array($next[1]??null) || $next[1][0]!==T_CONSTANT_ENCAPSED_STRING) continue;
            $name=trim($next[1][1], "\"'");
            if (in_array($name,['PETISH_STAGING_GUARD_ACTIVE','PETISH_RECOVERY_GUARD_TOKEN'],true)) fail_mail();
            if ($name==='WP_ENVIRONMENT_TYPE' && (($next[2]??null)!==',' || !is_array($next[3]??null) || $next[3][0]!==T_CONSTANT_ENCAPSED_STRING || trim($next[3][1],"\"'")!=='production' || ($next[4]??null)!==')')) fail_mail();
        }
        if (($env=getenv('WP_ENVIRONMENT_TYPE')) !== false && $env !== '' && $env !== 'production') fail_mail();
        $category = 'filesystem';
        foreach (['petish-mailer.php','probe-petish-smtp.php','credential.json'] as $file) canonical_mail($stage . '/' . $file);
        $state=['created_parent'=>!file_exists($private_parent),'created_private'=>!file_exists($private),'config_old_hash'=>null,'adapter_old_hash'=>null,'config_new_hash'=>hash_file('sha256',$stage.'/credential.json'),'adapter_new_hash'=>hash_file('sha256',$stage.'/petish-mailer.php'),'config_old_mode'=>0600,'adapter_old_mode'=>0644];
        foreach ([[$config,'config'],[$mu,'adapter']] as [$target,$name]) {
            if (!file_exists($target) && !is_link($target)) continue;
            $stat=canonical_mail($target);
            if ($stat['uid'] !== $stage_stat['uid']) fail_mail();
            if ($name==='config' && ($stat['mode'] & 07777)!==0600) fail_mail();
            if ($name==='adapter' && !str_contains(file_get_contents($target),'final class Petish_Mailer')) fail_mail();
            $state[$name.'_old_hash']=hash_file('sha256',$target); $state[$name.'_old_mode']=$stat['mode'] & 07777;
            private_write_mail($stage.'/'.$name.'.previous',file_get_contents($target));
        }
        // Save rollback intent before creating even a private directory.
        $state['candidate']=$private.'/.smtp-candidate.'.bin2hex(random_bytes(12)).'.json';
        $state['adapter_candidate']=dirname($mu).'/.petish-mailer.'.bin2hex(random_bytes(12)).'.candidate';
        save_state_mail($stage,$state);
        if ($state['created_parent'] && !mkdir($private_parent,0700)) fail_mail();
        canonical_mail($private_parent,true);
        if ($state['created_private'] && !mkdir($private,0700)) fail_mail();
        $stat=canonical_mail($private,true);
        if (($stat['mode'] & 07777)!==0700 || $stat['uid']!==$stage_stat['uid']) fail_mail();
        private_write_mail($state['candidate'],file_get_contents($stage.'/credential.json'));
        define('PETISH_SMTP_PROBE',true); define('PETISH_SMTP_WORDPRESS_ROOT',$root);
        require $stage.'/petish-mailer.php';
        if (Petish_Mailer::read_config($state['candidate'])===false) fail_mail();
        if ($state['config_old_hash']!==null && Petish_Mailer::read_config($config)===false) fail_mail();
        exit(0);
    }
    $category='deployment';
    $state=json_decode(file_get_contents($state_path),true,32,JSON_THROW_ON_ERROR);
    $probe=json_decode(file_get_contents($stage.'/probe.json'),true,8,JSON_THROW_ON_ERROR);
    if (($probe['smtp_authentication_verified']??null)!==true || ($probe['tls_peer_verification']??null)!==true || ($probe['message_submitted']??null)!==false) fail_mail();
    if (!unchanged_mail($config,$state['config_old_hash']) || !unchanged_mail($mu,$state['adapter_old_hash']) || !unchanged_mail($state['candidate'],$state['config_new_hash'])) fail_mail();
    private_write_mail($state['adapter_candidate'],file_get_contents($stage.'/petish-mailer.php'),0644);
    if (hash_file('sha256',$state['adapter_candidate'])!==$state['adapter_new_hash']) fail_mail();
    // Credential must exist before the mail adapter can become active.
    if (!rename($state['candidate'],$config) || !rename($state['adapter_candidate'],$mu)) fail_mail();
    echo json_encode(['configured'=>true,'smtp_authentication_verified'=>true,'tls_peer_verification'=>true,'message_submitted'=>false]),"\n";
    exit(0);
} catch (Throwable $ignored) {
    echo json_encode(['configured'=>false,'smtp_authentication_verified'=>false,'message_submitted'=>false,'failure_category'=>$category]),"\n"; exit(1);
}
