<?php
/**
 * Global top bar.
 *
 * Shared by every module's onboarding steps, by the customer portal, and by
 * the customer module's own review console.
 *
 * Every path here is built from BASE_URL and a per-role $moduleBase, never
 * from CUST_BASE. CUST_BASE only exists once customer/config/bootstrap.php
 * has run, which happens on customer requests and nowhere else.
 * core/onboarding_shell.php pulls this file in for the merchant and agent
 * onboarding steps too, without ever loading that file, so CUST_BASE was an
 * undefined constant there. PHP 8 treats that as a fatal Error, thrown the
 * instant execution reached it: the DOCTYPE, the title and the first few
 * pixels of the header had already gone to the browser, then the response
 * stopped dead. That is the blank page with only the hamburger button
 * showing above.
 *
 * $context decides what the right-hand side carries; $moduleBase (derived
 * from $user['role']) decides which module's own pages the dropdown and the
 * bell point into.
 *   onboarding  no bell (the notifications page is still locked); the
 *               dropdown's one link goes to landing_url($user), so it always
 *               reopens on the exact step that account is on, any role
 *   portal      bell with unread count (the customer dashboard, today)
 *   admin       bell showing the review queue (the customer module's own
 *               eKYC/document/credit console, distinct from admin/index.php)
 */

declare(strict_types=1);

function render_topbar(PDO $pdo, array $user, string $context = 'portal'): void
{
    $uid  = (int)$user['id'];
    $role = (string)($user['role'] ?? 'customer');

    $moduleBase = match ($role) {
        'admin'    => BASE_URL . '/admin',
        'merchant' => BASE_URL . '/merchant',
        'agent'    => BASE_URL . '/agent',
        default    => BASE_URL . '/customer',
    };

    $bellCount = 0;
    $bellHref  = null;

    if ($context === 'portal') {
        // Only the customer module's own dashboard uses this context today.
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$uid]);
        $bellCount = (int)$stmt->fetchColumn();
        $bellHref  = $moduleBase . '/dashboard/notifications.php';
    } elseif ($context === 'admin') {
        // The customer module's own review console, not admin/index.php.
        $counts    = admin_queue_counts($pdo);
        $bellCount = $counts['ekyc'] + $counts['docs'] + $counts['credit'];
        $bellHref  = BASE_URL . '/customer/admin/index.php';
    }

    $roleLabel = match ($role) {
        'admin'    => 'Back office',
        'agent'    => 'Agent',
        'merchant' => 'Merchant',
        default    => 'Customer',
    };

    // The accent and the wordmark tell you which of the three journeys you
    // are in without reading the URL. body[data-portal] in the shells does
    // the colouring; these two only pick the label and the glyph.
    $portalLabel = match ($role) {
        'admin'    => 'Back office',
        'agent'    => 'Agent Portal',
        'merchant' => 'Merchant Portal',
        default    => 'Customer Portal',
    };
    $portalIcon = match ($role) {
        'admin'    => 'fa-shield-halved',
        'agent'    => 'fa-user-tie',
        'merchant' => 'fa-store',
        default    => 'fa-user',
    };

    // Initials, not a photo. There is no avatar upload anywhere in the module,
    // so a broken image placeholder would be the only other option.
    $parts    = preg_split('/\s+/', trim((string)$user['full_name'])) ?: [];
    $initials = strtoupper(
        mb_substr($parts[0] ?? '?', 0, 1) . (count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '')
    );

    // Customer, agent and merchant each mint the account its own kind of ID,
    // into two different columns. Whichever one this account has, it is the
    // right one to show, whatever the role.
    $idValue = (string)($user['customer_code'] ?? '') ?: (string)($user['party_code'] ?? '');
    $idLabel = $role === 'customer' ? 'Customer ID' : ucfirst($role) . ' ID';
    ?>
<header class="topbar">
    <div class="topbar-left">
        <button type="button" class="nav-toggle" aria-label="Toggle navigation"
                aria-expanded="true" onclick="toggleNav(this)">
            <i class="fa-solid fa-bars"></i>
        </button>

        <a class="brand" href="<?= BASE_URL ?>/index.php">
            <span class="brand-mark" aria-hidden="true">
                <i class="fa-solid <?= e($portalIcon) ?>"></i>
            </span>
            <span class="brand-text">
                <span class="brand-name">IND<span>BIN</span></span>
                <span class="brand-sub"><?= e($portalLabel) ?></span>
            </span>
        </a>
    </div>

    <div class="topbar-right">
        <a class="help-link" href="<?= BASE_URL ?>/help.php">
            <i class="fa-regular fa-circle-question" aria-hidden="true"></i>
            <span>Help</span>
        </a>

        <?php if ($bellHref !== null): ?>
            <a class="bell" href="<?= e($bellHref) ?>"
               aria-label="<?= $bellCount ?> item(s) needing attention">
                <i class="fa-regular fa-bell"></i>
                <?php if ($bellCount > 0): ?>
                    <span class="bell-count"><?= $bellCount > 99 ? '99+' : $bellCount ?></span>
                <?php endif; ?>
            </a>
        <?php endif; ?>

        <details class="user-menu">
            <summary>
                <span class="avatar"><?= e($initials) ?></span>
                <span class="who">
                    <strong><?= e($user['full_name']) ?></strong>
                    <small><?= e($roleLabel) ?></small>
                </span>
                <i class="fa-solid fa-chevron-down" style="font-size:11px;color:var(--faint);"></i>
            </summary>

            <div class="user-dropdown">
                <?php if ($idValue !== ''): ?>
                    <div class="dropdown-id">
                        <small><?= e($idLabel) ?></small>
                        <strong><?= e($idValue) ?></strong>
                    </div>
                <?php endif; ?>

                <?php if ($context === 'portal'): ?>
                    <a href="<?= $moduleBase ?>/dashboard/profile.php"><i class="fa-solid fa-user"></i> My profile</a>
                    <a href="<?= $moduleBase ?>/consents/index.php"><i class="fa-solid fa-shield-halved"></i> Consents</a>
                    <a href="<?= $moduleBase ?>/dashboard/support.php"><i class="fa-solid fa-headset"></i> Support</a>
                <?php elseif ($context === 'admin'): ?>
                    <a href="<?= BASE_URL ?>/customer/admin/index.php"><i class="fa-solid fa-gauge-high"></i> Console</a>
                <?php else: ?>
                    <a href="<?= e(landing_url($user)) ?>"><i class="fa-solid fa-list-check"></i> My onboarding</a>
                <?php endif; ?>

                <a href="<?= BASE_URL ?>/logout.php" class="is-danger">
                    <i class="fa-solid fa-arrow-right-from-bracket"></i> Sign out
                </a>
            </div>
        </details>
    </div>
</header>

<script>
function toggleNav(btn) {
    var open = document.body.classList.toggle('nav-collapsed');
    btn.setAttribute('aria-expanded', open ? 'false' : 'true');
}

/* Close the account menu on an outside click. <details> stays open otherwise,
   which leaves it hanging over the page after you navigate away and back. */
document.addEventListener('click', function (e) {
    var menu = document.querySelector('.user-menu[open]');
    if (menu && !menu.contains(e.target)) { menu.removeAttribute('open'); }
});
</script>
<?php
}
