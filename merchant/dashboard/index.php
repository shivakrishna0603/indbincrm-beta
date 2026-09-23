<?php
/**
 * Merchant dashboard overview.
 *
 * The order is deliberate. Anything that needs acting on today (an overdue
 * instalment, a pending service request) sits above the headline numbers,
 * which sit above the pipeline. A number to glance at never outranks a
 * person waiting.
 *
 * Laid out as: welcome and merchant ID, attention banner, quick actions,
 * headline KPIs, business pipeline + account health, then the activity feed.
 *
 * Guard: the dashboard stays shut until the merchant is activated, which is
 * what makes the merchant ID in the header safe to print.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../portal/shell.php';
require_once __DIR__ . '/../portal/merchant_shell.php';

$user = require_merchant($pdo);
$user_id = (int)$user['id'];
$user['merchant_id'] = $user['party_code'] ?? '';

if ($user['account_status'] !== 'active') {
    header('Location: ../onboarding_complete/index.php');
    exit;
}

if (!function_exists('merchant_fmt')) {
    function merchant_fmt(float $n): string
    {
        return '₹' . number_format($n, 0);
    }
}

// ------------------------------------------------------- business facts
$biz = $pdo->prepare(
    "SELECT years_in_business, monthly_turnover FROM business_verifications
      WHERE user_id = ? ORDER BY id DESC LIMIT 1"
);
$biz->execute([$user_id]);
$bizFacts = $biz->fetch() ?: [];

// -------------------------------------------------------------- services
$svcStmt = $pdo->prepare("SELECT service_key, status FROM merchant_services WHERE user_id = ?");
$svcStmt->execute([$user_id]);
$services = $svcStmt->fetchAll();
$activeServices  = 0;
$pendingServices = 0;
foreach ($services as $s) {
    if ($s['status'] === 'approved') {
        $activeServices++;
    } elseif ($s['status'] === 'pending_approval') {
        $pendingServices++;
    }
}
$serviceNames = [
    'upi' => 'UPI', 'qr_to_cash' => 'QR to Cash', 'aeps' => 'AEPS',
    'money_transfer' => 'Money Transfer', 'loans' => 'Credit & Business Loan',
    'insurance' => 'Insurance', 'offers' => 'Offers', 'loyalty' => 'Loyalty',
];

// ---------------------------------------------------------------- leads
$leadStmt = $pdo->prepare(
    "SELECT l.*, pc.product_name AS product_label
       FROM leads l
       LEFT JOIN product_catalog pc ON pc.product_code = l.product_code
      WHERE l.merchant_id = ?
      ORDER BY l.created_at DESC LIMIT 5"
);
$leadStmt->execute([$user_id]);
$recentLeads = $leadStmt->fetchAll();

$leadTotalStmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE merchant_id = ?");
$leadTotalStmt->execute([$user_id]);
$leadTotal = (int)$leadTotalStmt->fetchColumn();

$leadNewStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM leads WHERE merchant_id = ?
       AND status IN ('new','assigned','contacted','interested','nurturing')"
);
$leadNewStmt->execute([$user_id]);
$activeLeads = (int)$leadNewStmt->fetchColumn();

// ------------------------------------------------------ applications
$appInFlightStmt = $pdo->prepare(
    "SELECT id, application_number, product_name, amount, current_stage, status, submitted_at
       FROM applications
      WHERE merchant_id = ? AND status NOT IN ('withdrawn','rejected','completed')
      ORDER BY id DESC LIMIT 3"
);
$appInFlightStmt->execute([$user_id]);
$appsInFlight = $appInFlightStmt->fetchAll();

$appCountStmt = $pdo->prepare("SELECT COUNT(*) FROM applications WHERE merchant_id = ?");
$appCountStmt->execute([$user_id]);
$appTotal = (int)$appCountStmt->fetchColumn();

$appOpenStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM applications
      WHERE merchant_id = ? AND status IN ('submitted','under_review','approved')"
);
$appOpenStmt->execute([$user_id]);
$appsOpen = (int)$appOpenStmt->fetchColumn();

// ------------------------------------------------------------- commission
$commStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(amount),0) FROM commissions
      WHERE earner_id = ? AND earner_role = 'merchant'
        AND status IN ('accrued','approved','paid','credited')"
);
$commStmt->execute([$user_id]);
$commissionTotal = (float)$commStmt->fetchColumn();

// --------------------------------------------------------------- support
$ticketStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM support_tickets WHERE merchant_id = ? AND status IN ('open','in_progress')"
);
$ticketStmt->execute([$user_id]);
$openTickets = (int)$ticketStmt->fetchColumn();

// ------------------------------------------------------- activity feed
$feed = [];
foreach ($recentLeads as $ld) {
    $feed[] = [
        'ts'   => (string)$ld['created_at'],
        'tone' => ($ld['status'] === 'converted' ? 'ok' : ($ld['status'] === 'lost' ? 'bad' : 'idle')),
        'icon' => 'fa-inbox',
        'text' => 'Lead: ' . ($ld['name'] ?? 'Unnamed customer') . ($ld['product_label'] ? ' · ' . $ld['product_label'] : ''),
        'sub'  => ucwords(str_replace('_', ' ', (string)$ld['status'])),
        'href' => 'lead_detail.php?id=' . (int)$ld['id'],
    ];
}
$quoteStmt = $pdo->prepare(
    "SELECT id, product_name, amount, status, created_at FROM quotes
      WHERE merchant_id = ? ORDER BY created_at DESC LIMIT 3"
);
$quoteStmt->execute([$user_id]);
foreach ($quoteStmt->fetchAll() as $qd) {
    $feed[] = [
        'ts'   => (string)$qd['created_at'],
        'tone' => ($qd['status'] === 'accepted' ? 'ok' : ($qd['status'] === 'sent' ? 'warn' : 'idle')),
        'icon' => 'fa-file-invoice-dollar',
        'text' => 'Quote: ' . ($qd['product_name'] ?? '') . ' · ' . merchant_fmt((float)$qd['amount']),
        'sub'  => ucfirst((string)$qd['status']),
        'href' => 'quotes.php',
    ];
}
foreach ($appsInFlight as $ad) {
    $feed[] = [
        'ts'   => (string)$ad['submitted_at'],
        'tone' => ($ad['status'] === 'approved' ? 'ok' : ($ad['status'] === 'disbursed' ? 'ok' : 'idle')),
        'icon' => 'fa-arrow-right-arrow-left',
        'text' => 'Application: ' . ($ad['product_name'] ?? '') . ' · ' . merchant_fmt((float)$ad['amount']),
        'sub'  => ucwords(str_replace('_', ' ', (string)$ad['status'])),
        'href' => 'applications.php',
    ];
}
$ticketStmt2 = $pdo->prepare(
    "SELECT subject, status, created_at FROM support_tickets
      WHERE merchant_id = ? AND status IN ('open','in_progress')
      ORDER BY created_at DESC LIMIT 2"
);
$ticketStmt2->execute([$user_id]);
foreach ($ticketStmt2->fetchAll() as $td) {
    $feed[] = [
        'ts'   => (string)$td['created_at'],
        'tone' => 'warn',
        'icon' => 'fa-headset',
        'text' => 'Ticket: ' . ($td['subject'] ?? ''),
        'sub'  => ucwords(str_replace('_', ' ', (string)$td['status'])),
        'href' => 'support.php',
    ];
}
usort($feed, fn(array $a, array $b) => strcmp((string)$b['ts'], (string)$a['ts']));
$feed = array_slice($feed, 0, 7);

// ------------------------------------------------------------- pipeline
const MERCHANT_TRACK = [
    ['Submitted',     'fa-file-pen'],
    ['KYC check',     'fa-user-check'],
    ['Credit review', 'fa-chart-line'],
    ['Approved',      'fa-thumbs-up'],
];

function merchant_stage_index(string $stage): int
{
    return match ($stage) {
        'kyc_check'    => 1,
        'credit_review'=> 2,
        'approved'     => 3,
        'disbursed'    => 4, // terminal for already-funded apps; shown as fully complete
        default        => 0,
    };
}

render_merchant_shell_open($pdo, $user_id, 'dashboard', 'Dashboard', false);
?>

<style>
    .home-cols {
        display: grid;
        grid-template-columns: minmax(0, 1.65fr) minmax(300px, 1fr);
        gap: 20px;
        margin-bottom: 22px;
    }
    @media (max-width: 980px) { .home-cols { grid-template-columns: 1fr; } }

    /* credit/risk dial */
    .risk-dial {
        display: flex; align-items: center; gap: 20px;
        background: var(--canvas); border-radius: 14px; padding: 18px;
        margin-bottom: 16px;
    }
    .risk-ring {
        width: 96px; height: 96px; flex-shrink: 0;
        border-radius: 50%;
        background:
            radial-gradient(circle at center, var(--surface) 58%, transparent 60%),
            conic-gradient(var(--brand) calc(var(--pct) * 1%), #e9edf3 0);
        display: flex; align-items: center; justify-content: center;
        margin: 0 auto;
    }
    .risk-ring .risk-score { font-size: 26px; font-weight: 800; color: var(--ink); }
    .risk-ring .risk-max  { display: block; font-size: 10px; color: var(--faint); text-align: center; }
    .risk-meta { flex: 1; min-width: 0; }
    .risk-meta .band { display: inline-flex; align-items: center; gap: 7px; font-size: 13px; font-weight: 800; }
    .risk-meta .detail { font-size: 12.5px; color: var(--muted); line-height: 1.55; margin-top: 6px; }

    /* services on the health card */
    .svc-chips { display: flex; flex-wrap: wrap; gap: 8px; }
    .svc-chip {
        display: inline-flex; align-items: center; gap: 7px;
        padding: 6px 12px; border-radius: 999px;
        font-size: 12px; font-weight: 700;
        background: var(--brand-soft); color: var(--brand-deep);
    }

    /* activity feed */
    .act { display: flex; align-items: flex-start; gap: 13px; padding: 12px 0; border-bottom: 1px solid var(--line); }
    .act:last-child { border-bottom: none; }
    .act-dot {
        width: 34px; height: 34px; border-radius: 50%; flex-shrink: 0;
        display: flex; align-items: center; justify-content: center; font-size: 13px;
    }
    .act-dot.is-ok   { background: var(--ok-soft);   color: var(--ok); }
    .act-dot.is-warn { background: var(--warn-soft); color: var(--warn); }
    .act-dot.is-bad  { background: var(--bad-soft);  color: var(--bad); }
    .act-dot.is-idle { background: #eef1f5;          color: var(--faint); }
    .act-body { flex: 1; min-width: 0; }
    .act-text { font-size: 13.5px; font-weight: 700; color: var(--ink); text-decoration: none; }
    .act-text:hover { color: var(--brand); }
    .act-sub { font-size: 12px; color: var(--faint); margin-top: 2px; }
    .act-time { font-size: 11.5px; color: var(--faint); white-space: nowrap; padding-top: 2px; }

    /* empty hint */
    .quiet-hint { font-size: 13px; color: var(--muted); line-height: 1.6; }

    /* how-you-earn strip */
    .earn-strip {
        display: flex; align-items: center; gap: 18px; flex-wrap: wrap;
        background: var(--brand-soft); border: 1px solid var(--brand-soft);
        border-radius: 14px; padding: 16px 22px; margin-bottom: 22px;
    }
    .earn-strip .earn-title { font-size: 13px; font-weight: 800; color: var(--brand-deep); }
    .earn-strip .earn-steps { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; font-size: 13px; color: var(--ink); font-weight: 600; }
    .earn-strip .earn-step { display: inline-flex; align-items: center; gap: 7px; }
    .earn-strip .earn-num {
        width: 22px; height: 22px; border-radius: 50%; flex-shrink: 0;
        background: var(--brand); color: #fff; font-size: 12px; font-weight: 800;
        display: inline-flex; align-items: center; justify-content: center;
    }
    .earn-strip .earn-arrow { color: var(--brand); font-size: 12px; }
</style>

<div class="welcome">
    <div>
        <h1>Welcome back, <?= e($user['business_name'] ?: 'Merchant') ?></h1>
        <p class="cust-id">Merchant ID: <strong><?= e($user['merchant_id'] ?: '—') ?></strong>
            &middot; <?= e(ucfirst($user['business_type'] ?: 'Business')) ?>
            <?= (int)($bizFacts['years_in_business'] ?? 0) > 0
                ? '&middot; ' . (int)$bizFacts['years_in_business'] . ' yrs in business' : '' ?>
        </p>
    </div>

    <div class="head-actions">
        <a class="chip" href="analytics.php">
            <i class="fa-solid fa-chart-line"></i> Reports
        </a>
        <a class="chip" href="services.php">
            <i class="fa-solid fa-boxes-stacked"></i> Manage services
        </a>
        <span class="chip is-verified">
            <i class="fa-solid fa-circle-check"></i> Verified merchant
        </span>
    </div>
</div>

<?php if ($pendingServices > 0): ?>
    <div class="banner-note">
        <div>
            <div class="label">
                <i class="fa-solid fa-clock"></i>
                Service approval pending
            </div>
            <div class="amount"><?= $pendingServices ?> service request<?= $pendingServices > 1 ? 's' : '' ?> waiting on admin review</div>
            <div class="due-on">You can keep selling enabled services in the meantime.</div>
        </div>
        <a class="btn" href="services.php">
            <i class="fa-solid fa-boxes-stacked"></i> View services
        </a>
    </div>
<?php endif; ?>

<div class="quick-actions">
    <a href="leads.php"><i class="fa-solid fa-inbox"></i> Leads &amp; requests</a>
    <a href="quotes.php"><i class="fa-solid fa-file-invoice-dollar"></i> Send a quote</a>
    <a href="services.php"><i class="fa-solid fa-boxes-stacked"></i> Add a service</a>
    <a href="self_service.php"><i class="fa-solid fa-hand-holding-dollar"></i> Apply for credit</a>
    <a href="analytics.php"><i class="fa-solid fa-chart-line"></i> Reports</a>
</div>

<div class="stat-grid" style="grid-template-columns:repeat(auto-fit,minmax(240px,1fr));">

    <div class="stat-card">
        <h3><i class="fa-solid fa-vault" style="color:var(--brand);"></i> Available credit</h3>
        <p class="sub">Limit on your merchant account</p>
        <div class="figure"><?= merchant_fmt((float)$user['credit_limit']) ?></div>
        <p class="caption">
            Risk category: <strong style="color:var(--ink);"><?= e(ucfirst($user['risk_category'] ?: '—')) ?></strong>
            <br>Risk score <?= (int)($user['risk_score'] ?? 0) ?>/100
        </p>
    </div>

    <div class="stat-card">
        <h3><i class="fa-solid fa-sack-dollar" style="color:var(--ok);"></i> Commission earned</h3>
        <p class="sub">From applications funded for your customers</p>
        <div class="figure"><?= merchant_fmt($commissionTotal) ?></div>
        <p class="caption">Earned when INDBIN disburses an application you referred</p>
    </div>

    <div class="stat-card">
        <h3><i class="fa-solid fa-inbox" style="color:#2563eb;"></i> Active leads</h3>
        <p class="sub">Open customer requests to work</p>
        <div class="figure"><?= $activeLeads ?></div>
        <p class="caption"><?= $leadTotal ?> lead<?= $leadTotal !== 1 ? 's' : '' ?> received all time
            &middot; <a href="leads.php" style="color:var(--brand);font-weight:700;">open leads</a>
        </p>
    </div>

    <div class="stat-card">
        <h3><i class="fa-solid fa-arrow-right-arrow-left" style="color:#2563eb;"></i> Applications in review</h3>
        <p class="sub">Submitted and heading to INDBIN approval</p>
        <div class="figure"><?= $appsOpen ?></div>
        <p class="caption">
            <a href="applications.php" style="color:var(--brand);font-weight:700;">View pipeline</a>
            &middot; <?= $appTotal ?> all time
        </p>
    </div>

</div>

<div class="home-cols">

    <div class="panel">
        <div class="panel-head">
            <div>
                <h2><i class="fa-solid fa-diagram-project" style="color:var(--brand);margin-right:7px;"></i> Applications in flight</h2>
                <p class="panel-sub"><?= $appsOpen ?> open &middot; <?= $appTotal ?> in total</p>
            </div>
            <a href="applications.php">View all <i class="fa-solid fa-arrow-right" style="font-size:11px;"></i></a>
        </div>

        <p class="panel-sub" style="margin:2px 0 10px;">
            INDBIN underwrites and funds approved applications &mdash; you earn commission on every funded one.
        </p>

        <?php if (!$appsInFlight): ?>
            <p class="quiet-hint">
                Nothing is moving through the pipeline right now.
                <a href="quotes.php" style="color:var(--brand);font-weight:700;">Convert an accepted quote</a>
                to start an application, or pick up a lead from
                <a href="leads.php" style="color:var(--brand);font-weight:700;">Leads &amp; requests</a>.
                You earn commission when INDBIN funds the application.
            </p>
        <?php else: ?>
            <?php foreach ($appsInFlight as $a):
                $stage = (string)$a['current_stage'];
                $reached = merchant_stage_index($stage); ?>
                <div class="track" style="border-top:1px solid var(--line);margin-top:16px;">
                    <div class="track-head">
                        <strong>
                            <?= e($a['product_name']) ?>
                            <span style="color:var(--faint);font-weight:600;">
                                (#<?= e($a['application_number']) ?>)
                            </span>
                        </strong>
                        <span class="pill <?= $reached >= 3 ? 'is-ok' : 'is-warn' ?>">
                            <?= e(ucwords(str_replace('_', ' ', (string)$a['status']))) ?>
                        </span>
                    </div>

                    <div class="track-steps">
                        <?php foreach (MERCHANT_TRACK as $i => [$label, $icon]):
                            $state = match (true) {
                                $i <  $reached => 'is-done',
                                $i === $reached => 'is-current',
                                default         => '',
                            }; ?>
                            <div class="track-step <?= $state ?>">
                                <div class="bubble">
                                    <i class="fa-solid <?= $state === 'is-done' ? 'fa-check' : $icon ?>"></i>
                                </div>
                                <span><?= e($label) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ((float)$a['amount'] > 0): ?>
                        <p style="font-size:12px;color:var(--muted);margin-top:14px;">
                            <?= merchant_fmt((float)$a['amount']) ?> submitted on
                            <?= e(date('j M Y', strtotime((string)$a['submitted_at']))) ?>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="panel">
        <h2 style="margin-bottom:14px;"><i class="fa-solid fa-shield-heart" style="color:var(--brand);margin-right:7px;"></i> Account health</h2>

        <?php $score = (int)($user['risk_score'] ?? 0);
        $pct = max(0, min(100, $score)); ?>
        <div class="risk-dial">
            <div class="risk-ring" style="--pct:<?= $pct ?>;">
                <div style="text-align:center;">
                    <span class="risk-score"><?= $score ?: '—' ?></span>
                    <span class="risk-max">of 100</span>
                </div>
            </div>
            <div class="risk-meta">
                <div class="band">
                    <i class="fa-solid <?= $pct >= 80 ? 'fa-circle-check' : ($pct >= 50 ? 'fa-circle-half-stroke' : 'fa-circle-exclamation') ?>"
                       style="color:<?= $pct >= 80 ? 'var(--ok)' : ($pct >= 50 ? 'var(--warn)' : 'var(--bad)') ?>;"></i>
                    <?= $score ? e(ucfirst($user['risk_category'] ?: '—')) . ' risk' : 'Not yet scored' ?>
                </div>
                <p class="detail">
                    <?php if ($score): ?>
                        A <?= $pct >= 80 ? 'low' : ($pct >= 50 ? 'moderate' : 'higher') ?> risk profile
                        sets your credit limit. Running the business cleanly and repaying on time keeps it healthy.
                    <?php else: ?>
                        Your credit profile is being assessed. Your limit appears here once the review completes.
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <div class="detail-block" style="margin-bottom:16px;">
            <?php if ((int)($bizFacts['years_in_business'] ?? 0) > 0): ?>
                <div class="detail-row"><span>Years in business</span><strong><?= (int)$bizFacts['years_in_business'] ?></strong></div>
            <?php endif; ?>
            <?php if ((float)($bizFacts['monthly_turnover'] ?? 0) > 0): ?>
                <div class="detail-row"><span>Monthly turnover</span><strong><?= merchant_fmt((float)$bizFacts['monthly_turnover']) ?></strong></div>
            <?php endif; ?>
            <div class="detail-row"><span>Business type</span><strong><?= e(ucfirst($user['business_type'] ?: '—')) ?></strong></div>
            <div class="detail-row"><span>Est. earnings from referrals</span><strong><?= merchant_fmt($commissionTotal) ?></strong></div>
        </div>

        <h3 style="font-size:13px;font-weight:800;color:var(--ink);margin-bottom:10px;">Enabled services</h3>
        <?php if ($activeServices === 0): ?>
            <p class="quiet-hint">
                No services enabled yet.
                <a href="services.php" style="color:var(--brand);font-weight:700;">Add UPI, AEPS and more</a>.
            </p>
        <?php else: ?>
            <div class="svc-chips">
                <?php foreach ($services as $s):
                    if ($s['status'] !== 'approved') { continue; } ?>
                    <span class="svc-chip">
                        <i class="fa-solid fa-circle-check" style="font-size:11px;color:var(--ok);"></i>
                        <?= e($serviceNames[$s['service_key']] ?? ucfirst($s['service_key'])) ?>
                    </span>
                <?php endforeach; ?>
            </div>
            <?php if ($pendingServices > 0): ?>
                <p style="font-size:12px;color:var(--muted);margin-top:10px;">
                    <i class="fa-solid fa-clock" style="color:var(--warn);"></i>
                    <?= $pendingServices ?> more request<?= $pendingServices > 1 ? 's' : '' ?> awaiting approval.
                </p>
            <?php endif; ?>
        <?php endif; ?>
    </div>

</div>

<div class="earn-strip">
    <span class="earn-title"><i class="fa-solid fa-sack-dollar" style="margin-right:6px;"></i>How you earn</span>
    <span class="earn-steps">
        <span class="earn-step"><span class="earn-num">1</span> Refer a customer</span>
        <i class="fa-solid fa-arrow-right earn-arrow" aria-hidden="true"></i>
        <span class="earn-step"><span class="earn-num">2</span> Application approved &amp; funded</span>
        <i class="fa-solid fa-arrow-right earn-arrow" aria-hidden="true"></i>
        <span class="earn-step"><span class="earn-num">3</span> Commission on funding</span>
    </span>
    <span style="font-size:12px;color:var(--brand-deep);margin-left:auto;">INDBIN lends &middot; you refer &middot; customers pay back INDBIN</span>
</div>

<div class="panel">
    <div class="panel-head">
        <div>
            <h2><i class="fa-solid fa-bolt" style="color:var(--brand);margin-right:7px;"></i> Recent activity</h2>
            <p class="panel-sub">Leads, quotes, applications and tickets, newest first</p>
        </div>
    </div>

    <?php if (!$feed): ?>
        <p class="quiet-hint">
            No activity yet. Everything that happens across your merchant account will land here.
        </p>
    <?php else: ?>
        <?php foreach ($feed as $item): ?>
            <div class="act">
                <div class="act-dot is-<?= $item['tone'] ?>">
                    <i class="fa-solid <?= $item['icon'] ?>"></i>
                </div>
                <div class="act-body">
                    <a class="act-text" href="<?= e($item['href']) ?>"><?= e($item['text']) ?></a>
                    <div class="act-sub"><?= e($item['sub']) ?></div>
                </div>
                <span class="act-time"><?= e(date('j M', strtotime((string)$item['ts']))) ?></span>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php render_shell_close(); ?>