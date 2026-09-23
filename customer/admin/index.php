<?php
/**
 * Back-office console. Where an admin lands after signing in.
 *
 * Shows what is waiting and, more usefully, what is stuck: an account that
 * cleared verification but never got a customer code is one nobody would
 * otherwise notice, because the customer just sees a locked dashboard and
 * no explanation.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../../admin/shell.php'; // was the separate customer/portal/admin_shell.php (its own 'Back office' branding) - consolidated so this renders inside the same admin dashboard shell

$admin  = require_admin($pdo);
$counts = admin_queue_counts($pdo);

// Anything that cleared eKYC but has no customer code. issue_customer_code()
// also needs onboarding_step to have reached 7, so these are customers who
// were approved before they finished the flow.
$stuck = $pdo->query(
    "SELECT id, full_name, email, onboarding_step, kyc_status
       FROM users
      WHERE role = 'customer' AND kyc_status = 'approved' AND customer_code IS NULL
      ORDER BY id DESC LIMIT 25"
)->fetchAll();

$recent = $pdo->query(
    "SELECT k.user_id, k.full_name, k.status, k.reviewed_at, u.customer_code
       FROM kyc_details k
       JOIN users u ON u.id = k.user_id
      WHERE k.reviewed_at IS NOT NULL
      ORDER BY k.reviewed_at DESC LIMIT 10"
)->fetchAll();

admin_shell_open($pdo, $admin, 'ekyc', 'Console');
?>
<div class="tile-grid">
    <div class="tile">
        <span>eKYC waiting</span>
        <strong><?= $counts['ekyc'] ?></strong>
    </div>
    <div class="tile">
        <span>Documents to check</span>
        <strong><?= $counts['docs'] ?></strong>
    </div>
    <div class="tile">
        <span>Credit decisions</span>
        <strong><?= $counts['credit'] ?></strong>
    </div>
    <div class="tile">
        <span>Approved, no ID issued</span>
        <strong><?= $counts['stuck'] ?></strong>
    </div>
</div>

<div class="card wide" style="margin-bottom:20px;">
    <h2 style="font-size:16px;font-weight:800;color:var(--ink);margin-bottom:6px;">
        How approval works
    </h2>
    <p style="font-size:13px;color:var(--muted);margin-bottom:14px;">
        A customer's dashboard stays locked until two things are both true:
        their eKYC case is approved, and they have reached the last onboarding
        step. Whichever happens second issues the customer ID and unlocks the
        account, so the order does not matter.
    </p>
    <a class="btn" href="<?= CUST_BASE ?>/ekyc/admin_review.php">
        Open the eKYC queue<?= $counts['ekyc'] ? ' (' . $counts['ekyc'] . ')' : '' ?>
    </a>
</div>

<?php if ($stuck): ?>
<div class="card wide" style="margin-bottom:20px;">
    <h2 style="font-size:16px;font-weight:800;color:var(--ink);margin-bottom:6px;">
        Approved but not activated
    </h2>
    <p style="font-size:13px;color:var(--muted);margin-bottom:14px;">
        These cleared verification but have no customer ID yet, because they
        have not finished onboarding. Nothing to do here: the ID is issued
        automatically when they reach step 7.
    </p>
    <table class="table">
        <thead><tr><th scope="col">Customer</th><th scope="col">Email</th>
        <th scope="col">Stopped at</th></tr></thead>
        <tbody>
        <?php foreach ($stuck as $s): ?>
            <tr>
                <td><?= e($s['full_name']) ?> <span style="color:var(--faint);">#<?= (int)$s['id'] ?></span></td>
                <td><?= e($s['email']) ?></td>
                <td>
                    <?php $n = (int)$s['onboarding_step']; ?>
                    Step <?= $n ?> of 7
                    <span style="color:var(--faint);font-size:12px;">
                        <?= e(CUSTOMER_STEPS[$n]['title'] ?? '') ?>
                    </span>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="card wide">
    <h2 style="font-size:16px;font-weight:800;color:var(--ink);margin-bottom:12px;">Recent decisions</h2>
    <?php if (!$recent): ?>
        <p style="font-size:13px;color:var(--muted);">Nothing reviewed yet.</p>
    <?php else: ?>
        <table class="table">
            <thead><tr><th scope="col">Customer</th><th scope="col">Outcome</th>
            <th scope="col">Customer ID</th><th scope="col">Reviewed</th></tr></thead>
            <tbody>
            <?php foreach ($recent as $r):
                $pill = match ($r['status']) {
                    'approved' => 'is-ok', 'rejected' => 'is-bad', default => 'is-warn',
                }; ?>
                <tr>
                    <td><?= e($r['full_name']) ?></td>
                    <td><span class="pill <?= $pill ?>"><?= e($r['status']) ?></span></td>
                    <td><?= e((string)($r['customer_code'] ?: '—')) ?></td>
                    <td><?= e(date('j M Y, H:i', strtotime((string)$r['reviewed_at']))) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php admin_shell_close(); ?>
