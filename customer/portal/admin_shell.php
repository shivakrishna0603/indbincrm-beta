<?php
/**
 * Back-office shell.
 *
 * The two review pages each carried their own <!DOCTYPE> and their own
 * one-off header, with no way to get from one to the other. A reviewer
 * finishing an eKYC case had to type the credit URL by hand.
 */

declare(strict_types=1);

require_once __DIR__ . '/topbar.php';

const ADMIN_NAV = [
    'home'      => ['Console',          'fa-gauge-high',    '/admin/index.php'],
    'ekyc'      => ['eKYC review',      'fa-shield-halved', '/ekyc/admin_review.php'],
    'documents' => ['Documents',        'fa-folder-open',   '/documents/admin_review.php'],
    'credit'    => ['Credit decisions', 'fa-chart-line',    '/credit/admin_override.php'],
];

/** Counts for the badges. One query, not one per badge. */
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

function admin_shell_open(PDO $pdo, array $admin, string $activeKey, string $pageTitle): void
{
    $counts = admin_queue_counts($pdo);
    $badge  = ['ekyc' => $counts['ekyc'], 'documents' => $counts['docs'], 'credit' => $counts['credit']];
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> | INDBIN back office</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/indbin.css">
</head>
<body data-portal="admin">
<?php render_topbar($pdo, $admin, 'admin'); ?>
<div class="layout">

<nav class="portal-nav" aria-label="Back office">
    <p style="padding:0 14px 14px;font-size:11px;font-weight:700;color:var(--faint);
              text-transform:uppercase;letter-spacing:.7px;">Review queues</p>

    <?php foreach (ADMIN_NAV as $key => [$label, $icon, $href]): ?>
        <a href="<?= CUST_BASE . e($href) ?>" <?= $key === $activeKey ? 'aria-current="page"' : '' ?>>
            <i class="fa-solid <?= e($icon) ?>" style="width:18px;"></i>
            <span><?= e($label) ?></span>
            <?php if (!empty($badge[$key])): ?>
                <span class="pill is-bad" style="margin-left:auto;"><?= (int)$badge[$key] ?></span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>

    <a href="<?= CUST_BASE ?>/../index.php" style="margin-top:18px;color:var(--muted);">
        <i class="fa-solid fa-house" style="width:18px;"></i><span>Main site</span>
    </a>
    <a href="<?= CUST_BASE ?>/../logout.php" style="color:var(--muted);">
        <i class="fa-solid fa-arrow-right-from-bracket" style="width:18px;"></i><span>Sign out</span>
    </a>
</nav>

<main class="main" style="align-items:stretch;padding:36px 32px;">
    <header style="margin-bottom:24px;">
        <h1 style="font-size:24px;font-weight:800;color:var(--ink);"><?= e($pageTitle) ?></h1>
        <p style="font-size:13px;color:var(--muted);margin-top:4px;">
            Reviewing as <?= e($admin['full_name']) ?>
        </p>
    </header>

<?php foreach (take_flashes() as $f): ?>
    <div class="notice is-<?= e($f['type'] === 'error' ? 'bad' : ($f['type'] === 'success' ? 'ok' : $f['type'])) ?>" role="status">
        <?= e($f['message']) ?>
    </div>
<?php endforeach;
}

function admin_shell_close(): void
{
    ?>
</main>
</div>
</body>
</html>
<?php
}
