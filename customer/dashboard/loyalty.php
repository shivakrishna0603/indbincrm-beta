<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
require_once CUST_ROOT . '/portal/customer_shell.php';

$user = require_customer($pdo);
if ($user['kyc_status'] !== 'approved' || empty($user['customer_code'])) {
    flash('info', 'Your dashboard opens once identity verification clears.');
    redirect(step_url(effective_step($pdo, $user)));
}
$uid = (int)$user['id'];

$stmt = $pdo->prepare("SELECT * FROM loyalty_enrollments WHERE user_id = ?");
$stmt->execute([$uid]);
$enrolment = $stmt->fetch() ?: null;

$stmt = $pdo->prepare("SELECT * FROM loyalty_ledger WHERE user_id = ? ORDER BY id DESC LIMIT 50");
$stmt->execute([$uid]);
$ledger = $stmt->fetchAll();

$balance = loyalty_balance($pdo, $uid);

$stmt = $pdo->prepare("SELECT COALESCE(SUM(points),0) FROM loyalty_ledger
                        WHERE user_id = ? AND points > 0 AND expires_on IS NOT NULL
                          AND expires_on BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)");
$stmt->execute([$uid]);
$expiring = (int)$stmt->fetchColumn();

portal_shell_open($pdo, $user, 'loyalty', 'Rewards');
?>
<?php if ($expiring > 0): ?>
    <div class="notice is-warn"><?= $expiring ?> points expire within 90 days.</div>
<?php endif; ?>

<div class="tile-grid">
    <div class="tile"><span>Balance</span><strong><?= $balance ?></strong></div>
    <div class="tile"><span>Tier</span><strong style="font-size:18px;">
        <?= e($enrolment['program_tier'] ?? 'Not enrolled') ?></strong></div>
</div>

<div class="card wide">
    <h2 style="font-size:16px;font-weight:800;color:var(--ink);margin-bottom:12px;">Points history</h2>
    <?php if (!$ledger): ?>
        <p style="color:var(--muted);font-size:13px;">Nothing yet.
           <a href="<?= CUST_BASE ?>/loyalty/index.php">Join the programme</a>.</p>
    <?php else: ?>
        <table class="table">
            <thead><tr><th scope="col">Date</th><th scope="col">Entry</th><th scope="col">Reference</th>
            <th scope="col" style="text-align:right;">Points</th>
            <th scope="col" style="text-align:right;">Balance</th></tr></thead>
            <tbody>
            <?php foreach ($ledger as $l): ?>
                <tr>
                    <td><?= e(date('j M Y', strtotime($l['created_at']))) ?></td>
                    <td><?= e($l['entry_type']) ?></td>
                    <td style="color:var(--faint);"><?= e((string)($l['reference'] ?: '—')) ?></td>
                    <td style="text-align:right;color:<?= (int)$l['points'] >= 0 ? 'var(--ok)' : 'var(--bad)' ?>;font-weight:700;">
                        <?= (int)$l['points'] >= 0 ? '+' : '' ?><?= (int)$l['points'] ?></td>
                    <td style="text-align:right;"><?= (int)$l['balance_after'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php portal_shell_close(); ?>
