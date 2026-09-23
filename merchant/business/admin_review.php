<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../credit/scoring.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bv_id  = (int)($_POST['bv_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($bv_id && in_array($action, ['approve', 'reject'])) {
        $rowStmt = $pdo->prepare("SELECT user_id FROM business_verifications WHERE id = ?");
        $rowStmt->execute([$bv_id]);
        $row = $rowStmt->fetch();

        if ($row) {
            if ($action === 'approve') {
                $pdo->prepare("UPDATE business_verifications SET status = 'approved', reviewed_at = NOW() WHERE id = ?")
                    ->execute([$bv_id]);
                $pdo->prepare("UPDATE users SET business_verification_status = 'approved' WHERE id = ?")
                    ->execute([$row['user_id']]);

                // Automatically run the risk scoring engine now that business verification is approved
                runAndSaveRiskAssessment($pdo, $row['user_id']);

                $message = "Submission #$bv_id approved and risk score calculated.";
            } else {
                $reason = trim($_POST['reason'] ?? 'Documents did not meet verification requirements.');
                $pdo->prepare("UPDATE business_verifications SET status = 'rejected', rejection_reason = ?, reviewed_at = NOW() WHERE id = ?")
                    ->execute([$reason, $bv_id]);
                $pdo->prepare("UPDATE users SET business_verification_status = 'rejected' WHERE id = ?")
                    ->execute([$row['user_id']]);
                $message = "Submission #$bv_id rejected.";
            }
        }
    }
}

