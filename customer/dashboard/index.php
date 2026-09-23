<?php
/**
 * Post-onboarding overview.
 *
 * Laid out as: welcome and customer ID, then anything demanding attention,
 * then quick actions, then the headline numbers, then applications in flight.
 *
 * The order is deliberate. An overdue instalment sits above the credit score,
 * because one needs acting on today and the other is a number to glance at.
 *
 * Guard: the dashboard stays shut until eKYC is approved and a customer code
 * exists, which is what makes the ID in the header safe to print.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
require_once CUST_ROOT . '/portal/customer_shell.php';

$user = require_customer($pdo);

if ($user['kyc_status'] !== 'approved' || empty($user['customer_code'])) {
    flash('info', 'Your dashboard opens once identity verification clears.');
    redirect(step_url(effective_step($pdo, $user)));
}

$uid = (int)$user['id'];

// ---------------------------------------------------------------- credit
$stmt = $pdo->prepare("SELECT * FROM credit_evaluations WHERE user_id = ? AND is_current = 1");
$stmt->execute([$uid]);
$eval = $stmt->fetch() ?: null;

// ------------------------------------------------------------ repayments
$stmt = $pdo->prepare(
    "SELECT id, loan_title, amount, due_date, status
       FROM repayments
      WHERE user_id = ? AND status IN ('pending','overdue')
      ORDER BY due_date ASC LIMIT 1"
);
$stmt->execute([$uid]);
$nextDue = $stmt->fetch() ?: null;

$stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(amount),0) FROM repayments
      WHERE user_id = ? AND status IN ('pending','overdue')"
);
$stmt->execute([$uid]);
$outstanding = (float)$stmt->fetchColumn();

// --------------------------------------------------------------- loyalty
$stmt = $pdo->prepare("SELECT status FROM loyalty_enrollments WHERE user_id = ?");
$stmt->execute([$uid]);
$loyaltyStatus = (string)$stmt->fetchColumn();
$points = loyalty_balance($pdo, $uid);

// ---------------------------------------------------------- applications
$stmt = $pdo->prepare(
    "SELECT * FROM customer_applications
      WHERE user_id = ? AND status <> 'withdrawn'
      ORDER BY id DESC LIMIT 4"
);
$stmt->execute([$uid]);
$applications = $stmt->fetchAll();

// -------------------------------------------------------------- products
$stmt = $pdo->prepare(
    "SELECT pa.status, pa.assigned_limit, pc.product_name
       FROM product_activations pa
       JOIN product_catalog pc ON pc.product_code = pa.product_code
      WHERE pa.user_id = ? AND pa.status IN ('active','requested')
      ORDER BY pc.product_name"
);
$stmt->execute([$uid]);
$products = $stmt->fetchAll();

/**
 * Where an application sits on the five-stage track.
 * Returns the stage index reached, and whether it failed there.
 */
function track_position(string $status): array
{
    return match ($status) {
        'draft', 'requirement_raised' => [0, false],
        'under_review'                => [2, false],
        'approved'                    => [3, false],
        'disbursed'                   => [4, false],
        'rejected'                    => [2, true],
        default                       => [0, false],
    };
}

const TRACK_STAGES = [
    ['Submitted',     'fa-file-pen'],
    ['KYC check',     'fa-user-check'],
    ['Credit review', 'fa-chart-line'],
    ['Approved',      'fa-thumbs-up'],
    ['Disbursed',     'fa-money-bill-transfer'],
];

// This page draws its own header, so the shell's generic one is switched off.
portal_shell_open($pdo, $user, 'index', 'Overview', false);
?>

<div class="welcome">
    <div>
        <h1>Welcome, <?= e($user['full_name']) ?></h1>
        <p class="cust-id">Customer ID: <strong><?= e($user['customer_code']) ?></strong></p>
    </div>

    <div class="head-actions">
        <a class="chip" href="<?= CUST_BASE ?>/dashboard/profile.php">
            <i class="fa-solid fa-user-gear"></i> Profile &amp; settings
        </a>
        <span class="chip is-verified">
            <i class="fa-solid fa-circle-check"></i> Verified customer
        </span>
    </div>
