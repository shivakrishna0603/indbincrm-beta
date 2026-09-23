<?php
/**
 * INDBIN : single bootstrap for the whole application.
 *
 * Every page in every module includes this, and nothing else, first.
 * It owns the session, the PDO handle and the shared helpers.
 *
 * Before this existed, each module called a bare session_start() with PHP's
 * default session name while the customer module used session_name('INDBINSESS').
 * Signing in on one and walking into another looked like signing out.
 */

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

// URL prefix worked out from where the app sits under the document root, so
// it runs from /indbincrm, /indbin or the root without editing anything.
$docRoot = str_replace('\\', '/', (string)(realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: ''));
$appDir  = str_replace('\\', '/', APP_ROOT);
define('BASE_URL', ($docRoot !== '' && str_starts_with($appDir, $docRoot))
    ? rtrim(substr($appDir, strlen($docRoot)), '/')
    : '');

define('APP_ENV', getenv('APP_ENV') ?: 'local');

// ---------------------------------------------------------------- errors
ini_set('display_errors', APP_ENV === 'local' ? '1' : '0');
ini_set('log_errors', '1');
@mkdir(APP_ROOT . '/storage/logs', 0750, true);
@mkdir(APP_ROOT . '/storage/keys', 0700, true);
ini_set('error_log', APP_ROOT . '/storage/logs/php-error.log');
error_reporting(E_ALL);

// --------------------------------------------------------------- session
if (session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'domain' => '',
        'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_name('INDBINSESS');
    session_start();
}

if (isset($_SESSION['last_seen']) && (time() - (int)$_SESSION['last_seen']) > 1800) {
    $_SESSION = [];
    session_destroy();
    session_start();
}
$_SESSION['last_seen'] = time();

// -------------------------------------------------------------------- db
require_once APP_ROOT . '/core/db.php';

// ------------------------------------------------------- stale session check
//
// A session can outlive the row it points at: re-running the schema, deleting
// an account, or restoring a backup all leave a browser holding a user_id for
// a user that no longer exists.
//
// The merchant and agent pages only test isset($_SESSION['user_id']) before
// using it, so a stale id sails through and lands in an INSERT, where the
// foreign key rejects it with a fatal "Cannot add or update a child row".
// The page has no idea it is signed in as a ghost.
//
// One indexed primary-key lookup per request settles it. Deliberately not
// cached in the session: caching would also mean a suspended account kept
// working until the person happened to log out, and a single lookup on a
// primary key is not worth that.
if (!empty($_SESSION['user_id'])) {
    try {
        $check = $pdo->prepare("SELECT account_status FROM users WHERE id = ? LIMIT 1");
        $check->execute([(int)$_SESSION['user_id']]);
        $status = $check->fetchColumn();

        if ($status === false || in_array($status, ['suspended', 'closed'], true)) {
            // Clear it rather than redirect: each page already has its own
            // not-signed-in branch, and this lets that run normally.
            $_SESSION = [];
            session_regenerate_id(true);
        }
    } catch (PDOException $e) {
        // A missing users table means the schema has not been installed yet.
        // Nothing to validate against, so leave the session alone.
        error_log('session check skipped: ' . $e->getMessage());
    }
}

// PII key. Generated on a laptop so the app runs out of the box; provisioned
// outside the web root in production, where a missing key is fatal.
if (!defined('PII_KEY')) {
    $keyFile = APP_ROOT . '/storage/keys/pii.key';
    if (!is_readable($keyFile) && APP_ENV === 'local') {
        @file_put_contents($keyFile, bin2hex(random_bytes(32)));
        @chmod($keyFile, 0600);
    }
    $key = is_readable($keyFile) ? trim((string)file_get_contents($keyFile)) : '';
    if ($key === '' && APP_ENV !== 'local') {
        error_log('bootstrap: PII key missing');
        http_response_code(503);
        exit('Service temporarily unavailable.');
    }
    define('PII_KEY', $key);
}

require_once APP_ROOT . '/core/helpers.php';
require_once APP_ROOT . '/core/auth.php';
require_once APP_ROOT . '/core/guard.php';
require_once APP_ROOT . '/core/workflow.php';

ensure_schema_current($pdo);
