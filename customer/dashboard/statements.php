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

$month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['m'] ?? '')) ? (string)$_GET['m'] : date('Y-m');

$stmt = $pdo->prepare("SELECT loan_title, instalment_no, amount, due_date, status, paid_at, payment_method
                         FROM repayments
                        WHERE user_id = ? AND DATE_FORMAT(COALESCE(paid_at, due_date), '%Y-%m') = ?
                        ORDER BY COALESCE(paid_at, due_date)");
$stmt->execute([$uid, $month]);
$rows = $stmt->fetchAll();

portal_shell_open($pdo, $user, 'statements', 'Statements');
?>
<div class="card wide">
    <form method="get" style="display:flex;gap:10px;align-items:flex-end;margin-bottom:20px;">
        <div class="field" style="margin:0;">
            <label for="m">Month</label>
            <input id="m" type="month" name="m" value="<?= e($month) ?>" style="max-width:200px;">
        </div>
        <button class="btn">Show</button>
    </form>

    <?php if (!$rows): ?>
        <p style="color:var(--muted);font-size:13px;">No activity in <?= e($month) ?>.</p>
    <?php else: ?>
        <table class="table">
            <thead><tr><th scope="col">Date</th><th scope="col">Description</th>
            <th scope="col">Method</th><th scope="col" style="text-align:right;">Amount</th></tr></thead>
            <tbody>
            <?php $total = 0.0; foreach ($rows as $r):
                if ($r['status'] === 'paid') $total += (float)$r['amount']; ?>
                <tr>
                    <td><?= e(date('j M', strtotime((string)($r['paid_at'] ?: $r['due_date'])))) ?></td>
                    <td><?= e($r['loan_title']) ?> instalment <?= (int)$r['instalment_no'] ?>
                        <span class="pill <?= $r['status'] === 'paid' ? 'is-ok' : 'is-warn' ?>"><?= e($r['status']) ?></span></td>
                    <td><?= e((string)($r['payment_method'] ?: '—')) ?></td>
                    <td style="text-align:right;"><?= money((float)$r['amount']) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr><td colspan="3" style="font-weight:700;">Paid this month</td>
                <td style="text-align:right;font-weight:700;"><?= money($total) ?></td></tr>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php portal_shell_close(); ?>
