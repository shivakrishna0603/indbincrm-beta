<?php
declare(strict_types=1);

/* Load the existing database connection and BASE_URL */
require_once __DIR__ . '/../db.php';

/* Clear all session data */
$_SESSION = [];

/* Remove the session cookie */
if (ini_get('session.use_cookies')) {

    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

/* Destroy the session */
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

/* Redirect to the main page */
header('Location: ' . BASE_URL . '/index.php');
exit;