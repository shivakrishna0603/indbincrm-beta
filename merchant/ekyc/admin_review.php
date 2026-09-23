<?php
require_once __DIR__ . '/../db.php';

// Requires a logged-in admin session
if (!isset($_SESSION['admin_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $doc_id = (int)($_POST['doc_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($doc_id && in_array($action, ['approve', 'reject'])) {
        $docStmt = $pdo->prepare("SELECT user_id FROM kyc_documents WHERE id = ?");
        $docStmt->execute([$doc_id]);
        $row = $docStmt->fetch();

        if ($row) {
            if ($action === 'approve') {
                $pdo->prepare("UPDATE kyc_documents SET status = 'approved', reviewed_at = NOW() WHERE id = ?")
                    ->execute([$doc_id]);
                $pdo->prepare("UPDATE users SET kyc_status = 'approved' WHERE id = ?")
                    ->execute([$row['user_id']]);
                $message = "Submission #$doc_id approved.";
            } else {
                $reason = trim($_POST['reason'] ?? 'Documents did not meet verification requirements.');
                $pdo->prepare("UPDATE kyc_documents SET status = 'rejected', rejection_reason = ?, reviewed_at = NOW() WHERE id = ?")
                    ->execute([$reason, $doc_id]);
                $pdo->prepare("UPDATE users SET kyc_status = 'rejected' WHERE id = ?")
                    ->execute([$row['user_id']]);
                $message = "Submission #$doc_id rejected.";
            }
        }
    }
}

$stmt = $pdo->query(
    "SELECT k.*, u.email, u.role
     FROM kyc_documents k
     JOIN users u ON u.id = k.user_id
     WHERE k.status = 'pending'
     ORDER BY k.submitted_at ASC"
);
$pending = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KYC Review | INDBIN Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Arial, sans-serif; }
        body { background: #f4f7fb; padding: 40px 5%; color: #1e293b; }

        h1 { font-size: 24px; color: #0b2545; margin-bottom: 4px; }
        .subtitle { color: #64748b; font-size: 14px; margin-bottom: 24px; }

        .alert { background: #dbeafe; color: #1d4ed8; border: 1px solid #93c5fd; padding: 10px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 20px; }

        .empty-state { background: #fff; border-radius: 12px; padding: 40px; text-align: center; color: #64748b; border: 1px solid #e2e8f0; }

        .kyc-row { background: #fff; border-radius: 14px; border: 1px solid #e2e8f0; padding: 22px 25px; margin-bottom: 16px; display: flex; gap: 24px; align-items: flex-start; }
        .kyc-photo { width: 110px; height: 80px; border-radius: 8px; object-fit: cover; border: 1px solid #e2e8f0; flex-shrink: 0; background: #f1f5f9; }
        .kyc-info { flex: 1; }
        .kyc-info .top-line { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
        .kyc-info h3 { font-size: 16px; color: #0b2545; }
        .role-badge { font-size: 11px; font-weight: 700; text-transform: uppercase; padding: 3px 10px; border-radius: 20px; background: #eef1f8; color: #475569; }
        .kyc-info .meta { font-size: 13px; color: #475569; line-height: 1.7; }
        .kyc-info .meta strong { color: #0b2545; }

        .actions { display: flex; gap: 10px; margin-top: 14px; }
        .actions button, .actions input[type=text] { font-size: 13px; }
        .btn-approve { background: #16a34a; color: #fff; border: none; padding: 9px 18px; border-radius: 6px; font-weight: 600; cursor: pointer; }
        .btn-reject { background: #dc2626; color: #fff; border: none; padding: 9px 18px; border-radius: 6px; font-weight: 600; cursor: pointer; }
        .reject-reason-input { padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; flex: 1; }
    </style>
</head>
<body>

    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <span style="font-size:13px; color:#64748b;">Signed in as <strong><?= htmlspecialchars($_SESSION['admin_name']) ?></strong></span>
        <a href="<?= BASE_URL ?>/logout.php" style="font-size:13px; color:#dc2626; text-decoration:none; font-weight:600;">Log out</a>
    </div>

    <h1>KYC review queue</h1>
    <p class="subtitle"><?= count($pending) ?> submission<?= count($pending) === 1 ? '' : 's' ?> pending review</p>

    <?php if ($message): ?>
        <div class="alert"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if (empty($pending)): ?>
        <div class="empty-state">No pending KYC submissions right now.</div>
    <?php else: ?>
        <?php foreach ($pending as $doc): ?>
            <div class="kyc-row">
                <img class="kyc-photo" src="<?= htmlspecialchars($doc['id_photo_path']) ?>" alt="ID document">
                <div class="kyc-info">
                    <div class="top-line">
                        <h3><?= htmlspecialchars($doc['full_name_on_document']) ?></h3>
                        <span class="role-badge"><?= htmlspecialchars($doc['role']) ?></span>
                    </div>
                    <div class="meta">
                        <strong>Email:</strong> <?= htmlspecialchars($doc['email']) ?><br>
                        <strong>Mobile:</strong> <?= htmlspecialchars($doc['mobile_number']) ?><br>
                        <strong>Document:</strong> <?= htmlspecialchars(ucwords(str_replace('_', ' ', $doc['document_type']))) ?> — <?= htmlspecialchars($doc['document_number']) ?><br>
                        <strong>Submitted:</strong> <?= htmlspecialchars(date('d M Y, h:i A', strtotime($doc['submitted_at']))) ?>
                    </div>

                    <div class="actions">
                        <form method="POST" onsubmit="return confirm('Approve this KYC submission?');">
                            <input type="hidden" name="doc_id" value="<?= $doc['id'] ?>">
                            <input type="hidden" name="action" value="approve">
                            <button type="submit" class="btn-approve">Approve</button>
                        </form>

                        <form method="POST" style="display:flex; gap:8px; flex:1;" onsubmit="return confirm('Reject this KYC submission?');">
                            <input type="hidden" name="doc_id" value="<?= $doc['id'] ?>">
                            <input type="hidden" name="action" value="reject">
                            <input type="text" name="reason" class="reject-reason-input" placeholder="Rejection reason (optional)">
                            <button type="submit" class="btn-reject">Reject</button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

</body>
</html>