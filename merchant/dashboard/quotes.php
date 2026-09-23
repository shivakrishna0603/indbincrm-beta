<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../portal/shell.php';
require_once __DIR__ . '/../portal/merchant_shell.php';

$user = require_merchant($pdo);
$user_id = (int)$user['id'];

if ($user['account_status'] !== 'active') { header('Location: ../onboarding_complete/index.php'); exit; }

$color = '#f97316';
$lightColor = '#fff1e5';

// quotes.status enum is draft/sent/accepted/declined/expired. The old map (and
// the "Mark Rejected" action) wrote 'rejected', which is not in the enum, so
// under STRICT_TRANS_TABLES marking a quote rejected threw instead of saving.
$statusColors = ['draft' => '#64748b', 'sent' => '#2563eb', 'accepted' => '#059669', 'declined' => '#dc2626', 'expired' => '#64748b'];
$statusLabels = ['draft' => 'Draft', 'sent' => 'Sent', 'accepted' => 'Accepted', 'declined' => 'Declined', 'expired' => 'Expired'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $quote_id = (int)($_POST['quote_id'] ?? 0);
    $action   = $_POST['action'] ?? '';

    $qStmt = $pdo->prepare("SELECT * FROM quotes WHERE id = ? AND merchant_id = ?");
    $qStmt->execute([$quote_id, $user_id]);
    $quote = $qStmt->fetch();

    if (!$quote) {
        flash('error', 'Quote not found.');
    } elseif ($action === 'mark_accepted' && $quote['status'] === 'sent') {
        $pdo->prepare("UPDATE quotes SET status = 'accepted' WHERE id = ?")->execute([$quote_id]);
        audit_log($pdo, 'quote.accepted', 'quotes', (string)$quote_id, ['status' => 'sent'], ['status' => 'accepted']);
        flash('success', 'Quote marked as accepted.');
    } elseif ($action === 'mark_declined' && $quote['status'] === 'sent') {
        $pdo->prepare("UPDATE quotes SET status = 'declined' WHERE id = ?")->execute([$quote_id]);
        audit_log($pdo, 'quote.declined', 'quotes', (string)$quote_id, ['status' => 'sent'], ['status' => 'declined']);
        flash('success', 'Quote marked as declined.');
    } elseif ($action === 'reopen' && in_array($quote['status'], ['declined', 'expired'], true)) {
        $pdo->prepare("UPDATE quotes SET status = 'sent' WHERE id = ?")->execute([$quote_id]);
        flash('success', 'Quote reopened and marked as sent.');
    } elseif ($action === 'convert' && $quote['status'] === 'accepted') {
        $existing = $pdo->prepare("SELECT id FROM applications WHERE quote_id = ?");
        $existing->execute([$quote_id]);
        if ($existing->fetch()) {
            flash('error', 'This quote has already been converted to an application.');
        } else {
            // Carry the lead's agent onto the application so whoever
            // nurtured the customer still earns commission on disbursal.
            $leadInfo = $pdo->prepare("SELECT agent_id, customer_id FROM leads WHERE id = ?");
            $leadInfo->execute([$quote['lead_id']]);
            $leadRow = $leadInfo->fetch() ?: [];

            // INDBIN-style reference, same format as create_application():
            // APP-yymmdd-####. status and current_stage are set explicitly
            // to 'submitted' - the table default is 'requirement_raised',
            // which no page's colour map or the admin review buttons
            // understand, so a converted quote used to be stranded.
            $appNumber = next_application_number($pdo);

            $pdo->prepare(
                "INSERT INTO applications
                    (quote_id, merchant_id, agent_id, lead_id, application_number,
                     product_name, product_code, amount, tenure_months, notes,
                     current_stage, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'submitted', 'submitted')"
            )->execute([
                $quote_id, $user_id, ($leadRow['agent_id'] ?? null), $quote['lead_id'],
                $appNumber, $quote['product_name'], $quote['product_code'],
                $quote['amount'], null, $quote['notes'],
            ]);
            $appId = (int)$pdo->lastInsertId();

            record_stage($pdo, $appId, null, 'submitted', 'Application converted from an accepted quote');
            $pdo->prepare("UPDATE leads SET status = 'converted' WHERE id = ?")->execute([$quote['lead_id']]);
            audit_log($pdo, 'application.created', 'applications', (string)$appId, null,
                      ['quote_id' => $quote_id, 'merchant' => $user_id]);
            notify_parties($pdo, $appId, 'Application submitted',
                           'Application ' . $appNumber . ' has been raised from your quote.');

            flash('success', "Converted to application $appNumber.");
        }
    } else {
        flash('error', 'That action is not available for this quote.');
    }

    header('Location: quotes.php');
    exit;
}

