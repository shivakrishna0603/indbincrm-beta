<?php
/**
 * The one PDO handle.
 *
 * Supports config.php (for Hostinger or custom environments),
 * getenv() environment variables, and fallback to local defaults.
 */

declare(strict_types=1);

// Load optional config.php if present
if (file_exists(dirname(__DIR__) . '/config.php')) {
    require_once dirname(__DIR__) . '/config.php';
}

$host = defined('DB_HOST') ? DB_HOST : (getenv('DB_HOST') ?: '127.0.0.1');
$user = defined('DB_USER') ? DB_USER : (getenv('DB_USER') ?: 'root');
$pass = defined('DB_PASS') ? DB_PASS : (getenv('DB_PASS') ?: '');
$port = defined('DB_PORT') ? (string)DB_PORT : (getenv('DB_PORT') ?: '3306');

if (defined('DB_NAME')) {
    $candidates = [DB_NAME];
} elseif (getenv('DB_NAME')) {
    $candidates = [getenv('DB_NAME')];
} else {
    $candidates = ['indbin_db', 'indbincrm', 'indbin'];
}

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::ATTR_STRINGIFY_FETCHES  => false,
];

$pdo = null;
$lastError = null;

foreach ($candidates as $dbname) {
    try {
        $pdo = new PDO(
            "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",
            $user, $pass, $options
        );
        if (!defined('DB_NAME_IN_USE')) {
            define('DB_NAME_IN_USE', $dbname);
        }
        break;
    } catch (PDOException $e) {
        $lastError = $e;
    }
}

if (!$pdo instanceof PDO) {
    error_log('DB connection failed for [' . implode(', ', $candidates) . ']: '
              . ($lastError ? $lastError->getMessage() : 'unknown'));
    http_response_code(503);

    $appEnv = defined('APP_ENV') ? APP_ENV : (getenv('APP_ENV') ?: 'local');
    if ($appEnv === 'local' || $appEnv === 'beta') {
        exit('<div style="font-family:sans-serif;max-width:600px;margin:50px auto;padding:25px;border:1px solid #f5c6cb;background:#f8d7da;color:#721c24;border-radius:8px;">'
           . '<h3>Database Connection Notice</h3>'
           . '<p>Cannot reach the database. Tried: <code>' . htmlspecialchars(implode(', ', $candidates)) . '</code> on <code>' . htmlspecialchars($host) . '</code>.</p>'
           . '<p><strong>If on Hostinger:</strong> Copy <code>config.sample.php</code> to <code>config.php</code> and set your database name, username, and password created in Hostinger hPanel.</p>'
           . '<p><strong>Error details:</strong> ' . htmlspecialchars($lastError ? $lastError->getMessage() : 'unknown') . '</p>'
           . '</div>');
    }
    exit('Service temporarily unavailable. Please try again shortly.');
}

$pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION'");
