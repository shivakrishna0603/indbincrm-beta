<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/shell.php';
$admin = require_admin($pdo);

$funnel = $pdo->query(
    "SELECT current_stage, COUNT(*) n, COALESCE(SUM(amount),0) total
       FROM applications
      GROUP BY current_stage
      ORDER BY FIELD(current_stage,'submitted','kyc_check','credit_review','approved','disbursed')"
)->fetchAll();

$topAgents = $pdo->query(
    "SELECT u.full_name, COUNT(c.id) deals, COALESCE(SUM(c.amount),0) earned
       FROM users u
       LEFT JOIN commissions c ON c.earner_id = u.id AND c.earner_role = 'agent'
      WHERE u.role = 'agent'
      GROUP BY u.id ORDER BY earned DESC, deals DESC LIMIT 10"
)->fetchAll();

$topMerchants = $pdo->query(
    "SELECT COALESCE(u.business_name, u.full_name) AS nm,
            COUNT(a.id) apps, COALESCE(SUM(a.amount),0) volume
       FROM users u LEFT JOIN applications a ON a.merchant_id = u.id
      WHERE u.role = 'merchant'
      GROUP BY u.id ORDER BY volume DESC LIMIT 10"
)->fetchAll();

$maxFunnel = max(1, ...array_map(static fn($f) => (int)$f['n'], $funnel ?: [['n' => 1]]));

admin_shell_open($pdo, $admin, 'reports', 'Reports');
?>
<h1 class="admin-h1">Reports</h1>
<p class="admin-sub">Where applications stop, and who is producing.</p>

<div class="admin-cols-3">
    <section class="panel">
        <h2>Application funnel</h2>
        <?php if (!$funnel): ?><p class="panel-sub">No applications yet.</p><?php endif; ?>
        <table class="mini-chart" style="margin-top:14px;">
        <?php foreach ($funnel as $f): ?>
            <tr>
                <th scope="row" style="width:92px;"><?= e(str_replace('_',' ',$f['current_stage'])) ?></th>
                <td>
                    <span class="bar" style="width:<?= max(round((int)$f['n']/$maxFunnel*100,1),2) ?>%;background:var(--brand);height:14px;"></span>
                </td>
                <td style="width:40px;text-align:right;font-weight:700;"><?= (int)$f['n'] ?></td>
            </tr>
        <?php endforeach; ?>
        </table>
    </section>

    <section class="panel">
        <h2>Top agents</h2>
        <table class="table" style="margin-top:10px;">
            <thead><tr><th scope="col">Agent</th><th scope="col">Deals</th><th scope="col">Earned</th></tr></thead>
            <tbody>
            <?php if (!$topAgents): ?><tr><td colspan="3" style="color:var(--muted);">None yet.</td></tr><?php endif; ?>
            <?php foreach ($topAgents as $a): ?>
                <tr><td><?= e($a['full_name']) ?></td><td><?= (int)$a['deals'] ?></td>
                    <td><?= money((float)$a['earned']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="panel">
        <h2>Top merchants</h2>
        <table class="table" style="margin-top:10px;">
            <thead><tr><th scope="col">Merchant</th><th scope="col">Apps</th><th scope="col">Volume</th></tr></thead>
            <tbody>
            <?php if (!$topMerchants): ?><tr><td colspan="3" style="color:var(--muted);">None yet.</td></tr><?php endif; ?>
            <?php foreach ($topMerchants as $m): ?>
                <tr><td><?= e($m['nm']) ?></td><td><?= (int)$m['apps'] ?></td>
                    <td><?= money((float)$m['volume']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</div>
<?php admin_shell_close(); ?>
