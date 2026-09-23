<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../portal/shell.php';
require_once __DIR__ . '/../portal/merchant_shell.php';

$user = require_merchant($pdo);
$user_id = (int)$user['id'];
$lead_id = (int)($_GET['id'] ?? 0);

if ($user['account_status'] !== 'active') { header('Location: ../onboarding_complete/index.php'); exit; }

$leadStmt = $pdo->prepare(
    "SELECT l.*, pc.product_name AS product_label
       FROM leads l
       LEFT JOIN product_catalog pc ON pc.product_code = l.product_code
      WHERE l.id = ? AND l.merchant_id = ?"
);
$leadStmt->execute([$lead_id, $user_id]);
$lead = $leadStmt->fetch();

if (!$lead) {
    header('Location: leads.php');
    exit;
}

$color = '#f97316';
$lightColor = '#fff1e5';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_quote') {
    $product_name = trim($_POST['product_name'] ?? '');
    $amount       = trim($_POST['amount'] ?? '');
    $valid_until  = trim($_POST['valid_until'] ?? '');
    $notes        = trim($_POST['notes'] ?? '');

    if (!$product_name) $errors[] = "Enter a product/service name.";
    if (!is_numeric($amount) || (float)$amount <= 0) $errors[] = "Enter a valid amount.";
    if (!$valid_until || strtotime($valid_until) < strtotime('today')) $errors[] = "Choose a valid future date.";

    if (empty($errors)) {
        $pdo->prepare(
            "INSERT INTO quotes (lead_id, merchant_id, product_name, amount, valid_until, notes)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([$lead_id, $user_id, $product_name, $amount, $valid_until, $notes]);
        $newQuoteId = (int)$pdo->lastInsertId();

        if ($lead['status'] === 'new') {
            $pdo->prepare("UPDATE leads SET status = 'contacted' WHERE id = ?")->execute([$lead_id]);
        }

        audit_log($pdo, 'quote.created', 'quotes', (string)$newQuoteId, null,
                  ['lead_id' => $lead_id, 'merchant_id' => $user_id, 'amount' => $amount]);

        // Tell the customer a quote is waiting, when the lead is linked to a
        // registered customer account (customer_id -> customers.user_id).
        if (!empty($lead['customer_id'])) {
            $custStmt = $pdo->prepare("SELECT user_id FROM customers WHERE id = ?");
            $custStmt->execute([(int)$lead['customer_id']]);
            $custUserId = (int)($custStmt->fetchColumn() ?: 0);
            if ($custUserId > 0) {
                $merchantLabel = $user['business_name'] ?: ($user['full_name'] ?: 'your merchant');
                notify($pdo, $custUserId, 'New quote from ' . $merchantLabel,
                       'A quote for ' . $product_name . ' (₹' . number_format((float)$amount, 0)
                       . ') is ready. Valid until ' . date('d M Y', strtotime($valid_until)) . '.',
                       'inapp', BASE_URL . '/customer/dashboard/notifications.php');
            }
        }

        header('Location: lead_detail.php?id=' . $lead_id);
        exit;
    }
}

$quotesStmt = $pdo->prepare("SELECT * FROM quotes WHERE lead_id = ? ORDER BY created_at DESC");
$quotesStmt->execute([$lead_id]);
$quotes = $quotesStmt->fetchAll();

// Same full ENUM as merchant/dashboard/leads.php - kept in sync so a lead
// shows the same colour on both the list and the detail page.
$statusColors = [
    'new'            => '#2563eb',
    'assigned'       => '#7c3aed',
    'contacted'      => '#b45309',
    'interested'     => '#0891b2',
    'not_interested' => '#64748b',
    'nurturing'      => '#d97706',
    'converted'      => '#059669',
    'lost'           => '#dc2626',
];

render_merchant_shell_open($pdo, $user_id, 'leads', 'Lead Detail');
?>

