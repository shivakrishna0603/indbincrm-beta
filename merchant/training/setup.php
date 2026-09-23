<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../portal/shell.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}
$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT role, party_code AS merchant_id, business_name, email, training_completed_at, app_walkthrough_completed_at, preferred_support_channel FROM users WHERE id = ?");
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

$color = '#f97316';
$lightColor = '#fff1e5';

$topics = [
    'accept_payments'   => ['title' => 'Accepting Payments', 'icon' => 'fa-credit-card', 'iconBg' => '#dcfce7', 'iconColor' => '#16a34a', 'desc' => 'Learn how to accept UPI and QR payments from your customers.'],
    'credit_repayments' => ['title' => 'Credit & Repayments', 'icon' => 'fa-file-invoice-dollar', 'iconBg' => '#ede9fe', 'iconColor' => '#7c3aed', 'desc' => 'Understand your credit limit and repayment process.'],
    'settlements'       => ['title' => 'Settlements & Payouts', 'icon' => 'fa-vault', 'iconBg' => '#ffedd5', 'iconColor' => '#ea580c', 'desc' => 'Learn about settlement cycles and payout processes.'],
    'dashboard'         => ['title' => 'Merchant Dashboard', 'icon' => 'fa-table-columns', 'iconBg' => '#dbeafe', 'iconColor' => '#2563eb', 'desc' => 'Get familiar with your dashboard, transactions and reports.'],
    'services'          => ['title' => 'Enabled Services', 'icon' => 'fa-star', 'iconBg' => '#fee2e2', 'iconColor' => '#dc2626', 'desc' => 'Learn how to use your activated products and services.'],
];

// Fetch per-topic completion
$progStmt = $pdo->prepare("SELECT topic_key FROM training_progress WHERE user_id = ?");
$progStmt->execute([$user_id]);
$completedTopics = array_column($progStmt->fetchAll(), 'topic_key');

// Fetch registered mobile from eKYC (the OTP-verified mobile number)
$mobStmt = $pdo->prepare("SELECT mobile_number FROM kyc_documents WHERE user_id = ? ORDER BY submitted_at DESC LIMIT 1");
$mobStmt->execute([$user_id]);
$mobRow = $mobStmt->fetch();
$registeredMobile = $mobRow['mobile_number'] ?? null;

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (($_POST['action'] ?? '') === 'start_walkthrough') {
        $pdo->prepare("UPDATE users SET app_walkthrough_completed_at = NOW() WHERE id = ?")->execute([$user_id]);
        header('Location: setup.php');
        exit;
    }

    if (($_POST['action'] ?? '') === 'complete_all') {
        $support_channel = $_POST['support_channel'] ?? '';

        // Re-check completion fresh from DB (don't trust only what was in this request)
        $progCheck = $pdo->prepare("SELECT COUNT(*) FROM training_progress WHERE user_id = ?");
        $progCheck->execute([$user_id]);
        $topicsDone = (int)$progCheck->fetchColumn();

        if ($topicsDone < count($topics)) {
            $errors[] = "Please complete all product training topics first.";
        }
        if (!$user['app_walkthrough_completed_at']) {
            $errors[] = "Please finish the app walkthrough first.";
        }
        if (!in_array($support_channel, ['phone', 'email', 'whatsapp', 'chat'])) {
            $errors[] = "Please select a preferred support channel.";
        }

        if (empty($errors)) {
            $pdo->prepare("UPDATE users SET training_completed_at = NOW(), preferred_support_channel = ? WHERE id = ?")
                ->execute([$support_channel, $user_id]);
            header('Location: status.php');
            exit;
        }
    }
}

$topicsDoneCount = count($completedTopics);
$walkthroughDone = (bool)$user['app_walkthrough_completed_at'];

$walkthroughSteps = [
    ['title' => 'Dashboard',    'icon' => 'fa-table-columns',   'desc' => 'Your home screen shows today\'s leads, quotes, and orders at a glance — the first thing to check each morning.'],
    ['title' => 'Transactions', 'icon' => 'fa-receipt',         'desc' => 'Every payment you receive appears here in real time, along with the customer\'s UPI handle and amount.'],
    ['title' => 'Settlements',  'icon' => 'fa-calendar-check',  'desc' => 'Track exactly when payments move from INDBIN into your bank account, and on what schedule.'],
    ['title' => 'Reports',      'icon' => 'fa-chart-line',      'desc' => 'See your volume, top products, and repeat-customer rate over any date range you choose.'],
    ['title' => 'Support',      'icon' => 'fa-headset',         'desc' => 'Raise a ticket or reach your chosen support channel directly, without leaving the dashboard.'],
];

