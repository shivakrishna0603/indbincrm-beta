<?php
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}
$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT role, kyc_status, business_verification_status FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Only merchants have a business to verify
if (($user['role'] ?? '') !== 'merchant') {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$bvStatus = $user['business_verification_status'] ?? 'not_submitted';

if (in_array($bvStatus, ['not_submitted','not_started'], true) || $bvStatus === 'rejected') {
    header('Location: upload.php');
} else {
    header('Location: status.php');
}
exit;