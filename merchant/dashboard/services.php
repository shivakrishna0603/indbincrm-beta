<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../portal/shell.php';
require_once __DIR__ . '/../portal/merchant_shell.php';

$user = require_merchant($pdo);
$user_id = (int)$user['id'];

if ($user['account_status'] !== 'active') {
    header('Location: ../onboarding_complete/index.php');
    exit;
}

$color = '#f97316';
$lightColor = '#fff1e5';

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

$existingStmt = $pdo->prepare("SELECT service_key, status, requested_at, reviewed_at FROM merchant_services WHERE user_id = ? ORDER BY requested_at DESC");
$existingStmt->execute([$user_id]);
$existing = [];
$existingAt = [];
$existingReviewedAt = [];
foreach ($existingStmt->fetchAll() as $row) {
    $existing[$row['service_key']] = $row['status'];
    $existingAt[$row['service_key']] = $row['requested_at'];
    $existingReviewedAt[$row['service_key']] = $row['reviewed_at'];
}

$statusMeta = [
    'requested' => ['Pending Approval', 'pending_approval', 'fa-clock'],
    'approved'  => ['Active',           'approved',         'fa-circle-check'],
    'rejected'  => ['Rejected',         'not_enabled',      'fa-circle-xmark'],
    'suspended' => ['Suspended',        'not_enabled',      'fa-pause'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selected = $_POST['services'] ?? [];
    $addedAny = false;

    foreach ($selected as $key) {
        if (!isset($serviceCatalog[$key])) continue;
        $current = $existing[$key] ?? null;
        // Approved or already-pending services are not touched. A rejected or
        // suspended service can be requested again, which re-opens the row
        // instead of being silently skipped forever.
        if (in_array($current, ['approved', 'requested'], true)) continue;

        $status = $serviceCatalog[$key]['requires_approval'] ? 'requested' : 'approved';

        if ($current === null) {
            $pdo->prepare("INSERT INTO merchant_services (user_id, service_key, status) VALUES (?, ?, ?)")
                ->execute([$user_id, $key, $status]);
        } else {
            $pdo->prepare("UPDATE merchant_services SET status = ?, requested_at = NOW(), reviewed_at = NULL, reviewer_id = NULL WHERE user_id = ? AND service_key = ?")
                ->execute([$status, $user_id, $key]);
        }

        $existing[$key] = $status;
        $existingAt[$key] = date('Y-m-d H:i:s');
        $existingReviewedAt[$key] = null;
        $addedAny = true;
    }

    flash($addedAny ? 'success' : 'info', $addedAny ? 'Service request submitted.' : 'No new services were selected.');
    header('Location: services.php');
    exit;
}

render_merchant_shell_open($pdo, $user_id, 'services', 'Manage Products & Services');
?>

<p style="font-size:13px;color:var(--muted);margin:0 0 18px 2px;">
    Services are enabled by INDBIN after review; the Credit &amp; Business Loan service is credit for your business, not lending to customers.
</p>

<style>
    .service-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 24px; }
    @media (max-width: 1000px) { .service-grid { grid-template-columns: repeat(2, 1fr); } }

    .service-card { border: 1.5px solid var(--line); border-radius: 12px; padding: 18px; text-align: center; position: relative; background: var(--surface); transition: border-color .15s ease, box-shadow .15s ease; }
    .service-card:hover { border-color: var(--brand); box-shadow: 0 4px 18px rgba(0,0,0,.05); }
    .service-card.enabled { border-color: #16a34a; background: #fbfefb; }
    .service-card.requested { border-color: #d97706; background: #fffdf5; }
    .service-icon { width: 46px; height: 46px; margin: 4px auto 10px; display: flex; align-items: center; justify-content: center; font-size: 18px; color: #475569; background: var(--brand-soft); border-radius: 50%; }
    .service-card h4 { font-size: 14px; color: var(--ink); margin-bottom: 6px; }
    .service-card p { font-size: 12px; color: var(--muted); line-height: 1.5; margin-bottom: 12px; min-height: 48px; }
    .service-card .since { display: block; font-size: 11px; color: var(--muted); margin-top: 8px; }

    .badge { display: inline-block; font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 20px; margin-bottom: 8px; }
    .badge.approved { background: #d1fae5; color: #059669; }
    .badge.pending_approval { background: #fef3c7; color: #b45309; }
    .badge.not_enabled { background: #f1f5f9; color: #64748b; }

    .service-card input[type=checkbox] { position: absolute; top: 14px; left: 14px; width: 18px; height: 18px; }

    .submit-btn { padding: 11px 26px; border-radius: 8px; border: none; background: var(--brand); color: #fff; font-weight: 700; font-size: 14px; cursor: pointer; }

    .req-panel { background: var(--surface); border: 1.5px solid var(--line); border-radius: 12px; padding: 20px 22px; margin-top: 26px; }
    .req-panel h3 { font-size: 15px; color: var(--ink); margin-bottom: 4px; }
    .req-panel .req-sub { font-size: 12px; color: var(--muted); margin-bottom: 14px; }
    .req-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .req-table th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); padding: 8px 10px; border-bottom: 1px solid var(--line); }
    .req-table td { padding: 11px 10px; border-bottom: 1px solid var(--line); color: var(--ink); vertical-align: middle; }
    .req-table tr:last-child td { border-bottom: none; }
    .req-table .svc-name { font-weight: 600; }
</style>

<form method="POST">
    <div class="service-grid">
        <?php foreach ($serviceCatalog as $key => $svc):
            $status = $existing[$key] ?? null;
            $selectable = !in_array($status, ['approved', 'requested'], true);
            $cardClass = $status === 'approved' ? 'enabled' : ($status === 'requested' ? 'requested' : '');
        ?>
        <div class="service-card <?= $cardClass ?>">
            <?php if ($selectable): ?>
                <input type="checkbox" name="services[]" value="<?= $key ?>" title="Select to request this service">
            <?php endif; ?>
            <div class="service-icon"><i class="fa-solid <?= $svc['icon'] ?>"></i></div>
            <h4><?= htmlspecialchars($svc['label']) ?></h4>
            <p><?= htmlspecialchars($svc['desc']) ?></p>
            <?php if ($status === 'approved'): ?>
                <span class="badge approved"><i class="fa-solid fa-circle-check"></i> Active</span>
            <?php elseif ($status === 'requested'): ?>
                <span class="badge pending_approval"><i class="fa-solid fa-clock"></i> Pending Approval</span>
            <?php elseif ($status === 'rejected'): ?>
                <span class="badge not_enabled">Rejected &mdash; select to retry</span>
            <?php elseif ($status === 'suspended'): ?>
                <span class="badge not_enabled">Suspended &mdash; select to retry</span>
            <?php else: ?>
                <span class="badge not_enabled">Not Enabled</span>
            <?php endif; ?>
            <?php if (!empty($existingAt[$key])): ?>
                <span class="since">Requested <?= htmlspecialchars(date('d M Y', strtotime($existingAt[$key]))) ?></span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <button type="submit" class="submit-btn">Request Selected Services</button>
</form>

<?php
$myRequests = array_filter($serviceCatalog, fn($svc, $key) => isset($existing[$key]), ARRAY_FILTER_USE_BOTH);
?>
<div class="req-panel">
    <h3>My Service Requests</h3>
    <p class="req-sub">Track what you have requested and where each request stands. Services that need approval stay <strong>Pending Approval</strong> until an admin reviews them.</p>
    <?php if (!$myRequests): ?>
        <p style="font-size:13px;color:var(--muted);">You have not requested any services yet. Tick the boxes above and submit.</p>
    <?php else: ?>
        <table class="req-table">
            <thead>
                <tr><th scope="col">Service</th><th scope="col">Status</th><th scope="col">Requested</th><th scope="col">Last reviewed</th></tr>
            </thead>
            <tbody>
                <?php foreach ($myRequests as $key => $svc):
                    $st = $existing[$key];
                    [$stLabel, $stClass, $stIcon] = $statusMeta[$st] ?? [$st, 'not_enabled', 'fa-circle-question'];
                ?>
                <tr>
                    <td class="svc-name"><?= htmlspecialchars($svc['label']) ?></td>
                    <td><span class="badge <?= $stClass ?>"><i class="fa-solid <?= $stIcon ?>"></i> <?= htmlspecialchars($stLabel) ?></span></td>
                    <td><?= !empty($existingAt[$key]) ? htmlspecialchars(date('d M Y', strtotime($existingAt[$key]))) : '&mdash;' ?></td>
                    <td><?= !empty($existingReviewedAt[$key]) ? htmlspecialchars(date('d M Y', strtotime($existingReviewedAt[$key]))) : '&mdash;' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php render_shell_close(); ?>