render_shell_open($pdo, $user_id, 'training', 'Training & Support');
?>

<div class="page-header-row">
    <div class="page-header-left">
        <div class="header-icon"><i class="fa-solid fa-graduation-cap"></i></div>
        <div>
            <h1>Training &amp; Support</h1>
            <p>Learn how to use your merchant services and get help whenever you need it.</p>
        </div>
    </div>
    <div class="merchant-chip">
        <div><div class="m-label">Merchant ID</div><div class="m-value"><?= htmlspecialchars($user['merchant_id'] ?? '—') ?></div></div>
        <div><div class="m-label">Business Name</div><div class="m-value"><?= htmlspecialchars($user['business_name'] ?: '—') ?></div></div>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert-error"><?php foreach ($errors as $e) echo "<div>" . htmlspecialchars($e) . "</div>"; ?></div>
<?php endif; ?>

<!-- Product Training -->
<div class="section-card">
    <div class="section-head">
        <div class="section-head-left">
            <div class="section-icon"><i class="fa-solid fa-book-open"></i></div>
            <div>
                <h3>Product Training</h3>
                <p>Work through each topic — a short 2-page lesson — then confirm you've read it.</p>
            </div>
        </div>
        <div class="progress-meta">
            <div class="p-frac"><?= $topicsDoneCount ?> of <?= count($topics) ?> completed</div>
            <div class="progress-bar-track"><div class="progress-bar-fill" style="width:<?= round($topicsDoneCount / count($topics) * 100) ?>%;"></div></div>
            <div class="progress-pct"><?= round($topicsDoneCount / count($topics) * 100) ?>%</div>
        </div>
    </div>

    <div class="topic-grid">
        <?php foreach ($topics as $key => $topic):
            $isDone = in_array($key, $completedTopics);
        ?>
        <a href="topic.php?key=<?= urlencode($key) ?>" class="topic-card">
            <div class="topic-icon" style="background:<?= $topic['iconBg'] ?>; color:<?= $topic['iconColor'] ?>;"><i class="fa-solid <?= $topic['icon'] ?>"></i></div>
            <h4><?= htmlspecialchars($topic['title']) ?></h4>
            <p><?= htmlspecialchars($topic['desc']) ?></p>
            <div class="topic-status <?= $isDone ? 'completed' : 'not-started' ?>">
                <i class="fa-solid <?= $isDone ? 'fa-circle-check' : 'fa-circle' ?>"></i> <?= $isDone ? 'Completed' : 'Not started' ?>
            </div>
            <span class="topic-btn <?= $isDone ? 'done' : 'todo' ?>"><?= $isDone ? 'Review Lesson' : 'Start Lesson' ?></span>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- App Walkthrough -->
