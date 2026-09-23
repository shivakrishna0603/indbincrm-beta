<?php
/**
 * INDBIN admin console shell.
 * Left rail, top bar, and the nav from the reference layout.
 */

declare(strict_types=1);

const ADMIN_MENU = [
    ['key' => 'dashboard',    'label' => 'Dashboard',       'icon' => 'fa-gauge-high',    'href' => '/admin/index.php'],
    ['key' => 'onboarding',   'label' => 'Onboarding',      'icon' => 'fa-user-plus',     'href' => '/admin/onboarding.php'],
    ['key' => 'customers',    'label' => 'Customers',       'icon' => 'fa-user',          'href' => '/admin/onboarding.php?role=customer'],
    ['key' => 'ekyc',         'label' => 'Document Verification', 'icon' => 'fa-file-shield', 'href' => '/customer/ekyc/admin_review.php',
     'children' => [
         ['label' => 'Console',          'href' => '/customer/admin/index.php'],
         ['label' => 'eKYC review',      'href' => '/customer/ekyc/admin_review.php'],
         ['label' => 'Documents',        'href' => '/customer/documents/admin_review.php'],
         ['label' => 'Credit decisions', 'href' => '/customer/credit/admin_override.php'],
     ]],
    ['key' => 'agents',       'label' => 'Agents',          'icon' => 'fa-user-tie',      'href' => '/admin/onboarding.php?role=agent'],
    ['key' => 'commissions',  'label' => 'Agent Settings',    'icon' => 'fa-user-gear',     'href' => '/admin/agent_settings.php'],
    ['key' => 'merchants',    'label' => 'Merchants',       'icon' => 'fa-store',         'href' => '/admin/onboarding.php?role=merchant'],
    ['key' => 'leads',        'label' => 'Leads',           'icon' => 'fa-bullseye',      'href' => '/admin/leads.php'],
    ['key' => 'applications', 'label' => 'Applications',    'icon' => 'fa-file-lines',    'href' => '/admin/applications.php'],
    ['key' => 'approvals',    'label' => 'Approvals',       'icon' => 'fa-circle-check',  'href' => '/admin/approvals.php'],
    ['key' => 'reports',      'label' => 'Reports',         'icon' => 'fa-chart-column',  'href' => '/admin/reports.php'],
    ['key' => 'transactions', 'label' => 'Transactions',    'icon' => 'fa-arrow-right-arrow-left', 'href' => '/admin/transactions.php'],
    ['key' => 'payouts',      'label' => 'Payouts',         'icon' => 'fa-indian-rupee-sign',      'href' => '/admin/payouts.php'],
    ['key' => 'support',      'label' => 'Support tickets', 'icon' => 'fa-headset',       'href' => '/admin/support.php'],
    ['key' => 'audit',        'label' => 'Audit logs',      'icon' => 'fa-clipboard-list','href' => '/admin/audit.php'],
];

/**
 * Counts for the Document Verification badges. One query, not one per
 * badge. Moved here from customer/portal/admin_shell.php (the old,
 * separately-branded back office shell) so it is available to this shell
 * too - customer/admin/index.php (the Console page) also calls this
 * directly, not just through the badge rendering below.
 */
function admin_queue_counts(PDO $pdo): array
{
    $row = $pdo->query(
        "SELECT
            (SELECT COUNT(*) FROM kyc_details
              WHERE status IN ('pending','resubmit'))                       AS ekyc,
            (SELECT COUNT(*) FROM customer_documents
              WHERE status = 'pending' AND is_current = 1)                  AS docs,
            (SELECT COUNT(*) FROM credit_evaluations
              WHERE is_current = 1 AND decision = 'manual_review')          AS credit,
            (SELECT COUNT(*) FROM users
              WHERE role = 'customer' AND kyc_status = 'approved'
                AND customer_code IS NULL)                                  AS stuck"
    )->fetch();

    return [
        'ekyc'   => (int)($row['ekyc']   ?? 0),
        'docs'   => (int)($row['docs']   ?? 0),
        'credit' => (int)($row['credit'] ?? 0),
        'stuck'  => (int)($row['stuck']  ?? 0),
    ];
}

function admin_pending_total(PDO $pdo): int
{
    $row = $pdo->query(
        "SELECT
           (SELECT COUNT(*) FROM kyc_details WHERE status IN ('pending','resubmit')) +
           (SELECT COUNT(*) FROM kyc_documents WHERE status = 'pending') +
           (SELECT COUNT(*) FROM business_verifications WHERE status = 'pending') +
           (SELECT COUNT(*) FROM applications WHERE status = 'under_review') AS total"
    )->fetch();
    return (int)($row['total'] ?? 0);
}

