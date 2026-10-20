<?php
/** Explicitly requested single delivery test; no WordPress bootstrap or order. */
if ('cli' !== PHP_SAPI) exit(1);
ini_set('display_errors', '0'); ini_set('log_errors', '0'); error_reporting(0);

function petish_test_mail_path($path, $directory = false) {
    if (!is_string($path) || '' === $path || '/' !== $path[0] || str_contains($path, "\0")) return false;
    $cursor = '';
    foreach (explode('/', substr($path, 1)) as $part) {
        if ('' === $part || '.' === $part || '..' === $part) return false;
        $cursor .= '/' . $part;
        if (is_link($cursor) || realpath($cursor) !== $cursor) return false;
    }
    $stat = @lstat($path);
    return $stat && ($directory ? is_dir($path) : (is_file($path) && 1 === $stat['nlink']));
}

$category = 'configuration'; $mailer = null;
try {
    if (5 !== $argc || '--send-one-test' !== $argv[4]) throw new RuntimeException();
    $home = getenv('HOME');
    if (!is_string($home) || '' === $home) throw new RuntimeException();
    $root = $home . '/domains/petish.biz/public_html';
    if ($argv[1] !== $root || !petish_test_mail_path($root, true)) throw new RuntimeException();
    $key_file = $argv[2]; $envelope_file = $argv[3]; $key_dir = dirname($key_file);
    if (dirname($key_dir) !== $home || basename($key_file) !== 'key.pem'
        || !preg_match('/\A\.petish-mail-test-([a-f0-9]{32})\z/D', basename($key_dir), $match)
        || dirname($envelope_file) !== $key_dir
        || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D', basename($envelope_file))
        || !petish_test_mail_path($key_dir, true) || !petish_test_mail_path($key_file)
        || !petish_test_mail_path($envelope_file)) throw new RuntimeException();
    $dir_stat = lstat($key_dir); $key_stat = lstat($key_file); $envelope_stat = lstat($envelope_file);
    if (($dir_stat['mode'] & 07777) !== 0700 || ($key_stat['mode'] & 07777) !== 0600
        || ($envelope_stat['mode'] & 07777) !== 0600 || $key_stat['uid'] !== $dir_stat['uid']
        || $envelope_stat['uid'] !== $dir_stat['uid'] || $key_stat['size'] < 1 || $key_stat['size'] > 32768
        || $envelope_stat['size'] < 1 || $envelope_stat['size'] > 4096
        || time() - $key_stat['mtime'] > 900 || $key_stat['mtime'] > time() + 30) throw new RuntimeException();
    $category = 'runtime';
    if (!extension_loaded('openssl')) throw new RuntimeException();
    $category = 'configuration';
    $key = openssl_pkey_get_private(file_get_contents($key_file));
    if (false === $key) throw new RuntimeException();
    $details = openssl_pkey_get_details($key);
    if (!$details || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048 || $details['bits'] > 4096) throw new RuntimeException();
    $plaintext = '';
    if (!openssl_private_decrypt(file_get_contents($envelope_file), $plaintext, $key, OPENSSL_PKCS1_OAEP_PADDING)) throw new RuntimeException();
    $request = json_decode($plaintext, true, 8, JSON_THROW_ON_ERROR);
    unset($plaintext, $key);
    if (!is_array($request)) throw new RuntimeException();
    $keys = array_keys($request); sort($keys);
    if ($keys !== array('recipient', 'token') || !is_string($request['token']) || !hash_equals($match[1], $request['token'])
        || !is_string($request['recipient']) || strlen($request['recipient']) > 254
        || preg_match('/[\x00-\x20\x7F]/', $request['recipient'])
        || !filter_var($request['recipient'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException();
    $recipient = $request['recipient']; unset($request);
    $adapter = $root . '/wp-content/mu-plugins/petish-mailer.php';
    if (!petish_test_mail_path($adapter)) throw new RuntimeException();
    define('PETISH_SMTP_PROBE', true); define('PETISH_SMTP_WORDPRESS_ROOT', $root);
    require_once $adapter; // Definitions only. Never load wp-config.php or WordPress.
    $config = Petish_Mailer::read_config();
    if (false === $config) throw new RuntimeException();
    $category = 'runtime';
    if (!Petish_Mailer::transport_available()) throw new RuntimeException();
    foreach (array('Exception.php', 'SMTP.php', 'PHPMailer.php') as $filename) {
        $file = $root . '/wp-includes/PHPMailer/' . $filename;
        if (!petish_test_mail_path($file)) throw new RuntimeException();
        require_once $file;
    }
    $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
    Petish_Mailer::configure($mailer, $config);
    $mailer->addAddress($recipient); unset($recipient);
    $mailer->addReplyTo(Petish_Mailer::FROM, Petish_Mailer::NAME);
    $mailer->CharSet = 'UTF-8'; $mailer->isHTML(false);
    $mailer->Subject = 'Petish SMTP delivery test';
    $mailer->Body = "This is the single SMTP delivery test requested for Petish.\n\n"
        . "Sent as Petish <orders@petish.biz> using the configured Hostinger SMTP transport.\n"
        . "This message contains no customer or order information.\n";
    $category = 'configuration';
    clearstatcache(true, $key_file);
    $current = @lstat($key_file);
    if (!$current || $current['ino'] !== $key_stat['ino'] || $current['dev'] !== $key_stat['dev']
        || $current['nlink'] !== 1 || is_link($key_file) || !unlink($key_file)) throw new RuntimeException();
    // Consumed key prevents reuse. Exactly one submission attempt; never retry.
    $category = 'smtp_submission';
    if (!$mailer->send()) throw new RuntimeException();
    try { $mailer->smtpClose(); } catch (Throwable $ignored_close) {}
    $mailer = null;
    echo json_encode(array('test_message_submitted' => true)), "\n";
    exit(0);
} catch (Throwable $ignored) {
    if ($mailer) { try { $mailer->smtpClose(); } catch (Throwable $ignored_close) {} }
    echo json_encode(array('test_message_submitted' => false, 'error_category' => $category)), "\n";
    exit(1);
}
