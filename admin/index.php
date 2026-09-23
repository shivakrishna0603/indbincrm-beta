<?php
/**
 * INDBIN admin dashboard.
 *
 * Counts, onboarding entry points, recent activity, a six-month trend and a
 * status split. Every figure is a real query against the unified schema; none
 * of it is placeholder text.
 */

declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/shell.php';

$admin = require_admin($pdo);

// ------------------------------------------------------------ headline
$counts = $pdo->query(
    "SELECT
        (SELECT COUNT(*) FROM users WHERE role='customer') AS customers,
        (SELECT COUNT(*) FROM users WHERE role='agent')    AS agents,
        (SELECT COUNT(*) FROM users WHERE role='merchant') AS merchants,
        (SELECT COUNT(*) FROM applications)                AS applications,
        (SELECT COUNT(*) FROM users
          WHERE role<>'admin' AND account_status IN ('registered','onboarding')) AS pending"
)->fetch();

// Month-on-month movement, computed rather than hardcoded.
$growth = $pdo->query(
    "SELECT role,
            SUM(created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) AS this_month,
            SUM(created_at >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
                AND created_at < DATE_SUB(CURDATE(), INTERVAL 30 DAY)) AS last_month
       FROM users WHERE role <> 'admin' GROUP BY role"
)->fetchAll();

$delta = [];
foreach ($growth as $g) {
    $prev = (int)$g['last_month'];
    $now  = (int)$g['this_month'];
    // No baseline means no percentage. "+100%" off a base of zero is noise.
    $delta[$g['role']] = $prev > 0 ? round((($now - $prev) / $prev) * 100, 1) : null;
}

// ------------------------------------------------------- recent activity
$recent = $pdo->query(
    "SELECT id, role, full_name, party_code, account_status, created_at
       FROM users WHERE role <> 'admin'
      ORDER BY created_at DESC LIMIT 6"
)->fetchAll();

// --------------------------------------------------------- six-month trend
$trend = $pdo->query(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym,
            SUM(role='customer') AS customers,
            SUM(role='agent')    AS agents,
            SUM(role='merchant') AS merchants
       FROM users
      WHERE role <> 'admin' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
      GROUP BY ym ORDER BY ym"
)->fetchAll();

$totalParties = (int)$counts['customers'] + (int)$counts['agents'] + (int)$counts['merchants'];
$maxTrend = 1;
foreach ($trend as $t) {
    $maxTrend = max($maxTrend, (int)$t['customers'], (int)$t['agents'], (int)$t['merchants']);
}

admin_shell_open($pdo, $admin, 'dashboard', 'Dashboard');
?>

<h1 class="admin-h1">Welcome back, <?= e(explode(' ', (string)$admin['full_name'])[1] ?? $admin['full_name']) ?> <span aria-hidden="true">&#128075;</span></h1>
<p class="admin-sub">Here's what's happening with your business today.</p>

<div class="kpi-grid">
    <?php
    $kpis = [
        ['Total customers',   (int)$counts['customers'],    'fa-users',      '#2563eb', '#dbeafe', $delta['customer'] ?? null],
        ['Total agents',      (int)$counts['agents'],       'fa-user-tie',   '#ea580c', '#ffedd5', $delta['agent']    ?? null],
        ['Total merchants',   (int)$counts['merchants'],    'fa-store',      '#16a34a', '#dcfce7', $delta['merchant'] ?? null],
        ['Total applications',(int)$counts['applications'], 'fa-file-lines', '#7c3aed', '#ede9fe', null],
    ];
    foreach ($kpis as [$label, $value, $icon, $colour, $soft, $pct]): ?>
        <div class="kpi">
            <div>
                <span class="kpi-label"><?= e($label) ?></span>
                <strong class="kpi-value"><?= number_format($value) ?></strong>
                <?php if ($pct !== null): ?>
                    <span class="kpi-delta <?= $pct >= 0 ? 'up' : 'down' ?>">
                        <i class="fa-solid fa-arrow-<?= $pct >= 0 ? 'up' : 'down' ?>"></i>
                        <?= abs($pct) ?>% vs last month
                    </span>
                <?php else: ?>
                    <span class="kpi-delta muted">No prior month to compare</span>
                <?php endif; ?>
            </div>
            <span class="kpi-icon" style="background:<?= $soft ?>;color:<?= $colour ?>;">
                <i class="fa-solid <?= e($icon) ?>"></i>
            </span>
        </div>
    <?php endforeach; ?>
</div>

