<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/shell.php';
$admin = require_admin($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (!admin_can($admin, 'can_manage_payouts')) {
        flash('error', 'Your account cannot approve payouts.');
        redirect(BASE_URL . '/admin/payouts.php');
    }
    $id = post_int('commission_id');
    $to = post_str('action', 12) === 'approve' ? 'approved' : 'withheld';

    // Only an accrued commission can move, so a paid one cannot be re-approved.
    $done = $pdo->prepare("UPDATE commissions SET status = ?, approved_by = ?
                            WHERE id = ? AND status = 'accrued'");
    $done->execute([$to, (int)$admin['id'], $id]);

    flash($done->rowCount() ? 'success' : 'error',
          $done->rowCount() ? 'Commission ' . $to . '.' : 'That commission is no longer pending.');
    audit_log($pdo, 'commission.' . $to, 'commissions', (string)$id);
    redirect(BASE_URL . '/admin/payouts.php');
}

$rows = $pdo->query(
    "SELECT c.*, u.full_name, u.business_name, a.application_number
       FROM commissions c
       JOIN users u ON u.id = c.earner_id
       JOIN applications a ON a.id = c.application_id
      ORDER BY FIELD(c.status,'accrued','approved','paid','withheld'), c.id DESC LIMIT 200"
)->fetchAll();

$totals = $pdo->query(
    "SELECT status, COUNT(*) n, COALESCE(SUM(amount),0) total FROM commissions GROUP BY status"
)->fetchAll();

admin_shell_open($pdo, $admin, 'payouts', 'Payouts');
?>
<h1 class="admin-h1">Commission payouts</h1>
<p class="admin-sub">Commission accrues on disbursement, never on approval. An approved loan that never funds owes nobody anything.</p>

<div class="kpi-grid">
<?php foreach ($totals as $t): ?>
    <div class="kpi"><div>
        <span class="kpi-label"><?= e(ucfirst($t['status'])) ?></span>
        <strong class="kpi-value"><?= money((float)$t['total']) ?></strong>
        <span class="kpi-delta muted"><?= (int)$t['n'] ?> entries</span>
    </div></div>
<?php endforeach; ?>
<?php if (!$totals): ?>
    <div class="kpi"><div><span class="kpi-label">Commission</span>
    <strong class="kpi-value"><?= money(0) ?></strong>
    <span class="kpi-delta muted">Nothing disbursed yet</span></div></div>
<?php endif; ?>
</div>

<section class="panel">
    <table class="table">
        <thead><tr><th scope="col">Earner</th><th scope="col">Role</th><th scope="col">Application</th>
        <th scope="col">Basis</th><th scope="col">Rate</th><th scope="col">Amount</th>
        <th scope="col">Status</th><th scope="col"></th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="8" style="color:var(--muted);">No commission accrued yet.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= e($r['business_name'] ?: $r['full_name']) ?></td>
                <td><?= e($r['earner_role']) ?></td>
                <td><?= e($r['application_number']) ?></td>
                <td><?= money((float)$r['basis_amount']) ?></td>
                <td><?= $r['commission_type'] === 'fixed' ? money((float)$r['rate']) : (float)$r['rate'] . '%' ?></td>
                <td><strong><?= money((float)$r['amount']) ?></strong></td>
                <td><span class="pill <?= $r['status']==='paid'?'is-ok':($r['status']==='withheld'?'is-bad':'is-warn') ?>"><?= e($r['status']) ?></span></td>
                <td>
                    <?php if ($r['status'] === 'accrued'): ?>
                    <form method="post" style="display:flex;gap:6px;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="commission_id" value="<?= (int)$r['id'] ?>">
                        <button class="btn" name="action" value="approve" style="min-height:32px;font-size:12px;">Approve</button>
                        <button class="btn secondary" name="action" value="withhold" style="min-height:32px;font-size:12px;">Withhold</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php admin_shell_close(); ?>