<div class="section-card">
    <div class="section-head">
        <div class="section-head-left">
            <div class="section-icon"><i class="fa-solid fa-mobile-screen"></i></div>
            <div>
                <h3>App Walkthrough</h3>
                <p><?= $walkthroughDone ? 'You\'ve completed the tour of your merchant portal.' : 'Step through what each part of your dashboard actually does.' ?></p>
            </div>
        </div>
        <?php if ($walkthroughDone): ?>
            <div class="topic-status completed"><i class="fa-solid fa-circle-check"></i> Completed</div>
        <?php endif; ?>
    </div>

    <?php if ($walkthroughDone): ?>
        <div class="walkthrough-steps">
            <?php foreach ($walkthroughSteps as $i => $step): ?>
                <div class="wt-step">
                    <div class="wt-badge is-done"><i class="fa-solid fa-check"></i></div>
                    <h5><?= htmlspecialchars($step['title']) ?></h5>
                    <span><?= htmlspecialchars(explode(' ', $step['desc'], 2)[0]) ?>&hellip;</span>
                </div>
                <?php if ($i < count($walkthroughSteps) - 1): ?><i class="fa-solid fa-arrow-right wt-arrow"></i><?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="wt-viewer" id="wtViewer">
            <div class="wt-progress-track"><div class="wt-progress-fill" id="wtProgressFill" style="width:20%;"></div></div>

            <div class="wt-slide">
                <div class="wt-slide-icon" id="wtSlideIcon"><i class="fa-solid fa-table-columns"></i></div>
                <h4 id="wtSlideTitle">Dashboard</h4>
                <p id="wtSlideDesc"><?= htmlspecialchars($walkthroughSteps[0]['desc']) ?></p>
                <div class="wt-slide-counter" id="wtSlideCounter">Step 1 of <?= count($walkthroughSteps) ?></div>
            </div>

            <div class="wt-nav">
                <button type="button" class="btn-lesson-back" id="wtBackBtn" onclick="wtGo(-1)" disabled><i class="fa-solid fa-arrow-left"></i> Back</button>
                <button type="button" class="btn-primary-sm" id="wtNextBtn" onclick="wtGo(1)">Next <i class="fa-solid fa-arrow-right"></i></button>
            </div>

            <form method="POST" id="wtFinishForm" style="display:none;">
                <input type="hidden" name="action" value="start_walkthrough">
            </form>
        </div>

        <script>
            const wtSteps = <?= json_encode($walkthroughSteps) ?>;
            let wtIndex = 0;

            function wtRender() {
                const step = wtSteps[wtIndex];
                document.getElementById('wtSlideIcon').innerHTML = '<i class="fa-solid ' + step.icon + '"></i>';
                document.getElementById('wtSlideTitle').textContent = step.title;
                document.getElementById('wtSlideDesc').textContent = step.desc;
                document.getElementById('wtSlideCounter').textContent = 'Step ' + (wtIndex + 1) + ' of ' + wtSteps.length;
                document.getElementById('wtProgressFill').style.width = Math.round((wtIndex + 1) / wtSteps.length * 100) + '%';
                document.getElementById('wtBackBtn').disabled = (wtIndex === 0);

                const nextBtn = document.getElementById('wtNextBtn');
                if (wtIndex === wtSteps.length - 1) {
                    nextBtn.innerHTML = '<i class="fa-solid fa-circle-check"></i> Finish Walkthrough';
                    nextBtn.onclick = () => document.getElementById('wtFinishForm').submit();
                } else {
                    nextBtn.innerHTML = 'Next <i class="fa-solid fa-arrow-right"></i>';
                    nextBtn.onclick = () => wtGo(1);
                }
            }

            function wtGo(dir) {
                wtIndex = Math.max(0, Math.min(wtSteps.length - 1, wtIndex + dir));
                wtRender();
            }
        </script>
    <?php endif; ?>
</div>

<form method="POST" id="finalForm">
<input type="hidden" name="action" value="complete_all">

<!-- Support Channel Activation -->
<div class="section-card">
    <div class="section-head">
        <div class="section-head-left">
            <div class="section-icon"><i class="fa-solid fa-headset"></i></div>
            <div>
                <h3>Support Channel Activation</h3>
                <p>Choose how you would like to contact our support team.</p>
            </div>
        </div>
    </div>

    <div class="channel-grid">

        <label class="channel-card" data-channel="phone" onclick="selectChannel(this)">
            <input type="radio" name="support_channel" value="phone" <?= $user['preferred_support_channel'] === 'phone' ? 'checked' : '' ?>>
            <div class="ch-title"><div class="ch-icon" style="background:#dbeafe; color:#2563eb;"><i class="fa-solid fa-phone"></i></div>Phone</div>
            <div class="ch-detail">Registered Mobile</div>
            <div class="ch-value"><?= $registeredMobile ? '+91 ' . htmlspecialchars($registeredMobile) : 'Not available' ?> <?= $registeredMobile ? '<span class="verified-check"><i class="fa-solid fa-circle-check"></i></span>' : '' ?></div>
            <div class="ch-hours-label">Support Hours<br>9:00 AM – 6:00 PM</div>
        </label>

        <label class="channel-card" data-channel="email" onclick="selectChannel(this)">
            <input type="radio" name="support_channel" value="email" <?= $user['preferred_support_channel'] === 'email' ? 'checked' : '' ?>>
            <div class="ch-title"><div class="ch-icon" style="background:#dcfce7; color:#16a34a;"><i class="fa-solid fa-envelope"></i></div>Email</div>
            <div class="ch-detail">Registered Email</div>
            <div class="ch-value"><?= htmlspecialchars($user['email']) ?> <span class="verified-check"><i class="fa-solid fa-circle-check"></i></span></div>
            <div class="ch-hours-label">Support Hours<br>24/7 (Response in 24 hrs)</div>
        </label>

        <label class="channel-card" data-channel="whatsapp" onclick="selectChannel(this)">
            <input type="radio" name="support_channel" value="whatsapp" <?= $user['preferred_support_channel'] === 'whatsapp' ? 'checked' : '' ?>>
            <div class="ch-title"><div class="ch-icon" style="background:#dcfce7; color:#16a34a;"><i class="fa-brands fa-whatsapp"></i></div>WhatsApp</div>
            <div class="ch-detail">Registered Mobile</div>
            <div class="ch-value"><?= $registeredMobile ? '+91 ' . htmlspecialchars($registeredMobile) : 'Not available' ?> <?= $registeredMobile ? '<span class="verified-check"><i class="fa-solid fa-circle-check"></i></span>' : '' ?></div>
            <div class="ch-hours-label">Support Hours<br>9:00 AM – 6:00 PM</div>
        </label>

        <label class="channel-card" data-channel="chat" onclick="selectChannel(this)">
            <input type="radio" name="support_channel" value="chat" <?= $user['preferred_support_channel'] === 'chat' ? 'checked' : '' ?>>
            <div class="ch-title"><div class="ch-icon" style="background:#f1f5f9; color:#475569;"><i class="fa-solid fa-comments"></i></div>Chat</div>
            <div class="ch-detail">In-Dashboard</div>
            <div class="ch-value">Available from Customer Support &amp; After Sales</div>
            <div class="ch-hours-label">Support Hours<br>9:00 AM – 9:00 PM</div>
        </label>

    </div>
