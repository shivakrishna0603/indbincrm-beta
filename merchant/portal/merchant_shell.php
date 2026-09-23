<?php
/*
    ============================================
    MERCHANT DASHBOARD SHELL — post-onboarding
    ============================================
    This is the chrome around every page under merchant/dashboard/. It was
    previously its own layout with its own inline CSS, which made the
    merchant portal look like a different product from the customer one.

    It now renders on the shared INDBIN design system (assets/indbin.css).
    body[data-portal="merchant"] turns the brand accent orange, so the
    top bar, sidebar and page cards all match the customer portal while
    staying unmistakably the merchant journey.

    Usage (call sites unchanged; $withHeader is optional):
        require_once __DIR__ . '/../portal/shell.php';          // render_shell_close()
        require_once __DIR__ . '/../portal/merchant_shell.php';
        render_merchant_shell_open($pdo, $user_id, 'services', 'Manage Products & Services');
        // ... page content ...
        render_shell_close();

    render_shell_close() (from portal/shell.php -> onboarding_shell_render_close())
    emits </main></div></body></html>, which closes what this opens.
*/

declare(strict_types=1);

function render_merchant_shell_open(
    PDO $pdo,
    int $user_id,
    string $activeKey,
    string $pageTitle,
    bool $withHeader = true
): void {
    $stmt = $pdo->prepare(
        "SELECT full_name, business_name, party_code, merchant_id FROM users WHERE id = ?"
    );
    $stmt->execute([$user_id]);
    $row = $stmt->fetch() ?: [];

    $fullName  = (string)($row['full_name'] ?? '');
    $business  = (string)($row['business_name'] ?? '');
    $partyCode = (string)($row['party_code'] ?? '');
    $idValue   = $partyCode !== '' ? $partyCode : (string)($row['merchant_id'] ?? '');

    // Initials avatar. full_name is preferred, business_name is the fallback,
    // so a merchant who never set a personal name still gets an avatar.
    $parts = preg_split('/\s+/', trim($fullName !== '' ? $fullName : $business)) ?: [];
    $initials = strtoupper(
        mb_substr($parts[0] ?? 'M', 0, 1)
        . (count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '')
    );
    $displayName = $fullName !== '' ? $fullName : ($business !== '' ? $business : 'Merchant');

    // Minted once here so every nav item that wants a badge reads the same query.
    $navBadges = [];
    $countSql  = [
        'leads'   => "SELECT COUNT(*) FROM leads WHERE merchant_id = ? AND status IN ('new','assigned')",
        'quotes'  => "SELECT COUNT(*) FROM quotes WHERE merchant_id = ? AND status = 'sent'",
        'crm'     => "SELECT COUNT(*) FROM applications WHERE merchant_id = ? AND status IN ('submitted','under_review','approved')",
        'support' => "SELECT COUNT(*) FROM support_tickets WHERE merchant_id = ? AND status IN ('open','in_progress')",
    ];
    foreach ($countSql as $key => $sql) {
        $cs = $pdo->prepare($sql);
        $cs->execute([$user_id]);
        $navBadges[$key] = (int)$cs->fetchColumn();
    }

    // Unread in-app notifications for the topbar bell.
    $unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $unreadStmt->execute([$user_id]);
    $unreadCount = (int)$unreadStmt->fetchColumn();

    $navItems = [
        'dashboard'    => ['Dashboard',                       'fa-table-columns',        '../dashboard/index.php'],
        'services'     => ['Manage Products / Services',      'fa-boxes-stacked',        '../dashboard/services.php'],
        'orders'       => ['Payments & Transactions',         'fa-receipt',              '../dashboard/orders.php'],
        'leads'        => ['Leads & Customer Requests',       'fa-inbox',                '../dashboard/leads.php'],
        'quotes'       => ['Provide Information / Quotes',    'fa-file-invoice-dollar',  '../dashboard/quotes.php'],
        'crm'          => ['Applications & Status Tracking',  'fa-arrow-right-arrow-left','../dashboard/applications.php'],
        'reports'      => ['Analytics & Business Reports',    'fa-chart-line',           '../dashboard/analytics.php'],
        'self_service' => ['Apply for Loan / Insurance',      'fa-file-signature',       '../dashboard/self_service.php'],
        'support'      => ['Customer Support & After Sales',  'fa-headset',              '../dashboard/support.php'],
    ];

    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle) ?> | INDBIN</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/indbin.css">