function admin_shell_open(PDO $pdo, array $admin, string $active, string $title): void
{
    $pending  = admin_pending_total($pdo);
    $parts    = preg_split('/\s+/', trim((string)$admin['full_name'])) ?: [];
    $initials = strtoupper(mb_substr($parts[0] ?? '?', 0, 1)
              . (count($parts) > 1 ? mb_substr(end($parts), 0, 1) : ''));

    // Only fetched when the Document Verification children are actually
    // showing - nothing else on this rail uses these counts.
    $childBadge = [];
    if ($active === 'ekyc') {
        $q = admin_queue_counts($pdo);
        $childBadge = [
            '/customer/ekyc/admin_review.php'      => $q['ekyc'],
            '/customer/documents/admin_review.php' => $q['docs'],
            '/customer/credit/admin_override.php'  => $q['credit'],
        ];
    }
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> | INDBIN CRM</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/indbin.css">
</head>
<body class="admin-body">

<header class="topbar">
    <div class="topbar-left">
        <button type="button" class="nav-toggle" aria-label="Toggle navigation"
                onclick="document.body.classList.toggle('nav-collapsed')">
            <i class="fa-solid fa-bars"></i>
        </button>
        <a class="brand" href="<?= BASE_URL ?>/admin/index.php" aria-label="INDBIN Fintech Services LLP">
            <span class="brand-name" aria-hidden="true"
                ><span class="ind">IND</span><span class="tri">B</span><span class="bin">IN</span></span>
            <span class="brand-sub" aria-hidden="true">Fintech Services LLP</span>
        </a>
    </div>

    <div class="topbar-right">
        <a class="bell" href="<?= BASE_URL ?>/admin/approvals.php" aria-label="<?= $pending ?> items pending">
            <i class="fa-regular fa-bell"></i>
            <?php if ($pending > 0): ?>
                <span class="bell-count"><?= $pending > 99 ? '99+' : $pending ?></span>
            <?php endif; ?>
        </a>

        <span class="org-chip">INDBIN FINTECH SERVICES LLP</span>

        <details class="user-menu">
            <summary>
                <span class="avatar"><?= e($initials) ?></span>
                <span class="who">
                    <strong><?= e($admin['full_name']) ?></strong>
                    <small><?= e($admin['designation'] ?? 'Administrator') ?></small>
                </span>
                <i class="fa-solid fa-chevron-down" style="font-size:11px;color:var(--faint);"></i>
            </summary>
            <div class="user-dropdown">
                <a href="<?= BASE_URL ?>/admin/audit.php"><i class="fa-solid fa-clipboard-list"></i> Audit logs</a>
                <a href="<?= BASE_URL ?>/index.php"><i class="fa-solid fa-house"></i> Main site</a>
                <a href="<?= BASE_URL ?>/logout.php" class="is-danger">
                    <i class="fa-solid fa-arrow-right-from-bracket"></i> Sign out</a>
            </div>
        </details>
    </div>
</header>

<div class="layout">
<nav class="admin-rail" aria-label="Admin">
    <?php foreach (ADMIN_MENU as $m): ?>
        <a href="<?= BASE_URL . e($m['href']) ?>" <?= $m['key'] === $active ? 'aria-current="page"' : '' ?>>
            <i class="fa-solid <?= e($m['icon']) ?>"></i><span><?= e($m['label']) ?></span>
        </a>
        <?php if ($m['key'] === $active && !empty($m['children'])): ?>
            <div class="rail-children">
                <?php foreach ($m['children'] as $c):
                    $isCurrentChild = ($_SERVER['SCRIPT_NAME'] ?? '') === $c['href']; ?>
                    <a href="<?= BASE_URL . e($c['href']) ?>" <?= $isCurrentChild ? 'aria-current="page"' : '' ?>>
                        &rsaquo; <?= e($c['label']) ?>
                        <?php if (!empty($childBadge[$c['href']])): ?>
                            <span class="pill is-bad" style="margin-left:6px;"><?= (int)$childBadge[$c['href']] ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>

    <div class="rail-promo">
        <span class="brand-name" style="font-size:16px;"
            ><span style="color:#fff;">IND</span><span class="tri">B</span><span style="color:#7dd3a0;">IN</span></span>
        <p>Grow retail on credit digitally</p>
    </div>
</nav>

<main class="admin-main">
<?php foreach (take_flashes() as $f): ?>
    <div class="notice is-<?= e($f['type'] === 'error' ? 'bad' : ($f['type'] === 'success' ? 'ok' : $f['type'])) ?>">
        <?= e($f['message']) ?>
    </div>
<?php endforeach;
}

function admin_shell_close(): void
{
    ?>
    <footer class="admin-footer">
        <span>&copy; <?= date('Y') ?> INDBIN Fintech Services LLP. All rights reserved.</span>
        <span>Version 3.0 &nbsp;|&nbsp; Support &nbsp;|&nbsp; Privacy Policy &nbsp;|&nbsp; Terms &amp; Conditions</span>
    </footer>
</main>
</div>
</body>
</html>
<?php
}
