<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../portal/shell.php';
require_once __DIR__ . '/../portal/merchant_shell.php';

$user = require_merchant($pdo);
$user_id = (int)$user['id'];

if ($user['account_status'] !== 'active') { header('Location: ../onboarding_complete/index.php'); exit; }

// Hidden feature: merchants no longer fund loans to customers or collect
// repayments from them - INDBIN underwrites and disburses applications. The
// file and its data path stay intact; the screen is just unreachable from
// the merchant portal. Direct hits are bounced back to the dashboard.
header('Location: index.php');
exit;

$color = '#f97316';
$lightColor = '#fff1e5';

// Mark anything past due as overdue (computed on each page load — no cron job in this environment)
$pdo->prepare("UPDATE repayments SET status = 'overdue' WHERE merchant_id = ? AND status = 'pending' AND due_date < CURDATE()")
    ->execute([$user_id]);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_paid') {
    $repayment_id = (int)($_POST['repayment_id'] ?? 0);
    $rStmt = $pdo->prepare("SELECT * FROM repayments WHERE id = ? AND merchant_id = ?");
    $rStmt->execute([$repayment_id, $user_id]);
    $rRow = $rStmt->fetch();

    if (!$rRow) {
        flash('error', "That installment couldn't be found.");
    } else {
        try {
            $pdo->beginTransaction();

            $pdo->prepare("UPDATE repayments SET status = 'paid', amount_paid = ?, paid_at = NOW() WHERE id = ? AND merchant_id = ?")
                ->execute([$rRow['amount_due'], $repayment_id, $user_id]);

            // Record the collection as a repayment transaction so it shows up
            // on the Orders page and in Analytics. Reference is derived from
            // the installment id so marking paid twice cannot double-book.
            $ref = 'RCPT' . $repayment_id;
            $dup = $pdo->prepare("SELECT id FROM transactions WHERE reference_number = ?");
            $dup->execute([$ref]);
            if (!$dup->fetch()) {
                $pdo->prepare(
                    "INSERT INTO transactions
                        (application_id, merchant_id, customer_id, transaction_type, amount,
                         reference_number, status, transaction_date)
                     VALUES (?, ?, NULL, 'repayment', ?, ?, 'success', NOW())"
                )->execute([
                    $rRow['application_id'] ?: null, $user_id,
                    $rRow['amount_due'], $ref,
                ]);
            }

            $pdo->commit();
            audit_log($pdo, 'repayment.paid', 'repayments', (string)$repayment_id,
                      ['status' => $rRow['status']], ['status' => 'paid', 'amount' => $rRow['amount_due']]);
            flash('success', 'Installment #' . (int)$rRow['installment_number'] . ' marked as paid.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', 'Could not record the payment. Please try again.');
        }
    }

    header('Location: repayments.php');
    exit;
}

$repayStmt = $pdo->prepare(
    "SELECT r.*, a.application_number,
            COALESCE(q.product_name, a.product_name) AS product_name,
            COALESCE(cu.full_name, l.name) AS customer_name
     FROM repayments r
     LEFT JOIN applications a ON a.id = r.application_id
     LEFT JOIN quotes q ON q.id = a.quote_id
     LEFT JOIN leads l ON l.id = a.lead_id
     LEFT JOIN users cu ON cu.id = r.user_id
     WHERE r.merchant_id = ?
     ORDER BY r.due_date ASC, r.id ASC"
);
$repayStmt->execute([$user_id]);
$repayments = $repayStmt->fetchAll();

$totalDue = 0; $totalCollected = 0; $totalOverdue = 0;
foreach ($repayments as $r) {
    if ($r['status'] === 'paid') $totalCollected += (float)$r['amount_paid'];
    elseif ($r['status'] === 'overdue') $totalOverdue += (float)$r['amount_due'];
    if (!in_array($r['status'], ['paid', 'waived'], true)) $totalDue += (float)$r['amount_due'];
}

