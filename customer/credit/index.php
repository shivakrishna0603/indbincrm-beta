<?php
/**
 * Step 4: Credit evaluation, customer-facing page.
 * Scoring runs on POST only, never on page load.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
require_once CUST_ROOT . '/portal/shell.php';
require_once CUST_ROOT . '/credit/scoring.php';

$user = require_customer($pdo);
require_step($pdo, $user, 4);

$stmt = $pdo->prepare("SELECT * FROM credit_evaluations WHERE user_id = ? AND is_current = 1");
$stmt->execute([(int)$user['id']]);
$eval = $stmt->fetch() ?: null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = post_str('action', 20);

    if ($action === 'run_check') {
        if (!isset($_POST['bureau_consent'])) {
            flash('error', 'We cannot run a credit check without your consent.');
            redirect(CUST_BASE . '/credit/index.php');
        }

        $consentId = record_consent($pdo, (int)$user['id'], 'bureau_pull', true, '1.0');

        // Income proof if the customer is asking for more than the auto-approval ceiling.
        if (($_FILES['INCOME_PROOF']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $r = store_document($pdo, (int)$user['id'], 'INCOME_PROOF', $_FILES['INCOME_PROOF'], 'credit');
            if (!$r['ok']) flash('error', $r['error']);
        }

        try {
            $eval = run_credit_evaluation($pdo, (int)$user['id'], true);
            audit_log($pdo, 'credit.consent', 'consents', (string)$consentId);
            flash('success', 'Credit check complete.');
        } catch (Throwable $e) {
            flash('error', 'The credit check could not run. Try again shortly.');
        }
        redirect(CUST_BASE . '/credit/index.php');
    }

    if ($action === 'accept' && $eval) {
        advance_step($pdo, (int)$user['id'], 5);
        redirect(step_url(5));
    }

    // Declining is a real answer, not an absence of one. Recorded so the
    // stepper can say "Skipped" rather than pretending the step never applied.
    if ($action === 'skip') {
        audit_log($pdo, 'credit.declined', 'credit_evaluations', (string)$user['id']);
        advance_step($pdo, (int)$user['id'], 5);
        flash('info', 'Skipped. You can run a check later from your dashboard.');
        redirect(step_url(5));
    }
}

onboarding_shell_open($pdo, $user, 4, 'Credit evaluation');
?>
<div class="card">
    <div class="card-icon"><i class="fa-solid fa-chart-line"></i></div>

    <?php if (!$eval): ?>
        <div class="card-head">
            <h2>Check your eligibility</h2>
            <p>Needed only for Buy Now Pay Later, Pay Later and loans. Rewards,
               payments and insurance do not require it.</p>
        </div>

        <?php if (has_credit_products($pdo, (int)$user['id'])): ?>
            <div class="notice is-info">
                You picked a credit-linked product, so this check has to run before
                it can be switched on.
            </div>
        <?php endif; ?>

        <p style="font-size:13px;color:var(--muted);margin-bottom:20px;">
            We look at your verified documents, your declared income and your
            repayment record. This is a soft enquiry and does not affect your
            bureau score.
        </p>

        <form method="post" enctype="multipart/form-data" action="<?= CUST_BASE ?>/credit/index.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="run_check">

            <div class="field">
                <label for="INCOME_PROOF">Income proof (needed above <?= money(CREDIT_AUTO_APPROVE_CEILING) ?>)</label>
                <input id="INCOME_PROOF" type="file" name="INCOME_PROOF" accept="application/pdf,image/jpeg,image/png">
                <p class="hint">Three salary slips, Form 16, or your last ITR.</p>
            </div>

            <div class="field">
                <label style="display:flex;gap:10px;align-items:flex-start;font-weight:600;">
                    <input type="checkbox" name="bureau_consent" value="1" required
                           style="width:auto;min-height:auto;margin-top:3px;">
                    <span>I authorise INDBIN to obtain my credit information from a licensed
                          credit information company for the purpose of this assessment.</span>
                </label>
            </div>

            <button type="submit" class="btn block">Run the check</button>
        </form>

        <form method="post" action="<?= CUST_BASE ?>/credit/index.php" style="margin-top:10px;">
            <?= csrf_field() ?>
            <button type="submit" class="btn block quiet" name="action" value="skip">
                Not now, I only want rewards and payments
            </button>
        </form>

    <?php else:
        $reasons = json_decode((string)$eval['reason_codes'], true) ?: [];
        $riskPill = match ($eval['risk_category']) {
            'low' => 'is-ok', 'moderate' => 'is-warn', default => 'is-bad',
        }; ?>

        <div class="card-head">
            <h2><?= $eval['decision'] === 'declined' ? 'Not eligible right now' : 'Your assessment' ?></h2>
            <p>Valid until <?= e(date('j M Y', strtotime((string)$eval['valid_until']))) ?>.</p>
        </div>

        <?php if ($eval['decision'] === 'manual_review'): ?>
            <div class="notice is-warn">
                A reviewer is checking this before the limit is released. You can continue
                to the next step meanwhile.
            </div>
        <?php endif; ?>

        <div class="detail-block">
            <div class="detail-row"><span>Score</span><strong><?= (int)$eval['credit_score'] ?></strong></div>
            <div class="detail-row">
                <span>Risk band</span>
                <strong><span class="pill <?= $riskPill ?>"><?= e(ucfirst($eval['risk_category'])) ?></span></strong>
            </div>
            <div class="detail-row"><span>Credit limit</span><strong><?= money((float)$eval['max_credit_limit']) ?></strong></div>
            <div class="detail-row"><span>BNPL limit</span><strong><?= money((float)$eval['bnpl_limit']) ?></strong></div>
        </div>

        <?php if ($reasons): ?>
            <h3 style="font-size:14px;font-weight:700;color:var(--ink);margin-bottom:10px;">What moved your score</h3>
            <table class="table" style="margin-bottom:20px;">
                <tbody>
                <?php foreach ($reasons as $r): ?>
                    <tr>
                        <td><?= e($r['note']) ?></td>
                        <td style="text-align:right;color:<?= $r['effect'] >= 0 ? 'var(--ok)' : 'var(--bad)' ?>;font-weight:700;">
                            <?= $r['effect'] >= 0 ? '+' : '' ?><?= (int)$r['effect'] ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <form method="post" action="<?= CUST_BASE ?>/credit/index.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="accept">
            <button type="submit" class="btn block">Continue to products</button>
        </form>
    <?php endif; ?>
</div>
<?php onboarding_shell_close(); ?>
