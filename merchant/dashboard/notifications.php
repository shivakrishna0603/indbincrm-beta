<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../portal/shell.php';
require_once __DIR__ . '/../portal/merchant_shell.php';

$user = require_merchant($pdo);
$user_id = (int)$user['id'];

if ($user['account_status'] !== 'active') { header('Location: ../onboarding_complete/index.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'mark_all_read') {
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")
            ->execute([$user_id]);
    } elseif ($action === 'mark_read') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")
                ->execute([$id, $user_id]);
        }
    }

    header('Location: notifications.php');
    exit;
}

$notifStmt = $pdo->prepare(
    "SELECT * FROM notifications WHERE user_id = ?
     ORDER BY is_read ASC, created_at DESC
     LIMIT 100"
);
$notifStmt->execute([$user_id]);
$notifications = $notifStmt->fetchAll();

$unreadCount = 0;
foreach ($notifications as $n) { if (!$n['is_read']) $unreadCount++; }

render_merchant_shell_open($pdo, $user_id, '', 'Notifications');
?>

<style>
    .notif-head { display: flex; justify-content: space-between; align-items: center; gap: 14px; flex-wrap: wrap; margin-bottom: 18px; }
    .notif-head .count { font-size: 13px; color: var(--muted); }
    .notif-head .count strong { color: var(--brand-deep); }
    .mark-all { padding: 9px 16px; border-radius: 8px; border: 1px solid var(--line); background: var(--surface); color: var(--ink); font-size: 13px; font-weight: 700; cursor: pointer; }
    .mark-all:hover { border-color: var(--brand); color: var(--brand); }

    .notif-item { display: flex; gap: 14px; align-items: flex-start; background: var(--surface); border: 1px solid var(--line); border-radius: 12px; padding: 16px 20px; margin-bottom: 10px; }
    .notif-item.is-unread { border-left: 4px solid var(--brand); background: var(--brand-soft); }
    .notif-icon { width: 38px; height: 38px; border-radius: 10px; background: var(--brand-soft); color: var(--brand-deep); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .notif-item .n-body { flex: 1; min-width: 0; }
    .notif-item .n-title { font-size: 14px; font-weight: 700; color: var(--ink); }
    .notif-item .n-msg { font-size: 13px; color: var(--body); margin-top: 4px; line-height: 1.5; }
    .notif-item .n-meta { font-size: 12px; color: var(--faint); margin-top: 8px; display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
    .notif-item .n-meta a { color: var(--brand); font-weight: 700; text-decoration: none; }
    .notif-item .n-actions { display: flex; flex-direction: column; gap: 8px; align-items: flex-end; flex-shrink: 0; }
    .notif-item .n-actions button { border: none; background: none; color: var(--muted); font-size: 12px; font-weight: 700; cursor: pointer; }
    .notif-item .n-actions button:hover { color: var(--brand); }
    .empty-state { background: var(--surface); border: 1px dashed var(--line); border-radius: 12px; padding: 50px 30px; text-align: center; color: var(--muted); }
</style>

<div class="notif-head">
    <div class="count">
        <?php if ($unreadCount > 0): ?>
            You have <strong><?= $unreadCount ?></strong> unread notification<?= $unreadCount === 1 ? '' : 's' ?>.
        <?php else: ?>
            You're all caught up.
        <?php endif; ?>
    </div>
    <?php if ($unreadCount > 0): ?>
        <form method="POST">
            <input type="hidden" name="action" value="mark_all_read">
            <button type="submit" class="mark-all"><i class="fa-solid fa-check-double"></i> Mark all as read</button>
        </form>
    <?php endif; ?>
</div>

<?php if (empty($notifications)): ?>
    <div class="empty-state">No notifications yet. Updates about your leads, applications, disbursals and support tickets will appear here.</div>
<?php else: ?>
    <?php foreach ($notifications as $n): ?>
        <div class="notif-item <?= $n['is_read'] ? '' : 'is-unread' ?>">
            <div class="notif-icon"><i class="fa-regular fa-bell"></i></div>
            <div class="n-body">
                <div class="n-title"><?= htmlspecialchars($n['title']) ?></div>
                <div class="n-msg"><?= nl2br(htmlspecialchars($n['message'])) ?></div>
                <div class="n-meta">
                    <span><?= htmlspecialchars(date('d M Y, g:i A', strtotime($n['created_at']))) ?></span>
                    <?php if (!empty($n['link'])): ?>
                        <a href="<?= htmlspecialchars($n['link']) ?>">Open <i class="fa-solid fa-arrow-right" style="font-size:9px;"></i></a>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (!$n['is_read']): ?>
                <div class="n-actions">
                    <form method="POST">
                        <input type="hidden" name="action" value="mark_read">
                        <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                        <button type="submit">Mark read</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php render_shell_close(); ?>
