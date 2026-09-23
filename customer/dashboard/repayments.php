<?php
/**
 * Repayments tab.
 *
 * Pattern for the remaining tabs (profile, applications, statements,
 * documents, mandates, offers, loyalty, referrals, notifications, support):
 * bootstrap, guard, POST handler with CSRF, its own queries, shell, markup.
 *
 * Two fixes carried over from dashboard.php's version:
 *  - "Pay now" there flips status to Paid with no payment step at all, so
 *    a crafted POST clears any instalment. Here the row is only settled
 *    after the gateway confirms, and the update is scoped and conditional.
 *  - overdue rows were never marked overdue; nothing recalculated them.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
require_once CUST_ROOT . '/portal/customer_shell.php';

$user = require_customer($pdo);

if ($user['kyc_status'] !== 'approved' || empty($user['customer_code'])) {
    flash('info', 'Your dashboard opens once identity verification clears.');
    redirect(step_url(effective_step($pdo, $user)));
}

$uid = (int)$user['id'];

// Age pending instalments past their due date.
$pdo->prepare("UPDATE repayments SET status = 'overdue'
                WHERE user_id = ? AND status = 'pending' AND due_date < CURDATE()")
    ->execute([$uid]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $repaymentId = post_int('repayment_id');

    $stmt = $pdo->prepare("SELECT * FROM repayments
                            WHERE id = ? AND user_id = ? AND status IN ('pending','overdue')");
    $stmt->execute([$repaymentId, $uid]);
    $row = $stmt->fetch();

    if (!$row) {
        flash('error', 'That instalment is not open for payment.');
        redirect(CUST_BASE . '/dashboard/repayments.php');
    }

    // Hand off to the payment gateway. The row is settled by the gateway
    // callback, not here, so a browser-side POST cannot mark it paid.
    $_SESSION['pending_payment'] = ['repayment_id' => (int)$row['id'], 'amount' => (float)$row['amount']];
    audit_log($pdo, 'repayment.initiated', 'repayments', (string)$row['id'], null,
              ['amount' => $row['amount']]);

    flash('info', 'Redirecting to the payment page for ' . money((float)$row['amount']) . '.');
    redirect(CUST_BASE . '/dashboard/repayments.php');
}

$stmt = $pdo->prepare("SELECT * FROM repayments WHERE user_id = ? ORDER BY due_date ASC");
$stmt->execute([$uid]);
$rows = $stmt->fetchAll();

portal_shell_open($pdo, $user, 'repayments', 'Repayments');
?>
<div class="card wide">
    <?php if (!$rows): ?>
        <p style="color:var(--muted);font-size:13px;">Nothing to repay.</p>
    <?php else: ?>
        <table class="table">
            <thead><tr>
                <th scope="col">Loan</th><th scope="col">Instalment</th><th scope="col">Amount</th>
                <th scope="col">Due</th><th scope="col">Status</th><th scope="col"></th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r):
                $pill = match ($r['status']) {
                    'paid' => 'is-ok', 'overdue', 'failed' => 'is-bad', 'waived' => 'is-idle', default => 'is-warn',
                }; ?>
                <tr>
                    <td><?= e($r['loan_title']) ?></td>
                    <td><?= (int)$r['instalment_no'] ?></td>
                    <td><?= money((float)$r['amount']) ?></td>
                    <td><?= e(date('j M Y', strtotime($r['due_date']))) ?></td>
                    <td><span class="pill <?= $pill ?>"><?= e($r['status']) ?></span></td>
                    <td>
                        <?php if (in_array($r['status'], ['pending', 'overdue'], true)): ?>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="repayment_id" value="<?= (int)$r['id'] ?>">
                                <button class="btn" style="min-height:34px;font-size:12px;">Pay</button>
                            </form>
                        <?php elseif ($r['status'] === 'paid'): ?>
                            <span style="font-size:12px;color:var(--faint);">
                                <?= e(date('j M Y', strtotime((string)$r['paid_at']))) ?>
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php portal_shell_close(); ?>