</div>

<?php if ($nextDue):
    $late = $nextDue['status'] === 'overdue'; ?>
    <div class="banner-due <?= $late ? 'is-late' : '' ?>">
        <div>
            <div class="label">
                <i class="fa-solid <?= $late ? 'fa-triangle-exclamation' : 'fa-clock' ?>"></i>
                <?= $late ? 'Payment overdue' : 'Upcoming payment' ?>
            </div>
            <div class="amount">
                <?= money((float)$nextDue['amount']) ?> for <?= e($nextDue['loan_title']) ?>
            </div>
            <div class="due-on">
                Due <?= e(date('j M Y', strtotime($nextDue['due_date']))) ?>
            </div>
        </div>
        <a class="btn" href="<?= CUST_BASE ?>/dashboard/repayments.php">
            <i class="fa-solid fa-bolt"></i> Pay now
        </a>
    </div>
<?php endif; ?>

<div class="quick-actions">
    <a href="<?= CUST_BASE ?>/dashboard/repayments.php"><i class="fa-solid fa-credit-card"></i> Pay bill / EMI</a>
    <a href="<?= CUST_BASE ?>/documents/index.php"><i class="fa-solid fa-cloud-arrow-up"></i> Upload identity docs</a>
    <a href="<?= CUST_BASE ?>/dashboard/credit.php"><i class="fa-solid fa-gauge-high"></i> Check credit limit</a>
    <a href="<?= CUST_BASE ?>/dashboard/statements.php"><i class="fa-solid fa-file-arrow-down"></i> Download statement</a>
</div>

<div class="stat-grid">

    <div class="stat-card">
        <h3><i class="fa-solid fa-gauge-high" style="color:var(--brand);"></i> Credit score</h3>
        <?php if ($eval):
            $score = (int)$eval['credit_score'];
            // The model runs 300 to 900, so the marker is placed on that range.
            $pct   = max(0, min(100, ($score - 300) / 6));
            [$bandLabel, $bandColour] = match ($eval['risk_category']) {
                'low'      => ['Strong',   'var(--ok)'],
                'moderate' => ['Fair',     'var(--warn)'],
                'high'     => ['Building', '#ea580c'],
                default    => ['Declined', 'var(--bad)'],
            }; ?>
            <div class="gauge">
                <div class="score"><?= $score ?></div>
                <div class="band" style="color:<?= $bandColour ?>;"><?= $bandLabel ?></div>
                <div class="gauge-track">
                    <span class="gauge-marker" style="left:<?= round($pct, 1) ?>%;"></span>
                </div>
                <p class="hint">Repaying on time is the single biggest factor in raising this.</p>
            </div>
        <?php else: ?>
            <div class="gauge">
                <div class="score" style="color:var(--faint);">&mdash;</div>
                <div class="band" style="color:var(--muted);">Not assessed</div>
                <p class="hint" style="margin-top:12px;">
                    <a href="<?= CUST_BASE ?>/credit/index.php">Run a credit check</a>
                    to unlock pay later and loans.
                </p>
            </div>
        <?php endif; ?>
    </div>

    <div class="stat-card">
        <h3><i class="fa-solid fa-wallet" style="color:var(--ok);"></i> Available credit</h3>
        <p class="sub">Pre-approved</p>
        <div class="figure"><?= money((float)($eval['max_credit_limit'] ?? 0)) ?></div>
        <p class="caption">
            BNPL limit <?= money((float)($eval['bnpl_limit'] ?? 0)) ?>
            <?php if ($outstanding > 0): ?>
                &bull; <?= money($outstanding) ?> outstanding
            <?php endif; ?>
        </p>
    </div>

    <div class="stat-card">
        <h3><i class="fa-solid fa-gift" style="color:#c026d3;"></i> Rewards</h3>
        <p class="sub">Status: <?= $loyaltyStatus === 'active' ? 'Enrolled' : 'Not enrolled' ?></p>
        <div class="figure"><?= number_format($points) ?> pts</div>
        <p class="caption">
            <?php if ($loyaltyStatus === 'active'): ?>
                Redeemable against repayments and offers
            <?php else: ?>
                <a href="<?= CUST_BASE ?>/loyalty/index.php">Join the programme</a> to start earning
            <?php endif; ?>
        </p>
    </div>

