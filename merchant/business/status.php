<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../portal/shell.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}
$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT role, business_verification_status FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (($user['role'] ?? '') !== 'merchant') {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$status = $user['business_verification_status'] ?? 'not_submitted';

$docStmt = $pdo->prepare("SELECT * FROM business_verifications WHERE user_id = ? ORDER BY submitted_at DESC LIMIT 1");
$docStmt->execute([$user_id]);
$doc = $docStmt->fetch();

$color = '#f97316';
$lightColor = '#fff1e5';

$statusConfig = [
    'not_submitted' => ['label' => 'Not submitted', 'icon' => 'fa-circle-exclamation', 'sColor' => '#64748b', 'sBg' => '#f1f5f9'],
    'not_started'   => ['label' => 'Not submitted', 'icon' => 'fa-circle-exclamation', 'sColor' => '#64748b', 'sBg' => '#f1f5f9'],   // the schema's name for the same state
    'pending'       => ['label' => 'Pending review', 'icon' => 'fa-clock', 'sColor' => '#b45309', 'sBg' => '#fef3c7'],
    'approved'      => ['label' => 'Approved', 'icon' => 'fa-circle-check', 'sColor' => '#059669', 'sBg' => '#d1fae5'],
    'rejected'      => ['label' => 'Rejected', 'icon' => 'fa-circle-xmark', 'sColor' => '#dc2626', 'sBg' => '#fee2e2'],
];
$cfg = $statusConfig[$status] ?? $statusConfig['not_submitted'];
?>


    <?php render_shell_open($pdo, $user_id, 'business', 'Business Verification Status'); ?>

    <div style="max-width:460px; margin:0 auto;">
    <div class="card">
        <div class="icon-circle"><i class="fa-solid <?= $cfg['icon'] ?>"></i></div>
        <h1>Business verification</h1>
        <p class="status-label"><?= htmlspecialchars($cfg['label']) ?></p>

        <div class="gradient-divider"></div>

        <?php if ($doc): ?>
            <div class="details">
                <div><span>GSTIN</span><span><?= htmlspecialchars($doc['gstin']) ?></span></div>
                <div><span>Bank account</span><span>••••<?= htmlspecialchars(substr($doc['bank_account_number'], -4)) ?></span></div>
                <div><span>IFSC</span><span><?= htmlspecialchars($doc['ifsc_code']) ?></span></div>
                <div><span>Years in business</span><span><?= htmlspecialchars($doc['years_in_business'] ?? '—') ?></span></div>
                <div><span>Submitted</span><span><?= htmlspecialchars(date('d M Y', strtotime($doc['submitted_at']))) ?></span></div>
            </div>
        <?php endif; ?>

        <?php if ($status === 'rejected' && $doc && !empty($doc['rejection_reason'])): ?>
            <div class="reject-reason">
                <strong>Reason:</strong> <?= htmlspecialchars($doc['rejection_reason']) ?>
            </div>
        <?php endif; ?>

        <?php if (in_array($status, ['not_submitted','not_started'], true)): ?>
            <a href="upload.php" class="btn block">Start business verification</a>
        <?php elseif ($status === 'rejected'): ?>
            <a href="upload.php" class="btn block">Resubmit documents</a>
        <?php elseif ($status === 'pending'): ?>
            <p style="font-size:13px; color:#64748b; margin-top:4px; margin-bottom:14px;">We'll notify you once it's reviewed. This usually takes 1–2 business days.</p>
            <a href="../credit/status.php" class="btn block">Continue to Credit & Risk Assessment</a>
        <?php elseif ($status === 'approved'): ?>
            <a href="../credit/status.php" class="btn block">View your credit score</a>
        <?php endif; ?>
    </div>
    </div>

    <?php render_shell_close(); ?>