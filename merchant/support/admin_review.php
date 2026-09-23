<?php
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ticket_id = (int)($_POST['ticket_id'] ?? 0);
    $action    = $_POST['action'] ?? '';

    if ($ticket_id && $action === 'respond') {
        $response = trim($_POST['response'] ?? '');
        $newStatus = $_POST['new_status'] ?? 'in_progress';
        if (!in_array($newStatus, ['open', 'in_progress', 'resolved', 'closed'])) $newStatus = 'in_progress';

        $pdo->prepare("UPDATE support_tickets SET admin_response = ?, status = ? WHERE id = ?")
            ->execute([$response, $newStatus, $ticket_id]);
        $message = "Ticket #$ticket_id updated.";
    }
}

$stmt = $pdo->query(
    "SELECT t.*, u.business_name, u.full_name, u.email
     FROM support_tickets t
     JOIN users u ON u.id = t.merchant_id
     WHERE t.status != 'closed'
     ORDER BY FIELD(t.priority,'high','medium','low'), t.created_at ASC"
);
$tickets = $stmt->fetchAll();

$statusColors = ['open' => '#2563eb', 'in_progress' => '#b45309', 'resolved' => '#059669'];
$priorityColors = ['low' => '#64748b', 'medium' => '#b45309', 'high' => '#dc2626'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support Tickets | INDBIN Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Arial, sans-serif; }
        body { background: #f4f7fb; padding: 40px 5%; color: #1e293b; }
        h1 { font-size: 24px; color: #0b2545; margin-bottom: 4px; }
        .subtitle { color: #64748b; font-size: 14px; margin-bottom: 24px; }
        .alert { background: #dbeafe; color: #1d4ed8; border: 1px solid #93c5fd; padding: 10px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 20px; }
        .empty-state { background: #fff; border-radius: 12px; padding: 40px; text-align: center; color: #64748b; border: 1px solid #e2e8f0; }
        .row { background: #fff; border-radius: 12px; border: 1px solid #e2e8f0; padding: 18px 22px; margin-bottom: 14px; }
        .row-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; }
        .row .t-subject { font-size: 15px; font-weight: 700; color: #0b2545; }
        .row .meta { font-size: 12px; color: #64748b; margin-top: 4px; }
        .pill-row { display: flex; gap: 6px; }
        .status-pill { font-size: 11px; font-weight: 700; padding: 4px 12px; border-radius: 20px; text-transform: capitalize; }
        .desc { font-size: 13px; color: #475569; background: #f8fafc; padding: 10px 14px; border-radius: 8px; margin-bottom: 12px; }
        .response-form { display: flex; gap: 8px; align-items: flex-start; }
        .response-form textarea { flex: 1; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; font-family: inherit; }
        .response-form select { padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; }
        .btn { padding: 9px 16px; border: none; border-radius: 6px; background: #0b2545; color: #fff; font-size: 12px; font-weight: 700; cursor: pointer; }
    </style>
</head>
<body>

    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <span style="font-size:13px; color:#64748b;">Signed in as <strong><?= htmlspecialchars($_SESSION['admin_name']) ?></strong></span>
        <a href="<?= BASE_URL ?>/logout.php" style="font-size:13px; color:#dc2626; text-decoration:none; font-weight:600;">Log out</a>
    </div>

    <h1>Support tickets</h1>
    <p class="subtitle"><?= count($tickets) ?> open ticket<?= count($tickets) === 1 ? '' : 's' ?></p>

    <?php if ($message): ?><div class="alert"><?= htmlspecialchars($message) ?></div><?php endif; ?>

    <?php if (empty($tickets)): ?>
        <div class="empty-state">No open tickets right now.</div>
    <?php else: ?>
        <?php foreach ($tickets as $t): ?>
            <div class="row">
                <div class="row-top">
                    <div>
                        <div class="t-subject"><?= htmlspecialchars($t['subject']) ?></div>
                        <div class="meta"><?= htmlspecialchars($t['business_name'] ?: $t['full_name']) ?> (<?= htmlspecialchars($t['email']) ?>) &middot; Raised <?= htmlspecialchars(date('d M Y', strtotime($t['created_at']))) ?></div>
                    </div>
                    <div class="pill-row">
                        <span class="status-pill" style="background:<?= $priorityColors[$t['priority']] ?>22; color:<?= $priorityColors[$t['priority']] ?>;"><?= htmlspecialchars($t['priority']) ?></span>
                        <span class="status-pill" style="background:<?= $statusColors[$t['status']] ?>22; color:<?= $statusColors[$t['status']] ?>;"><?= htmlspecialchars(str_replace('_', ' ', $t['status'])) ?></span>
                    </div>
                </div>
                <div class="desc"><?= nl2br(htmlspecialchars($t['description'])) ?></div>

                <form method="POST" class="response-form">
                    <input type="hidden" name="ticket_id" value="<?= $t['id'] ?>">
                    <input type="hidden" name="action" value="respond">
                    <textarea name="response" rows="2" placeholder="Write a response..."><?= htmlspecialchars($t['admin_response'] ?? '') ?></textarea>
                    <select name="new_status">
                        <option value="in_progress" <?= $t['status'] === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                        <option value="resolved" <?= $t['status'] === 'resolved' ? 'selected' : '' ?>>Resolved</option>
                        <option value="closed">Closed</option>
                    </select>
                    <button type="submit" class="btn">Save</button>
                </form>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

</body>
</html>
