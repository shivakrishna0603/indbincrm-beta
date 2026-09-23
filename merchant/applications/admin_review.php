<?php
require_once __DIR__ . '/../db.php';

// Admin authentication. This page used to test $_SESSION['admin_id'], a key
// nothing in the application ever sets - the real login (core/auth.php) stores
// user_id/role, so the page redirected to the login screen even for a
// signed-in administrator. require_admin() is the same role-based guard the
// /admin portal uses.
$admin = require_admin($pdo);

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $app_id  = (int)($_POST['app_id'] ?? 0);
    $action  = $_POST['action'] ?? '';
    $adminId = (int)$admin['id'];

    if ($app_id && in_array($action, ['review', 'approve', 'reject', 'disburse'])) {
        if ($action === 'review') {
            $pdo->prepare("UPDATE applications SET status = 'under_review', reviewed_at = NOW() WHERE id = ?")->execute([$app_id]);
            $message = "Application #$app_id moved to Under Review.";
        } elseif ($action === 'approve') {
            $done = $pdo->prepare(
                "UPDATE applications
                    SET status = 'approved', current_stage = 'approved',
                        reviewed_at = NOW(), decision_by = COALESCE(?, decision_by)
                  WHERE id = ? AND status IN ('submitted','requirement_raised','under_review')"
            );
            $done->execute([$adminId, $app_id]);
            if ($done->rowCount()) {
                record_stage($pdo, $app_id, null, 'approved', 'Approved in review');
                $message = "Application #$app_id approved.";
            } else {
                $message = "Application #$app_id is not in a state that can be approved.";
            }
        } elseif ($action === 'reject') {
            $reason = trim($_POST['reason'] ?? 'Did not meet approval criteria.');
            if (reject_application($pdo, $app_id, $reason, $adminId)) {
                $message = "Application #$app_id rejected.";
            } else {
                $message = "Application #$app_id could not be rejected.";
            }
        } elseif ($action === 'disburse') {
            $appStmt = $pdo->prepare(
                "SELECT amount, merchant_id, customer_id, tenure_months, product_name
                   FROM applications WHERE id = ? AND status = 'approved'"
            );
            $appStmt->execute([$app_id]);
            $appRow = $appStmt->fetch();

            if (!$appRow) {
                $message = "Application #$app_id is not ready to be disbursed.";
            } else {
                $pdo->prepare(
                    "UPDATE applications SET status = 'disbursed', current_stage = 'disbursed', disbursed_at = NOW() WHERE id = ?"
                )->execute([$app_id]);

                // Reference number derived from the application id, so two
                // apps disbursed in the same second cannot collide on the
                // unique transactions.reference_number the way time() % 1000000
                // could (it repeats every ~27.7 hours and is shared by everyone).
                $refNumber = 'TXN' . str_pad((string)$app_id, 6, '0', STR_PAD_LEFT);
                $pdo->prepare(
                    "INSERT INTO transactions (application_id, merchant_id, transaction_type, amount, reference_number, status)
                     VALUES (?, ?, 'disbursement', ?, ?, 'success')"
                )->execute([$app_id, $appRow['merchant_id'], $appRow['amount'], $refNumber]);

                // Auto-generate the repayment schedule: split evenly across
                // tenure_months, due monthly starting one month from
                // disbursement. user_id is who owes - the customer when the
                // application has one, otherwise the merchant themselves.
                $tenure = max(1, (int)($appRow['tenure_months'] ?? 1));
                $installmentAmount = round((float)$appRow['amount'] / $tenure, 2);
                $debtorId = $appRow['customer_id'] ?: $appRow['merchant_id'];
                $title    = (string)($appRow['product_name'] ?: 'Application #' . $app_id);
                $insertRepay = $pdo->prepare(
                    "INSERT INTO repayments
                        (application_id, user_id, merchant_id, loan_title,
                         installment_number, amount, amount_due, due_date)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                for ($i = 1; $i <= $tenure; $i++) {
                    $dueDate = date('Y-m-d', strtotime("+$i month"));
                    // Last installment absorbs any rounding remainder
                    $amt = ($i === $tenure)
                        ? round((float)$appRow['amount'] - ($installmentAmount * ($tenure - 1)), 2)
                        : $installmentAmount;
                    $insertRepay->execute([$app_id, $debtorId, $appRow['merchant_id'], $title, $i, $amt, $amt, $dueDate]);
                }

                record_stage($pdo, $app_id, null, 'disbursed', 'Application disbursed');
                accrue_commissions($pdo, $app_id);
                notify_parties($pdo, $app_id, 'Application disbursed',
                               'Application #' . $app_id . ' has been disbursed.');

                $message = "Application #$app_id marked as disbursed. Repayment schedule generated.";
            }
        }
    }
}

$stmt = $pdo->query(
    "SELECT a.*, u.business_name, u.full_name, u.email, l.name AS customer_name, q.product_name
     FROM applications a
     JOIN users u ON u.id = a.merchant_id
     JOIN leads l ON l.id = a.lead_id
     JOIN quotes q ON q.id = a.quote_id
     WHERE a.status != 'disbursed'
     ORDER BY a.submitted_at ASC"
);
$applications = $stmt->fetchAll();

