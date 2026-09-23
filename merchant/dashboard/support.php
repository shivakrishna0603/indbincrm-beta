<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../portal/shell.php';
require_once __DIR__ . '/../portal/merchant_shell.php';
require_once __DIR__ . '/../support/_ticket_logic.php';

$user = require_merchant($pdo);
$user_id = (int)$user['id'];

if ($user['account_status'] !== 'active') { header('Location: ../onboarding_complete/index.php'); exit; }

$color = '#f97316';
$lightColor = '#fff1e5';

$result = tkt_handle_submit($pdo, $user_id);
$errors = $result['errors'];
if ($result['success']) {
    flash('success', $result['success']);
    header('Location: support.php');
    exit;
}

$ticketTabs = merchant_ticket_tabs();
$activeTab = $_GET['status'] ?? 'all';
if (!isset($ticketTabs[$activeTab])) $activeTab = 'all';

$ticketCounts = merchant_ticket_counts($pdo, $user_id);
$tickets      = merchant_ticket_list($pdo, $user_id, $activeTab);
$displays     = merchant_ticket_displays();

$statusColors   = $displays['status'];
$priorityColors = $displays['priority'];
$categoryLabels = $displays['category'];

render_merchant_shell_open($pdo, $user_id, 'support', 'Customer Support & After Sales');
?>