$statusColors = ['pending' => '#2563eb', 'paid' => '#059669', 'overdue' => '#dc2626', 'waived' => '#64748b', 'failed' => '#b45309'];

render_merchant_shell_open($pdo, $user_id, 'repayments', 'Repayment Tracking');
?>

<style>
    .alert-success { background: #d1fae5; color: #059669; border: 1px solid #6ee7b7; padding: 10px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 20px; }
    .alert-error { background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; padding: 10px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 20px; }

    .stat-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 24px; }
    @media (max-width: 900px) { .stat-grid { grid-template-columns: 1fr; } }
    .stat-card { background: var(--surface); border: 1px solid var(--line); border-radius: 12px; padding: 20px; }
    .stat-card .s-label { font-size: 12px; color: var(--muted); margin-bottom: 6px; }
    .stat-card .s-value { font-size: 22px; font-weight: 800; }

    .empty-state { background: var(--surface); border: 1px dashed var(--line); border-radius: 12px; padding: 50px 30px; text-align: center; color: var(--muted); }

    table { width: 100%; border-collapse: collapse; background: var(--surface); border-radius: 12px; overflow: hidden; border: 1px solid var(--line); }
    th { text-align: left; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--muted); padding: 12px 16px; background: var(--canvas); border-bottom: 1px solid var(--line); }
    td { padding: 12px 16px; font-size: 13px; border-bottom: 1px solid #f1f5f9; }
    tr:last-child td { border-bottom: none; }
    .status-pill { font-size: 11px; font-weight: 700; padding: 4px 12px; border-radius: 20px; text-transform: capitalize; }
    .btn-pay { padding: 6px 14px; border-radius: 6px; border: none; background: var(--brand); color: #fff; font-size: 12px; font-weight: 700; cursor: pointer; }
</style>

<div class="stat-grid">
    <div class="stat-card"><div class="s-label">Total Outstanding</div><div class="s-value" style="color:var(--ink);">₹<?= number_format($totalDue, 0) ?></div></div>
    <div class="stat-card"><div class="s-label">Collected So Far</div><div class="s-value" style="color:var(--ok);">₹<?= number_format($totalCollected, 0) ?></div></div>
    <div class="stat-card"><div class="s-label">Overdue Amount</div><div class="s-value" style="color:var(--bad);">₹<?= number_format($totalOverdue, 0) ?></div></div>
</div>

<?php if (empty($repayments)): ?>
    <div class="empty-state">No repayment installments yet. These are generated automatically once an application is disbursed.</div>
<?php else: ?>
    <table>
        <tr>
            <th>Application</th>
            <th>Customer</th>
            <th>Installment</th>
            <th>Due Date</th>
            <th>Amount</th>
            <th>Status</th>
            <th></th>
        </tr>
        <?php foreach ($repayments as $r): ?>
        <tr>
            <td>
                <?php if (!empty($r['application_number'])): ?>
                    <?= htmlspecialchars($r['application_number']) ?>
                <?php else: ?>
                    <?= htmlspecialchars($r['loan_title']) ?>
                <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($r['customer_name'] ?: $r['loan_title']) ?></td>
            <td>#<?= (int)$r['installment_number'] ?></td>
            <td><?= htmlspecialchars(date('d M Y', strtotime($r['due_date']))) ?></td>
            <td>₹<?= number_format((float)$r['amount_due'], 0) ?></td>
            <td><span class="status-pill" style="background:<?= $statusColors[$r['status']] ?? '#64748b' ?>22; color:<?= $statusColors[$r['status']] ?? '#64748b' ?>;"><?= htmlspecialchars($r['status']) ?></span></td>
            <td>
                <?php if ($r['status'] !== 'paid'): ?>
                    <form method="POST" onsubmit="return confirm('Confirm this installment was collected?');">
                        <input type="hidden" name="repayment_id" value="<?= $r['id'] ?>">
                        <input type="hidden" name="action" value="mark_paid">
                        <button type="submit" class="btn-pay">Mark Paid</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<?php render_shell_close(); ?>
