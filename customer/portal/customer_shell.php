<?php
/**
 * Post-activation portal shell.
 *
 * dashboard.php currently holds 13 tabs in one 1,094-line file behind
 * a chain of "if ($active_tab === ...)" blocks, so every tab pays the
 * cost of every other tab's queries. Each tab is its own file under
 * dashboard/ and shares this chrome.
 */

declare(strict_types=1);

require_once __DIR__ . '/topbar.php';

const CUSTOMER_NAV = [
    'index'         => ['Overview',       'fa-gauge-high'],
    'profile'       => ['My profile',     'fa-user'],
    'credit'        => ['Credit & limits','fa-chart-line'],
    'applications'  => ['Applications',   'fa-file-lines'],
    'repayments'    => ['Repayments',     'fa-indian-rupee-sign'],
    'statements'    => ['Statements',     'fa-receipt'],
    'documents'     => ['Documents',      'fa-folder-open'],
    'mandates'      => ['Mandates',       'fa-building-columns'],
    'offers'        => ['Offers',         'fa-tags'],
    'loyalty'       => ['Rewards',        'fa-gift'],
    'referrals'     => ['Referrals',      'fa-user-plus'],
    'notifications' => ['Notifications',  'fa-bell'],
    'support'       => ['Support',        'fa-headset'],
];

/**
 * @param bool $withHeader  false lets a page draw its own header, which the
 *                          overview does so it can show the welcome block.
 */
function portal_shell_open(
    PDO $pdo, array $user, string $activeKey, string $pageTitle, bool $withHeader = true
): void {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([(int)$user['id']]);
    $unread = (int)$stmt->fetchColumn();
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> | INDBIN</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/indbin.css">
</head>
<body data-portal="customer">
<?php render_topbar($pdo, $user, 'portal'); ?>
<div class="layout">

<nav class="portal-nav" aria-label="Customer portal">
    <p style="padding:0 14px 14px;font-size:11px;font-weight:700;color:var(--faint);
              text-transform:uppercase;letter-spacing:.7px;">My account</p>

    <?php foreach (CUSTOMER_NAV as $key => [$label, $icon]):
        $file = $key === 'index' ? 'index.php' : $key . '.php'; ?>
        <a href="<?= CUST_BASE ?>/dashboard/<?= e($file) ?>"
           <?= $key === $activeKey ? 'aria-current="page"' : '' ?>>
            <i class="fa-solid <?= e($icon) ?>" style="width:18px;"></i>
            <span><?= e($label) ?></span>
            <?php if ($key === 'notifications' && $unread > 0): ?>
                <span class="pill is-bad" style="margin-left:auto;"><?= $unread ?></span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>

    <a href="<?= CUST_BASE ?>/../logout.php" style="margin-top:18px;color:var(--muted);">
        <i class="fa-solid fa-arrow-right-from-bracket" style="width:18px;"></i><span>Sign out</span>
    </a>
</nav>

<main class="main" style="align-items:stretch;padding:36px 32px;">
    <?php if ($withHeader): ?>
    <header style="margin-bottom:24px;">
        <h1 style="font-size:24px;font-weight:800;color:var(--ink);"><?= e($pageTitle) ?></h1>
        <p style="font-size:13px;color:var(--muted);margin-top:4px;">
            Signed in as <?= e($user['full_name']) ?>
        </p>
    </header>
    <?php endif; ?>

<?php foreach (take_flashes() as $f): ?>
    <div class="notice is-<?= e($f['type'] === 'error' ? 'bad' : ($f['type'] === 'success' ? 'ok' : $f['type'])) ?>" role="status">
        <?= e($f['message']) ?>
    </div>
<?php endforeach;
}

function portal_shell_close(): void
{
    ?>
</main>
</div>
</body>
</html>
<?php
}
