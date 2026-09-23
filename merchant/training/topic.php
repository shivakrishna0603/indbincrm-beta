<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../portal/shell.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}
$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT role, training_completed_at FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (($user['role'] ?? '') !== 'merchant') {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}
if ($user['training_completed_at']) {
    header('Location: status.php');
    exit;
}

$topics = [
    'accept_payments'   => ['title' => 'Accepting Payments', 'icon' => 'fa-credit-card', 'iconBg' => '#dcfce7', 'iconColor' => '#16a34a'],
    'credit_repayments' => ['title' => 'Credit & Repayments', 'icon' => 'fa-file-invoice-dollar', 'iconBg' => '#ede9fe', 'iconColor' => '#7c3aed'],
    'settlements'       => ['title' => 'Settlements & Payouts', 'icon' => 'fa-vault', 'iconBg' => '#ffedd5', 'iconColor' => '#ea580c'],
    'dashboard'         => ['title' => 'Merchant Dashboard', 'icon' => 'fa-table-columns', 'iconBg' => '#dbeafe', 'iconColor' => '#2563eb'],
    'services'          => ['title' => 'Enabled Services', 'icon' => 'fa-star', 'iconBg' => '#fee2e2', 'iconColor' => '#dc2626'],
];

$key = $_GET['key'] ?? '';
if (!isset($topics[$key])) {
    header('Location: setup.php');
    exit;
}
$topic = $topics[$key];

$pagesStmt = $pdo->prepare("SELECT page_number, heading, body, tip FROM training_content WHERE topic_key = ? ORDER BY page_number ASC");
$pagesStmt->execute([$key]);
$pages = $pagesStmt->fetchAll();

// No content seeded yet for this topic — fall back rather than show a blank lesson
if (!$pages) {
    header('Location: setup.php');
    exit;
}
$totalPages = count($pages);

$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;
if ($page > $totalPages) $page = $totalPages;
$currentPage = $pages[$page - 1];
$isLastPage = ($page === $totalPages);

$progCheck = $pdo->prepare("SELECT 1 FROM training_progress WHERE user_id = ? AND topic_key = ?");
$progCheck->execute([$user_id, $key]);
$alreadyDone = (bool)$progCheck->fetchColumn();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'complete_topic') {
    if (empty($_POST['acknowledge'])) {
        $errors[] = "Please confirm you've read this before marking it complete.";
    } else {
        $pdo->prepare("INSERT IGNORE INTO training_progress (user_id, topic_key) VALUES (?, ?)")
            ->execute([$user_id, $key]);
        header('Location: setup.php');
        exit;
    }
}

$color = '#f97316';
$lightColor = '#fff1e5';