<style>
    .back-link { font-size: 13px; color: var(--muted); text-decoration: none; margin-bottom: 16px; display: inline-block; }
    .back-link:hover { color: var(--brand); }
    .lead-card { background: var(--surface); border: 1px solid var(--line); border-radius: 12px; padding: 22px 26px; margin-bottom: 22px; }
    .lead-card h1 { font-size: 20px; color: var(--ink); margin-bottom: 4px; }
    .lead-card .meta-row { font-size: 13px; color: var(--muted); margin-top: 8px; }
    .lead-card .message-box { background: var(--canvas); border-radius: 8px; padding: 14px; margin-top: 14px; font-size: 13px; color: var(--body); }
    .status-pill { font-size: 11px; font-weight: 700; padding: 4px 12px; border-radius: 20px; text-transform: capitalize; }

    .section-card { background: var(--surface); border: 1px solid var(--line); border-radius: 12px; padding: 22px 26px; margin-bottom: 20px; }
    .section-card h3 { font-size: 16px; color: var(--ink); margin-bottom: 16px; }

    .form-group { margin-bottom: 14px; }
    .form-group label { display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; color: var(--ink); }
    .form-group input, .form-group textarea { width: 100%; padding: 10px; border: 1px solid var(--field); border-radius: 8px; font-size: 14px; font-family: inherit; }
    .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

    .submit-btn { padding: 11px 22px; border-radius: 8px; border: none; background: var(--brand); color: #fff; font-weight: 700; font-size: 14px; cursor: pointer; }

    .quote-row { border: 1px solid var(--line); border-radius: 10px; padding: 14px 18px; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center; }
    .quote-row .q-name { font-size: 14px; font-weight: 700; color: var(--ink); }
    .quote-row .q-meta { font-size: 12px; color: var(--muted); margin-top: 3px; }

    .alert-error { background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; padding: 10px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; }
</style>

<a href="leads.php" class="back-link">&larr; Back to Leads</a>

<div class="lead-card">
    <div style="display:flex; justify-content:space-between; align-items:flex-start;">
        <div>
            <h1><?= htmlspecialchars($lead['name'] ?: 'Unnamed customer') ?></h1>
            <div class="meta-row">
                Interested in: <strong><?= htmlspecialchars($lead['product_label'] ?: 'General inquiry') ?></strong>
                &middot; Received <?= htmlspecialchars(date('d M Y', strtotime($lead['created_at']))) ?>
                <?= $lead['mobile'] ? ' &middot; ' . htmlspecialchars($lead['mobile']) : '' ?>
            </div>
        </div>
        <span class="status-pill" style="background:<?= $statusColors[$lead['status']] ?? '#64748b' ?>22; color:<?= $statusColors[$lead['status']] ?? '#64748b' ?>;"><?= htmlspecialchars($lead['status']) ?></span>
    </div>
    <?php if ($lead['requirement']): ?>
        <div class="message-box"><?= nl2br(htmlspecialchars($lead['requirement'])) ?></div>
    <?php endif; ?>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert-error"><?php foreach ($errors as $e) echo "<div>" . htmlspecialchars($e) . "</div>"; ?></div>
<?php endif; ?>

<?php if (!empty($quotes)): ?>
<div class="section-card">
    <h3>Quotes sent for this lead</h3>
    <?php foreach ($quotes as $q):
        $qColors = ['draft' => '#64748b', 'sent' => '#2563eb', 'accepted' => '#059669', 'declined' => '#dc2626', 'expired' => '#64748b'];
    ?>
    <div class="quote-row">
        <div>
            <div class="q-name"><?= htmlspecialchars($q['product_name']) ?> — ₹<?= number_format((float)$q['amount'], 0) ?></div>
            <div class="q-meta">Valid until <?= htmlspecialchars(date('d M Y', strtotime($q['valid_until']))) ?> &middot; Sent <?= htmlspecialchars(date('d M Y', strtotime($q['created_at']))) ?></div>
        </div>
        <span class="status-pill" style="background:<?= ($qColors[$q['status']] ?? '#64748b') ?>22; color:<?= ($qColors[$q['status']] ?? '#64748b') ?>;"><?= htmlspecialchars($q['status']) ?></span>
    </div>
    <?php endforeach; ?>
    <p style="font-size:12px; color:var(--faint); margin-top:10px;">Manage quote responses and conversions from the <a href="quotes.php" style="color:var(--brand); font-weight:600;">Quotes</a> page.</p>
</div>
<?php endif; ?>

<div class="section-card">
    <h3>Send a new quote / product information</h3>
    <form method="POST">
        <input type="hidden" name="action" value="send_quote">

        <div class="form-group">
            <label>Product / Service</label>
            <input type="text" name="product_name" placeholder="e.g. Business Loan, POS Machine" required>
        </div>

        <div class="two-col">
            <div class="form-group">
                <label>Quoted Amount (₹)</label>
                <input type="number" name="amount" min="1" step="1" required>
            </div>
            <div class="form-group">
                <label>Valid Until</label>
                <input type="date" name="valid_until" min="<?= date('Y-m-d') ?>" required>
            </div>
        </div>

        <div class="form-group">
            <label>Notes for the customer</label>
            <textarea name="notes" rows="3" placeholder="Terms, conditions, or additional details"></textarea>
        </div>

        <button type="submit" class="submit-btn">Send Quote</button>
    </form>
</div>

<?php render_shell_close(); ?>