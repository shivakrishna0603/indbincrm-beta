<?php
require_once __DIR__ . '/../db.php';

// Admin authentication. This page used to test $_SESSION['admin_id'], a key
// nothing in the application ever sets, so it redirected to the login screen
// even for a signed-in administrator. require_admin() is the same role-based
// guard the /admin portal uses.
$admin = require_admin($pdo);

$serviceLabels = [
    'upi' => 'UPI', 'qr_to_cash' => 'QR to Cash', 'aeps' => 'AEPS',
    'money_transfer' => 'Money Transfer', 'loans' => 'Credit & Business Loan',
    'insurance' => 'Insurance', 'offers' => 'Offers', 'loyalty' => 'Loyalty',
];

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ms_id = (int)($_POST['ms_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($ms_id && in_array($action, ['approve', 'reject'], true)) {
        $newStatus = $action === 'approve' ? 'approved' : 'rejected';

        $ownerStmt = $pdo->prepare("SELECT ms.user_id, ms.service_key FROM merchant_services ms WHERE ms.id = ?");
        $ownerStmt->execute([$ms_id]);
        $owner = $ownerStmt->fetch();

        $upd = $pdo->prepare("UPDATE merchant_services SET status = ?, reviewer_id = ?, reviewed_at = NOW() WHERE id = ? AND status = 'requested'");
        $upd->execute([$newStatus, (int)$admin['id'], $ms_id]);
        if ($upd->rowCount()) {
            if ($owner) {
                $label = $serviceLabels[$owner['service_key']] ?? $owner['service_key'];
                notify(
                    $pdo,
                    (int)$owner['user_id'],
                    $newStatus === 'approved' ? "$label enabled" : "$label request declined",
                    $newStatus === 'approved'
                        ? "Your request for $label has been approved and is now active."
                        : "Your request for $label was not approved. You can request it again from Manage Products & Services."
                );
            }
            flash('success', "Service request #$ms_id " . ($action === 'approve' ? 'approved' : 'rejected') . ".");
        } else {
            flash('info', "Request #$ms_id is no longer pending.");
        }
    }
    header('Location: admin_review.php');
    exit;
}

$message = '';
foreach (take_flashes() as $f) {
    $message .= ($message ? ' ' : '') . $f['message'];
}

$stmt = $pdo->query(
    "SELECT ms.*, u.full_name, u.business_name, u.party_code AS merchant_id, u.email
     FROM merchant_services ms
     JOIN users u ON u.id = ms.user_id
     WHERE ms.status = 'requested'
     ORDER BY ms.requested_at ASC"
);
$pending = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Enablement Review | INDBIN Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Arial, sans-serif; }
        body { background: #f4f7fb; padding: 40px 5%; color: #1e293b; }
        h1 { font-size: 24px; color: #0b2545; margin-bottom: 4px; }
        .subtitle { color: #64748b; font-size: 14px; margin-bottom: 24px; }
        .alert { background: #dbeafe; color: #1d4ed8; border: 1px solid #93c5fd; padding: 10px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 20px; }
        .empty-state { background: #fff; border-radius: 12px; padding: 40px; text-align: center; color: #64748b; border: 1px solid #e2e8f0; }
        .row { background: #fff; border-radius: 12px; border: 1px solid #e2e8f0; padding: 18px 22px; margin-bottom: 14px; display: flex; justify-content: space-between; align-items: center; }
        .row .info strong { color: #0b2545; }
        .row .meta { font-size: 12px; color: #64748b; margin-top: 4px; }
        .svc-badge { background: #ffedd5; color: #c2410c; font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 20px; margin-left: 8px; }
        .actions { display: flex; gap: 10px; }
        .btn-approve { background: #16a34a; color: #fff; border: none; padding: 8px 16px; border-radius: 6px; font-weight: 600; cursor: pointer; }
        .btn-reject { background: #dc2626; color: #fff; border: none; padding: 8px 16px; border-radius: 6px; font-weight: 600; cursor: pointer; }
    </style>
</head>
<body>

    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <span style="font-size:13px; color:#64748b;">Signed in as <strong><?= htmlspecialchars($admin['full_name']) ?></strong></span>
        <a href="<?= BASE_URL ?>/logout.php" style="font-size:13px; color:#dc2626; text-decoration:none; font-weight:600;">Log out</a>
    </div>

    <h1>Service enablement review</h1>
    <p class="subtitle"><?= count($pending) ?> request<?= count($pending) === 1 ? '' : 's' ?> pending approval</p>

    <?php if ($message): ?><div class="alert"><?= htmlspecialchars($message) ?></div><?php endif; ?>

    <?php if (empty($pending)): ?>
        <div class="empty-state">No service requests pending review right now.</div>
    <?php else: ?>
        <?php foreach ($pending as $ms): ?>
            <div class="row">
                <div class="info">
                    <strong><?= htmlspecialchars($ms['business_name'] ?: $ms['full_name']) ?></strong>
                    <span class="svc-badge"><?= htmlspecialchars($serviceLabels[$ms['service_key']] ?? $ms['service_key']) ?></span>
                    <div class="meta"><?= htmlspecialchars($ms['merchant_id'] ?? '—') ?> &middot; <?= htmlspecialchars($ms['email']) ?> &middot; Requested <?= htmlspecialchars(date('d M Y', strtotime($ms['requested_at']))) ?></div>
                </div>
                <div class="actions">
                    <form method="POST" onsubmit="return confirm('Approve this service?');">
                        <input type="hidden" name="ms_id" value="<?= $ms['id'] ?>">
                        <input type="hidden" name="action" value="approve">
                        <button type="submit" class="btn-approve">Approve</button>
                    </form>
                    <form method="POST" onsubmit="return confirm('Reject this service?');">
                        <input type="hidden" name="ms_id" value="<?= $ms['id'] ?>">
                        <input type="hidden" name="action" value="reject">
                        <button type="submit" class="btn-reject">Reject</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

</body>
</html>
