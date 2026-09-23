<?php
/**
 * Step 6: Loyalty enrolment.
 *
 * The current step 6 has a "Claim Rewards" button that only toggles a div
 * with JavaScript, and the 100 points are written as a column value on the
 * users row. Here enrolment is a real decision the customer can decline,
 * and points are posted to a ledger so a balance can be reconstructed.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
require_once CUST_ROOT . '/portal/shell.php';

$user = require_customer($pdo);
require_step($pdo, $user, 6);

$stmt = $pdo->prepare("SELECT * FROM loyalty_enrollments WHERE user_id = ?");
$stmt->execute([(int)$user['id']]);
$enrolment = $stmt->fetch() ?: null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $choice = post_str('choice', 10);

    if ($choice === 'skip') {
        advance_step($pdo, (int)$user['id'], 7);
        audit_log($pdo, 'loyalty.declined', 'loyalty_enrollments', (string)$user['id']);
        redirect(step_url(7));
    }

    if ($choice === 'join') {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO loyalty_enrollments (user_id, program_tier, status)
                           VALUES (?, 'Tier-1 Rewards', 'active')
                           ON DUPLICATE KEY UPDATE status = 'active'")
                ->execute([(int)$user['id']]);

            // Welcome bonus posts once. A second visit does not mint more points.
            $already = $pdo->prepare("SELECT COUNT(*) FROM loyalty_ledger
                                       WHERE user_id = ? AND entry_type = 'welcome'");
            $already->execute([(int)$user['id']]);
            if ((int)$already->fetchColumn() === 0) {
                loyalty_post($pdo, (int)$user['id'], 'welcome', 100, 'onboarding');
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('loyalty enrol: ' . $e->getMessage());
            flash('error', 'Enrolment did not save. Try again.');
            redirect(CUST_BASE . '/loyalty/index.php');
        }

        audit_log($pdo, 'loyalty.enrolled', 'loyalty_enrollments', (string)$user['id']);
        advance_step($pdo, (int)$user['id'], 7);
        flash('success', '100 welcome points added.');
        redirect(step_url(7));
    }
}

$balance = loyalty_balance($pdo, (int)$user['id']);

onboarding_shell_open($pdo, $user, 6, 'Loyalty enrolment');
?>
<div class="card">
    <div class="card-icon"><i class="fa-solid fa-gift"></i></div>
    <div class="card-head">
        <h2>Join INDBIN Rewards</h2>
        <p>Earn points on transactions and referrals. Points expire 24 months after they are earned.</p>
    </div>

    <?php if ($enrolment && $enrolment['status'] === 'active'): ?>
        <div class="detail-block" style="text-align:center;">
            <div style="font-size:12px;font-weight:700;color:var(--ok);">Current balance</div>
            <div style="font-size:32px;font-weight:800;color:var(--ok);margin-top:4px;"><?= $balance ?> points</div>
        </div>
        <a class="btn block" href="<?= e(step_url(7)) ?>">Continue</a>
    <?php else: ?>
        <div class="detail-block">
            <div class="detail-row"><span>Joining bonus</span><strong>100 points</strong></div>
            <div class="detail-row"><span>Tier</span><strong>Tier-1 Rewards</strong></div>
            <div class="detail-row"><span>Point validity</span><strong>24 months from earning</strong></div>
        </div>

        <form method="post" action="<?= CUST_BASE ?>/loyalty/index.php">
            <?= csrf_field() ?>
            <button class="btn block" name="choice" value="join">Join and claim 100 points</button>
            <button class="btn block quiet" name="choice" value="skip" style="margin-top:10px;">Not now</button>
        </form>
    <?php endif; ?>
</div>
<?php onboarding_shell_close(); ?>
