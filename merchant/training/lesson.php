<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../portal/shell.php';
require_once __DIR__ . '/lessons.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}
$user_id = (int)$_SESSION['user_id'];

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

$topicKey = (string)($_GET['topic'] ?? '');
$lesson   = merchant_lesson($topicKey);

if ($lesson === null) {
    header('Location: setup.php');
    exit;
}

$progStmt = $pdo->prepare("SELECT topic_key FROM training_progress WHERE user_id = ?");
$progStmt->execute([$user_id]);
$completedTopics = array_column($progStmt->fetchAll(), 'topic_key');
$isDone = in_array($topicKey, $completedTopics, true);

$errors   = [];
$success  = '';
$confirmed = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_complete') {
    $confirmed = ($_POST['confirm'] ?? '') === 'yes';
    if (!$confirmed) {
        $errors[] = 'Please tick the checkbox at the bottom of the guide to confirm you have read it.';
    } else {
        $pdo->prepare("INSERT IGNORE INTO training_progress (user_id, topic_key) VALUES (?, ?)")
            ->execute([$user_id, $topicKey]);
        flash('success', 'Well done — "' . $lesson['title'] . '" is complete.');
        header('Location: setup.php');
        exit;
    }
}

$total   = count(merchant_lessons());
$todoNow = count($completedTopics);

$color = '#f97316';
$lightColor = '#fff1e5';

render_shell_open($pdo, $user_id, 'training', 'Training & Support');
?>

<style>
    .section-card { background: var(--surface); border: 1px solid var(--line); border-radius: 12px; padding: 22px 26px; margin-bottom: 22px; }
    .lesson-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 14px; flex-wrap: wrap; margin-bottom: 18px; }
    .lesson-head h2 { font-size: 18px; color: var(--ink); margin: 0 0 6px; }
    .lesson-head p { font-size: 13px; color: var(--muted); margin: 0; }
    .back-link { display: inline-flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 700; color: var(--brand); text-decoration: none; }
    .back-link:hover { text-decoration: underline; }
    .progress-meta { display: flex; align-items: center; gap: 10px; font-size: 12px; color: var(--muted); }
    .progress-bar-track { width: 110px; height: 6px; background: var(--field); border-radius: 999px; overflow: hidden; }
    .progress-bar-fill { height: 100%; background: var(--brand); border-radius: 999px; }

    .pdf-frame { width: 100%; height: 560px; border: 1px solid var(--line); border-radius: 10px; background: #fff; }

    .confirm-panel { margin-top: 22px; border: 1px dashed var(--line); border-radius: 10px; padding: 16px 20px; }
    .confirm-panel label { display: flex; gap: 10px; align-items: flex-start; font-size: 14px; font-weight: 700; color: var(--ink); cursor: pointer; }
    .confirm-panel input[type="checkbox"] { width: 18px; height: 18px; margin-top: 1px; accent-color: var(--brand); }
    .confirm-panel .hint { font-size: 12px; color: var(--muted); margin-top: 4px; }
    .confirm-panel .confirm-actions { margin-top: 14px; display: flex; gap: 10px; align-items: center; }
    .submit-btn { padding: 10px 20px; border-radius: 8px; border: none; background: var(--brand); color: #fff; font-weight: 700; font-size: 14px; cursor: pointer; }
    .submit-btn:disabled { opacity: .5; cursor: not-allowed; }
    .btn-back { display: inline-block; padding: 10px 18px; border-radius: 8px; border: 1px solid var(--line); color: var(--ink); font-size: 13px; font-weight: 700; text-decoration: none; }
    .btn-back:hover { border-color: var(--brand); color: var(--brand); }

    .done-banner { display: flex; align-items: center; gap: 12px; background: #d1fae5; border: 1px solid #6ee7b7; color: #065f46; border-radius: 10px; padding: 14px 18px; font-size: 14px; font-weight: 700; }
    .alert { padding: 10px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; }
    .alert-error { background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; }
    .help-links { display: flex; gap: 10px; flex-wrap: wrap; }
</style>

<div class="lesson-head">
    <div>
        <a class="back-link" href="setup.php"><i class="fa-solid fa-arrow-left"></i> Back to Training</a>
        <h2 style="margin-top:12px;"><?= htmlspecialchars($lesson['title']) ?></h2>
        <p><?= htmlspecialchars($lesson['subtitle'] ?? '') ?></p>
    </div>
    <div class="progress-meta">
        <span><?= $todoNow ?> of <?= $total ?> topics done</span>
        <div class="progress-bar-track"><div class="progress-bar-fill" style="width:<?= round($todoNow / $total * 100) ?>%;"></div></div>
    </div>
</div>

<?php if (!empty($success)): ?>
    <div class="alert" style="background:#d1fae5;color:#059669;border:1px solid #6ee7b7;"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if (!empty($errors)): ?>
    <div class="alert alert-error"><?php foreach ($errors as $e) echo "<div>" . htmlspecialchars($e) . "</div>"; ?></div>
<?php endif; ?>

<?php if ($isDone): ?>
    <div class="done-banner">
        <i class="fa-solid fa-circle-check" style="font-size:18px;"></i>
        You have completed this guide.
        <a class="btn-back" href="setup.php" style="margin-left:auto;">Back to Training</a>
    </div>
<?php else: ?>
    <div class="section-card">
        <iframe class="pdf-frame" src="pdf.php?topic=<?= urlencode($topicKey) ?>" title="<?= htmlspecialchars($lesson['title']) ?> guide"></iframe>

        <div class="confirm-panel">
            <form method="POST">
                <input type="hidden" name="action" value="confirm_complete">
                <label>
                    <input type="checkbox" name="confirm" value="yes" id="confirmBox" onclick="document.getElementById('completeBtn').disabled = !this.checked;">
                    <span>I have read the complete guide.</span>
                </label>
                <div class="hint">Please scroll through the entire guide above, then tick this box and complete the topic.</div>
                <div class="confirm-actions">
                    <button type="submit" class="submit-btn" id="completeBtn" disabled>Mark this topic complete</button>
                    <a class="btn-back" href="setup.php">Not now</a>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<div class="section-card">
    <h3 style="font-size:16px;color:var(--ink);margin:0 0 12px;">Need a hand?</h3>
    <div class="help-links">
        <a class="btn-back" href="../support/knowledge_base.php"><i class="fa-solid fa-book-open"></i> Browse knowledge base</a>
        <a class="btn-back" href="../support/index.php"><i class="fa-solid fa-headset"></i> Raise a support ticket</a>
    </div>
</div>

<?php render_shell_close(); ?>