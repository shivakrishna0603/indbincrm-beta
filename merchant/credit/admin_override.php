<?php
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'override') {
    $target_user_id = (int)($_POST['user_id'] ?? 0);
    $new_limit = (float)($_POST['new_limit'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');

    if ($target_user_id && $new_limit > 0 && $reason) {
        $current = $pdo->prepare("SELECT risk_score, risk_category FROM users WHERE id = ?");
        $current->execute([$target_user_id]);
        $row = $current->fetch();

        $pdo->prepare("UPDATE users SET credit_limit = ? WHERE id = ?")->execute([$new_limit, $target_user_id]);

        $pdo->prepare(
            "INSERT INTO credit_assessments (user_id, risk_score, risk_category, credit_limit, is_override, overridden_by, override_reason)
             VALUES (?, ?, ?, ?, 1, ?, ?)"
        )->execute([
            $target_user_id,
            $row['risk_score'] ?? 0,
            $row['risk_category'] ?? 'medium',
            $new_limit,
            $_SESSION['admin_id'],
            $reason,
        ]);

        $message = "Credit limit updated for user #$target_user_id.";
    } else {
        $message = "Enter a valid limit and reason.";
    }
}

$stmt = $pdo->query(
    "SELECT id, full_name, email, business_name, risk_score, risk_category, credit_limit
     FROM users
     WHERE role = 'merchant' AND risk_score IS NOT NULL
     ORDER BY risk_score ASC"
);
$merchants = $stmt->fetchAll();

$categoryColors = ['low' => '#059669', 'medium' => '#b45309', 'high' => '#dc2626'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Credit & Risk Overview | INDBIN Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Arial, sans-serif; }
        body { background: #f4f7fb; padding: 40px 5%; color: #1e293b; }

        h1 { font-size: 24px; color: #0b2545; margin-bottom: 4px; }
        .subtitle { color: #64748b; font-size: 14px; margin-bottom: 24px; }

        .alert { background: #dbeafe; color: #1d4ed8; border: 1px solid #93c5fd; padding: 10px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 20px; }
        .empty-state { background: #fff; border-radius: 12px; padding: 40px; text-align: center; color: #64748b; border: 1px solid #e2e8f0; }

        table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 12px; overflow: hidden; border: 1px solid #e2e8f0; }
        th { text-align: left; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; padding: 12px 16px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
        td { padding: 14px 16px; font-size: 13px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        tr:last-child td { border-bottom: none; }

        .cat-pill { display: inline-block; padding: 3px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: capitalize; }

        .override-form { display: flex; gap: 6px; align-items: center; }
        .override-form input[type=number] { width: 100px; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; }
        .override-form input[type=text] { width: 130px; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; }
        .override-form button { padding: 6px 12px; border: none; border-radius: 6px; background: #0b2545; color: #fff; font-size: 12px; font-weight: 600; cursor: pointer; }
    </style>
</head>
<body>

    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <span style="font-size:13px; color:#64748b;">Signed in as <strong><?= htmlspecialchars($_SESSION['admin_name']) ?></strong></span>
        <a href="<?= BASE_URL ?>/logout.php" style="font-size:13px; color:#dc2626; text-decoration:none; font-weight:600;">Log out</a>
    </div>

    <h1>Credit & risk overview</h1>
    <p class="subtitle"><?= count($merchants) ?> scored merchant<?= count($merchants) === 1 ? '' : 's' ?></p>

    <?php if ($message): ?>
        <div class="alert"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if (empty($merchants)): ?>
        <div class="empty-state">No merchants have been scored yet. Scores are generated automatically when a business verification is approved.</div>
    <?php else: ?>
        <table>
            <tr>
                <th>Merchant</th>
                <th>Score</th>
                <th>Category</th>
                <th>Credit limit</th>
                <th>Override</th>
            </tr>
            <?php foreach ($merchants as $m): ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($m['business_name'] ?: $m['full_name']) ?></strong><br>
                        <span style="color:#94a3b8;"><?= htmlspecialchars($m['email']) ?></span>
                    </td>
                    <td><?= (int)$m['risk_score'] ?>/100</td>
                    <td><span class="cat-pill" style="background:<?= $categoryColors[$m['risk_category']] ?>22; color:<?= $categoryColors[$m['risk_category']] ?>;"><?= htmlspecialchars($m['risk_category']) ?></span></td>
                    <td>₹<?= number_format((float)$m['credit_limit'], 0) ?></td>
                    <td>
                        <form method="POST" class="override-form" onsubmit="return confirm('Override credit limit for this merchant?');">
                            <input type="hidden" name="action" value="override">
                            <input type="hidden" name="user_id" value="<?= $m['id'] ?>">
                            <input type="number" name="new_limit" placeholder="New ₹ limit" min="1" required>
                            <input type="text" name="reason" placeholder="Reason" required>
                            <button type="submit">Save</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

</body>
</html>
