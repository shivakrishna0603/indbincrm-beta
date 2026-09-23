<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/shell.php';
$admin = require_admin($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id       = post_int('ticket_id');
    $response = post_str('admin_response', 4000);
    $status   = post_str('status', 20);

    if ($response === '') {
        flash('error', 'Write a response before closing a ticket.');
    } else {
        $pdo->prepare("UPDATE support_tickets SET admin_response = ?, status = ?, assigned_to = ? WHERE id = ?")
            ->execute([$response, in_array($status,['in_progress','resolved','closed'],true)?$status:'in_progress',
                       (int)$admin['id'], $id]);

        $stmt = $pdo->prepare("SELECT user_id, ticket_code FROM support_tickets WHERE id = ?");
        $stmt->execute([$id]);
        if ($t = $stmt->fetch()) {
            notify($pdo, (int)$t['user_id'], 'Reply on ' . $t['ticket_code'], $response);
        }
        flash('success', 'Response sent.');
    }
    redirect(BASE_URL . '/admin/support.php');
}

$rows = $pdo->query(
    "SELECT t.*, u.full_name, u.role FROM support_tickets t
       JOIN users u ON u.id = t.user_id
      ORDER BY FIELD(t.status,'open','in_progress','waiting_customer','resolved','closed'),
               FIELD(t.priority,'urgent','high','normal','low'), t.id DESC LIMIT 100"
)->fetchAll();

admin_shell_open($pdo, $admin, 'support', 'Support tickets');
?>
<h1 class="admin-h1">Support tickets</h1>
<p class="admin-sub">Open and urgent first, from customers, agents and merchants alike.</p>
<section class="panel">
    <?php if (!$rows): ?><p class="panel-sub">No tickets.</p><?php endif; ?>
    <?php foreach ($rows as $t): ?>
        <div style="border-bottom:1px solid var(--line);padding:16px 0;">
            <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                <strong style="color:var(--ink);"><?= e($t['ticket_code']) ?> &mdash; <?= e($t['subject']) ?></strong>
                <span>
                    <span class="pill is-idle"><?= e($t['role']) ?></span>
                    <span class="pill <?= in_array($t['status'],['resolved','closed'],true)?'is-ok':'is-warn' ?>"><?= e(str_replace('_',' ',$t['status'])) ?></span>
                </span>
            </div>
            <p style="font-size:13px;color:var(--muted);margin:8px 0;">
                <?= e((string)($t['description'] ?: $t['message'] ?: '')) ?>
            </p>
            <?php if ($t['admin_response']): ?>
                <p style="font-size:13px;color:var(--ok);margin-bottom:8px;">
                    Replied: <?= e($t['admin_response']) ?></p>
            <?php endif; ?>
            <?php if (!in_array($t['status'], ['resolved','closed'], true)): ?>
            <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                <?= csrf_field() ?>
                <input type="hidden" name="ticket_id" value="<?= (int)$t['id'] ?>">
                <input type="text" name="admin_response" required placeholder="Your response"
                       style="flex:1;min-width:240px;min-height:36px;font-size:13px;">
                <select name="status" style="min-height:36px;font-size:13px;max-width:150px;">
                    <option value="in_progress">In progress</option>
                    <option value="resolved">Resolved</option>
                    <option value="closed">Closed</option>
                </select>
                <button class="btn" style="min-height:36px;font-size:13px;">Send</button>
            </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</section>
<?php admin_shell_close(); ?>
