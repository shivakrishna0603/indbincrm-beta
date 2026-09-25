<?php
declare(strict_types=1);

/**
 * INDBIN : single bootstrap for the whole application.
 *
 * Every page in every module includes this, and nothing else, first.
 * It owns the session, the PDO handle and the shared helpers.
 */

define('APP_ROOT', dirname(__DIR__));

// Load config.php if present
if (file_exists(APP_ROOT . '/config.php')) {
    require_once APP_ROOT . '/config.php';
}

// URL prefix worked out from where the app sits under the document root
$docRoot = str_replace('\\', '/', (string)(realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: ''));
$appDir  = str_replace('\\', '/', APP_ROOT);
define('BASE_URL', ($docRoot !== '' && str_starts_with($appDir, $docRoot))
    ? rtrim(substr($appDir, strlen($docRoot)), '/')
    : '');

if (!defined('APP_ENV')) {
    define('APP_ENV', getenv('APP_ENV') ?: 'local');
}

// ---------------------------------------------------------------- errors
ini_set('display_errors', '1');
ini_set('log_errors', '1');
@mkdir(APP_ROOT . '/storage/logs', 0755, true);
@mkdir(APP_ROOT . '/storage/keys', 0755, true);
error_reporting(E_ALL);

// --------------------------------------------------------------- session
if (session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    @session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'domain' => '',
        'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_name('INDBINSESS');
    @session_start();
}

if (isset($_SESSION['last_seen']) && (time() - (int)$_SESSION['last_seen']) > 1800) {
    $_SESSION = [];
    @session_destroy();
    @session_start();
}
$_SESSION['last_seen'] = time();

// -------------------------------------------------------------------- db
require_once APP_ROOT . '/core/db.php';

// ------------------------------------------------------- stale session check
if (!empty($_SESSION['user_id']) && isset($pdo)) {
    try {
        $check = $pdo->prepare("SELECT account_status FROM users WHERE id = ? LIMIT 1");
        $check->execute([(int)$_SESSION['user_id']]);
        $status = $check->fetchColumn();

        if ($status === false || in_array($status, ['suspended', 'closed'], true)) {
            $_SESSION = [];
            @session_regenerate_id(true);
        }
    } catch (PDOException $e) {
        error_log('session check skipped: ' . $e->getMessage());
    }
}

// PII key. Auto-generate or fallback securely
if (!defined('PII_KEY')) {
    $keyFile = APP_ROOT . '/storage/keys/pii.key';
    if (!is_readable($keyFile)) {
        @file_put_contents($keyFile, bin2hex(random_bytes(32)));
    }
    $key = is_readable($keyFile) ? trim((string)@file_get_contents($keyFile)) : '';
    if ($key === '') {
        $key = hash('sha256', (string)(defined('DB_NAME') ? DB_NAME : 'indbincrm') . 'salt_secret');
    }
    define('PII_KEY', $key);
}

require_once APP_ROOT . '/core/helpers.php';
require_once APP_ROOT . '/core/auth.php';
require_once APP_ROOT . '/core/guard.php';
require_once APP_ROOT . '/core/workflow.php';

if (isset($pdo)) {
    ensure_schema_current($pdo);
}
