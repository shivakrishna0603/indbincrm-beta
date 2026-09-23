<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../portal/shell.php';
require_once __DIR__ . '/../portal/merchant_shell.php';

$user = require_merchant($pdo);
$user_id = (int)$user['id'];

if ($user['account_status'] !== 'active') { header('Location: ../onboarding_complete/index.php'); exit; }

$color = '#f97316';
$lightColor = '#fff1e5';

$totalLeads = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE merchant_id = ?");
$totalLeads->execute([$user_id]);
$totalLeads = (int)$totalLeads->fetchColumn();

$totalQuotes = $pdo->prepare("SELECT COUNT(*) FROM quotes WHERE merchant_id = ?");
$totalQuotes->execute([$user_id]);
$totalQuotes = (int)$totalQuotes->fetchColumn();

$totalApps = $pdo->prepare("SELECT COUNT(*) FROM applications WHERE merchant_id = ?");
$totalApps->execute([$user_id]);
$totalApps = (int)$totalApps->fetchColumn();

$settledStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE merchant_id = ? AND status = 'success' AND transaction_type = 'settlement'");
$settledStmt->execute([$user_id]);
$totalSettled = (float)$settledStmt->fetchColumn();

$commissionStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(amount),0) FROM commissions
      WHERE earner_id = ? AND earner_role = 'merchant'
        AND status IN ('accrued','approved','paid','credited')"
);
$commissionStmt->execute([$user_id]);
$totalCommission = (float)$commissionStmt->fetchColumn();

$conversionRate = $totalLeads > 0 ? round(($totalApps / $totalLeads) * 100) : 0;

// Leads by status - full leads.status enum
$leadStatusStmt = $pdo->prepare("SELECT status, COUNT(*) as cnt FROM leads WHERE merchant_id = ? GROUP BY status");
$leadStatusStmt->execute([$user_id]);
$leadsByStatus = ['new' => 0, 'assigned' => 0, 'contacted' => 0, 'interested' => 0, 'not_interested' => 0, 'nurturing' => 0, 'converted' => 0, 'lost' => 0];
foreach ($leadStatusStmt->fetchAll() as $row) { $leadsByStatus[$row['status']] = (int)$row['cnt']; }

// Applications by status - full applications.status enum
$appStatusStmt = $pdo->prepare("SELECT status, COUNT(*) as cnt FROM applications WHERE merchant_id = ? GROUP BY status");
$appStatusStmt->execute([$user_id]);
$appsByStatus = ['draft' => 0, 'requirement_raised' => 0, 'submitted' => 0, 'under_review' => 0, 'approved' => 0, 'rejected' => 0, 'completed' => 0, 'withdrawn' => 0];
foreach ($appStatusStmt->fetchAll() as $row) { $appsByStatus[$row['status']] = (int)$row['cnt']; }

render_merchant_shell_open($pdo, $user_id, 'reports', 'Analytics & Business Reports');
?>

<style>
    .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px; }
    .stat-card { background: var(--surface); border: 1px solid var(--line); border-radius: 12px; padding: 20px; }
    .stat-card .s-label { font-size: 12px; color: var(--muted); margin-bottom: 6px; }
    .stat-card .s-value { font-size: 24px; font-weight: 800; color: var(--ink); }

    .chart-row { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
    @media (max-width: 900px) { .chart-row { grid-template-columns: 1fr; } }
    .chart-card { background: var(--surface); border: 1px solid var(--line); border-radius: 12px; padding: 22px; }
    .chart-card h3 { font-size: 15px; color: var(--ink); margin-bottom: 16px; }

    .bar-row { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
    .bar-label { width: 110px; font-size: 12px; color: var(--muted); text-transform: capitalize; flex-shrink: 0; }
    .bar-track { flex: 1; height: 18px; background: #f1f5f9; border-radius: 4px; overflow: hidden; }
    .bar-fill { height: 100%; border-radius: 4px; }
    .bar-count { width: 24px; font-size: 12px; font-weight: 700; color: var(--ink); text-align: right; }
</style>

<div class="stat-grid">
    <div class="stat-card"><div class="s-label">Total Leads</div><div class="s-value"><?= $totalLeads ?></div></div>
    <div class="stat-card"><div class="s-label">Applications</div><div class="s-value"><?= $totalApps ?></div></div>
    <div class="stat-card"><div class="s-label">Quotes Sent</div><div class="s-value"><?= $totalQuotes ?></div></div>
    <div class="stat-card"><div class="s-label">Lead-to-Application Rate</div><div class="s-value"><?= $conversionRate ?>%</div></div>
    <div class="stat-card"><div class="s-label">Commission Earned</div><div class="s-value">₹<?= number_format($totalCommission, 0) ?></div></div>
    <div class="stat-card"><div class="s-label">Settlements</div><div class="s-value">₹<?= number_format($totalSettled, 0) ?></div></div>
</div>

<div class="chart-row">
    <div class="chart-card">
        <h3>Leads by Status</h3>
        <?php
        $leadColors = ['new' => '#2563eb', 'assigned' => '#7c3aed', 'contacted' => '#b45309', 'interested' => '#0ea5e9', 'not_interested' => '#dc2626', 'nurturing' => '#d97706', 'converted' => '#059669', 'lost' => '#64748b'];
        $maxLead = max(1, max($leadsByStatus));
        foreach ($leadsByStatus as $status => $count):
        ?>
        <div class="bar-row">
            <div class="bar-label"><?= htmlspecialchars(str_replace('_', ' ', $status)) ?></div>
            <div class="bar-track"><div class="bar-fill" style="width:<?= round($count / $maxLead * 100) ?>%; background:<?= $leadColors[$status] ?? '#64748b' ?>;"></div></div>
            <div class="bar-count"><?= $count ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="chart-card">
        <h3>Applications by Status</h3>
        <?php
        $appColors = ['draft' => '#94a3b8', 'requirement_raised' => '#2563eb', 'submitted' => '#2563eb', 'under_review' => '#b45309', 'approved' => '#059669', 'rejected' => '#dc2626', 'completed' => '#0d9488', 'withdrawn' => '#64748b'];
        $maxApp = max(1, max($appsByStatus));
        foreach ($appsByStatus as $status => $count):
        ?>
        <div class="bar-row">
            <div class="bar-label"><?= htmlspecialchars(str_replace('_', ' ', $status)) ?></div>
            <div class="bar-track"><div class="bar-fill" style="width:<?= round($count / $maxApp * 100) ?>%; background:<?= $appColors[$status] ?? '#64748b' ?>;"></div></div>
            <div class="bar-count"><?= $count ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php render_shell_close(); ?>