<style>
    /* ---- portal chrome tweaks for the merchant journey ---- */
    .merchant-nav-heading {
        padding: 0 14px 14px; font-size: 11px; font-weight: 700;
        color: var(--faint); text-transform: uppercase; letter-spacing: .7px;
    }
    .nav-badge {
        margin-left: auto; min-width: 20px; height: 20px; padding: 0 6px;
        border-radius: 999px; background: var(--brand-soft); color: var(--brand-deep);
        font-size: 11px; font-weight: 800; display: inline-flex;
        align-items: center; justify-content: center; flex-shrink: 0;
    }

    .notif-link {
        position: relative; width: 40px; height: 40px; border-radius: 10px;
        display: inline-flex; align-items: center; justify-content: center;
        color: var(--muted); text-decoration: none; transition: background .15s ease, color .15s ease;
    }
    .notif-link:hover { background: var(--canvas); color: var(--brand); }
    .notif-badge {
        position: absolute; top: 2px; right: 2px; min-width: 17px; height: 17px; padding: 0 4px;
        border-radius: 999px; background: var(--brand); color: #fff;
        font-size: 10px; font-weight: 800; display: inline-flex;
        align-items: center; justify-content: center; border: 2px solid var(--surface);
    }
    .nav-rail-card {
        margin: 18px 8px 0; padding: 14px; border-radius: 14px;
        background: var(--canvas); border: 1px solid var(--line);
    }
    .nav-rail-card p { font-size: 12px; line-height: 1.55; color: var(--muted); }
    .nav-rail-card a { font-size: 12.5px; font-weight: 700; color: var(--brand); text-decoration: none; }

    .main-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 14px; flex-wrap: wrap; }
    .main-flash { width: 100%; }

    /* shared attention banners used across the dashboard */
    .banner-note {
        display: flex; justify-content: space-between; align-items: center;
        gap: 18px; flex-wrap: wrap;
        background: var(--brand-soft); border: 1px solid var(--brand-soft);
        border-radius: 14px; padding: 16px 22px; margin-bottom: 22px;
    }
    .banner-note .label {
        font-size: 11px; font-weight: 800; letter-spacing: .6px;
        text-transform: uppercase; color: var(--brand-deep);
        display: flex; align-items: center; gap: 7px;
    }
    .banner-note .amount { font-size: 16px; font-weight: 800; color: var(--ink); margin-top: 6px; }
    .banner-note .due-on { font-size: 12px; color: var(--muted); margin-top: 3px; }
    .banner-note .btn { min-height: 40px; }

    @media (max-width: 700px) {
        .portal-nav { width: 100%; }
    }
</style>
</head>
<body data-portal="merchant">

