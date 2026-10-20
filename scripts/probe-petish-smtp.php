<?php
/** Authenticate over verified implicit TLS; never submit an SMTP message. */
if ('cli' !== PHP_SAPI) exit(1);
ini_set('display_errors', '0');
ini_set('log_errors', '0');
error_reporting(0);

$category = 'configuration';
$mailer = null;
try {
    if ($argc < 3 || $argc > 4) throw new RuntimeException();
    $config_path = $argv[1];
    $root = rtrim($argv[2], '/');
    if (!is_dir($root) || realpath($root) !== $root || is_link($root)) throw new RuntimeException();
    $adapter = $argv[3] ?? ($root . '/wp-content/mu-plugins/petish-mailer.php');
    if (!is_file($adapter) || is_link($adapter) || realpath($adapter) !== $adapter) throw new RuntimeException();
    define('PETISH_SMTP_PROBE', true);
    define('PETISH_SMTP_WORDPRESS_ROOT', $root);
    require_once $adapter; // Class definitions only; WordPress is never loaded.
    $config = Petish_Mailer::read_config($config_path);
    if (false === $config) throw new RuntimeException();
    $category = 'runtime';
    if (!extension_loaded('openssl')) throw new RuntimeException();
    foreach (array('Exception.php', 'SMTP.php', 'PHPMailer.php') as $class_file) {
        $file = $root . '/wp-includes/PHPMailer/' . $class_file;
        if (!is_file($file) || is_link($file) || realpath($file) !== $file) throw new RuntimeException();
        require_once $file;
    }
    $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
    Petish_Mailer::configure($mailer, $config);
    $category = 'smtp_authentication';
    // smtpConnect negotiates TLS, EHLO and AUTH. It never sends MAIL FROM, RCPT
    // TO or DATA. smtpClose sends QUIT and closes the transport without a message.
    if (!$mailer->smtpConnect()) throw new RuntimeException();
    $mailer->smtpClose();
    $mailer = null;
    echo json_encode(array('smtp_authentication_verified' => true,
                           'message_submitted' => false,
                           'tls_peer_verification' => true)) . PHP_EOL;
    exit(0);
} catch (Throwable $ignored) {
    if ($mailer) {
        try { $mailer->smtpClose(); } catch (Throwable $ignored_close) {}
    }
    echo json_encode(array('smtp_authentication_verified' => false,
                           'message_submitted' => false,
                           'error_category' => $category)) . PHP_EOL;
    exit(1);
}
