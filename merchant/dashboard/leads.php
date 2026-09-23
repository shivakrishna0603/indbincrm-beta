<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../portal/shell.php';
require_once __DIR__ . '/../portal/merchant_shell.php';

$user = require_merchant($pdo);
$user_id = (int)$user['id'];

if ($user['account_status'] !== 'active') { header('Location: ../onboarding_complete/index.php'); exit; }

$color = '#f97316';
$lightColor = '#fff1e5';
// Covers every value in leads.status. Previously had four of the ENUM's
// eight values (and one, 'closed', that isn't in the ENUM at all), so most
// leads hit an undefined array key here and rendered with no status colour.
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

// Status filter tabs. 'all' is the default view.
$statusTabs = [
    'all'            => 'All',
    'new'            => 'New',
    'assigned'       => 'Assigned',
    'contacted'      => 'Contacted',
    'interested'     => 'Interested',
    'nurturing'      => 'Nurturing',
    'converted'      => 'Converted',
    'not_interested' => 'Not interested',
    'lost'           => 'Lost',
];
$activeTab = $_GET['status'] ?? 'all';
if (!isset($statusTabs[$activeTab])) $activeTab = 'all';

// Per-status counts for the tab labels (one grouped query, not one per tab).
$countStmt = $pdo->prepare("SELECT status, COUNT(*) AS cnt FROM leads WHERE merchant_id = ? GROUP BY status");
$countStmt->execute([$user_id]);
$statusCounts = ['all' => 0];
foreach ($countStmt->fetchAll() as $row) {
    $statusCounts[$row['status']] = (int)$row['cnt'];
    $statusCounts['all'] += (int)$row['cnt'];
}

// l.* keeps this to the leads columns only; product_label is the one extra
// column added on top, so there is no name collision with product_catalog's
// own status/created_at columns the way a bare "SELECT *" join would cause.
$leadsStmt = $pdo->prepare(
    "SELECT l.*, pc.product_name AS product_label
       FROM leads l
       LEFT JOIN product_catalog pc ON pc.product_code = l.product_code
      WHERE l.merchant_id = ?" . ($activeTab === 'all' ? '' : " AND l.status = ?") . "
      ORDER BY l.created_at DESC"
);
$params = [$user_id];
if ($activeTab !== 'all') $params[] = $activeTab;
$leadsStmt->execute($params);
$leads = $leadsStmt->fetchAll();

// Merchants can log a customer who walked in / called directly.
// NOTE: create_lead() in core/workflow.php cannot be used here: it writes the
// same $in['source'] into customers.source (self/agent/merchant/referral/import)
// and leads.source (customer_request/agent_sourced/merchant_referral/campaign/
// walk_in). The two enums do not overlap, so under STRICT_TRANS_TABLES it fails
// on a brand-new customer. This does the same work with the correct per-table
// source, staying inside the merchant module.
$formErrors = [];
$formOld = ['name' => '', 'mobile' => '', 'email' => '', 'requirement' => '', 'source' => 'walk_in'];
$validLeadSources = ['walk_in', 'merchant_referral', 'customer_request', 'campaign'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formOld = [
        'name'        => trim($_POST['name'] ?? ''),
        'mobile'      => trim($_POST['mobile'] ?? ''),
        'email'       => trim($_POST['email'] ?? ''),
        'requirement' => trim($_POST['requirement'] ?? ''),
        'source'      => in_array($_POST['source'] ?? '', $validLeadSources, true) ? $_POST['source'] : 'walk_in',
    ];
    $mobile = preg_replace('/\D/', '', $formOld['mobile']);
    if ($formOld['name'] === '') $formErrors[] = 'Enter the customer name.';
    if (!preg_match('/^[6-9]\d{9}$/', $mobile)) $formErrors[] = 'Enter a valid 10-digit mobile number.';

    if ($formErrors === []) {
        try {
            $pdo->beginTransaction();

            $custStmt = $pdo->prepare("SELECT id FROM customers WHERE mobile = ?");
            $custStmt->execute([$mobile]);
            $customerId = (int)($custStmt->fetchColumn() ?: 0);

            if (!$customerId) {
                $pdo->prepare(
                    "INSERT INTO customers (merchant_id, name, mobile, email, requirement, source, status)
                     VALUES (?, ?, ?, ?, ?, 'merchant', 'prospect')"
                )->execute([$user_id, $formOld['name'], $mobile, $formOld['email'] ?: null, $formOld['requirement'] ?: null]);
                $customerId = (int)$pdo->lastInsertId();
            }

            $pdo->prepare(
                "INSERT INTO leads (customer_id, merchant_id, name, mobile, email, requirement, source, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'new')"
            )->execute([$customerId, $user_id, $formOld['name'], $mobile, $formOld['email'] ?: null, $formOld['requirement'] ?: null, $formOld['source']]);
            $newLeadId = (int)$pdo->lastInsertId();

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $formErrors[] = 'Could not save the lead. Please try again.';
        }

        if ($formErrors === []) {
            audit_log($pdo, 'lead.created', 'leads', (string)$newLeadId, null,
                      ['customer_id' => $customerId, 'merchant_id' => $user_id, 'source' => $formOld['source']]);
            notify($pdo, $user_id, 'New customer enquiry',
                   $formOld['name'] . ' (' . $mobile . ') was added as a lead.');
            header('Location: leads.php');
            exit;
        }
    }
}