</div>

<div class="card wide" style="margin-bottom:20px;">
    <h2 style="font-size:16px;font-weight:800;color:var(--ink);margin-bottom:4px;">
        <i class="fa-solid fa-diagram-project" style="color:var(--brand);margin-right:7px;"></i>
        Application track
    </h2>

    <?php if (!$applications): ?>
        <p style="font-size:13px;color:var(--muted);margin-top:12px;">
            Nothing in flight.
            <a href="<?= CUST_BASE ?>/dashboard/applications.php">Raise a requirement</a>
            to apply for a product.
        </p>
    <?php else: ?>
        <?php foreach ($applications as $a):
            [$reached, $failed] = track_position($a['status']); ?>
            <div class="track" style="border-top:1px solid var(--line);margin-top:16px;">
                <div class="track-head">
                    <strong>
                        <?= e($a['product_name']) ?>
                        <span style="color:var(--faint);font-weight:600;">
                            (#APP-<?= str_pad((string)$a['id'], 4, '0', STR_PAD_LEFT) ?>)
                        </span>
                    </strong>
                    <span class="pill <?= $failed ? 'is-bad' : ($reached >= 3 ? 'is-ok' : 'is-warn') ?>">
                        <?= e(ucwords(str_replace('_', ' ', $a['status']))) ?>
                    </span>
                </div>

                <div class="track-steps">
                    <?php foreach (TRACK_STAGES as $i => $stage):
                        [$label, $icon] = $stage;
                        $state = match (true) {
                            $failed && $i === $reached => 'is-failed',
                            $i <  $reached             => 'is-done',
                            $i === $reached            => 'is-current',
                            default                    => '',
                        }; ?>
                        <div class="track-step <?= $state ?>">
                            <div class="bubble">
                                <i class="fa-solid <?= $failed && $i === $reached ? 'fa-xmark' : $icon ?>"></i>
                            </div>
                            <span><?= e($label) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ((float)$a['amount'] > 0): ?>
                    <p style="font-size:12px;color:var(--muted);margin-top:14px;">
                        <?= money((float)$a['amount']) ?> requested on
                        <?= e(date('j M Y', strtotime($a['created_at']))) ?>
                    </p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="card wide">
    <h2 style="font-size:16px;font-weight:800;color:var(--ink);margin-bottom:12px;">Your products</h2>
    <?php if (!$products): ?>
        <p style="color:var(--muted);font-size:13px;">
            Nothing active yet. <a href="<?= CUST_BASE ?>/dashboard/offers.php">Browse what is available</a>.
        </p>
    <?php else: ?>
        <table class="table">
            <thead><tr><th scope="col">Product</th><th scope="col">Limit</th><th scope="col">Status</th></tr></thead>
            <tbody>
            <?php foreach ($products as $p): ?>
                <tr>
                    <td><?= e($p['product_name']) ?></td>
                    <td><?= (float)$p['assigned_limit'] > 0 ? money((float)$p['assigned_limit']) : '&mdash;' ?></td>
                    <td>
                        <span class="pill <?= $p['status'] === 'active' ? 'is-ok' : 'is-warn' ?>">
                            <?= e($p['status']) ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php portal_shell_close(); ?>
