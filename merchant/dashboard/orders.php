<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../portal/shell.php';
require_once __DIR__ . '/../portal/merchant_shell.php';

$user = require_merchant($pdo);
$user_id = (int)$user['id'];

if ($user['account_status'] !== 'active') { header('Location: ../onboarding_complete/index.php'); exit; }

$color = '#f97316';
$lightColor = '#fff1e5';

// Disbursement and repayment are INDBIN's transactions, not the merchant's;
// they no longer appear here. The merchant sees what actually reaches their
// books: settlements, refunds, fees and commission.
$typeTabs = ['all' => 'All', 'settlement' => 'Settlement', 'refund' => 'Refund', 'fee' => 'Fee', 'commission' => 'Commission'];
$activeTab = $_GET['type'] ?? 'all';
if (!isset($typeTabs[$activeTab])) $activeTab = 'all';

$countStmt = $pdo->prepare("SELECT transaction_type, COUNT(*) AS cnt FROM transactions WHERE merchant_id = ? GROUP BY transaction_type");
$countStmt->execute([$user_id]);
$typeCounts = ['all' => 0];
foreach ($countStmt->fetchAll() as $row) {
    $typeCounts[$row['transaction_type']] = (int)$row['cnt'];
    $typeCounts['all'] += (int)$row['cnt'];
}

// Headline figures are over the whole book, not the current filter.
$totalsStmt = $pdo->prepare(
    "SELECT COUNT(*) AS cnt,
            COALESCE(SUM(CASE WHEN status = 'success' AND transaction_type = 'settlement' THEN amount END), 0) AS settled,
            COALESCE(SUM(CASE WHEN status = 'success' AND transaction_type = 'commission' THEN amount END), 0) AS commission
     FROM transactions WHERE merchant_id = ?"
);
$totalsStmt->execute([$user_id]);
$totals = $totalsStmt->fetch();
$totalSettled   = (float)$totals['settled'];
$totalCommission = (float)$totals['commission'];

$txnStmt = $pdo->prepare(
    "SELECT t.*, a.application_number,
            COALESCE(q.product_name, a.product_name) AS product_name
     FROM transactions t
     LEFT JOIN applications a ON a.id = t.application_id
     LEFT JOIN quotes q ON q.id = a.quote_id
     WHERE t.merchant_id = ?" . ($activeTab === 'all' ? '' : " AND t.transaction_type = ?") . "
     ORDER BY t.transaction_date DESC, t.id DESC"
);
$params = [$user_id];
if ($activeTab !== 'all') $params[] = $activeTab;
$txnStmt->execute($params);
$transactions = $txnStmt->fetchAll();

// transactions.status enum is initiated/success/failed/reversed - the old map
// keyed on completed/pending/failed, so every real status rendered with
// undefined-index fallbacks.
$statusColors = ['initiated' => '#2563eb', 'success' => '#059669', 'failed' => '#dc2626', 'reversed' => '#64748b'];

render_merchant_shell_open($pdo, $user_id, 'orders', 'Payments & Transactions');
?>

<style>
    .stat-strip { background: var(--brand-soft); border-radius: 12px; padding: 18px 24px; margin-bottom: 22px; display: flex; gap: 40px; flex-wrap: wrap; }
    .stat-strip .s-item .s-label { font-size: 12px; color: var(--brand-deep); }
    .stat-strip .s-item .s-value { font-size: 20px; font-weight: 800; color: var(--brand-deep); }

    .empty-state { background: var(--surface); border: 1px dashed var(--line); border-radius: 12px; padding: 50px 30px; text-align: center; color: var(--muted); }

    .tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 18px; }
    .tab { display: inline-flex; align-items: center; gap: 7px; padding: 8px 14px; border-radius: 999px; border: 1px solid var(--line); background: var(--surface); color: var(--muted); font-size: 12.5px; font-weight: 700; text-decoration: none; }
    .tab:hover { border-color: var(--brand); color: var(--brand); }
    .tab.active { background: var(--brand); border-color: var(--brand); color: #fff; }
    .tab .tab-count { min-width: 18px; height: 18px; padding: 0 5px; border-radius: 999px; background: var(--canvas); color: var(--muted); font-size: 11px; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; }
    .tab.active .tab-count { background: rgba(255,255,255,.25); color: #fff; }

    table { width: 100%; border-collapse: collapse; background: var(--surface); border-radius: 12px; overflow: hidden; border: 1px solid var(--line); }
    th { text-align: left; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--muted); padding: 12px 16px; background: var(--canvas); border-bottom: 1px solid var(--line); }
    td { padding: 14px 16px; font-size: 13px; border-bottom: 1px solid #f1f5f9; }
    tr:last-child td { border-bottom: none; }
    .status-pill { font-size: 11px; font-weight: 700; padding: 4px 12px; border-radius: 20px; text-transform: capitalize; }
</style>

<div class="stat-strip">
    <div class="s-item"><div class="s-label">Total Transactions</div><div class="s-value"><?= (int)$totals['cnt'] ?></div></div>
    <div class="s-item"><div class="s-label">Total Settled</div><div class="s-value">₹<?= number_format($totalSettled, 0) ?></div></div>
    <div class="s-item"><div class="s-label">Total Commission</div><div class="s-value">₹<?= number_format($totalCommission, 0) ?></div></div>
</div>

<div class="tabs">
    <?php foreach ($typeTabs as $key => $label): ?>
        <a href="orders.php?type=<?= urlencode($key) ?>" class="tab <?= $key === $activeTab ? 'active' : '' ?>">
            <?= htmlspecialchars($label) ?>
            <span class="tab-count"><?= $typeCounts[$key] ?? 0 ?></span>
        </a>
    <?php endforeach; ?>
</div>

<?php if (empty($transactions)): ?>
    <div class="empty-state"><?= $activeTab === 'all' ? 'No transactions yet. Settlements, refunds, fees and commission land here automatically.' : 'No transactions of this type.' ?></div>
<?php else: ?>
    <table>
        <tr>
            <th>Reference</th>
            <th>Application</th>
            <th>Product</th>
            <th>Type</th>
            <th>Amount</th>
            <th>Date</th>
            <th>Status</th>
        </tr>
        <?php foreach ($transactions as $t): ?>
        <tr>
            <td><?= htmlspecialchars($t['reference_number'] ?? '—') ?></td>
            <td><?= htmlspecialchars($t['application_number'] ?? '—') ?></td>
            <td><?= htmlspecialchars($t['product_name'] ?? '—') ?></td>
            <td style="text-transform:capitalize;"><?= htmlspecialchars(str_replace('_', ' ', $t['transaction_type'])) ?></td>
            <td>₹<?= number_format((float)$t['amount'], 0) ?></td>
            <td><?= htmlspecialchars(date('d M Y', strtotime($t['transaction_date']))) ?></td>
            <td><span class="status-pill" style="background:<?= ($statusColors[$t['status']] ?? '#64748b') ?>22; color:<?= ($statusColors[$t['status']] ?? '#64748b') ?>;"><?= htmlspecialchars($t['status']) ?></span></td>
        </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<?php render_shell_close(); ?>