render_merchant_shell_open($pdo, $user_id, 'leads', 'Leads');
?>

<style>
    .lead-row { background: var(--surface); border: 1px solid var(--line); border-radius: 12px; padding: 16px 20px; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center; text-decoration: none; transition: border-color .15s ease, box-shadow .15s ease; }
    .lead-row:hover { border-color: var(--brand); box-shadow: 0 4px 18px rgba(0,0,0,.05); }
    .lead-row .l-name { font-size: 14px; font-weight: 700; color: var(--ink); }
    .lead-row .l-meta { font-size: 12px; color: var(--muted); margin-top: 3px; }
    .status-pill { font-size: 11px; font-weight: 700; padding: 4px 12px; border-radius: 20px; text-transform: capitalize; }
    .empty-state { text-align: center; color: var(--faint); padding: 40px 0; font-size: 14px; }

    .panel-lead { background: var(--surface); border: 1px solid var(--line); border-radius: 12px; padding: 4px 20px 18px; margin-bottom: 18px; }
    .panel-lead summary { cursor: pointer; font-size: 14px; font-weight: 700; color: var(--brand-deep); padding: 14px 0; list-style: none; display: flex; align-items: center; gap: 8px; }
    .panel-lead summary::-webkit-details-marker { display: none; }
    .lead-form { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    .lead-form .field { display: flex; flex-direction: column; gap: 6px; }
    .lead-form .field.full { grid-column: 1 / -1; }
    .lead-form label { font-size: 12px; font-weight: 700; color: var(--muted); }
    .lead-form input, .lead-form select, .lead-form textarea { padding: 10px; border: 1px solid var(--field); border-radius: 8px; font-size: 13px; font-family: inherit; }
    .lead-form .submit-btn { grid-column: 1 / -1; justify-self: start; padding: 10px 22px; border: none; border-radius: 8px; background: var(--brand); color: #fff; font-weight: 700; cursor: pointer; }
    .alert-error { background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; padding: 10px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 14px; }

    .tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 18px; }
    .tab { display: inline-flex; align-items: center; gap: 7px; padding: 8px 14px; border-radius: 999px; border: 1px solid var(--line); background: var(--surface); color: var(--muted); font-size: 12.5px; font-weight: 700; text-decoration: none; }
    .tab:hover { border-color: var(--brand); color: var(--brand); }
    .tab.active { background: var(--brand); border-color: var(--brand); color: #fff; }
    .tab .tab-count { min-width: 18px; height: 18px; padding: 0 5px; border-radius: 999px; background: var(--canvas); color: var(--muted); font-size: 11px; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; }
    .tab.active .tab-count { background: rgba(255,255,255,.25); color: #fff; }

    .follow-cue { display: inline-flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 700; padding: 2px 9px; border-radius: 999px; margin-left: 8px; }
    .follow-cue.due { background: #fee2e2; color: #dc2626; }
    .follow-cue.scheduled { background: var(--canvas); color: var(--muted); }
</style>

<details class="panel-lead" <?= $formErrors ? 'open' : '' ?>>
    <summary><i class="fa-solid fa-user-plus"></i> Add a lead</summary>

    <?php if ($formErrors): ?>
        <div class="alert-error"><?php foreach ($formErrors as $e) echo '<div>' . htmlspecialchars($e) . '</div>'; ?></div>
    <?php endif; ?>

    <form method="POST" class="lead-form">
        <div class="field">
            <label>Customer name</label>
            <input type="text" name="name" value="<?= htmlspecialchars($formOld['name']) ?>" placeholder="Full name" required>
        </div>
        <div class="field">
            <label>Mobile</label>
            <input type="tel" name="mobile" value="<?= htmlspecialchars($formOld['mobile']) ?>" placeholder="10-digit mobile" required>
        </div>
        <div class="field">
            <label>Email (optional)</label>
            <input type="email" name="email" value="<?= htmlspecialchars($formOld['email']) ?>" placeholder="name@example.com">
        </div>
        <div class="field">
            <label>Source</label>
            <select name="source">
                <option value="walk_in" <?= $formOld['source'] === 'walk_in' ? 'selected' : '' ?>>Walk-in</option>
                <option value="merchant_referral" <?= $formOld['source'] === 'merchant_referral' ? 'selected' : '' ?>>Merchant referral</option>
                <option value="customer_request" <?= $formOld['source'] === 'customer_request' ? 'selected' : '' ?>>Customer request</option>
                <option value="campaign" <?= $formOld['source'] === 'campaign' ? 'selected' : '' ?>>Campaign</option>
            </select>
        </div>
        <div class="field full">
            <label>Requirement</label>
            <textarea name="requirement" rows="3" placeholder="What is the customer looking for?"><?= htmlspecialchars($formOld['requirement']) ?></textarea>
        </div>
        <button type="submit" class="submit-btn">Save lead</button>
    </form>
</details>

<p style="font-size:12.5px;color:var(--muted);margin:0 0 10px 2px;">
    A lead becomes a quote, then an application that INDBIN reviews and funds. You earn commission on funded applications.
</p>

<div class="tabs">
    <?php foreach ($statusTabs as $key => $label): ?>
        <a href="leads.php?status=<?= urlencode($key) ?>" class="tab <?= $key === $activeTab ? 'active' : '' ?>">
            <?= htmlspecialchars($label) ?>
            <span class="tab-count"><?= $statusCounts[$key] ?? 0 ?></span>
        </a>
    <?php endforeach; ?>
</div>

<?php if (empty($leads)): ?>
    <div class="empty-state"><?= $activeTab === 'all' ? 'No leads yet. Add a customer who asks about credit &mdash; referred and funded applications earn you commission.' : 'No leads with this status.' ?></div>
<?php else: ?>
    <?php foreach ($leads as $lead):
        $terminal = in_array($lead['status'], ['converted', 'lost', 'not_interested'], true);
        $dueTs = $lead['next_follow_up'] ? strtotime($lead['next_follow_up']) : null;
        $isOverdue = $dueTs && $dueTs < strtotime('today') && !$terminal;
    ?>
        <a href="lead_detail.php?id=<?= (int)$lead['id'] ?>" class="lead-row">
            <div>
                <!-- leads.name / product_label (joined from product_catalog).
                     customer_name and product_interest were never real
                     columns, so every lead used to fall back to these two
                     placeholder strings regardless of what was actually
                     submitted. -->
                <div class="l-name">
                    <?= htmlspecialchars($lead['name'] ?: 'Unnamed customer') ?>
                    <?php if ($isOverdue): ?>
                        <span class="follow-cue due"><i class="fa-solid fa-triangle-exclamation"></i> Follow-up due</span>
                    <?php elseif ($dueTs && !$terminal): ?>
                        <span class="follow-cue scheduled"><i class="fa-regular fa-clock"></i> <?= htmlspecialchars(date('d M', $dueTs)) ?></span>
                    <?php endif; ?>
                </div>
                <div class="l-meta">
                    <?= htmlspecialchars($lead['product_label'] ?: 'General inquiry') ?>
                    &middot; <?= htmlspecialchars(date('d M Y', strtotime($lead['created_at']))) ?>
                </div>
            </div>
            <span class="status-pill" style="background:<?= $statusColors[$lead['status']] ?? '#64748b' ?>22; color:<?= $statusColors[$lead['status']] ?? '#64748b' ?>;">
                <?= htmlspecialchars(str_replace('_', ' ', $lead['status'])) ?>
            </span>
        </a>
    <?php endforeach; ?>
<?php endif; ?>

<?php render_shell_close(); ?>