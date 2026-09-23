<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/shell.php';
$admin = require_admin($pdo);

$action = (string)($_GET['action'] ?? '');
$sql  = "SELECT l.*, u.full_name AS actor_name FROM audit_logs l
           LEFT JOIN users u ON u.id = l.actor_id";
$args = [];
if ($action !== '') { $sql .= " WHERE l.action = ?"; $args[] = $action; }
$sql .= " ORDER BY l.id DESC LIMIT 200";

$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$rows = $stmt->fetchAll();

$actions = $pdo->query("SELECT action, COUNT(*) n FROM audit_logs
                         GROUP BY action ORDER BY n DESC LIMIT 20")->fetchAll();

admin_shell_open($pdo, $admin, 'audit', 'Audit logs');
?>
<h1 class="admin-h1">Audit logs</h1>
<p class="admin-sub">Who did what, when, and from where. Written by every module, never edited.</p>

<section class="panel">
    <div class="panel-head">
        <h2><?= $action ? e($action) : 'All actions' ?></h2>
        <?php if ($action): ?><a href="?">Clear filter</a><?php endif; ?>
    </div>

    <p style="margin-bottom:14px;">
    <?php foreach ($actions as $a): ?>
        <a href="?action=<?= e($a['action']) ?>" class="pill is-idle"
           style="text-decoration:none;margin:0 5px 5px 0;display:inline-block;">
            <?= e($a['action']) ?> <?= (int)$a['n'] ?>
        </a>
    <?php endforeach; ?>
    </p>

    <table class="table">
        <thead><tr><th scope="col">When</th><th scope="col">Actor</th><th scope="col">Action</th>
        <th scope="col">Entity</th><th scope="col">IP</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="5" style="color:var(--muted);">Nothing logged yet.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= e(date('j M, H:i:s', strtotime($r['created_at']))) ?></td>
                <td><?= e((string)($r['actor_name'] ?: 'system')) ?>
                    <div style="color:var(--faint);font-size:11px;"><?= e((string)$r['actor_role']) ?></div></td>
                <td><?= e($r['action']) ?></td>
                <td><?= e($r['entity']) ?><?= $r['entity_id'] ? ' #' . e($r['entity_id']) : '' ?></td>
                <td style="font-size:11px;color:var(--faint);">
                    <?= e((string)(@inet_ntop((string)$r['request_ip']) ?: '—')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php admin_shell_close(); ?>