$statusTabs = ['all' => 'All', 'sent' => 'Sent', 'accepted' => 'Accepted', 'declined' => 'Declined', 'expired' => 'Expired'];
$activeTab = $_GET['status'] ?? 'all';
if (!isset($statusTabs[$activeTab])) $activeTab = 'all';

$countStmt = $pdo->prepare("SELECT status, COUNT(*) AS cnt FROM quotes WHERE merchant_id = ? GROUP BY status");
$countStmt->execute([$user_id]);
$statusCounts = ['all' => 0];
foreach ($countStmt->fetchAll() as $row) {
    $statusCounts[$row['status']] = (int)$row['cnt'];
    $statusCounts['all'] += (int)$row['cnt'];
}

$quotesStmt = $pdo->prepare(
    "SELECT q.*, l.name AS customer_name
     FROM quotes q
     JOIN leads l ON l.id = q.lead_id
     WHERE q.merchant_id = ?" . ($activeTab === 'all' ? '' : " AND q.status = ?") . "
     ORDER BY q.created_at DESC"
);
$params = [$user_id];
if ($activeTab !== 'all') $params[] = $activeTab;
$quotesStmt->execute($params);
$quotes = $quotesStmt->fetchAll();

$convertedStmt = $pdo->prepare("SELECT quote_id FROM applications WHERE merchant_id = ?");
$convertedStmt->execute([$user_id]);
$converted = array_column($convertedStmt->fetchAll(), 'quote_id');

render_merchant_shell_open($pdo, $user_id, 'quotes', 'Quotes');
?>