$stmt = $pdo->query(
    "SELECT b.*, u.email, u.full_name, u.business_name
     FROM business_verifications b
     JOIN users u ON u.id = b.user_id
     WHERE b.status = 'pending'
     ORDER BY b.submitted_at ASC"
);
$pending = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Business Verification Review | INDBIN Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Arial, sans-serif; }
        body { background: #f4f7fb; padding: 40px 5%; color: #1e293b; }

        h1 { font-size: 24px; color: #0b2545; margin-bottom: 4px; }
        .subtitle { color: #64748b; font-size: 14px; margin-bottom: 24px; }

        .alert { background: #dbeafe; color: #1d4ed8; border: 1px solid #93c5fd; padding: 10px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 20px; }
        .empty-state { background: #fff; border-radius: 12px; padding: 40px; text-align: center; color: #64748b; border: 1px solid #e2e8f0; }

        .bv-row { background: #fff; border-radius: 14px; border: 1px solid #e2e8f0; padding: 22px 25px; margin-bottom: 16px; }
        .bv-row .top-line { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .bv-row h3 { font-size: 16px; color: #0b2545; }
        .role-badge { font-size: 11px; font-weight: 700; text-transform: uppercase; padding: 3px 10px; border-radius: 20px; background: #fff1e5; color: #c2410c; }

        .meta-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; font-size: 13px; color: #475569; margin-bottom: 14px; }
        .meta-grid strong { display: block; color: #0b2545; font-size: 12px; margin-bottom: 2px; }

        .photo-strip { display: flex; gap: 10px; margin-bottom: 16px; flex-wrap: wrap; }
        .photo-strip a { display: block; }
        .photo-strip img { width: 90px; height: 70px; object-fit: cover; border-radius: 6px; border: 1px solid #e2e8f0; }
        .photo-strip .doc-label { font-size: 10px; color: #64748b; text-align: center; margin-top: 3px; }

        .actions { display: flex; gap: 10px; }
        .btn-approve { background: #16a34a; color: #fff; border: none; padding: 9px 18px; border-radius: 6px; font-weight: 600; cursor: pointer; }
        .btn-reject { background: #dc2626; color: #fff; border: none; padding: 9px 18px; border-radius: 6px; font-weight: 600; cursor: pointer; }
        .reject-reason-input { padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; flex: 1; font-size: 13px; }
    </style>
</head>
<body>

    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <span style="font-size:13px; color:#64748b;">Signed in as <strong><?= htmlspecialchars($_SESSION['admin_name']) ?></strong></span>
        <a href="<?= BASE_URL ?>/logout.php" style="font-size:13px; color:#dc2626; text-decoration:none; font-weight:600;">Log out</a>
    </div>

    <h1>Business verification review queue</h1>
    <p class="subtitle"><?= count($pending) ?> submission<?= count($pending) === 1 ? '' : 's' ?> pending review</p>

    <?php if ($message): ?>
        <div class="alert"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if (empty($pending)): ?>
        <div class="empty-state">No pending business verification submissions right now.</div>
    <?php else: ?>
        <?php foreach ($pending as $b): ?>
            <div class="bv-row">
                <div class="top-line">
                    <h3><?= htmlspecialchars($b['business_name'] ?: $b['full_name']) ?></h3>
                    <span class="role-badge">Merchant</span>
                </div>

                <div class="meta-grid">
                    <div><strong>Owner</strong><?= htmlspecialchars($b['full_name']) ?></div>
                    <div><strong>Email</strong><?= htmlspecialchars($b['email']) ?></div>
                    <div><strong>GSTIN</strong><?= htmlspecialchars($b['gstin']) ?></div>
                    <div><strong>Bank A/C</strong><?= htmlspecialchars($b['bank_account_number']) ?></div>
                    <div><strong>IFSC</strong><?= htmlspecialchars($b['ifsc_code']) ?></div>
                    <div><strong>Years in business</strong><?= htmlspecialchars($b['years_in_business'] ?? '—') ?></div>
                    <div><strong>Monthly turnover</strong><?= htmlspecialchars(str_replace(['below_1L','1L_5L','5L_25L','25L_1Cr','above_1Cr'], ['Below ₹1L','₹1–5L','₹5–25L','₹25L–1Cr','Above ₹1Cr'], $b['monthly_turnover'] ?? '—')) ?></div>
                    <div><strong>Submitted</strong><?= htmlspecialchars(date('d M Y, h:i A', strtotime($b['submitted_at']))) ?></div>
                </div>

                <div style="font-size:13px; color:#475569; margin-bottom:14px;">
                    <strong style="color:#0b2545;">Address:</strong> <?= htmlspecialchars($b['business_address']) ?>
                </div>

                <div class="photo-strip">
                    <div><a href="<?= htmlspecialchars($b['gst_certificate_path']) ?>" target="_blank"><img src="<?= htmlspecialchars($b['gst_certificate_path']) ?>" alt="GST cert"></a><div class="doc-label">GST Cert</div></div>
                    <div><a href="<?= htmlspecialchars($b['address_proof_path']) ?>" target="_blank"><img src="<?= htmlspecialchars($b['address_proof_path']) ?>" alt="Address proof"></a><div class="doc-label">Address Proof</div></div>
                    <div><a href="<?= htmlspecialchars($b['cancelled_cheque_path']) ?>" target="_blank"><img src="<?= htmlspecialchars($b['cancelled_cheque_path']) ?>" alt="Cheque"></a><div class="doc-label">Cheque</div></div>
                    <div><a href="<?= htmlspecialchars($b['shop_photo_path']) ?>" target="_blank"><img src="<?= htmlspecialchars($b['shop_photo_path']) ?>" alt="Shop photo"></a><div class="doc-label">Shop Photo</div></div>
                </div>

                <div class="actions">
                    <form method="POST" onsubmit="return confirm('Approve this business verification?');">
                        <input type="hidden" name="bv_id" value="<?= $b['id'] ?>">
                        <input type="hidden" name="action" value="approve">
                        <button type="submit" class="btn-approve">Approve</button>
                    </form>

                    <form method="POST" style="display:flex; gap:8px; flex:1;" onsubmit="return confirm('Reject this submission?');">
                        <input type="hidden" name="bv_id" value="<?= $b['id'] ?>">
                        <input type="hidden" name="action" value="reject">
                        <input type="text" name="reason" class="reject-reason-input" placeholder="Rejection reason (optional)">
                        <button type="submit" class="btn-reject">Reject</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

</body>
</html>