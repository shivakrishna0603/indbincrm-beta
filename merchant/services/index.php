<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../portal/shell.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}
$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT role, party_code AS merchant_id FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (($user['role'] ?? '') !== 'merchant') {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

// Settlement account + payout preference summary, for the top summary box
$accStmt = $pdo->prepare("SELECT account_number FROM settlement_accounts WHERE user_id = ? AND is_primary = 1 LIMIT 1");
$accStmt->execute([$user_id]);
$acc = $accStmt->fetch();

$prefStmt = $pdo->prepare("SELECT payout_frequency FROM payout_preferences WHERE user_id = ?");
$prefStmt->execute([$user_id]);
$pref = $prefStmt->fetch();

// Service catalog
$serviceCatalog = [
    'upi'            => ['label' => 'UPI Payments', 'icon' => 'fa-indian-rupee-sign', 'desc' => 'Accept payments from customers using UPI', 'requires_approval' => false],
    'qr_to_cash'     => ['label' => 'QR to Cash', 'icon' => 'fa-qrcode', 'desc' => 'Accept payments via QR and get instant settlement', 'requires_approval' => false],
    'aeps'           => ['label' => 'AEPS', 'icon' => 'fa-fingerprint', 'desc' => 'Aadhaar Enabled Payment System services at your counter', 'requires_approval' => true],
    'money_transfer' => ['label' => 'Money Transfer', 'icon' => 'fa-money-bill-transfer', 'desc' => 'Domestic money transfer services for customers', 'requires_approval' => true],
    'insurance'      => ['label' => 'Insurance', 'icon' => 'fa-shield-halved', 'desc' => 'Offer insurance products to your customers', 'requires_approval' => false],
    'offers'         => ['label' => 'Offers', 'icon' => 'fa-gift', 'desc' => 'Create and manage offers and promotions', 'requires_approval' => false],
    'loyalty'        => ['label' => 'Loyalty', 'icon' => 'fa-star', 'desc' => 'Reward your customers with loyalty programs', 'requires_approval' => false],
    'loans'          => ['label' => 'Credit & Business Loan', 'icon' => 'fa-hand-holding-dollar', 'desc' => 'Access business loans and working capital for your own business', 'requires_approval' => true],
];

// Existing selections, if the merchant is revisiting this page
$existingStmt = $pdo->prepare("SELECT service_key, status FROM merchant_services WHERE user_id = ?");
$existingStmt->execute([$user_id]);
$existing = [];
foreach ($existingStmt->fetchAll() as $row) {
    $existing[$row['service_key']] = $row['status'];
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selected = array_values(array_filter($_POST['services'] ?? [], fn($key) => isset($serviceCatalog[$key])));

    // Page in the enablement wizard can be revisited after services have been
    // reviewed. Preserve rows that are still selected so an admin's approval
    // is not wiped just by pressing Continue again; only drop deselected ones.
    $keep = [];
    foreach ($selected as $key) {
        if (!isset($existing[$key])) {
            $status = $serviceCatalog[$key]['requires_approval'] ? 'requested' : 'approved';
            $pdo->prepare("INSERT INTO merchant_services (user_id, service_key, status) VALUES (?, ?, ?)")
                ->execute([$user_id, $key, $status]);
        }
        $keep[] = $key;
    }

    if ($keep) {
        $placeholders = implode(',', array_fill(0, count($keep), '?'));
        $pdo->prepare("DELETE FROM merchant_services WHERE user_id = ? AND service_key NOT IN ($placeholders)")
            ->execute(array_merge([$user_id], $keep));
    } else {
        $pdo->prepare("DELETE FROM merchant_services WHERE user_id = ?")->execute([$user_id]);
    }

    $pdo->prepare("UPDATE users SET product_enablement_completed_at = NOW() WHERE id = ?")->execute([$user_id]);

    header('Location: ../training/index.php');
    exit;
}

render_shell_open($pdo, $user_id, 'services', 'Product & Service Enablement');
?>

<div class="page-header">
    <h1>7. Product &amp; Service Enablement</h1>
    <p>Select the products and services you want to enable for your business.</p>
</div>

<div class="summary-box">
    <div class="summary-title">Merchant Account Summary</div>
    <div class="summary-grid">
        <div class="summary-item">
            <i class="fa-solid fa-id-card"></i>
            <div><div class="s-label">Merchant ID</div><div class="s-value"><?= htmlspecialchars($user['merchant_id'] ?? '—') ?></div></div>
        </div>
        <div class="summary-item">
            <i class="fa-solid fa-building-columns"></i>
            <div><div class="s-label">Settlement Account</div><div class="s-value">••• •••• <?= htmlspecialchars($acc ? substr($acc['account_number'], -4) : '----') ?><span class="verified-tag">Verified</span></div></div>
        </div>
        <div class="summary-item">
            <i class="fa-solid fa-file-invoice-dollar"></i>
            <div><div class="s-label">Payout Preference</div><div class="s-value"><?= htmlspecialchars(ucfirst($pref['payout_frequency'] ?? 'Not set')) ?> Settlement</div></div>
        </div>
    </div>
</div>

<h3 class="section-title">Select Products / Services</h3>
<p class="section-sub">Choose the services you want to activate for your account.</p>

<form method="POST" id="serviceForm">
    <div class="service-grid">
        <?php foreach ($serviceCatalog as $key => $svc):
            $existingStatus = $existing[$key] ?? null;
            $isChecked = $existingStatus !== null;
        ?>
        <label class="service-card <?= $isChecked ? 'checked' : '' ?>" onclick="this.classList.toggle('checked', this.querySelector('input').checked)">
            <input type="checkbox" name="services[]" value="<?= $key ?>" <?= $isChecked ? 'checked' : '' ?> onclick="event.stopPropagation(); this.closest('.service-card').classList.toggle('checked', this.checked)">
            <div class="service-icon"><i class="fa-solid <?= $svc['icon'] ?>"></i></div>
            <h4><?= htmlspecialchars($svc['label']) ?></h4>
            <p><?= htmlspecialchars($svc['desc']) ?></p>
            <span class="badge <?= $svc['requires_approval'] ? 'requires_approval' : 'available' ?>">
                <?= $svc['requires_approval'] ? 'Requires Approval' : 'Available' ?>
            </span>
            <a href="#" class="view-details" onclick="event.preventDefault();">View Details</a>
        </label>
        <?php endforeach; ?>
    </div>

    <div class="info-banner">
        <i class="fa-solid fa-circle-info"></i>
        Some services may require additional verification and approval.
    </div>

    <div class="selected-summary">
        <h4>Selected Services Summary</h4>
        <div class="count-grid">
            <div class="count-box"><i class="fa-solid fa-layer-group" style="color:#f97316;"></i><div><div class="c-num" id="countSelected">0</div><div class="c-label">Selected Services</div></div></div>
            <div class="count-box"><i class="fa-solid fa-hourglass-half" style="color:#ea580c;"></i><div><div class="c-num" id="countApproval">0</div><div class="c-label">Requires Approval</div></div></div>
            <div class="count-box"><i class="fa-solid fa-circle-check" style="color:#16a34a;"></i><div><div class="c-num">0</div><div class="c-label">Pending Verification</div></div></div>
            <div class="count-box"><i class="fa-solid fa-boxes-stacked" style="color:#64748b;"></i><div><div class="c-num"><?= count($serviceCatalog) ?></div><div class="c-label">Total Services</div></div></div>
        </div>

        <div class="selected-line"><strong>Selected Services:</strong> <span id="selectedList">None</span></div>
        <div class="selected-line"><strong>Services Requiring Approval:</strong> <span id="approvalList">None</span></div>
    </div>

    <div class="form-actions">
        <a href="../account_setup/status.php" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Back</a>
        <button type="submit" class="btn-continue">Continue <i class="fa-solid fa-arrow-right"></i></button>
    </div>
</form>

<script>
    const serviceMeta = <?= json_encode($serviceCatalog) ?>;

    function updateSummary() {
        const checked = Array.from(document.querySelectorAll('input[name="services[]"]:checked'));
        const selectedKeys = checked.map(c => c.value);
        const approvalKeys = selectedKeys.filter(k => serviceMeta[k].requires_approval);

        document.getElementById('countSelected').innerText = selectedKeys.length;
        document.getElementById('countApproval').innerText = approvalKeys.length;

        document.getElementById('selectedList').innerText = selectedKeys.length
            ? selectedKeys.map(k => serviceMeta[k].label).join(', ') : 'None';
        document.getElementById('approvalList').innerText = approvalKeys.length
            ? approvalKeys.map(k => serviceMeta[k].label).join(', ') : 'None';
    }

    document.querySelectorAll('input[name="services[]"]').forEach(cb => cb.addEventListener('change', updateSummary));
    updateSummary();
</script>

<?php render_shell_close(); ?>