<style>
    .empty-state { background: var(--surface); border: 1px dashed var(--line); border-radius: 12px; padding: 50px 30px; text-align: center; color: var(--muted); }

    .tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 18px; }
    .tab { display: inline-flex; align-items: center; gap: 7px; padding: 8px 14px; border-radius: 999px; border: 1px solid var(--line); background: var(--surface); color: var(--muted); font-size: 12.5px; font-weight: 700; text-decoration: none; }
    .tab:hover { border-color: var(--brand); color: var(--brand); }
    .tab.active { background: var(--brand); border-color: var(--brand); color: #fff; }
    .tab .tab-count { min-width: 18px; height: 18px; padding: 0 5px; border-radius: 999px; background: var(--canvas); color: var(--muted); font-size: 11px; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; }
    .tab.active .tab-count { background: rgba(255,255,255,.25); color: #fff; }

    .quote-card { background: var(--surface); border: 1px solid var(--line); border-radius: 12px; padding: 18px 22px; margin-bottom: 12px; }
    .quote-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; }
    .quote-top .q-name { font-size: 15px; font-weight: 700; color: var(--ink); }
    .quote-top .q-meta { font-size: 12px; color: var(--muted); margin-top: 4px; }
    .quote-top .q-meta a { color: var(--brand); font-weight: 700; text-decoration: none; }
    .status-pill { font-size: 11px; font-weight: 700; padding: 4px 12px; border-radius: 20px; text-transform: capitalize; }

    .quote-actions { display: flex; gap: 8px; margin-top: 10px; flex-wrap: wrap; }
    .btn-sm { padding: 7px 14px; border-radius: 6px; border: none; font-size: 12px; font-weight: 700; cursor: pointer; }
    .btn-accept { background: #d1fae5; color: #059669; }
    .btn-reject { background: #fee2e2; color: #dc2626; }
    .btn-convert { background: var(--brand); color: #fff; }
    .converted-tag { font-size: 12px; color: #059669; font-weight: 700; }
</style>

<p style="font-size:13px;color:var(--muted);margin:0 0 14px 2px;">
    Quotes turn leads into applications. INDBIN reviews and funds them &mdash; you earn commission on funding.
</p>

<div class="tabs">
    <?php foreach ($statusTabs as $key => $label): ?>
        <a href="quotes.php?status=<?= urlencode($key) ?>" class="tab <?= $key === $activeTab ? 'active' : '' ?>">
            <?= htmlspecialchars($label) ?>
            <span class="tab-count"><?= $statusCounts[$key] ?? 0 ?></span>
        </a>
    <?php endforeach; ?>
</div>

<?php if (empty($quotes)): ?>
    <div class="empty-state"><?= $activeTab === 'all' ? 'No quotes sent yet. Respond to a lead to send your first quote. An accepted quote becomes an application that INDBIN underwrites.' : 'No quotes with this status.' ?></div>
<?php else: ?>
    <?php foreach ($quotes as $q): ?>
        <div class="quote-card">
            <div class="quote-top">
                <div>
                    <div class="q-name"><?= htmlspecialchars($q['product_name']) ?> — ₹<?= number_format((float)$q['amount'], 0) ?></div>
                    <div class="q-meta">
                        For <a href="lead_detail.php?id=<?= (int)$q['lead_id'] ?>"><?= htmlspecialchars($q['customer_name'] ?: 'Unnamed customer') ?></a>
                        &middot; Valid until <?= htmlspecialchars($q['valid_until'] ? date('d M Y', strtotime($q['valid_until'])) : '—') ?>
                    </div>
                </div>
                <span class="status-pill" style="background:<?= $statusColors[$q['status']] ?? '#64748b' ?>22; color:<?= $statusColors[$q['status']] ?? '#64748b' ?>;"><?= htmlspecialchars($statusLabels[$q['status']] ?? ucfirst($q['status'])) ?></span>
            </div>

            <?php if ($q['status'] === 'sent'): ?>
                <div class="quote-actions">
                    <form method="POST"><input type="hidden" name="quote_id" value="<?= $q['id'] ?>"><input type="hidden" name="action" value="mark_accepted"><button type="submit" class="btn-sm btn-accept">Mark Accepted</button></form>
                    <form method="POST"><input type="hidden" name="quote_id" value="<?= $q['id'] ?>"><input type="hidden" name="action" value="mark_declined"><button type="submit" class="btn-sm btn-reject">Mark Declined</button></form>
                </div>
            <?php elseif ($q['status'] === 'accepted'): ?>
                <?php if (in_array($q['id'], $converted)): ?>
                    <span class="converted-tag"><i class="fa-solid fa-circle-check"></i> Converted to application</span>
                <?php else: ?>
                    <div class="quote-actions">
                        <form method="POST"><input type="hidden" name="quote_id" value="<?= $q['id'] ?>"><input type="hidden" name="action" value="convert"><button type="submit" class="btn-sm btn-convert">Convert to Application</button></form>
                    </div>
                <?php endif; ?>
            <?php elseif (in_array($q['status'], ['declined', 'expired'], true)): ?>
                <div class="quote-actions">
                    <form method="POST"><input type="hidden" name="quote_id" value="<?= $q['id'] ?>"><input type="hidden" name="action" value="reopen"><button type="submit" class="btn-sm btn-accept">Reopen as Sent</button></form>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php render_shell_close(); ?>