<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../portal/shell.php';
require_once __DIR__ . '/../portal/merchant_shell.php';

$user = require_merchant($pdo);
$user_id = (int)$user['id'];

if ($user['account_status'] !== 'active') { header('Location: ../onboarding_complete/index.php'); exit; }

$color = '#f97316';
$lightColor = '#fff1e5';

$statusTabs = ['all' => 'All', 'submitted' => 'Submitted', 'under_review' => 'Under Review', 'approved' => 'Approved', 'rejected' => 'Rejected', 'completed' => 'Completed'];
$activeTab = $_GET['status'] ?? 'all';
if (!isset($statusTabs[$activeTab])) $activeTab = 'all';

$countStmt = $pdo->prepare("SELECT status, COUNT(*) AS cnt FROM applications WHERE merchant_id = ? GROUP BY status");
$countStmt->execute([$user_id]);
$statusCounts = ['all' => 0];
foreach ($countStmt->fetchAll() as $row) {
    $statusCounts[$row['status']] = (int)$row['cnt'];
    $statusCounts['all'] += (int)$row['cnt'];
}

$appsStmt = $pdo->prepare(
    "SELECT a.id, a.application_number, a.amount, a.status, a.current_stage,
            a.rejection_reason, a.submitted_at, a.product_name,
            COALESCE(l.name, cu.full_name) AS customer_name
     FROM applications a
     LEFT JOIN leads l  ON l.id = a.lead_id
     LEFT JOIN users cu ON cu.id = a.customer_id
     WHERE a.merchant_id = ?" . ($activeTab === 'all' ? '' : " AND a.status = ?") . "
     ORDER BY a.submitted_at DESC, a.id DESC"
);
$params = [$user_id];
if ($activeTab !== 'all') $params[] = $activeTab;
$appsStmt->execute($params);
$applications = $appsStmt->fetchAll();

// All stage events for the listed applications, in one query, grouped in PHP.
$eventsByApp = [];
if ($applications) {
    $ids = array_column($applications, 'id');
    $place = implode(',', array_fill(0, count($ids), '?'));
    $evStmt = $pdo->prepare(
        "SELECT * FROM application_events WHERE application_id IN ($place)
         ORDER BY created_at ASC, id ASC"
    );
    $evStmt->execute($ids);
    foreach ($evStmt->fetchAll() as $ev) {
        $eventsByApp[(int)$ev['application_id']][] = $ev;
    }
}

$statusColors = ['draft' => '#94a3b8', 'requirement_raised' => '#2563eb', 'submitted' => '#2563eb', 'under_review' => '#b45309', 'approved' => '#059669', 'rejected' => '#dc2626', 'disbursed' => '#7c3aed', 'completed' => '#0d9488', 'withdrawn' => '#64748b'];
$statusLabels = ['draft' => 'Draft', 'requirement_raised' => 'Requirement Raised', 'submitted' => 'Submitted', 'under_review' => 'Under Review', 'approved' => 'Approved', 'rejected' => 'Rejected', 'disbursed' => 'Disbursed', 'completed' => 'Completed', 'withdrawn' => 'Withdrawn'];
// The track follows applications.current_stage (the workflow's real stages),
// not status - status only adds terminal states around it. Disbursement is
// INDBIN's action, so the merchant pipeline ends at Approved; already-funded
// applications are shown as fully complete.
$stagePipeline = ['submitted' => 'Submitted', 'kyc_check' => 'KYC Check', 'credit_review' => 'Credit Review', 'approved' => 'Approved'];

render_merchant_shell_open($pdo, $user_id, 'crm', 'Applications');
?>

