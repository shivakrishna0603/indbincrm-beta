<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/shell.php';
$admin = require_admin($pdo);

// Hand an unassigned lead to an agent.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    try {
        assign_lead($pdo, post_int('lead_id'), post_int('agent_id'), 'Assigned from admin console');
        flash('success', 'Lead assigned.');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect(BASE_URL . '/admin/leads.php');
}

$rows = $pdo->query(
    "SELECT l.*, a.full_name AS agent_name, m.business_name
       FROM leads l
       LEFT JOIN users a ON a.id = l.agent_id
       LEFT JOIN users m ON m.id = l.merchant_id
      ORDER BY l.id DESC LIMIT 200"
)->fetchAll();

$agents = $pdo->query(
    "SELECT id, full_name FROM users
      WHERE role = 'agent' AND account_status = 'active' ORDER BY full_name"
)->fetchAll();

admin_shell_open($pdo, $admin, 'leads', 'Leads');
?>
<h1 class="admin-h1">Leads</h1>
<p class="admin-sub">Assigning a lead also makes that agent the customer's owner, so their next request routes the same way.</p>

<section class="panel">
    <table class="table">
        <thead><tr><th scope="col">Customer</th><th scope="col">Requirement</th>
        <th scope="col">Source</th><th scope="col">Agent</th><th scope="col">Merchant</th>
        <th scope="col">Status</th><th scope="col">Assign</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="7" style="color:var(--muted);">No leads yet.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= e($r['name']) ?><div style="color:var(--faint);font-size:11px;"><?= e(mask_tail($r['mobile'])) ?></div></td>
                <td><?= e((string)($r['requirement'] ?: '—')) ?></td>
                <td><?= e(str_replace('_',' ',$r['source'])) ?></td>
                <td><?= e((string)($r['agent_name'] ?: '—')) ?></td>
                <td><?= e((string)($r['business_name'] ?: '—')) ?></td>
                <td><span class="pill <?= $r['status']==='converted'?'is-ok':($r['status']==='lost'?'is-bad':'is-warn') ?>"><?= e($r['status']) ?></span></td>
                <td>
                    <?php if ($r['status'] !== 'converted' && $agents): ?>
                    <form method="post" style="display:flex;gap:6px;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="lead_id" value="<?= (int)$r['id'] ?>">
                        <select name="agent_id" required style="min-height:34px;font-size:12px;max-width:150px;">
                            <option value="" disabled selected>Choose agent</option>
                            <?php foreach ($agents as $a): ?>
                                <option value="<?= (int)$a['id'] ?>"><?= e($a['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn" style="min-height:34px;font-size:12px;">Assign</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php admin_shell_close(); ?>
