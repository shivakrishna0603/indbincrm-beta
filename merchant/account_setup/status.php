<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../portal/shell.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}
$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT role, party_code AS merchant_id, account_setup_completed_at FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (($user['role'] ?? '') !== 'merchant') {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}
if (!$user['account_setup_completed_at']) {
    header('Location: setup.php');
    exit;
}

$accStmt = $pdo->prepare("SELECT * FROM settlement_accounts WHERE user_id = ? AND is_primary = 1 ORDER BY created_at DESC LIMIT 1");
$accStmt->execute([$user_id]);
$account = $accStmt->fetch();

$prefStmt = $pdo->prepare("SELECT * FROM payout_preferences WHERE user_id = ?");
$prefStmt->execute([$user_id]);
$pref = $prefStmt->fetch();

$color = '#f97316';
$lightColor = '#fff1e5';

$freqLabels = ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'];
?>


    <?php render_shell_open($pdo, $user_id, 'account_setup', 'Account Setup Complete'); ?>

    <div style="max-width:480px; margin:0 auto;">
        <div class="card">
            <div class="icon-circle"><i class="fa-solid fa-circle-check"></i></div>
            <h1>Account setup complete</h1>
            <p class="subtitle">Your merchant account is configured and ready</p>

            <div class="merchant-id-box">
                <div class="label">Merchant ID</div>
                <div class="value"><?= htmlspecialchars($user['merchant_id']) ?></div>
            </div>

            <?php if ($account): ?>
                <div class="details">
                    <h4>Settlement account</h4>
                    <!-- account_holder / account_number match the settlement_accounts
                         columns. The page previously read account_holder_name and
                         bank_account_number, which are not real columns on this table -
                         hence "Undefined array key" and the null passed into substr(). -->
                    <div class="row"><span>Account holder</span><span><?= htmlspecialchars($account['account_holder'] ?? '') ?></span></div>
                    <div class="row"><span>Account number</span><span>•••<?= htmlspecialchars(substr((string)($account['account_number'] ?? ''), -4)) ?></span></div>
                    <div class="row"><span>IFSC</span><span><?= htmlspecialchars($account['ifsc_code']) ?></span></div>
                </div>
            <?php endif; ?>

            <?php if ($pref): ?>
                <div class="details">
                    <h4>Payout preferences</h4>
                    <div class="row"><span>Frequency</span><span><?= htmlspecialchars($freqLabels[$pref['payout_frequency']] ?? $pref['payout_frequency']) ?></span></div>
                    <?php if ($pref['payout_day']): ?>
                        <div class="row"><span>Payout day</span><span><?= htmlspecialchars($pref['payout_day']) ?></span></div>
                    <?php endif; ?>
                    <div class="row"><span>Minimum threshold</span><span>₹<?= number_format((float)$pref['minimum_threshold'], 0) ?></span></div>
                    <div class="row"><span>Notifications</span><span>
                        <?= $pref['notify_sms'] ? 'SMS' : '' ?><?= ($pref['notify_sms'] && $pref['notify_email']) ? ' + ' : '' ?><?= $pref['notify_email'] ? 'Email' : '' ?>
                        <?= (!$pref['notify_sms'] && !$pref['notify_email']) ? 'None' : '' ?>
                    </span></div>
                </div>
            <?php endif; ?>

            <a href="../training/index.php" class="btn block">Continue to Training &amp; Support</a>
            <a href="setup.php" class="edit-link">Edit settlement account or preferences</a>
        </div>
    </div>

    <?php render_shell_close(); ?>