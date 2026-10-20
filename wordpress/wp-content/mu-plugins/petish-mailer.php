<?php
/**
 * Plugin Name: Petish Transactional Mail
 * Description: Verified private SMTP transport for production Petish mail.
 * Version: 0.1.0
 */
if (!defined('ABSPATH') && !defined('PETISH_SMTP_PROBE')) {
    return;
}

final class Petish_Mailer {
    const FROM = 'orders@petish.biz';
    const NAME = 'Petish';
    const HOSTS = array('smtp.hostinger.com', 'smtp.titan.email');

    public static function boot() {
        add_filter('pre_wp_mail', array(__CLASS__, 'pre_mail'), PHP_INT_MAX, 2);
        add_filter('wp_mail_from', array(__CLASS__, 'from'), PHP_INT_MAX);
        add_filter('wp_mail_from_name', array(__CLASS__, 'from_name'), PHP_INT_MAX);
        add_action('phpmailer_init', array(__CLASS__, 'initialize'), PHP_INT_MAX);
    }

    public static function from($unused = '') { return self::FROM; }
    public static function from_name($unused = '') { return self::NAME; }

    public static function production() {
        return function_exists('wp_get_environment_type')
            && 'production' === wp_get_environment_type()
            && !defined('PETISH_STAGING_GUARD_ACTIVE')
            && !defined('PETISH_RECOVERY_GUARD_TOKEN');
    }

    public static function transport_available() {
        return extension_loaded('openssl') && defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT');
    }

    public static function config_path() {
        $root = defined('ABSPATH') ? ABSPATH : (defined('PETISH_SMTP_WORDPRESS_ROOT') ? PETISH_SMTP_WORDPRESS_ROOT : false);
        if (!is_string($root)) return false;
        return dirname(rtrim($root, '/\\')) . '/private/petish-mailer/smtp.json';
    }

    /** Return validated values or false; never emit private paths or JSON errors. */
    public static function read_config($path = null) {
        $path = null === $path ? self::config_path() : $path;
        if (!is_string($path) || '' === $path || '/' !== $path[0]
            || str_contains($path, "\0") || strlen($path) > 4096) return false;
        $expected = self::config_path();
        if (false === $expected || dirname($path) !== dirname($expected)
            || !preg_match('/^(?:smtp\.json|\.smtp-candidate\.[A-Za-z0-9]+\.json)$/D', basename($path))) return false;
        $parts = explode('/', substr($path, 1));
        if (in_array('', $parts, true) || in_array('.', $parts, true)
            || in_array('..', $parts, true)) return false;
        $cursor = '';
        $last = count($parts) - 1;
        $before = false;
        foreach ($parts as $index => $part) {
            $cursor .= '/' . $part;
            clearstatcache(true, $cursor);
            $entry = @lstat($cursor);
            if (!$entry || @is_link($cursor) || @realpath($cursor) !== $cursor) return false;
            $kind = $entry['mode'] & 0170000;
            if ($index < $last && 0040000 !== $kind) return false;
            if ($index === $last - 1 && 0700 !== ($entry['mode'] & 07777)) return false;
            if ($index === $last) {
                if (0100000 !== $kind || 0600 !== ($entry['mode'] & 07777)
                    || 1 !== $entry['nlink'] || $entry['size'] < 1 || $entry['size'] > 8192) return false;
                $before = $entry;
            }
        }
        $handle = @fopen($path, 'rb');
        if (!$handle) return false;
        try {
            $opened = @fstat($handle);
            if (!$opened || $opened['ino'] !== $before['ino'] || $opened['dev'] !== $before['dev']
                || 1 !== $opened['nlink'] || 0600 !== ($opened['mode'] & 07777)
                || 0100000 !== ($opened['mode'] & 0170000)) return false;
            $raw = @stream_get_contents($handle, 8193);
            if (!is_string($raw) || strlen($raw) > 8192) return false;
            $config = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable $ignored) {
            return false;
        } finally {
            fclose($handle);
        }
        if (!is_array($config)) return false;
        $keys = array_keys($config);
        sort($keys);
        if ($keys !== array('encryption', 'host', 'password', 'port', 'username')) return false;
        if (!is_string($config['host']) || !in_array($config['host'], self::HOSTS, true)
            || 465 !== $config['port'] || 'smtps' !== $config['encryption']
            || self::FROM !== $config['username'] || !is_string($config['password'])
            || strlen($config['password']) < 1 || strlen($config['password']) > 2048
            || preg_match('/[\x00-\x1F\x7F]/', $config['password'])) return false;
        return $config;
    }

    public static function pre_mail($pre, $attributes = array()) {
        // A staging/recovery capture that already handled mail must keep its result.
        if (null !== $pre) return $pre;
        if (!self::production() || !self::transport_available() || false === self::read_config()) return false;
        return null;
    }

    /** Shared by WordPress and the authentication-only standalone probe. */
    public static function configure($mailer, $config) {
        $mailer->isSMTP();
        if (!self::transport_available()) {
            throw new \PHPMailer\PHPMailer\Exception('Petish mail transport is unavailable.');
        }
        $mailer->Host = $config['host'];
        $mailer->Port = 465;
        $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mailer->SMTPAutoTLS = false;
        $mailer->SMTPAuth = true;
        $mailer->Username = self::FROM;
        $mailer->Password = $config['password'];
        $mailer->AuthType = '';
        $mailer->SMTPKeepAlive = false;
        $mailer->Timeout = 20;
        $mailer->SMTPDebug = 0;
        $mailer->Debugoutput = static function () {};
        $crypto = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) $crypto |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        $mailer->SMTPOptions = array('ssl' => array(
            'verify_peer' => true, 'verify_peer_name' => true,
            'allow_self_signed' => false, 'peer_name' => $config['host'],
            'crypto_method' => $crypto,
        ));
        $mailer->setFrom(self::FROM, self::NAME, false);
        $mailer->Sender = self::FROM;
    }

    public static function initialize($mailer) {
        // Also guard against config rotation/removal between pre_wp_mail and init.
        $mailer->isSMTP();
        $config = self::production() && self::transport_available() ? self::read_config() : false;
        if (false === $config) {
            throw new \PHPMailer\PHPMailer\Exception('Petish mail transport is unavailable.');
        }
        self::configure($mailer, $config);
    }
}

if (!defined('PETISH_SMTP_PROBE')) Petish_Mailer::boot();
