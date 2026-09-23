<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../portal/shell.php';
require_once __DIR__ . '/../portal/merchant_shell.php';

$user = require_merchant($pdo);
$user_id = (int)$user['id'];

if ($user['account_status'] !== 'active') { header('Location: ../onboarding_complete/index.php'); exit; }

$color = '#f97316';
$lightColor = '#fff1e5';
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_application') {
    $application_type = trim($_POST['application_type'] ?? '');
    $amount            = trim($_POST['amount'] ?? '');
    $purpose           = trim($_POST['purpose'] ?? '');
    $tenure_months     = trim($_POST['tenure_months'] ?? '');
    $notes             = trim($_POST['notes'] ?? '');

    if (!in_array($application_type, ['loan', 'insurance'])) $errors[] = "Select an application type.";
    if (!is_numeric($amount) || (float)$amount <= 0) $errors[] = "Enter a valid amount.";
    if (!$purpose) $errors[] = "Enter a purpose for this application.";

    if ($application_type === 'loan') {
        if (!is_numeric($tenure_months) || (int)$tenure_months <= 0 || (int)$tenure_months > 60) {
            $errors[] = "Enter a valid repayment tenure (1-60 months).";
        }
    } else {
        $tenure_months = null; // not applicable to insurance
    }

    if (empty($errors)) {
        $pdo->prepare(
            "INSERT INTO self_service_applications
             (merchant_id, application_type, amount, purpose, tenure_months, notes)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([$user_id, $application_type, $amount, $purpose, $tenure_months, $notes]);

        $success = ucfirst($application_type) . " application submitted successfully.";
    } else {
        // Re-show the submitted type so the form doesn't reset to "loan" on error
        $_SESSION['self_service_last_type'] = $application_type;
    }
}

$appsStmt = $pdo->prepare("SELECT * FROM self_service_applications WHERE merchant_id = ? ORDER BY created_at DESC");
$appsStmt->execute([$user_id]);
$applications = $appsStmt->fetchAll();

$statusColors = ['submitted' => '#2563eb', 'under_review' => '#b45309', 'approved' => '#059669', 'rejected' => '#dc2626'];

render_merchant_shell_open($pdo, $user_id, 'self_service', 'Apply for Loan / Insurance');
?>

<p style="font-size:13px;color:var(--muted);margin:0 0 18px 2px;">
    This is credit for your own business &mdash; reviewed and funded by INDBIN, separate from the applications you refer for customers.
</p>

<style>
    .section-card { background: var(--surface); border: 1px solid var(--line); border-radius: 12px; padding: 22px 26px; margin-bottom: 22px; }
    .section-card h3 { font-size: 16px; color: var(--ink); margin-bottom: 16px; }

    .type-toggle { display: flex; gap: 10px; margin-bottom: 18px; }
    .type-toggle label { flex: 1; border: 1.5px solid var(--field); border-radius: 8px; padding: 12px; text-align: center; font-size: 13px; font-weight: 700; color: var(--muted); cursor: pointer; }
    .type-toggle input { display: none; }
    .type-toggle input:checked + span { color: var(--brand); }
    .type-toggle label:has(input:checked) { border-color: var(--brand); background: var(--brand-soft); }

    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; color: var(--ink); }
    .form-group input, .form-group textarea { width: 100%; padding: 10px; border: 1px solid var(--field); border-radius: 8px; font-size: 14px; font-family: inherit; }
    .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

    .submit-btn { padding: 11px 22px; border-radius: 8px; border: none; background: var(--brand); color: #fff; font-weight: 700; font-size: 14px; cursor: pointer; }

    .app-row { border: 1px solid var(--line); border-radius: 10px; padding: 14px 18px; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center; }
    .app-row .a-name { font-size: 14px; font-weight: 700; color: var(--ink); text-transform: capitalize; }
    .app-row .a-meta { font-size: 12px; color: var(--muted); margin-top: 3px; }
    .status-pill { font-size: 11px; font-weight: 700; padding: 4px 12px; border-radius: 20px; text-transform: capitalize; }

    .alert { padding: 10px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; }
    .alert-error { background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; }
    .alert-success { background: #d1fae5; color: #059669; border: 1px solid #6ee7b7; }
    .empty-state { text-align: center; color: var(--faint); padding: 30px 0; font-size: 14px; }
</style>

<?php if ($success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error"><?php foreach ($errors as $e) echo "<div>" . htmlspecialchars($e) . "</div>"; ?></div>
<?php endif; ?>

<?php if (!empty($applications)): ?>
<div class="section-card">
    <h3>Your applications</h3>
    <?php foreach ($applications as $a): ?>
    <div class="app-row">
        <div>
            <div class="a-name"><?= htmlspecialchars($a['application_type']) ?> — ₹<?= number_format((float)$a['amount'], 0) ?></div>
            <div class="a-meta">
                <?= htmlspecialchars($a['purpose']) ?>
                <?= $a['tenure_months'] ? ' &middot; ' . (int)$a['tenure_months'] . ' months' : '' ?>
                &middot; Submitted <?= htmlspecialchars(date('d M Y', strtotime($a['created_at']))) ?>
            </div>
        </div>
        <span class="status-pill" style="background:<?= $statusColors[$a['status']] ?>22; color:<?= $statusColors[$a['status']] ?>;"><?= htmlspecialchars(str_replace('_', ' ', $a['status'])) ?></span>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="section-card">
    <h3>New application</h3>
    <form method="POST" id="selfServiceForm">
        <input type="hidden" name="action" value="submit_application">

        <div class="type-toggle">
            <label>
                <input type="radio" name="application_type" value="loan" checked onchange="toggleTenure()">
                <span>Loan</span>
            </label>
            <label>
                <input type="radio" name="application_type" value="insurance" onchange="toggleTenure()">
                <span>Insurance</span>
            </label>
        </div>

        <div class="two-col">
            <div class="form-group">
                <label>Amount Requested (₹)</label>
                <input type="number" name="amount" min="1" step="1" required>
            </div>
            <div class="form-group" id="tenureGroup">
                <label>Repayment Tenure (months)</label>
                <input type="number" name="tenure_months" min="1" max="60" value="12">
            </div>
        </div>

        <div class="form-group">
            <label>Purpose</label>
            <input type="text" name="purpose" placeholder="e.g. Inventory expansion, shop equipment, business cover" required>
        </div>

        <div class="form-group">
            <label>Additional Notes</label>
            <textarea name="notes" rows="3" placeholder="Any extra details for the underwriting team"></textarea>
        </div>

        <button type="submit" class="submit-btn">Submit Application</button>
    </form>
</div>

<script>
    function toggleTenure() {
        const isLoan = document.querySelector('input[name="application_type"]:checked').value === 'loan';
        const group = document.getElementById('tenureGroup');
        group.style.display = isLoan ? 'block' : 'none';
        group.querySelector('input').required = isLoan;
    }
    toggleTenure();
</script>

<?php render_shell_close(); ?>
