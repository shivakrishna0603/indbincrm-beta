<?php
declare(strict_types=1);

/**
 * Authentication for every role.
 *
 * One registration path and one login path for customers, agents, merchants
 * and admins. The three modules each had their own copy; they had drifted,
 * and only one of them set $_SESSION['role'].
 */const SIGNUP_ROLES = ['customer', 'agent', 'merchant'];
const LOGIN_ROLES  = ['customer', 'agent', 'merchant', 'admin'];

/**
 * Where a signed-in account belongs.
 *
 * The merchant and agent modules used to each carry their own index.php to
 * work this out, which meant a fourth and fifth copy of the routing rules.
 * landing_url() below does it from the user row, and this stays for the
 * cases where only the role is known.
 */
function role_home(string $role): string
{
    return match (strtolower($role)) {
        'admin'    => BASE_URL . '/admin/index.php',
        'merchant' => BASE_URL . '/merchant/ekyc/index.php',
        'agent'    => BASE_URL . '/agent/ekyc/index.php',
        default    => BASE_URL . '/customer/index.php',
    };
}

/**
 * The precise page an account should land on, given its current state.
 * Used right after login and registration, so nobody is dropped on a step
 * they have already finished or one they cannot reach yet.
 */
function landing_url(array $user): string
{
    $role   = strtolower((string)$user['role']);
    $kyc    = (string)($user['kyc_status'] ?? 'not_started');
    $biz    = (string)($user['business_verification_status'] ?? 'not_started');
    $active = ($user['account_status'] ?? '') === 'active';

    if ($role === 'admin') {
        return BASE_URL . '/admin/index.php';
    }

    if ($role === 'customer') {
        // customer/index.php already resolves the seven-step position.
        return BASE_URL . '/customer/index.php';
    }

    if ($role === 'merchant') {
        if ($active && $kyc === 'approved' && $biz === 'approved') {
            return BASE_URL . '/merchant/dashboard/index.php';
        }
        return match (true) {
            in_array($kyc, ['not_started', 'not_submitted', 'rejected'], true)
                => BASE_URL . '/merchant/ekyc/upload.php',
            $kyc !== 'approved'
                => BASE_URL . '/merchant/ekyc/status.php',
            in_array($biz, ['not_started', 'not_submitted', 'rejected'], true)
                => BASE_URL . '/merchant/business/upload.php',
            $biz !== 'approved'
                => BASE_URL . '/merchant/business/status.php',
            default
                => BASE_URL . '/merchant/credit/status.php',
        };
    }

    // agent
    if ($active && $kyc === 'approved') {
        return BASE_URL . '/agent/dashboard/index.php';
    }
    return match (true) {
        in_array($kyc, ['not_started', 'not_submitted', 'rejected'], true)
            => BASE_URL . '/agent/ekyc/upload.php',
        $kyc !== 'approved'
            => BASE_URL . '/agent/ekyc/status.php',
        default
            => BASE_URL . '/agent/background/index.php',
    };
}

/** Where to send someone straight after registering. */
function role_onboarding_link(string $role): string
{
    return role_home($role);
}

function auth_redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function auth_is_logged_in(): bool
{
    return !empty($_SESSION['user_id']) && !empty($_SESSION['role']);
}

function auth_user(PDO $pdo): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

/**
 * Create an account.
 *
 * @return array{ok:bool, errors:string[], user_id?:int, message?:string}
 */