render_shell_open($pdo, $user_id, 'training', $topic['title']);
?>
<style>
    .lesson-wrap { max-width: 720px; margin: 0 auto; }
    .lesson-back-link { display:inline-flex; align-items:center; gap:6px; font-size:13px; color:#64748b; text-decoration:none; margin-bottom:16px; }
    .lesson-back-link:hover { color:<?= $color ?>; }
    .lesson-header { display:flex; align-items:center; gap:14px; margin-bottom: 16px; }
    .lesson-icon { width:48px; height:48px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:20px; background:<?= $topic['iconBg'] ?>; color:<?= $topic['iconColor'] ?>; flex-shrink:0; }
    .lesson-header h1 { font-size:21px; color:#0b2545; margin-bottom:2px; }
    .lesson-header .page-indicator { font-size:12px; color:#64748b; font-weight:600; }
    .lesson-progress-track { height:5px; background:#e2e8f0; border-radius:4px; overflow:hidden; margin-bottom:24px; }
    .lesson-progress-fill { height:100%; background:<?= $color ?>; transition:width .2s; }
    .lesson-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:32px; }
    .lesson-card h2 { font-size:17px; color:#0b2545; margin-bottom:14px; }
    .body-text { font-size:14px; color:#334155; line-height:1.75; white-space: pre-line; }
    .lesson-tip { display:flex; gap:10px; background:<?= $lightColor ?>; border-radius:10px; padding:14px 16px; margin-top:22px; font-size:13px; color:#7c4a1e; }
    .lesson-tip i { color:<?= $color ?>; margin-top:2px; }
    .lesson-nav { display:flex; justify-content:space-between; align-items:center; margin-top:26px; }
    .btn-lesson-back { padding:10px 20px; border-radius:8px; border:1px solid #cbd5e1; background:#fff; color:#334155; font-weight:600; font-size:13px; text-decoration:none; }
    .btn-lesson-back.disabled { opacity:0.4; pointer-events:none; }
    .btn-lesson-next { padding:10px 22px; border-radius:8px; border:none; background:<?= $color ?>; color:#fff; font-weight:700; font-size:13px; text-decoration:none; cursor:pointer; display:inline-flex; align-items:center; gap:7px; }
    .complete-box { border-top:1px solid #e2e8f0; margin-top:26px; padding-top:22px; }
    .complete-checkbox-row { display:flex; align-items:flex-start; gap:10px; font-size:13px; color:#334155; margin-bottom:16px; }
    .complete-checkbox-row input { margin-top:3px; }
    .alert-error { background:#fee2e2; color:#dc2626; border:1px solid #fca5a5; padding:10px 14px; border-radius:8px; font-size:13px; margin-bottom:16px; }
    .already-done-banner { display:flex; align-items:center; gap:8px; background:#d1fae5; color:#059669; padding:10px 16px; border-radius:8px; font-size:13px; font-weight:600; margin-bottom:20px; }
</style>

<div class="lesson-wrap">
    <a href="setup.php" class="lesson-back-link"><i class="fa-solid fa-arrow-left"></i> Back to Training &amp; Support</a>

    <div class="lesson-header">
        <div class="lesson-icon"><i class="fa-solid <?= $topic['icon'] ?>"></i></div>
        <div>
            <h1><?= htmlspecialchars($topic['title']) ?></h1>
            <div class="page-indicator">Page <?= $page ?> of <?= $totalPages ?></div>
        </div>
    </div>

    <div class="lesson-progress-track"><div class="lesson-progress-fill" style="width:<?= round($page / $totalPages * 100) ?>%;"></div></div>

    <?php if ($alreadyDone): ?>
        <div class="already-done-banner"><i class="fa-solid fa-circle-check"></i> You've already completed this topic — feel free to review it again anytime.</div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert-error"><?php foreach ($errors as $e) echo "<div>" . htmlspecialchars($e) . "</div>"; ?></div>
    <?php endif; ?>

    <div class="lesson-card">
        <h2><?= htmlspecialchars($currentPage['heading']) ?></h2>
        <div class="body-text"><?= htmlspecialchars($currentPage['body']) ?></div>

        <?php if (!empty($currentPage['tip'])): ?>
            <div class="lesson-tip"><i class="fa-solid fa-lightbulb"></i><div><?= htmlspecialchars($currentPage['tip']) ?></div></div>
        <?php endif; ?>

        <?php if (!$isLastPage): ?>
            <div class="lesson-nav">
                <a href="topic.php?key=<?= urlencode($key) ?>&page=<?= $page - 1 ?>" class="btn-lesson-back <?= $page <= 1 ? 'disabled' : '' ?>"><i class="fa-solid fa-arrow-left"></i> Back</a>
                <a href="topic.php?key=<?= urlencode($key) ?>&page=<?= $page + 1 ?>" class="btn-lesson-next">Next <i class="fa-solid fa-arrow-right"></i></a>
            </div>
        <?php else: ?>
            <div class="lesson-nav">
                <a href="topic.php?key=<?= urlencode($key) ?>&page=<?= $page - 1 ?>" class="btn-lesson-back <?= $page <= 1 ? 'disabled' : '' ?>"><i class="fa-solid fa-arrow-left"></i> Back</a>
            </div>

            <div class="complete-box">
                <form method="POST">
                    <input type="hidden" name="action" value="complete_topic">
                    <label class="complete-checkbox-row">
                        <input type="checkbox" name="acknowledge" value="1" required>
                        I've read and understood this
                    </label>
                    <button type="submit" class="btn-lesson-next"><i class="fa-solid fa-circle-check"></i> Mark as Complete</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php render_shell_close(); ?>