<style>
    .empty-state { background: var(--surface); border: 1px dashed var(--line); border-radius: 12px; padding: 50px 30px; text-align: center; color: var(--muted); }

    .tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 18px; }
    .tab { display: inline-flex; align-items: center; gap: 7px; padding: 8px 14px; border-radius: 999px; border: 1px solid var(--line); background: var(--surface); color: var(--muted); font-size: 12.5px; font-weight: 700; text-decoration: none; }
    .tab:hover { border-color: var(--brand); color: var(--brand); }
    .tab.active { background: var(--brand); border-color: var(--brand); color: #fff; }
    .tab .tab-count { min-width: 18px; height: 18px; padding: 0 5px; border-radius: 999px; background: var(--canvas); color: var(--muted); font-size: 11px; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; }
    .tab.active .tab-count { background: rgba(255,255,255,.25); color: #fff; }

    .app-card { background: var(--surface); border: 1px solid var(--line); border-radius: 12px; padding: 20px 24px; margin-bottom: 14px; }
    .app-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 14px; }
    .app-top .a-num { font-size: 13px; color: var(--faint); font-weight: 600; }
    .app-top .a-name { font-size: 16px; font-weight: 700; color: var(--ink); margin-top: 2px; }
    .app-top .a-meta { font-size: 12px; color: var(--muted); margin-top: 4px; }
    .status-pill { font-size: 11px; font-weight: 700; padding: 4px 12px; border-radius: 20px; }

    .pipeline { display: flex; align-items: center; gap: 4px; margin-top: 12px; }
    .pipe-step { flex: 1; text-align: center; }
    .pipe-dot { width: 22px; height: 22px; border-radius: 50%; margin: 0 auto 6px; display: flex; align-items: center; justify-content: center; font-size: 10px; color: #fff; }
    .pipe-dot.done { background: #16a34a; }
    .pipe-dot.current { background: var(--brand); }
    .pipe-dot.upcoming { background: var(--line); color: var(--faint); }
    .pipe-label { font-size: 10px; color: var(--faint); }
    .pipe-line { flex: 0.4; height: 2px; background: var(--line); margin-top: -18px; }

    .rejected-note { background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; border-radius: 8px; padding: 10px 14px; font-size: 12px; margin-top: 12px; }

    .history { margin-top: 14px; }
    .history summary { cursor: pointer; font-size: 12px; font-weight: 700; color: var(--muted); list-style: none; }
    .history summary::-webkit-details-marker { display: none; }
    .history summary:hover { color: var(--brand); }
    .timeline { margin: 12px 0 0; padding: 0 0 0 18px; border-left: 2px solid var(--line); }
    .tl-item { position: relative; padding-bottom: 14px; }
    .tl-item:last-child { padding-bottom: 0; }
    .tl-item::before { content: ''; position: absolute; left: -24px; top: 3px; width: 9px; height: 9px; border-radius: 50%; background: var(--brand); border: 2px solid var(--surface); }
    .tl-stage { font-size: 12.5px; font-weight: 700; color: var(--ink); text-transform: capitalize; }
    .tl-meta { font-size: 11px; color: var(--faint); margin-top: 2px; }
    .tl-note { font-size: 12px; color: var(--body); margin-top: 3px; }
</style>

<p style="font-size:13px;color:var(--muted);margin:0 0 14px 2px;">
    INDBIN underwrites and funds applications &mdash; you earn commission on approved ones. Funding is INDBIN's step, so your pipeline ends at Approved.
</p>

<div class="tabs">
    <?php foreach ($statusTabs as $key => $label): ?>
        <a href="applications.php?status=<?= urlencode($key) ?>" class="tab <?= $key === $activeTab ? 'active' : '' ?>">
            <?= htmlspecialchars($label) ?>
            <span class="tab-count"><?= $statusCounts[$key] ?? 0 ?></span>
        </a>
    <?php endforeach; ?>
</div>

<?php if (empty($applications)): ?>
    <div class="empty-state"><?= $activeTab === 'all' ? 'No applications yet. Convert an accepted quote into an application from the Quotes page. You earn commission once INDBIN funds the application.' : 'No applications with this status.' ?></div>
<?php else: ?>
    <?php foreach ($applications as $app): ?>
        <div class="app-card">
            <div class="app-top">
                <div>
                    <div class="a-num"><?= htmlspecialchars($app['application_number']) ?></div>
                    <div class="a-name"><?= htmlspecialchars($app['product_name']) ?> — ₹<?= number_format((float)$app['amount'], 0) ?></div>
                    <div class="a-meta">For <?= htmlspecialchars($app['customer_name'] ?: 'Unnamed customer') ?> &middot; Submitted <?= htmlspecialchars(date('d M Y', strtotime($app['submitted_at']))) ?></div>
                </div>
                <span class="status-pill" style="background:<?= ($statusColors[$app['status']] ?? '#64748b') ?>22; color:<?= ($statusColors[$app['status']] ?? '#64748b') ?>;"><?= htmlspecialchars($statusLabels[$app['status']] ?? ucfirst(str_replace('_', ' ', $app['status']))) ?></span>
            </div>

            <?php if ($app['status'] === 'rejected'): ?>
                <div class="rejected-note"><strong>Rejected:</strong> <?= htmlspecialchars($app['rejection_reason'] ?: 'Did not meet approval criteria.') ?></div>
            <?php elseif (in_array($app['status'], ['requirement_raised', 'draft', 'withdrawn'], true)): ?>
                <div class="rejected-note" style="background:#fff7ed; color:#c2410c; border-color:#fdba74;">
                    <?php if ($app['status'] === 'requirement_raised'): ?>Awaiting the customer's required information or documents before it moves into review.
                    <?php elseif ($app['status'] === 'draft'): ?>Draft &mdash; not yet submitted for review.
                    <?php else: ?>This application was withdrawn.<?php endif; ?>
                </div>
            <?php else: ?>
                <?php
                $stage = $app['current_stage'] ?: 'submitted';
                // Funding is INDBIN's job; a disbursed app shows its pipeline
                // complete (same as approved + funded).
                if ($stage === 'disbursed') $stage = 'approved';
                $stageKeys = array_keys($stagePipeline);
                $currentIndex = array_search($stage, $stageKeys, true);
                if ($currentIndex === false) $currentIndex = 0;
                ?>
                <div class="pipeline">
                    <?php foreach ($stageKeys as $i => $stageKey): ?>
                        <div class="pipe-step">
                            <div class="pipe-dot <?= $i < $currentIndex ? 'done' : ($i === $currentIndex ? 'current' : 'upcoming') ?>">
                                <?= $i < $currentIndex ? '<i class="fa-solid fa-check"></i>' : $i + 1 ?>
                            </div>
                            <div class="pipe-label"><?= htmlspecialchars($stagePipeline[$stageKey]) ?></div>
                        </div>
                        <?php if ($i < count($stageKeys) - 1): ?><div class="pipe-line"></div><?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php $events = $eventsByApp[(int)$app['id']] ?? []; ?>
            <?php if ($events): ?>
                <details class="history">
                    <summary><i class="fa-regular fa-clock"></i> History (<?= count($events) ?>)</summary>
                    <div class="timeline">
                        <?php foreach ($events as $ev): ?>
                            <div class="tl-item">
                                <div class="tl-stage">
                                    <?= htmlspecialchars(str_replace('_', ' ', $ev['to_stage'])) ?>
                                    <?php if ($ev['from_stage']): ?>
                                        <span style="color:var(--faint);font-weight:600;">(from <?= htmlspecialchars(str_replace('_', ' ', $ev['from_stage'])) ?>)</span>
                                    <?php endif; ?>
                                </div>
                                <div class="tl-meta">
                                    <?= htmlspecialchars(date('d M Y, g:i A', strtotime($ev['created_at']))) ?>
                                    <?= $ev['actor_role'] ? ' · ' . htmlspecialchars($ev['actor_role']) : '' ?>
                                </div>
                                <?php if (!empty($ev['note'])): ?>
                                    <div class="tl-note"><?= htmlspecialchars($ev['note']) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php render_shell_close(); ?>
