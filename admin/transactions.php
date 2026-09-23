<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/shell.php';
$admin = require_admin($pdo);

$rows = $pdo->query(
    "SELECT t.*, a.application_number, m.business_name
       FROM transactions t
       LEFT JOIN applications a ON a.id = t.application_id
       LEFT JOIN users m ON m.id = t.merchant_id
      ORDER BY t.id DESC LIMIT 200"
)->fetchAll();

admin_shell_open($pdo, $admin, 'transactions', 'Transactions');
?>
<h1 class="admin-h1">Transactions</h1>
<p class="admin-sub">Disbursements, repayments and settlements, newest first.</p>
<section class="panel">
    <table class="table">
        <thead><tr><th scope="col">Reference</th><th scope="col">Type</th><th scope="col">Application</th>
        <th scope="col">Merchant</th><th scope="col">Amount</th><th scope="col">Status</th><th scope="col">When</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="7" style="color:var(--muted);">No transactions yet.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= e($r['reference_number']) ?></td>
                <td><?= e(str_replace('_',' ',$r['transaction_type'])) ?></td>
                <td><?= e((string)($r['application_number'] ?: '—')) ?></td>
                <td><?= e((string)($r['business_name'] ?: '—')) ?></td>
                <td><?= money((float)$r['amount']) ?></td>
                <td><span class="pill <?= $r['status']==='success'?'is-ok':($r['status']==='failed'?'is-bad':'is-warn') ?>"><?= e($r['status']) ?></span></td>
                <td><?= e(date('j M Y, H:i', strtotime($r['created_at']))) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php admin_shell_close(); ?>
