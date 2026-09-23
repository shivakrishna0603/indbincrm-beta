<?php
/**
 * Onboarding progress for all three roles, with the same columns for each,
 * so a stalled merchant is as visible as a stalled customer.
 */

declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/shell.php';

$admin = require_admin($pdo);

$role = (string)($_GET['role'] ?? '');
if (!in_array($role, ['customer', 'agent', 'merchant'], true)) {
    $role = '';
}

$sql  = "SELECT id, role, full_name, email, mobile, business_name, party_code, customer_code,
                kyc_status, business_verification_status, onboarding_step,
                account_status, created_at, last_login_at
           FROM users WHERE role <> 'admin'";
$args = [];
if ($role !== '') { $sql .= " AND role = ?"; $args[] = $role; }
$sql .= " ORDER BY created_at DESC LIMIT 200";

$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$rows = $stmt->fetchAll();

// admin/shell.php's rail highlights whichever key matches this, so a
// role-filtered visit lights up that role's own item rather than the
// generic "Onboarding" one above it.
$activeKey = match ($role) {
    'customer' => 'customers', 'agent' => 'agents', 'merchant' => 'merchants', default => 'onboarding',
};

admin_shell_open($pdo, $admin, $activeKey, 'Onboarding');
?>
<h1 class="admin-h1">Onboarding</h1>
<p class="admin-sub">
    <?= $role ? e(ucfirst($role)) . ' accounts' : 'Customers, agents and merchants' ?>,
    newest first.
</p>

<section class="panel">
    <div class="panel-head">
        <h2><?= count($rows) ?> account(s)</h2>
        <div>
            <a href="?" style="margin-right:12px;">All</a>
            <a href="?role=customer" style="margin-right:12px;">Customers</a>
            <a href="?role=agent" style="margin-right:12px;">Agents</a>
            <a href="?role=merchant">Merchants</a>
        </div>
    </div>

    <table class="table">
        <thead><tr>
            <th scope="col">Name</th><th scope="col">Role</th><th scope="col">INDBIN ID</th>
            <th scope="col">Contact</th><th scope="col">Step</th>
            <th scope="col">KYC</th><th scope="col">Status</th><th scope="col">Joined</th>
            <th scope="col">Action</th>
        </tr></thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="9" style="color:var(--muted);">Nobody yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r):
            $kycPill = match ($r['kyc_status']) {
                'approved' => 'is-ok', 'rejected' => 'is-bad',
                'not_started' => 'is-idle', default => 'is-warn',
            };
            $accPill = $r['account_status'] === 'active' ? 'is-ok'
                     : ($r['account_status'] === 'suspended' ? 'is-bad' : 'is-warn');

            // Customers mint customer_code (customer/includes/guard.php);
            // agents and merchants mint party_code (core/workflow.php).
            // Reading party_code alone showed every customer as "—" even
            // once they had a real ID, since it was never in that column.
            $code = $r['role'] === 'customer' ? $r['customer_code'] : $r['party_code'];

            // Customers have 7 steps, agents and merchants 8 - showing
            // everyone against "of 7" made a fully-onboarded agent or
            // merchant look one step short forever.
            $totalSteps = $r['role'] === 'customer' ? 7 : 8;

            // Point at whatever queue would actually let the admin act on
            // this specific row. Not a deep link to the row itself - none
            // of these queues support that yet - but it lands on the right
            // page instead of leaving the admin to go find it.
            $reviewHref = null;
            if ($r['kyc_status'] !== 'approved' && $r['kyc_status'] !== 'not_started') {
                $reviewHref = $r['role'] === 'customer'
                    ? BASE_URL . '/customer/ekyc/admin_review.php'
                    : BASE_URL . '/admin/approvals.php?queue=kyc';
            } elseif ($r['role'] === 'merchant' && $r['business_verification_status'] !== 'approved'
                      && $r['business_verification_status'] !== 'not_started') {
                $reviewHref = BASE_URL . '/merchant/business/admin_review.php';
            } elseif ($r['role'] === 'agent' && $r['kyc_status'] === 'approved' && $r['account_status'] !== 'active') {
                $reviewHref = BASE_URL . '/admin/approvals.php?queue=agentbg';
            }
            ?>
            <tr>
                <td><?= e($r['business_name'] ?: $r['full_name']) ?>
                    <?php if ($r['business_name']): ?>
                        <div style="color:var(--faint);font-size:11px;"><?= e($r['full_name']) ?></div>
                    <?php endif; ?></td>
                <td><?= e(ucfirst($r['role'])) ?></td>
                <td><?= e($code ?: '—') ?></td>
                <td><?= e($r['email']) ?>
                    <div style="color:var(--faint);font-size:11px;">
                        <?= e(mask_tail((string)$r['mobile'])) ?></div></td>
                <td><?= (int)$r['onboarding_step'] ?> of <?= $totalSteps ?></td>
                <td><span class="pill <?= $kycPill ?>"><?= e(str_replace('_',' ',$r['kyc_status'])) ?></span></td>
                <td><span class="pill <?= $accPill ?>"><?= e($r['account_status']) ?></span></td>
                <td><?= e(date('j M Y', strtotime($r['created_at']))) ?></td>
                <td>
                    <?php if ($reviewHref): ?><a href="<?= e($reviewHref) ?>">Review &rsaquo;</a><?php endif; ?>
                    <?php if ($r['role'] === 'agent'): ?>
                        <?php if ($reviewHref): ?><br><?php endif; ?>
                        <a href="<?= BASE_URL ?>/admin/agent_settings.php">Commission &rsaquo;</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php admin_shell_close(); ?>
