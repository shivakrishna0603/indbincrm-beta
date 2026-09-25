<?php
declare(strict_types=1);

/**
 * Shared helpers. One definition of each, for all three modules.
 */function e(?string $v): string
{
    return htmlspecialchars($v ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function money(float $amount): string
{
    return 'Ã¢â€šÂ¹' . number_format($amount, 2);
}

function mask_tail(?string $v, int $keep = 4): string
{
    $v = (string)$v;
    return strlen($v) <= $keep
        ? str_repeat('Ã¢â‚¬Â¢', strlen($v))
        : str_repeat('Ã¢â‚¬Â¢', strlen($v) - $keep) . substr($v, -$keep);
}

// ------------------------------------------------------------- flashes
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

// ---------------------------------------------------------------- CSRF
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

function csrf_check(): bool
{
    $sent = $_POST['_token'] ?? '';
    return is_string($sent) && hash_equals($_SESSION['csrf_token'] ?? '', $sent);
}

/** Aborts on mismatch. Call at the top of every POST handler. */
function csrf_verify(): void
{
    if (!csrf_check()) {
        http_response_code(419);
        exit('Your session expired. Reload the page and try again.');
    }
}

// --------------------------------------------------------------- input
function post_str(string $key, int $max = 255): string
{
    return mb_substr(trim((string)($_POST[$key] ?? '')), 0, $max);
}

function post_int(string $key): int
{
    return (int)($_POST[$key] ?? 0);
}

function valid_mobile(string $m): bool { return (bool)preg_match('/^[6-9][0-9]{9}$/', $m); }
function valid_pan(string $p): bool    { return (bool)preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', strtoupper($p)); }
function valid_ifsc(string $i): bool   { return (bool)preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', strtoupper($i)); }
function valid_pincode(string $p): bool{ return (bool)preg_match('/^[1-9][0-9]{5}$/', $p); }

/** Aadhaar Verhoeff checksum. Catches typos a length check misses. */
function valid_aadhaar(string $a): bool
{
    $a = preg_replace('/\s+/', '', $a);
    if (!preg_match('/^[2-9][0-9]{11}$/', $a)) return false;
    $d = [[0,1,2,3,4,5,6,7,8,9],[1,2,3,4,0,6,7,8,9,5],[2,3,4,0,1,7,8,9,5,6],
          [3,4,0,1,2,8,9,5,6,7],[4,0,1,2,3,9,5,6,7,8],[5,9,8,7,6,0,4,3,2,1],
          [6,5,9,8,7,1,0,4,3,2],[7,6,5,9,8,2,1,0,4,3],[8,7,6,5,9,3,2,1,0,4],
          [9,8,7,6,5,4,3,2,1,0]];
    $p = [[0,1,2,3,4,5,6,7,8,9],[1,5,7,6,2,8,3,0,9,4],[5,8,0,3,7,9,6,1,4,2],
          [8,9,1,6,0,4,3,5,2,7],[9,4,5,3,1,2,6,8,7,0],[4,2,8,6,5,7,3,9,0,1],
          [2,7,9,3,8,0,6,4,1,5],[7,0,4,6,9,1,3,2,5,8]];
    $c = 0;
    foreach (array_reverse(str_split($a)) as $i => $digit) {
        $c = $d[$c][$p[($i + 1) % 8][(int)$digit]];
    }
    return $c === 0;
}

/**
 * A random 12-digit number that genuinely passes valid_aadhaar() above -
 * for local-mode test data only. A real Aadhaar number's check digit is
 * one specific value out of ten for any given first 11 digits, so typing
 * digits at random passes only about 10% of the time; this tries all ten
 * against the same validator every submission is checked against, rather
 * than re-deriving the Verhoeff math a second time by hand where a mistake
 * could quietly diverge from the real check.
 */
/**
 * Adds product_catalog.customer_facing if it is missing, seeded so
 * Merchant Business Loan is excluded from customer-facing catalog views
 * and everything else stays included. Three customer pages query this
 * column with no try/catch, so a missing column is an uncaught fatal
 * there, not a silent failure - same reasoning as
 * core/workflow.php's ensure_party_code_sequences().
 */
/**
 * The current product taxonomy: Investments, Digital Banking, Insurance,
 * AI & Human Advisory. Single source of truth for both the self-healing
 * migration below and the fresh-install seed in sql/install_from_scratch.sql
 * and sql/indbin_full.sql - all three need the exact same rows.
 *
 * Columns: code, name, category, description, requires_credit, requires_kfs,
 * min_score, agent_commission_rate, merchant_commission_rate, customer_facing.
 *
 * customer_facing = 0 marks the B2B / platform items a retail customer
 * would never activate for themselves - Merchant Onboarding, Banking API
 * Integration, Fintech CRM & White-Label Solutions, Business Insurance -
 * the same distinction Merchant Business Loan needed before this catalog
 * existed. Only two items are genuinely credit-gated lending products
 * (BNPL Solutions, Loan & Credit Facilitation); everything else in
 * Investments and Insurance is open to any verified customer, same as
 * Health Cover and Term Life were before. Investment Comparison is listed
 * once, under Investments, even though the source taxonomy mentions it
 * under both Investments and AI & Human Advisory - a product_code can only
 * carry one category in this schema, and Investments is where it appears
 * first.
 */
function product_catalog_rows(): array
{
    return [
        // Investments
        ['INV_MF',       'Mutual Funds',                          'investments',     'Curated mutual fund schemes across risk profiles.',        0,1,  0, 1.00, 0.00, 1],
        ['INV_SIP',      'SIP & Wealth Planning',                 'investments',     'A systematic investment plan toward a goal.',               0,1,  0, 1.00, 0.00, 1],
        ['INV_STOCKS',   'Stocks & ETFs',                         'investments',     'Trade listed stocks and exchange-traded funds.',            0,1,  0, 0.50, 0.00, 1],
        ['INV_BONDS',    'Bonds & Fixed Deposits',                'investments',     'Fixed-income options for steady, predictable returns.',     0,1,  0, 0.75, 0.00, 1],
        ['INV_RETIRE',   'Retirement Planning',                   'investments',     'Plan and invest toward retirement.',                        0,1,  0, 1.00, 0.00, 1],
        ['INV_COMPARE',  'Investment Comparison',                 'investments',     'Compare investment options side by side.',                  0,0,  0, 0.00, 0.00, 1],
        ['INV_ADVISORY', 'Financial Advisory Support',            'investments',     'Guidance from a financial advisor.',                        0,0,  0, 0.00, 0.00, 1],

        // Digital Banking
        ['DB_UPI',        'UPI & QR Payments',                    'digital_banking', 'Send, receive and pay using UPI and QR codes.',             0,0,  0, 0.00, 0.50, 1],
        ['DB_AEPS',       'AEPS & Money Transfer',                'digital_banking', 'Aadhaar-enabled payment and money transfer services.',      0,0,  0, 0.50, 0.50, 1],
        ['DB_BNPL',       'BNPL Solutions',                       'digital_banking', 'Split a purchase over instalments.',                        1,1,650, 1.50, 1.00, 1],
        ['DB_LOAN',       'Loan & Credit Facilitation',           'digital_banking', 'Unsecured personal credit.',                                1,1,650, 2.50, 1.50, 1],
        ['DB_SERVICES',   'Digital Banking Services',             'digital_banking', 'Core digital banking account and services.',                0,0,  0, 0.50, 0.50, 1],
        ['DB_MERCHONBRD', 'Merchant Onboarding',                  'digital_banking', 'Onboard as a merchant and accept digital payments.',        0,0,  0, 0.00, 0.00, 0],
        ['DB_API',        'Banking API Integration',              'digital_banking', 'Integrate INDBIN banking services via API.',                0,0,  0, 0.00, 0.00, 0],
        ['DB_WHITELABEL', 'Fintech CRM & White-Label Solutions',  'digital_banking', 'White-label CRM and fintech tooling for partners.',         0,0,  0, 0.00, 0.00, 0],

        // Insurance
        ['INS_LIFECOVER','Life Insurance',                        'insurance',       'Term and whole-life cover.',                                0,1,  0, 5.00, 2.00, 1],
        ['INS_HEALTHCARE','Health Insurance',                     'insurance',       'Individual and family medical cover.',                      0,1,  0, 5.00, 2.00, 1],
        ['INS_MOTOR',    'Motor Insurance',                       'insurance',       'Cover for two-wheelers and cars.',                          0,1,  0, 4.00, 2.00, 1],
        ['INS_ACCIDENT', 'Personal Accident Insurance',           'insurance',       'Cover against accidental injury or death.',                 0,1,  0, 4.00, 2.00, 1],
        ['INS_TRAVEL',   'Travel Insurance',                      'insurance',       'Cover for domestic and international trips.',               0,1,  0, 3.00, 1.00, 1],
        ['INS_BUSINESS', 'Business Insurance',                    'insurance',       'Cover for a merchant\'s shop, stock and liability.',        0,1,  0, 4.00, 2.00, 0],
        ['INS_RENEWAL',  'Policy Renewal & Support',              'insurance',       'Renew an existing policy or get help with one.',            0,0,  0, 1.00, 0.50, 1],
        ['INS_CLAIMS',   'Claims Assistance',                     'insurance',       'Help filing and tracking an insurance claim.',              0,0,  0, 1.00, 0.50, 1],

        // AI & Human Advisory
        ['AI_DISCOVERY', 'AI Financial Product Discovery',        'ai_advisory',     'AI-matched product suggestions based on your profile.',     0,0,  0, 0.00, 0.00, 1],
        ['AI_INSCOMP',   'Insurance Comparison',                  'ai_advisory',     'Compare insurance policies side by side.',                  0,0,  0, 0.00, 0.00, 1],
        ['AI_LOANELIG',  'Loan Eligibility Discovery',            'ai_advisory',     'Check what credit you are likely to qualify for.',          0,0,  0, 0.00, 0.00, 1],
        ['AI_PLANNING',  'Financial Planning Support',            'ai_advisory',     'A structured plan across your financial goals.',            0,0,  0, 0.00, 0.00, 1],
        ['AI_CRM',       'Customer Relationship Management',      'ai_advisory',     'A dedicated relationship manager for your account.',        0,0,  0, 0.00, 0.00, 1],
    ];
}

/** Codes from the catalog this replaced - deactivated below, never deleted (see the migration function for why). */
function legacy_product_catalog_codes(): array
{
    return ['BNPL_STD', 'PAYLATER30', 'PL_SMALL', 'MERCH_BIZ', 'INS_HEALTH', 'INS_LIFE', 'REWARDS', 'UPI_PAY'];
}

/**
 * A category slug's display heading. ucwords(str_replace('_',' ',...))
 * handles every current slug correctly except ai_advisory, which would
 * otherwise render as "Ai Advisory".
 */
function product_category_label(string $slug): string
{
    if ($slug === 'ai_advisory') return 'AI & Human Advisory';
    return ucwords(str_replace('_', ' ', $slug));
}

function ensure_product_catalog_customer_facing(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS product_catalog (
            product_code    VARCHAR(40) PRIMARY KEY,
            product_name    VARCHAR(150) NOT NULL,
            category        VARCHAR(30) NOT NULL,
            description     TEXT NULL,
            requires_credit TINYINT(1) NOT NULL DEFAULT 0,
            requires_kfs    TINYINT(1) NOT NULL DEFAULT 0,
            min_score       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            agent_commission_rate    DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            merchant_commission_rate DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
            is_active       TINYINT(1) NOT NULL DEFAULT 1,
            customer_facing TINYINT(1) NOT NULL DEFAULT 1,
            created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $stmt = $pdo->prepare(
        "SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_catalog'
            AND COLUMN_NAME = 'customer_facing'"
    );
    $stmt->execute();
    if (!$stmt->fetchColumn()) {
        $pdo->exec("ALTER TABLE product_catalog ADD COLUMN customer_facing TINYINT(1) NOT NULL DEFAULT 1");
    }

    // category was an ENUM naming only the original eight products'
    // groupings (bnpl, pay_later, loan, insurance, rewards, payments).
    // Every category this catalog has ever needed meant another
    // ALTER TABLE MODIFY listing every value old and new - the same
    // fragile pattern that bit monthly_turnover before it. VARCHAR means
    // adding a category from here on is a row, not a schema change.
    $stmt = $pdo->prepare(
        "SELECT DATA_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_catalog' AND COLUMN_NAME = 'category'"
    );
    $stmt->execute();
    if (strtolower((string)$stmt->fetchColumn()) === 'enum') {
        $pdo->exec("ALTER TABLE product_catalog MODIFY category VARCHAR(30) NOT NULL");
    }

    // Deactivate the old catalog rather than delete them:
    // product_activations has an unconditional foreign key to
    // product_catalog.product_code with no ON DELETE CASCADE, so any
    // customer who already activated (say) BNPL_STD would make a DELETE
    // fail outright. is_active = 0 removes them from every "what can a
    // customer pick" query without touching that history.
    $legacy = legacy_product_catalog_codes();
    $placeholders = implode(',', array_fill(0, count($legacy), '?'));
    $pdo->prepare("UPDATE product_catalog SET is_active = 0 WHERE product_code IN ($placeholders) AND is_active <> 0")
        ->execute($legacy);

    // INSERT IGNORE never touches a row that already exists, so a site
    // that has genuinely customized a description or a commission rate
    // on one of these keeps it; only a code that is actually absent gets
    // added. Safe to run on every request behind the static guard above.
    $insert = $pdo->prepare(
        "INSERT IGNORE INTO product_catalog
            (product_code, product_name, category, description, requires_credit, requires_kfs,
             min_score, agent_commission_rate, merchant_commission_rate, status, is_active, customer_facing)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', 1, ?)"
    );
    foreach (product_catalog_rows() as $r) {
        [$code, $name, $cat, $desc, $reqCredit, $reqKfs, $minScore, $agentRate, $merchRate, $custFacing] = $r;
        $insert->execute([$code, $name, $cat, $desc, $reqCredit, $reqKfs, $minScore, $agentRate, $merchRate, $custFacing]);
    }

    // Same reasoning as before: not gated on any row being newly created,
    // so a partial or earlier attempt at this migration that left a
    // customer_facing value wrong gets corrected every time this runs,
    // not just the first.
    foreach (product_catalog_rows() as $r) {
        $pdo->prepare("UPDATE product_catalog SET customer_facing = ? WHERE product_code = ? AND customer_facing <> ?")
            ->execute([$r[9], $r[0], $r[9]]);
    }
}

function ensure_agreement_signatures_columns(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $has = function (string $col) use ($pdo): bool {
        $s = $pdo->prepare(
            "SELECT 1 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agreement_signatures' AND COLUMN_NAME = ?"
        );
        $s->execute([$col]);
        return (bool)$s->fetchColumn();
    };

    if (!$has('agreement_version') && $has('version')) {
        $pdo->exec("ALTER TABLE agreement_signatures CHANGE version agreement_version VARCHAR(20) NOT NULL DEFAULT 'v1.0'");
    }
    if (!$has('signature_image_path') && $has('signature_path')) {
        $pdo->exec("ALTER TABLE agreement_signatures CHANGE signature_path signature_image_path VARCHAR(255) NULL");
    }
    if (!$has('credit_limit_at_signing')) {
        $pdo->exec("ALTER TABLE agreement_signatures ADD COLUMN credit_limit_at_signing DECIMAL(12,2) NULL");
    }
    if (!$has('user_agent')) {
        $pdo->exec("ALTER TABLE agreement_signatures ADD COLUMN user_agent VARCHAR(255) NULL");
    }
    if ($has('ip_address')) {
        $pdo->exec("ALTER TABLE agreement_signatures MODIFY ip_address VARCHAR(45) NULL");
    }
}

function ensure_settlement_accounts_nullable(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $s = $pdo->prepare(
        "SELECT IS_NULLABLE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'settlement_accounts' AND COLUMN_NAME = 'bank_name'"
    );
    $s->execute();
    if (strtoupper((string)$s->fetchColumn()) === 'NO') {
        $pdo->exec("ALTER TABLE settlement_accounts MODIFY bank_name VARCHAR(150) NULL");
    }
}

function ensure_monthly_turnover_enum(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $s = $pdo->prepare(
        "SELECT DATA_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'business_verifications' AND COLUMN_NAME = 'monthly_turnover'"
    );
    $s->execute();
    if (strtolower((string)$s->fetchColumn()) !== 'enum') {
        $pdo->exec("ALTER TABLE business_verifications MODIFY monthly_turnover VARCHAR(20) NULL");
        $pdo->exec(
            "UPDATE business_verifications SET monthly_turnover = NULL
              WHERE monthly_turnover IS NOT NULL
                AND monthly_turnover NOT IN ('below_1L','1L_5L','5L_25L','25L_1Cr','above_1Cr')"
        );
        $pdo->exec(
            "ALTER TABLE business_verifications MODIFY monthly_turnover
                ENUM('below_1L','1L_5L','5L_25L','25L_1Cr','above_1Cr') NULL"
        );
    }
}

/**
 * Every schema change this application has ever needed, run once and only
 * once per database, on the very next page load after these files are
 * deployed - not dependent on anyone separately running an installer.
 * The fast path, once migrated, is the single SELECT below: a few
 * milliseconds on every request from then on. Call this from
 * core/bootstrap.php, after $pdo exists.
 */
function ensure_schema_current(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        $pdo->query("SELECT 1 FROM schema_migrations WHERE id = 1")->fetchColumn();
        return; // marker row present - already fully migrated
    } catch (PDOException $e) {
        // schema_migrations does not exist yet: fall through and migrate.
    }

    try {
        ensure_party_code_sequences($pdo);
        ensure_product_catalog_customer_facing($pdo);
        ensure_agreement_signatures_columns($pdo);
        ensure_settlement_accounts_nullable($pdo);
        ensure_monthly_turnover_enum($pdo);

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS schema_migrations (
                id INT UNSIGNED PRIMARY KEY, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );
        $pdo->exec("INSERT IGNORE INTO schema_migrations (id) VALUES (1)");
    } catch (Throwable $e) {
        error_log('ensure_schema_current caught: ' . $e->getMessage());
    }
}

function generate_valid_aadhaar(): string
{
    $prefix = (string)random_int(2, 9);
    for ($i = 0; $i < 10; $i++) { $prefix .= (string)random_int(0, 9); }
    for ($check = 0; $check <= 9; $check++) {
        $candidate = $prefix . (string)$check;
        if (valid_aadhaar($candidate)) return $candidate;
    }
    // Unreachable given the Verhoeff table always has exactly one valid
    // check digit per prefix, but a hardcoded fallback beats a fatal.
    return '234567890121';
}

// ----------------------------------------------------------- PII at rest
function pii_encrypt(string $plain): ?string
{
    if (PII_KEY === '') { error_log('pii_encrypt: no key'); return null; }
    $iv = random_bytes(12); $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', hex2bin(PII_KEY), OPENSSL_RAW_DATA, $iv, $tag);
    return $ct === false ? null : $iv . $tag . $ct;
}

function pii_decrypt(?string $blob): ?string
{
    if ($blob === null || PII_KEY === '' || strlen($blob) < 29) return null;
    $out = openssl_decrypt(substr($blob, 28), 'aes-256-gcm', hex2bin(PII_KEY),
                           OPENSSL_RAW_DATA, substr($blob, 0, 12), substr($blob, 12, 16));
    return $out === false ? null : $out;
}

function pii_hash(string $plain): string
{
    return hash('sha256', strtoupper(trim($plain)) . '|' . PII_KEY);
}

// ------------------------------------------------------ notify and audit
function notify(PDO $pdo, int $userId, string $title, string $message,
                string $channel = 'inapp', ?string $link = null): void
{
    try {
        $pdo->prepare("INSERT INTO notifications (user_id, channel, title, message, link)
                       VALUES (?,?,?,?,?)")
            ->execute([$userId, $channel, $title, $message, $link]);
    } catch (PDOException $e) {
        error_log('notify failed: ' . $e->getMessage());
    }
}

function audit_log(PDO $pdo, string $action, string $entity, ?string $entityId = null,
                   ?array $before = null, ?array $after = null, ?int $subjectId = null): void
{
    try {
        $pdo->prepare(
            "INSERT INTO audit_logs (actor_id, actor_role, subject_id, action, entity, entity_id,
                                     before_json, after_json, request_ip, user_agent)
             VALUES (?,?,?,?,?,?,?,?,?,?)"
        )->execute([
            $_SESSION['user_id'] ?? null,
            $_SESSION['role'] ?? null,
            $subjectId ?? ($_SESSION['user_id'] ?? null),
            $action, $entity, $entityId,
            $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
            $after  !== null ? json_encode($after,  JSON_UNESCAPED_UNICODE) : null,
            client_ip_binary(),
            mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (PDOException $e) {
        // An audit write must never break the request it is recording.
        error_log('audit_log failed: ' . $e->getMessage());
    }
}

function client_ip_binary(): ?string
{
    $packed = @inet_pton($_SERVER['REMOTE_ADDR'] ?? '');
    return $packed === false ? null : $packed;
}

// -------------------------------------------------------------- loyalty
function loyalty_balance(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(points),0) FROM loyalty_ledger WHERE user_id = ?");
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

function loyalty_post(PDO $pdo, int $userId, string $type, int $points, ?string $ref = null): int
{
    $balance = loyalty_balance($pdo, $userId) + $points;
    $pdo->prepare("INSERT INTO loyalty_ledger (user_id, entry_type, points, balance_after, reference, expires_on)
                   VALUES (?,?,?,?,?, DATE_ADD(CURDATE(), INTERVAL 24 MONTH))")
        ->execute([$userId, $type, $points, $balance, $ref]);
    return $balance;
}

// ------------------------------------------------------------------ OTP
function otp_issue(PDO $pdo, ?int $userId, string $purpose, string $destination, int $ttl = 300): ?string
{
    $ttl = max(60, min(1800, $ttl));   // bounded int, safe to interpolate below

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM otp_verifications
                            WHERE destination = ? AND purpose = ?
                              AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    $stmt->execute([$destination, $purpose]);
    if ((int)$stmt->fetchColumn() >= 3) {
        return null;
    }

    $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $pdo->prepare(
        "INSERT INTO otp_verifications (user_id, purpose, destination, otp_hash, expires_at, request_ip)
         VALUES (?,?,?,?, DATE_ADD(NOW(), INTERVAL {$ttl} SECOND), ?)"
    )->execute([$userId, $purpose, $destination, hash('sha256', $otp . PII_KEY), client_ip_binary()]);

    if (APP_ENV === 'local') {
        error_log("OTP {$purpose} -> {$destination}: {$otp}");
    }
    return $otp;
}

function otp_verify(PDO $pdo, string $purpose, string $destination, string $otp): bool
{
    $stmt = $pdo->prepare(
        "SELECT id, otp_hash, attempts, max_attempts FROM otp_verifications
          WHERE destination = ? AND purpose = ? AND consumed_at IS NULL AND expires_at > NOW()
          ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$destination, $purpose]);
    $row = $stmt->fetch();
    if (!$row || (int)$row['attempts'] >= (int)$row['max_attempts']) {
        return false;
    }

    $pdo->prepare("UPDATE otp_verifications SET attempts = attempts + 1 WHERE id = ?")
        ->execute([$row['id']]);

    if (!hash_equals($row['otp_hash'], hash('sha256', $otp . PII_KEY))) {
        return false;
    }
    $pdo->prepare("UPDATE otp_verifications SET consumed_at = NOW() WHERE id = ?")
        ->execute([$row['id']]);
    return true;
}

// --------------------------------------------------------------- consent
function record_consent(PDO $pdo, int $userId, string $type, bool $granted,
                        string $policyVersion = '1.0', ?string $artifactRef = null): int
{
    $pdo->prepare(
        "INSERT INTO consents (user_id, consent_type, artifact_ref, policy_version,
                               granted, channel, request_ip, user_agent)
         VALUES (?,?,?,?,?, 'web', ?, ?)"
    )->execute([
        $userId, $type, $artifactRef, $policyVersion, $granted ? 1 : 0,
        client_ip_binary(), mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]);
    return (int)$pdo->lastInsertId();
}
