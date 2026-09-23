<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../portal/shell.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}
$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT role, training_completed_at, preferred_support_channel FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (($user['role'] ?? '') !== 'merchant') {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}
if (!$user['training_completed_at']) {
    header('Location: setup.php');
    exit;
}

$color = '#f97316';
$lightColor = '#fff1e5';

$channelLabels = ['phone' => 'Phone', 'email' => 'Email', 'whatsapp' => 'WhatsApp', 'chat' => 'Chat'];
$channelIcons = ['phone' => 'fa-phone', 'email' => 'fa-envelope', 'whatsapp' => 'fa-brands fa-whatsapp', 'chat' => 'fa-comments'];

render_shell_open($pdo, $user_id, 'training', 'Training & Support Complete');
?>

<div class="status-wrap">
    <div class="card">
        <div class="icon-circle"><i class="fa-solid fa-graduation-cap"></i></div>
        <h1>Training complete</h1>
        <p class="subtitle">You're all set on product training and support setup</p>

        <div class="channel-box">
            <i class="fa-solid <?= $channelIcons[$user['preferred_support_channel']] ?? 'fa-comments' ?>"></i>
            <div class="c-text">
                <div class="c-label">Preferred support channel</div>
                <div class="c-value"><?= htmlspecialchars($channelLabels[$user['preferred_support_channel']] ?? 'Not set') ?></div>
            </div>
        </div>

        <a href="../onboarding_complete/index.php" class="btn block">Continue to Finish Onboarding</a>
    </div>
</div>

<?php render_shell_close(); ?>