<div class="admin-cols">
    <section class="panel">
        <h2>Onboard new</h2>
        <p class="panel-sub">Choose the type of user you want to onboard.</p>

        <div class="onboard-grid">
            <?php
            $paths = [
                ['Onboard customer', 'Register new customers and enable them to access our services.',
                 'fa-users', '#2563eb', '#eff6ff', '/register.php?role=customer'],
                ['Onboard agent', 'Register new agents (DSAs) and grow your distribution network.',
                 'fa-user-tie', '#ea580c', '#fff7ed', '/register.php?role=agent'],
                ['Onboard merchant', 'Onboard merchants and enable them to offer credit to their customers.',
                 'fa-store', '#16a34a', '#f0fdf4', '/register.php?role=merchant'],
            ];
            foreach ($paths as [$title, $blurb, $icon, $colour, $soft, $href]): ?>
                <div class="onboard-card" style="background:<?= $soft ?>;">
                    <span class="onboard-icon" style="background:#fff;color:<?= $colour ?>;">
                        <i class="fa-solid <?= e($icon) ?>"></i>
                    </span>
                    <h3><?= e($title) ?></h3>
                    <p><?= e($blurb) ?></p>
                    <a class="btn" style="background:<?= $colour ?>;" href="<?= BASE_URL . e($href) ?>">
                        <i class="fa-solid fa-plus"></i> <?= e($title) ?>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <h2>Recent onboarding activity</h2>
            <a href="<?= BASE_URL ?>/admin/onboarding.php">View all</a>
        </div>

        <?php if (!$recent): ?>
            <p class="panel-sub">Nobody has registered yet.</p>
        <?php endif; ?>

        <?php foreach ($recent as $r):
            [$dot, $tint] = match ($r['role']) {
                'agent'    => ['#ea580c', '#ffedd5'],
                'merchant' => ['#16a34a', '#dcfce7'],
                default    => ['#2563eb', '#dbeafe'],
            };
            $mins = max(0, (int)((time() - strtotime($r['created_at'])) / 60)); ?>
            <div class="activity">
                <span class="activity-dot" style="background:<?= $tint ?>;color:<?= $dot ?>;">
                    <?= strtoupper(substr($r['role'], 0, 1)) ?>
                </span>
                <div class="activity-body">
                    <span class="activity-role" style="color:<?= $dot ?>;"><?= e(ucfirst($r['role'])) ?></span>
                    <div>
                        <strong><?= e($r['full_name']) ?></strong>
                        <span class="activity-state">
                            &bull; <?= $r['account_status'] === 'active' ? 'Onboarded' : 'In progress' ?>
                        </span>
                    </div>
                </div>
                <span class="activity-time">
                    <?= $mins < 60 ? $mins . ' mins ago'
                        : ($mins < 1440 ? intdiv($mins, 60) . ' hours ago'
                        : intdiv($mins, 1440) . ' days ago') ?>
                </span>
            </div>
        <?php endforeach; ?>
    </section>
</div>

<div class="admin-cols-3">
    <section class="panel">
        <h2>Onboarding overview</h2>
        <?php if (!$trend): ?>
            <p class="panel-sub">Not enough history yet. This fills in as accounts are created.</p>
        <?php else: ?>
            <div class="legend">
                <span><i style="background:#2563eb"></i> Customers</span>
                <span><i style="background:#ea580c"></i> Agents</span>
                <span><i style="background:#16a34a"></i> Merchants</span>
            </div>
            <table class="mini-chart">
                <?php foreach ($trend as $t): ?>
                    <tr>
                        <th scope="row"><?= e(date('M', strtotime($t['ym'] . '-01'))) ?></th>
                        <td>
                            <?php foreach ([['customers','#2563eb'],['agents','#ea580c'],['merchants','#16a34a']] as [$k,$c]):
                                $w = round((int)$t[$k] / $maxTrend * 100, 1); ?>
                                <span class="bar" style="width:<?= max($w, 1.5) ?>%;background:<?= $c ?>;"
                                      title="<?= (int)$t[$k] ?> <?= $k ?>"></span>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Onboarding status</h2>
        <div class="split-total"><strong><?= number_format($totalParties) ?></strong><span>Total</span></div>
        <?php
        $split = [
            ['Customers', (int)$counts['customers'], '#2563eb'],
            ['Agents',    (int)$counts['agents'],    '#ea580c'],
            ['Merchants', (int)$counts['merchants'], '#16a34a'],
            ['Pending',   (int)$counts['pending'],   '#94a3b8'],
        ];
        foreach ($split as [$label, $n, $colour]):
            $pc = $totalParties > 0 ? round($n / $totalParties * 100, 1) : 0; ?>
            <div class="split-row">
                <span><i style="background:<?= $colour ?>"></i><?= e($label) ?></span>
                <strong><?= number_format($n) ?> <small>(<?= $pc ?>%)</small></strong>
            </div>
            <div class="split-track"><span style="width:<?= $pc ?>%;background:<?= $colour ?>;"></span></div>
        <?php endforeach; ?>
    </section>

    <section class="panel">
        <h2>Quick links</h2>
        <?php
        $links = [
            ['Application list',  'fa-file-lines',    '/admin/applications.php'],
            ['Pending approvals', 'fa-circle-check',  '/admin/approvals.php'],
            ['KYC verification',  'fa-shield-halved', '/admin/approvals.php?queue=kyc'],
            ['Reports & analytics','fa-chart-column', '/admin/reports.php'],
        ];
        foreach ($links as [$label, $icon, $href]): ?>
            <a class="quick-link" href="<?= BASE_URL . e($href) ?>">
                <i class="fa-solid <?= e($icon) ?>"></i>
                <span><?= e($label) ?></span>
                <i class="fa-solid fa-arrow-right"></i>
            </a>
        <?php endforeach; ?>
    </section>
</div>

<?php admin_shell_close(); ?>
