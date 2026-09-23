<?php
/**
 * Step 2 status: what the customer sees while eKYC is in review, and the
 * resubmission path when a document is bounced.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
require_once CUST_ROOT . '/portal/shell.php';

$user = require_customer($pdo);

$stmt = $pdo->prepare("SELECT * FROM kyc_details WHERE user_id = ?");
$stmt->execute([(int)$user['id']]);
$kyc = $stmt->fetch();

if (!$kyc) {
    redirect(CUST_BASE . '/ekyc/index.php');
}

$stmt = $pdo->prepare("SELECT doc_code, doc_label, status, rejection_reason, created_at
                         FROM customer_documents
                        WHERE user_id = ? AND stage = 'ekyc' AND is_current = 1
                        ORDER BY id");
$stmt->execute([(int)$user['id']]);
$docs = $stmt->fetchAll();

[$iconClass, $icon, $heading, $blurb] = match ($user['kyc_status']) {
    'approved' => ['is-ok', 'fa-circle-check', 'Identity verified',
                   'Your documents cleared verification.'],
    'rejected' => ['is-warn', 'fa-circle-xmark', 'Verification did not pass',
                   'Review the reason below, then submit again.'],
    'resubmit' => ['is-warn', 'fa-rotate', 'One more document needed',
                   'Replace the document flagged below.'],
    default    => ['is-warn', 'fa-clock', 'Verification in progress',
                   'A reviewer is checking your documents. This usually takes one working day.'],
};

onboarding_shell_open($pdo, $user, 2, 'eKYC status');
?>
<div class="card">
    <div class="card-icon <?= e($iconClass) ?>"><i class="fa-solid <?= e($icon) ?>"></i></div>
    <div class="card-head">
        <h2><?= e($heading) ?></h2>
        <p><?= e($blurb) ?></p>
    </div>

    <?php if (!empty($kyc['rejection_reason'])): ?>
        <div class="notice is-bad"><?= e($kyc['rejection_reason']) ?></div>
    <?php endif; ?>

    <div class="detail-block">
        <div class="detail-row">
            <span>Method</span><strong><?= e(str_replace('_', ' ', $kyc['kyc_mode'])) ?></strong>
        </div>
        <div class="detail-row">
            <span>Document</span>
            <strong><?= e($kyc['doc_type']) ?> &bull;&bull;&bull;&bull;<?= e($kyc['doc_number_last4'] ?? '') ?></strong>
        </div>
        <div class="detail-row">
            <span>Submitted</span><strong><?= e(date('j M Y, H:i', strtotime($kyc['created_at']))) ?></strong>
        </div>
    </div>

    <table class="table">
        <caption class="sr-only">Uploaded documents</caption>
        <thead><tr><th scope="col">Document</th><th scope="col">Status</th></tr></thead>
        <tbody>
        <?php foreach ($docs as $d):
            $pill = match ($d['status']) {
                'verified' => 'is-ok', 'rejected' => 'is-bad', 'expired' => 'is-bad', default => 'is-warn',
            }; ?>
            <tr>
                <td><?= e($d['doc_label']) ?>
                    <?php if (!empty($d['rejection_reason'])): ?>
                        <div style="color:var(--bad);font-size:12px;margin-top:4px;"><?= e($d['rejection_reason']) ?></div>
                    <?php endif; ?>
                </td>
                <td><span class="pill <?= $pill ?>"><?= e(ucfirst($d['status'])) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div style="margin-top:24px;display:flex;gap:10px;">
        <?php if (in_array($user['kyc_status'], ['rejected', 'resubmit'], true)): ?>
            <a class="btn" href="<?= CUST_BASE ?>/ekyc/upload.php">Replace documents</a>
        <?php endif; ?>
        <?php if ((int)$user['onboarding_step'] >= 3): ?>
            <a class="btn secondary" href="<?= e(step_url(3)) ?>">Continue to profile</a>
        <?php endif; ?>
    </div>

    <p class="hint" style="margin-top:16px;">
        You can carry on with the next steps while verification runs. Your account activates
        once both are done.
    </p>
</div>
<?php onboarding_shell_close(); ?>
