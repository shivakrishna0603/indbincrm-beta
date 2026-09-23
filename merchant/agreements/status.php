<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../portal/shell.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}
$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT role, agreement_signed_at, credit_limit FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (($user['role'] ?? '') !== 'merchant' || !$user['agreement_signed_at']) {
    header('Location: index.php');
    exit;
}

$sigStmt = $pdo->prepare("SELECT * FROM agreement_signatures WHERE user_id = ? ORDER BY signed_at DESC LIMIT 1");
$sigStmt->execute([$user_id]);
$sig = $sigStmt->fetch();

$color = '#f97316';
$lightColor = '#fff1e5';
?>


    <?php render_shell_open($pdo, $user_id, 'agreement', 'Agreement Signed'); ?>

    <div style="max-width:480px; margin:0 auto;">
        <div class="card">
            <div class="icon-circle"><i class="fa-solid fa-file-circle-check"></i></div>
            <h1>Agreement signed</h1>
            <p class="subtitle">Your merchant credit line is now active</p>

            <?php if ($sig): ?>
                <div class="sig-preview">
                    <img src="<?= htmlspecialchars($sig['signature_image_path']) ?>" alt="Your signature">
                    <div class="sig-name">— <?= htmlspecialchars($sig['signed_name']) ?></div>
                </div>

                <div class="details">
                    <div><span>Agreement version</span><span><?= htmlspecialchars($sig['agreement_version']) ?></span></div>
                    <div><span>Credit limit at signing</span><span>₹<?= number_format((float)$sig['credit_limit_at_signing'], 0) ?></span></div>
                    <div><span>Signed on</span><span><?= htmlspecialchars(date('d M Y, h:i A', strtotime($sig['signed_at']))) ?></span></div>
                </div>
            <?php endif; ?>

            <a href="../account_setup/index.php" class="btn block">Continue to Account Setup</a>
            <a href="../credit/status.php" style="display:block; text-align:center; font-size:13px; color:#64748b; text-decoration:none; margin-top:10px;">View your credit dashboard instead</a>
        </div>
    </div>

    <?php render_shell_close(); ?>