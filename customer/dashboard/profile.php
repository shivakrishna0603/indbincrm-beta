<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
require_once CUST_ROOT . '/portal/customer_shell.php';

$user = require_customer($pdo);
if ($user['kyc_status'] !== 'approved' || empty($user['customer_code'])) {
    flash('info', 'Your dashboard opens once identity verification clears.');
    redirect(step_url(effective_step($pdo, $user)));
}
$uid = (int)$user['id'];

$stmt = $pdo->prepare("SELECT * FROM customer_profiles WHERE user_id = ?");
$stmt->execute([$uid]);
$profile = $stmt->fetch() ?: [];

portal_shell_open($pdo, $user, 'profile', 'My profile');
?>
<div class="card wide">
    <div class="detail-block">
        <div class="detail-row"><span>Customer ID</span><strong><?= e($user['customer_code']) ?></strong></div>
        <div class="detail-row"><span>Name</span><strong><?= e($user['full_name']) ?></strong></div>
        <div class="detail-row"><span>Email</span><strong><?= e($user['email']) ?></strong></div>
        <div class="detail-row"><span>Mobile</span><strong><?= e(mask_tail((string)$user['mobile'])) ?></strong></div>
        <div class="detail-row"><span>Address</span><strong><?= e(trim(($profile['address_line1'] ?? '') . ' ' . ($profile['city'] ?? '') . ' ' . ($profile['pincode'] ?? ''))) ?: '—' ?></strong></div>
        <div class="detail-row"><span>Language</span><strong><?= e($profile['preferred_language'] ?? 'English') ?></strong></div>
    </div>
    <a class="btn" href="<?= CUST_BASE ?>/profile/index.php">Update details</a>
    <a class="btn secondary" href="<?= CUST_BASE ?>/consents/index.php">Manage consents</a>
</div>
<?php portal_shell_close(); ?>
