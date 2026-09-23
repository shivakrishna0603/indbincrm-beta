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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")
        ->execute([$uid]);
    redirect(CUST_BASE . '/dashboard/notifications.php');
}

$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 100");
$stmt->execute([$uid]);
$rows = $stmt->fetchAll();

portal_shell_open($pdo, $user, 'notifications', 'Notifications');
?>
<div class="card wide">
    <?php if (!$rows): ?>
        <p style="color:var(--muted);font-size:13px;">Nothing here yet.</p>
    <?php else: ?>
        <form method="post" style="margin-bottom:16px;">
            <?= csrf_field() ?>
            <button class="btn secondary" style="min-height:38px;font-size:13px;">Mark all as read</button>
        </form>
        <?php foreach ($rows as $n): ?>
            <div style="padding:14px 0;border-bottom:1px solid var(--line);
                        <?= (int)$n['is_read'] ? 'opacity:.6;' : '' ?>">
                <strong style="font-size:14px;color:var(--ink);"><?= e($n['title']) ?></strong>
                <?php if (!(int)$n['is_read']): ?><span class="pill is-bad">new</span><?php endif; ?>
                <p style="font-size:13px;color:var(--muted);margin-top:4px;"><?= e($n['message']) ?></p>
                <span style="font-size:11px;color:var(--faint);">
                    <?= e(date('j M Y, H:i', strtotime($n['created_at']))) ?>
                    &bull; <?= e($n['channel']) ?>
                </span>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php portal_shell_close(); ?>