$statusColors = ['submitted' => '#2563eb', 'requirement_raised' => '#2563eb', 'under_review' => '#b45309', 'approved' => '#059669', 'rejected' => '#dc2626'];
$statusLabels = ['submitted' => 'Submitted', 'requirement_raised' => 'Requirement Raised', 'under_review' => 'Under Review', 'approved' => 'Approved', 'rejected' => 'Rejected'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Application Underwriting | INDBIN Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Arial, sans-serif; }
        body { background: #f4f7fb; padding: 40px 5%; color: #1e293b; }
        h1 { font-size: 24px; color: #0b2545; margin-bottom: 4px; }
        .subtitle { color: #64748b; font-size: 14px; margin-bottom: 24px; }
        .alert { background: #dbeafe; color: #1d4ed8; border: 1px solid #93c5fd; padding: 10px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 20px; }
        .empty-state { background: #fff; border-radius: 12px; padding: 40px; text-align: center; color: #64748b; border: 1px solid #e2e8f0; }
        .row { background: #fff; border-radius: 12px; border: 1px solid #e2e8f0; padding: 18px 22px; margin-bottom: 14px; }
        .row-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; }
        .row .a-num { font-size: 12px; color: #94a3b8; }
        .row .a-title { font-size: 15px; font-weight: 700; color: #0b2545; }
        .row .meta { font-size: 12px; color: #64748b; margin-top: 4px; }
        .status-pill { font-size: 11px; font-weight: 700; padding: 4px 12px; border-radius: 20px; }
        .actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .btn { padding: 8px 16px; border-radius: 6px; border: none; font-size: 12px; font-weight: 700; cursor: pointer; }
        .btn-review { background: #dbeafe; color: #1d4ed8; }
        .btn-approve { background: #16a34a; color: #fff; }
        .btn-reject { background: #dc2626; color: #fff; }
        .btn-disburse { background: #7c3aed; color: #fff; }
        .reason-input { padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; flex: 1; min-width: 160px; }
    </style>
</head>
<body>

    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <span style="font-size:13px; color:#64748b;">Signed in as <strong><?= htmlspecialchars($admin['full_name']) ?></strong></span>
        <a href="<?= BASE_URL ?>/logout.php" style="font-size:13px; color:#dc2626; text-decoration:none; font-weight:600;">Log out</a>
    </div>

    <h1>Application underwriting</h1>
    <p class="subtitle"><?= count($applications) ?> application<?= count($applications) === 1 ? '' : 's' ?> in the pipeline</p>

    <?php if ($message): ?><div class="alert"><?= htmlspecialchars($message) ?></div><?php endif; ?>

    <?php if (empty($applications)): ?>
        <div class="empty-state">No active applications right now.</div>
    <?php else: ?>
        <?php foreach ($applications as $app): ?>
            <div class="row">
                <div class="row-top">
                    <div>
                        <div class="a-num"><?= htmlspecialchars($app['application_number']) ?></div>
                        <div class="a-title"><?= htmlspecialchars($app['product_name']) ?> — ₹<?= number_format((float)$app['amount'], 0) ?></div>
                        <div class="meta">
                            Merchant: <?= htmlspecialchars($app['business_name'] ?: $app['full_name']) ?> (<?= htmlspecialchars($app['email']) ?>)<br>
                            Customer: <?= htmlspecialchars($app['customer_name'] ?: 'Unnamed') ?> &middot; Submitted <?= htmlspecialchars(date('d M Y', strtotime($app['submitted_at']))) ?>
                        </div>
                    </div>
                    <span class="status-pill" style="background:<?= ($statusColors[$app['status']] ?? '#64748b') ?>22; color:<?= ($statusColors[$app['status']] ?? '#64748b') ?>;"><?= htmlspecialchars($statusLabels[$app['status']] ?? ucfirst(str_replace('_', ' ', $app['status']))) ?></span>
                </div>

                <div class="actions">
                    <?php if (in_array($app['status'], ['submitted','requirement_raised'])): ?>
                        <form method="POST"><input type="hidden" name="app_id" value="<?= $app['id'] ?>"><input type="hidden" name="action" value="review"><button type="submit" class="btn btn-review">Move to Review</button></form>
                    <?php endif; ?>

                    <?php if (in_array($app['status'], ['submitted', 'requirement_raised', 'under_review'])): ?>
                        <form method="POST"><input type="hidden" name="app_id" value="<?= $app['id'] ?>"><input type="hidden" name="action" value="approve"><button type="submit" class="btn btn-approve">Approve</button></form>
                        <form method="POST" style="display:flex; gap:6px; flex:1;">
                            <input type="hidden" name="app_id" value="<?= $app['id'] ?>">
                            <input type="hidden" name="action" value="reject">
                            <input type="text" name="reason" class="reason-input" placeholder="Rejection reason">
                            <button type="submit" class="btn btn-reject">Reject</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($app['status'] === 'approved'): ?>
                        <form method="POST"><input type="hidden" name="app_id" value="<?= $app['id'] ?>"><input type="hidden" name="action" value="disburse"><button type="submit" class="btn btn-disburse">Mark Disbursed</button></form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

</body>
</html>