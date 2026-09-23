<?php
/**
 * Role guards.
 *
 * The merchant and agent modules only ever checked isset($_SESSION['user_id']),
 * so any signed-in account could open any module's pages and write to its
 * tables. These load the row fresh each request, which also means a suspended
 * account is stopped immediately rather than at next login.
 */

declare(strict_types=1);

function require_login(PDO $pdo): array
{
    if (empty($_SESSION['user_id'])) {
        redirect(BASE_URL . '/index.php');
    }
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        $_SESSION = [];
        session_destroy();
        redirect(BASE_URL . '/index.php');
    }
    if (in_array($user['account_status'], ['suspended', 'closed'], true)) {
        http_response_code(403);
        exit('This account is not active. Contact support.');
    }

    $_SESSION['role']      = $user['role'];
    $_SESSION['full_name'] = $user['full_name'];
    return $user;
}

function require_role(PDO $pdo, string $role): array
{
    $user = require_login($pdo);
    if ($user['role'] !== $role) {
        http_response_code(403);
        exit('This area is for ' . e($role) . ' accounts.');
    }
    return $user;
}

function require_customer(PDO $pdo): array { return require_role($pdo, 'customer'); }
function require_agent(PDO $pdo): array    { return require_role($pdo, 'agent'); }
function require_merchant(PDO $pdo): array { return require_role($pdo, 'merchant'); }

/** Admin, with the permission flags from the admins table merged in. */
function require_admin(PDO $pdo): array
{
    $user = require_login($pdo);
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        exit('Administrator access required.');
    }
    $stmt = $pdo->prepare("SELECT * FROM admins WHERE user_id = ?");
    $stmt->execute([(int)$user['id']]);
    return array_merge($user, $stmt->fetch() ?: ['designation' => 'Administrator']);
}

/** Fine-grained check, for pages only some reviewers should reach. */
function admin_can(array $admin, string $permission): bool
{
    return (int)($admin[$permission] ?? 0) === 1;
}
