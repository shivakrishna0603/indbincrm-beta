<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../portal/shell.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}
$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT role, risk_score, risk_category, credit_limit, agreement_signed_at FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (($user['role'] ?? '') !== 'merchant') {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$hasScore = $user['risk_score'] !== null;
$isSigned = $user['agreement_signed_at'] !== null;
$color = '#f97316';
$lightColor = '#fff1e5';

$categoryConfig = [
    'low'    => ['label' => 'Low risk', 'color' => '#059669', 'bg' => '#d1fae5'],
    'medium' => ['label' => 'Medium risk', 'color' => '#b45309', 'bg' => '#fef3c7'],
    'high'   => ['label' => 'High risk', 'color' => '#dc2626', 'bg' => '#fee2e2'],
];
$cfg = $categoryConfig[$user['risk_category'] ?? 'medium'];
?>


    <?php render_shell_open($pdo, $user_id, 'credit', 'Credit & Risk Assessment'); ?>

    <div style="max-width:480px; margin:0 auto;">
        <div class="credit-card">

            <?php if (!$hasScore): ?>

                <div class="icon-circle"><i class="fa-solid fa-chart-line"></i></div>
                <h1>No credit score yet</h1>
                <p class="empty-state">Your risk score and credit limit are calculated automatically once your business verification is approved by our team.</p>
                <a href="../business/index.php" class="btn block">Check verification status</a>

            <?php elseif (!$isSigned): ?>

                <div class="icon-circle"><i class="fa-solid fa-file-signature"></i></div>
                <h1>Almost there</h1>
                <p class="subtitle">You've been assessed — sign your merchant agreement to activate this credit limit</p>

                <div class="category-pill"><?= htmlspecialchars($cfg['label']) ?> &middot; Score <?= (int)$user['risk_score'] ?>/100</div>

                <div class="limit-box" style="opacity:0.5;">
                    <div class="label">Pending credit limit</div>
                    <div class="amount">₹<?= number_format((float)$user['credit_limit'], 0) ?></div>
                </div>

                <a href="../agreements/index.php" class="btn block">Sign agreement to activate</a>

            <?php else: ?>

                <div class="icon-circle"><i class="fa-solid fa-chart-line"></i></div>
                <h1>Your credit profile</h1>
                <p class="subtitle">Based on your eKYC, business verification, and profile</p>

                <div class="score-ring">
                    <div class="score-ring-inner">
                        <div class="score-num"><?= (int)$user['risk_score'] ?></div>
                        <div class="score-label">out of 100</div>
                    </div>
                </div>

                <div class="category-pill"><?= htmlspecialchars($cfg['label']) ?></div>

                <div class="limit-box">
                    <div class="label">Active credit limit</div>
                    <div class="amount">₹<?= number_format((float)$user['credit_limit'], 0) ?></div>
                </div>

                <p style="font-size:12px; color:#94a3b8; margin-top:14px;">This score is recalculated whenever your verification details change.</p>

            <?php endif; ?>

        </div>
    </div>

    <?php render_shell_close(); ?>