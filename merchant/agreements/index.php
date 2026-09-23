<?php
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}
$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT role, risk_score, agreement_signed_at FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (($user['role'] ?? '') !== 'merchant') {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

// Must have a credit score/limit assigned before an agreement can even be generated
if ($user['risk_score'] === null) {
    header('Location: ../business/index.php');
    exit;
}

if ($user['agreement_signed_at']) {
    header('Location: status.php');
} else {
    header('Location: sign.php');
}
exit;