function auth_register(PDO $pdo, array $in): array
{
    $errors = [];

    $role     = trim((string)($in['role'] ?? ''));
    $fullName = trim((string)($in['full_name'] ?? ''));
    $email    = filter_var(trim((string)($in['email'] ?? '')), FILTER_VALIDATE_EMAIL);
    $mobile   = preg_replace('/\D/', '', (string)($in['mobile'] ?? ''));
    $password = (string)($in['password'] ?? '');
    $bizName  = trim((string)($in['business_name'] ?? ''));
    $bizType  = trim((string)($in['business_type'] ?? ''));
    $refCode  = strtoupper(trim((string)($in['referral_code'] ?? '')));

    if (!in_array($role, SIGNUP_ROLES, true))  $errors[] = 'Choose a valid account type.';
    if ($fullName === '')                      $errors[] = 'Enter your full name.';
    if (!$email)                               $errors[] = 'Enter a valid email address.';
    // mb_strlen, not strlen: strlen counts bytes, so an accented password
    // passed the old check on fewer characters than intended.
    if (mb_strlen($password) < 8)              $errors[] = 'Password must be at least 8 characters.';
    if ($mobile !== '' && !valid_mobile($mobile)) $errors[] = 'Enter a 10-digit Indian mobile number.';

    if ($role === 'merchant') {
        if ($bizName === '') $errors[] = 'Enter your business name.';
        if ($bizType === '') $errors[] = 'Choose a business type.';
    }

    if ($errors) {
        return ['ok' => false, 'errors' => $errors];
    }

    // An agent signing up under a referral code joins that agent's downline.
    $uplineId = null;
    if ($role === 'agent' && $refCode !== '') {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE referral_code = ? AND role = 'agent'");
        $stmt->execute([$refCode]);
        $uplineId = $stmt->fetchColumn() ?: null;
        if (!$uplineId) {
            return ['ok' => false, 'errors' => ['That referral code does not match an agent.']];
        }
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "INSERT INTO users (role, full_name, email, mobile, password_hash,
                                business_name, business_type, upline_id, account_status)
             VALUES (?,?,?,?,?,?,?,?, 'registered')"
        )->execute([
            $role, $fullName, $email, $mobile ?: null,
            password_hash($password, PASSWORD_DEFAULT),
            $role === 'merchant' ? $bizName : null,
            $role === 'merchant' ? $bizType : null,
            $uplineId,
        ]);
        $userId = (int)$pdo->lastInsertId();

        if ($role === 'agent') {
            // Referral code and wallet exist from day one, so nothing has to
            // check whether they were created later. The agent ID itself is
            // different: it is only minted once, by issue_party_code() when
            // the agent reaches Hierarchy & Code Creation, so agent_id
            // starts null here rather than holding a placeholder that page
            // then has to detect and replace.
            $pdo->prepare("UPDATE users SET referral_code = ? WHERE id = ?")
                ->execute(['AGT' . str_pad((string)$userId, 4, '0', STR_PAD_LEFT), $userId]);
            $pdo->prepare("INSERT IGNORE INTO agent_wallet (user_id) VALUES (?)")->execute([$userId]);
            $pdo->prepare(
                "INSERT INTO agent_hierarchy (user_id, agent_id, registered_name, email, referral_code, upline_id)
                 VALUES (?, NULL, ?, ?, ?, ?)"
            )->execute([
                $userId, $fullName, $email,
                'AGT' . str_pad((string)$userId, 4, '0', STR_PAD_LEFT), $uplineId,
            ]);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // 23000 is the duplicate-key class. The unique index is what actually
        // prevents two accounts sharing an email; the old SELECT-then-INSERT
        // let two simultaneous signups both through.
        if ((int)$e->getCode() === 23000) {
            return ['ok' => false, 'errors' => ['An account with this email or mobile already exists.']];
        }
        error_log('auth_register: ' . $e->getMessage());
        return ['ok' => false, 'errors' => ['Registration could not complete. Try again.']];
    }

    session_regenerate_id(true);
    $_SESSION['user_id']   = $userId;
    $_SESSION['role']      = $role;
    $_SESSION['full_name'] = $fullName;
    $_SESSION['last_seen'] = time();

    audit_log($pdo, 'account.registered', 'users', (string)$userId, null, ['role' => $role], $userId);

    if ($uplineId) {
        notify($pdo, (int)$uplineId, 'New agent in your downline', $fullName . ' signed up with your code.');
    }

    return ['ok' => true, 'errors' => [], 'user_id' => $userId,
            'message' => ucfirst($role) . ' account created.'];
}

/**
 * Sign in by email or mobile.
 *
 * @return array{ok:bool, error?:string, redirect?:string}
 */
function auth_login(PDO $pdo, string $identifier, string $password, string $role): array
{
    $identifier = trim($identifier);

    if ($identifier === '' || $password === '') {
        return ['ok' => false, 'error' => 'Enter your email or mobile and your password.'];
    }
    if (!in_array($role, LOGIN_ROLES, true)) {
        return ['ok' => false, 'error' => 'Choose a valid account type.'];
    }

    $key = 'login_fails';
    if (($_SESSION[$key]['count'] ?? 0) >= 5 && (time() - ($_SESSION[$key]['at'] ?? 0)) < 300) {
        return ['ok' => false, 'error' => 'Too many attempts. Wait five minutes and try again.'];
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE (email = ? OR mobile = ?) AND role = ? LIMIT 1");
    $stmt->execute([$identifier, $identifier, $role]);
    $user = $stmt->fetch();

    // Hash even with no match, so a wrong address and a wrong password take
    // the same time and the form cannot be used to enumerate accounts.
    $hash = $user['password_hash']
        ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ql6m';

    if (!password_verify($password, $hash) || !$user) {
        $_SESSION[$key] = ['count' => ($_SESSION[$key]['count'] ?? 0) + 1, 'at' => time()];
        return ['ok' => false, 'error' => 'Those details do not match an account.'];
    }

    if (in_array($user['account_status'], ['suspended', 'closed'], true)) {
        return ['ok' => false, 'error' => 'This account is not active. Contact support.'];
    }

    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
            ->execute([password_hash($password, PASSWORD_DEFAULT), (int)$user['id']]);
    }

    unset($_SESSION[$key]);
    session_regenerate_id(true);

    $_SESSION['user_id']   = (int)$user['id'];
    $_SESSION['role']      = $user['role'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['last_seen'] = time();

    $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")->execute([(int)$user['id']]);
    audit_log($pdo, 'account.login', 'users', (string)$user['id'], null, null, (int)$user['id']);

    return ['ok' => true, 'redirect' => landing_url($user)];
}