<header class="topbar">
    <div class="topbar-left">
        <button type="button" class="nav-toggle" aria-label="Toggle navigation"
                aria-expanded="true" onclick="toggleMerchantNav(this)">
            <i class="fa-solid fa-bars"></i>
        </button>

        <a class="brand" href="<?= BASE_URL ?>/index.php">
            <span class="brand-mark" aria-hidden="true">
                <i class="fa-solid fa-store"></i>
            </span>
            <span class="brand-text">
                <span class="brand-name">IND<span>BIN</span></span>
                <span class="brand-sub">Merchant Portal</span>
            </span>
        </a>
    </div>

    <div class="topbar-right">
        <a class="notif-link" href="../dashboard/notifications.php" aria-label="Notifications">
            <i class="fa-regular fa-bell" aria-hidden="true"></i>
            <?php if ($unreadCount > 0): ?>
                <span class="notif-badge"><?= $unreadCount > 9 ? '9+' : $unreadCount ?></span>
            <?php endif; ?>
        </a>

        <a class="help-link" href="<?= BASE_URL ?>/help.php">
            <i class="fa-regular fa-circle-question" aria-hidden="true"></i>
            <span>Help</span>
        </a>

        <details class="user-menu">
            <summary>
                <span class="avatar"><?= htmlspecialchars($initials) ?></span>
                <span class="who">
                    <strong><?= htmlspecialchars($displayName) ?></strong>
                    <small><?= $idValue !== '' ? htmlspecialchars($idValue) : 'Merchant' ?></small>
                </span>
                <i class="fa-solid fa-chevron-down" style="font-size:11px;color:var(--faint);"></i>
            </summary>

            <div class="user-dropdown">
                <?php if ($idValue !== ''): ?>
                    <div class="dropdown-id">
                        <small>Merchant ID</small>
                        <strong><?= htmlspecialchars($idValue) ?></strong>
                    </div>
                <?php endif; ?>

                <a href="../dashboard/profile.php"><i class="fa-solid fa-id-badge"></i> My profile</a>
                <a href="../dashboard/support.php"><i class="fa-solid fa-headset"></i> Support</a>
                <a href="../dashboard/analytics.php"><i class="fa-solid fa-chart-line"></i> Reports</a>
                <a href="<?= BASE_URL ?>/help.php"><i class="fa-regular fa-circle-question"></i> Help centre</a>

                <a href="<?= BASE_URL ?>/logout.php" class="is-danger">
                    <i class="fa-solid fa-arrow-right-from-bracket"></i> Sign out
                </a>
            </div>
        </details>
    </div>
</header>

<div class="layout">

    <nav class="portal-nav" aria-label="Merchant portal">
        <p class="merchant-nav-heading">Merchant journey</p>

        <?php $activeShown = false;
        foreach ($navItems as $key => [$label, $icon, $href]): ?>
            <a href="<?= htmlspecialchars($href) ?>"
               <?= $key === $activeKey ? 'aria-current="page"' : '' ?>>
                <i class="fa-solid <?= $icon ?>" style="width:18px;"></i>
                <span><?= htmlspecialchars($label) ?></span>
                <?php if (($navBadges[$key] ?? 0) > 0): ?>
                    <span class="nav-badge"><?= $navBadges[$key] > 9 ? '9+' : $navBadges[$key] ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>

        <div class="nav-rail-card">
            <p><strong>Need a hand?</strong>
               Our support team usually replies within one working day.</p>
            <a href="../dashboard/support.php">Raise a ticket <i class="fa-solid fa-arrow-right" style="font-size:10px;"></i></a>
        </div>

        <a href="<?= BASE_URL ?>/logout.php" style="margin-top:18px;color:var(--muted);">
            <i class="fa-solid fa-arrow-right-from-bracket" style="width:18px;"></i><span>Sign out</span>
        </a>
    </nav>

    <main class="main" style="align-items:stretch;padding:32px 36px;">

        <?php if ($withHeader): ?>
        <div class="main-top">
            <header>
                <h1 style="font-size:24px;font-weight:800;color:var(--ink);"><?= htmlspecialchars($pageTitle) ?></h1>
                <p style="font-size:13px;color:var(--muted);margin-top:4px;">
                    Signed in as <?= htmlspecialchars($displayName) ?>
                    <?php if ($business !== '' && $business !== $displayName): ?>
                        &middot; <?= htmlspecialchars($business) ?>
                    <?php endif; ?>
                </p>
            </header>
        </div>
        <?php endif; ?>

        <?php foreach (take_flashes() as $f): ?>
            <div class="main-flash notice is-<?= htmlspecialchars($f['type'] === 'error' ? 'bad' : ($f['type'] === 'success' ? 'ok' : $f['type'])) ?>" role="status">
                <?= htmlspecialchars($f['message']) ?>
            </div>
        <?php endforeach; ?>

<script>
function toggleMerchantNav(btn) {
    var open = document.body.classList.toggle('nav-collapsed');
    btn.setAttribute('aria-expanded', open ? 'false' : 'true');
}

/* Close the account menu on an outside click. */
document.addEventListener('click', function (e) {
    var menu = document.querySelector('.user-menu[open]');
    if (menu && !menu.contains(e.target)) { menu.removeAttribute('open'); }
});
</script>
    <?php
}