<style>
    .section-card { background: var(--surface); border: 1px solid var(--line); border-radius: 12px; padding: 22px 26px; margin-bottom: 22px; }
    .section-card h3 { font-size: 16px; color: var(--ink); margin-bottom: 14px; }

    .kb-banner { display: flex; align-items: center; gap: 16px; background: #fff1e5; border: 1px solid #fed7aa; border-radius: 12px; padding: 16px 22px; margin-bottom: 22px; flex-wrap: wrap; }
    .kb-banner .kb-icon { width: 42px; height: 42px; border-radius: 10px; background: var(--brand); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 18px; }
    .kb-banner .kb-title { font-size: 15px; font-weight: 800; color: var(--ink); }
    .kb-banner .kb-sub { font-size: 12.5px; color: var(--muted); margin-top: 2px; }
    .kb-banner a { margin-left: auto; }

    .form-group { margin-bottom: 14px; }
    .form-group label { display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; color: var(--ink); }
    .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px; border: 1px solid var(--field); border-radius: 8px; font-size: 14px; font-family: inherit; }
    .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .submit-btn { padding: 11px 22px; border-radius: 8px; border: none; background: var(--brand); color: #fff; font-weight: 700; font-size: 14px; cursor: pointer; }

    .alert-error { background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; padding: 10px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; }

    .empty-state { background: var(--surface); border: 1px dashed var(--line); border-radius: 12px; padding: 40px 30px; text-align: center; color: var(--muted); }

    .ticket-row { background: var(--surface); border: 1px solid var(--line); border-radius: 12px; padding: 16px 20px; margin-bottom: 10px; }
    .ticket-top { display: flex; justify-content: space-between; align-items: flex-start; }
    .ticket-top .t-subject { font-size: 14px; font-weight: 700; color: var(--ink); }
    .ticket-top .t-meta { font-size: 12px; color: var(--faint); margin-top: 4px; }
    .pill-row { display: flex; gap: 6px; }
    .status-pill { font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 20px; text-transform: capitalize; }
    .ticket-desc { font-size: 13px; color: var(--body); margin-top: 10px; }
    .admin-response { background: #f0fdf4; border-left: 3px solid #16a34a; padding: 10px 14px; margin-top: 10px; font-size: 13px; color: #166534; border-radius: 0 8px 8px 0; }

    .tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
    .tab { display: inline-flex; align-items: center; gap: 7px; padding: 8px 14px; border-radius: 999px; border: 1px solid var(--line); background: var(--surface); color: var(--muted); font-size: 12.5px; font-weight: 700; text-decoration: none; }
    .tab:hover { border-color: var(--brand); color: var(--brand); }
    .tab.active { background: var(--brand); border-color: var(--brand); color: #fff; }
    .tab .tab-count { min-width: 18px; height: 18px; padding: 0 5px; border-radius: 999px; background: var(--canvas); color: var(--muted); font-size: 11px; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; }
    .tab.active .tab-count { background: rgba(255,255,255,.25); color: #fff; }
</style>

<div class="kb-banner">
    <div class="kb-icon"><i class="fa-solid fa-book-open"></i></div>
    <div>
        <div class="kb-title">Answers before you ask</div>
        <div class="kb-sub">Payments, settlements, credit and more — search our knowledge base first.</div>
    </div>
    <a class="btn-primary-sm" href="../support/knowledge_base.php"><i class="fa-solid fa-magnifying-glass"></i> Browse knowledge base</a>
</div>

<div class="section-card">
    <h3>Raise a new ticket</h3>

    <?php if (!empty($errors)): ?>
        <div class="alert-error"><?php foreach ($errors as $e) echo "<div>" . htmlspecialchars($e) . "</div>"; ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label>Subject</label>
            <input type="text" name="subject" placeholder="Brief summary of the issue" value="<?= htmlspecialchars($_POST['subject'] ?? '') ?>" required>
        </div>
        <div class="two-col">
            <div class="form-group">
                <label>Category</label>
                <?php $selCat = $_POST['category'] ?? 'general'; ?>
                <select name="category">
                    <?php foreach ($categoryLabels as $ck => $cl): ?>
                        <option value="<?= $ck ?>" <?= $selCat === $ck ? 'selected' : '' ?>><?= htmlspecialchars($cl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Priority</label>
                <?php $selPri = $_POST['priority'] ?? 'normal'; ?>
                <select name="priority">
                    <option value="low" <?= $selPri === 'low' ? 'selected' : '' ?>>Low</option>
                    <option value="normal" <?= $selPri === 'normal' ? 'selected' : '' ?>>Normal</option>
                    <option value="high" <?= $selPri === 'high' ? 'selected' : '' ?>>High</option>
                    <option value="urgent" <?= $selPri === 'urgent' ? 'selected' : '' ?>>Urgent</option>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label>Description</label>
            <textarea name="description" rows="4" placeholder="Describe the issue in detail" required><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
        </div>
        <button type="submit" class="submit-btn">Submit Ticket</button>
    </form>
</div>

<h3 style="font-size:16px; color:var(--ink); margin-bottom:14px;">Your tickets</h3>

<div class="tabs">
    <?php foreach ($ticketTabs as $key => $label): ?>
        <a href="support.php?status=<?= urlencode($key) ?>" class="tab <?= $key === $activeTab ? 'active' : '' ?>">
            <?= htmlspecialchars($label) ?>
            <span class="tab-count"><?= $ticketCounts[$key] ?? 0 ?></span>
        </a>
    <?php endforeach; ?>
</div>

<?php if (empty($tickets)): ?>
    <div class="empty-state"><?= $activeTab === 'all' ? 'No support tickets yet.' : 'No tickets with this status.' ?></div>
<?php else: ?>
    <?php foreach ($tickets as $t): ?>
        <div class="ticket-row">
            <div class="ticket-top">
                <div>
                    <div class="t-subject"><?= htmlspecialchars($t['subject']) ?></div>
                    <div class="t-meta">
                        <?= htmlspecialchars($t['ticket_code']) ?> &middot;
                        <?= htmlspecialchars($categoryLabels[$t['category']] ?? ucfirst($t['category'])) ?> &middot;
                        Raised <?= htmlspecialchars(date('d M Y', strtotime($t['created_at']))) ?>
                    </div>
                </div>
                <div class="pill-row">
                    <span class="status-pill" style="background:<?= ($priorityColors[$t['priority']] ?? '#64748b') ?>22; color:<?= ($priorityColors[$t['priority']] ?? '#64748b') ?>;"><?= htmlspecialchars($t['priority']) ?></span>
                    <span class="status-pill" style="background:<?= ($statusColors[$t['status']] ?? '#64748b') ?>22; color:<?= ($statusColors[$t['status']] ?? '#64748b') ?>;"><?= htmlspecialchars(str_replace('_', ' ', $t['status'])) ?></span>
                </div>
            </div>
            <div class="ticket-desc"><?= nl2br(htmlspecialchars($t['description'])) ?></div>
            <?php if ($t['admin_response']): ?>
                <div class="admin-response"><strong>Support team:</strong> <?= nl2br(htmlspecialchars($t['admin_response'])) ?></div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php render_shell_close(); ?>
