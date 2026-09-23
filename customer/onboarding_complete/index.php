<?php
/**
 * Step 7: Onboarding complete.
 *
 * Two outcomes: every step done and eKYC approved, which mints the customer
 * ID and unlocks the dashboard; or every step done with eKYC still in review,
 * which shows a summary and keeps the dashboard closed.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
require_once CUST_ROOT . '/portal/shell.php';

$user = require_customer($pdo);
require_step($pdo, $user, 7);

$code = $user['customer_code'] ?: issue_customer_code($pdo, (int)$user['id']);
$approved = $user['kyc_status'] === 'approved' && $code !== null;

$stmt = $pdo->prepare(
    "SELECT pa.status, pa.assigned_limit, pc.product_name
       FROM product_activations pa
       JOIN product_catalog pc ON pc.product_code = pa.product_code
      WHERE pa.user_id = ? ORDER BY pc.product_name"
);
$stmt->execute([(int)$user['id']]);
$products = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT full_name FROM kyc_details WHERE user_id = ?");
$stmt->execute([(int)$user['id']]);
$kycName = (string)$stmt->fetchColumn();

$balance = loyalty_balance($pdo, (int)$user['id']);

onboarding_shell_open($pdo, $user, 7, $approved ? 'Onboarding complete' : 'Awaiting verification');
?>
<div class="card">
    <div class="card-icon <?= $approved ? 'is-ok' : 'is-warn' ?>">
        <i class="fa-solid <?= $approved ? 'fa-circle-check' : 'fa-clock' ?>"></i>
    </div>
    <div class="card-head">
        <h2><?= $approved ? 'You are all set' : 'Everything submitted' ?></h2>
        <p><?= $approved
            ? 'Your account is active and your customer ID is below.'
            : 'Your details are recorded. The dashboard opens once identity verification clears.' ?></p>
    </div>

    <div class="detail-block">
        <div class="detail-row">
            <span>Customer ID</span>
            <strong style="color:<?= $approved ? 'var(--brand)' : 'var(--warn)' ?>;">
                <?= e($code ?: 'Issued after verification') ?>
            </strong>
        </div>
        <div class="detail-row"><span>Name</span><strong><?= e($kycName ?: $user['full_name']) ?></strong></div>
        <div class="detail-row"><span>Mobile</span><strong><?= e(mask_tail((string)$user['mobile'])) ?></strong></div>
        <div class="detail-row"><span>Reward points</span><strong><?= $balance ?></strong></div>
    </div>

    <?php if ($products): ?>
        <h3 style="font-size:14px;font-weight:700;color:var(--ink);margin-bottom:10px;">Your products</h3>
        <div class="detail-block">
            <?php foreach ($products as $p): ?>
                <div class="detail-row">
                    <span><?= e($p['product_name']) ?></span>
                    <strong>
                        <?php if ((float)$p['assigned_limit'] > 0): ?>
                            <?= money((float)$p['assigned_limit']) ?>
                        <?php endif; ?>
                        <span class="pill <?= $p['status'] === 'active' ? 'is-ok' : ($p['status'] === 'declined' ? 'is-bad' : 'is-warn') ?>">
                            <?= e($p['status']) ?>
                        </span>
                    </strong>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($approved): ?>
        <a class="btn block" href="<?= CUST_BASE ?>/dashboard/index.php">Open my dashboard</a>
    <?php else: ?>
        <button class="btn block" disabled>
            <i class="fa-solid fa-lock"></i> Dashboard opens after verification
        </button>
        <a class="btn block quiet" style="margin-top:10px;" href="<?= CUST_BASE ?>/ekyc/status.php">
            Check verification status
        </a>
    <?php endif; ?>
</div>
<?php onboarding_shell_close(); ?>