</div>

<!-- Summary -->
<div class="summary-card">
    <div class="summary-title"><i class="fa-solid fa-circle-check" style="color:#16a34a;"></i> Training &amp; Support Summary</div>
    <div class="summary-cols">
        <div class="summary-col">
            <h5>Training</h5>
            <div class="summary-line"><i class="fa-solid <?= $topicsDoneCount === count($topics) ? 'fa-circle-check' : 'fa-circle-dot' ?>" style="color:<?= $topicsDoneCount === count($topics) ? '#16a34a' : '#94a3b8' ?>;"></i> Product Training <?= $topicsDoneCount === count($topics) ? 'Completed' : "($topicsDoneCount/" . count($topics) . ")" ?></div>
            <div class="summary-line"><i class="fa-solid <?= $walkthroughDone ? 'fa-circle-check' : 'fa-circle-dot' ?>" style="color:<?= $walkthroughDone ? '#16a34a' : '#94a3b8' ?>;"></i> App Walkthrough <?= $walkthroughDone ? 'Completed' : 'Not started' ?></div>
        </div>
        <div class="summary-col">
            <h5>Support</h5>
            <div class="summary-line">Preferred Channel: <strong id="summaryChannel"><?= htmlspecialchars(ucfirst($user['preferred_support_channel'] ?? 'Not selected')) ?></strong></div>
            <div class="summary-line">Status: <span class="status-pill">Ready to activate</span></div>
        </div>
    </div>
</div>

<div class="form-actions">
    <a href="../account_setup/status.php" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Back</a>
    <button type="submit" class="btn-continue">Complete &amp; Continue <i class="fa-solid fa-arrow-right"></i></button>
</div>

</form>

<style>
    .topic-card { text-decoration: none; display: block; cursor: pointer; }
    .topic-status.not-started { color: #94a3b8; }
    .topic-status.not-started i { font-size: 8px; }

    .wt-viewer { max-width: 480px; margin: 8px auto 0; }
    .wt-progress-track { height: 5px; background: #e2e8f0; border-radius: 4px; overflow: hidden; margin-bottom: 22px; }
    .wt-progress-fill { height: 100%; background: <?= $color ?>; transition: width .2s; }
    .wt-slide { text-align: center; padding: 10px 0 22px; }
    .wt-slide-icon { width: 56px; height: 56px; border-radius: 50%; background: <?= $lightColor ?>; color: <?= $color ?>; display: flex; align-items: center; justify-content: center; font-size: 22px; margin: 0 auto 14px; }
    .wt-slide h4 { font-size: 16px; color: #0b2545; margin-bottom: 8px; }
    .wt-slide p { font-size: 13px; color: #64748b; line-height: 1.6; max-width: 380px; margin: 0 auto; }
    .wt-slide-counter { font-size: 11px; color: #94a3b8; margin-top: 14px; font-weight: 600; }
    .wt-nav { display: flex; justify-content: space-between; gap: 10px; }
    .wt-nav .btn-lesson-back { padding: 10px 20px; border-radius: 8px; border: 1px solid #cbd5e1; background: #fff; color: #334155; font-weight: 600; font-size: 13px; cursor: pointer; }
    .wt-nav .btn-lesson-back:disabled { opacity: 0.4; cursor: default; }
    .wt-badge.is-done { background: #16a34a; color: #fff; }
</style>

<?php render_shell_close(); ?>