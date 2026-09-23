<?php
/**
 * Replace a single eKYC document after a reviewer rejects it.
 * Kept separate from index.php so a resubmission does not require the
 * customer to re-key their identity details.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
require_once CUST_ROOT . '/portal/shell.php';

$user = require_customer($pdo);

$stmt = $pdo->prepare("SELECT status FROM kyc_details WHERE user_id = ?");
$stmt->execute([(int)$user['id']]);
$kycStatus = (string)$stmt->fetchColumn();

if (!in_array($kycStatus, ['rejected', 'resubmit'], true)) {
    redirect(CUST_BASE . '/ekyc/status.php');
}

// Only the documents a reviewer actually rejected.
$stmt = $pdo->prepare("SELECT doc_code, doc_label, rejection_reason
                         FROM customer_documents
                        WHERE user_id = ? AND stage = 'ekyc' AND is_current = 1 AND status = 'rejected'");
$stmt->execute([(int)$user['id']]);
$rejected = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $saved = 0;

    foreach ($rejected as $r) {
        $code = $r['doc_code'];
        if (($_FILES[$code]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $res = store_document($pdo, (int)$user['id'], $code, $_FILES[$code], 'ekyc');
        if ($res['ok']) {
            $saved++;
        } else {
            flash('error', $r['doc_label'] . ': ' . $res['error']);
        }
    }

    if ($saved > 0) {
        $pdo->prepare("UPDATE kyc_details SET status = 'pending', rejection_reason = NULL WHERE user_id = ?")
            ->execute([(int)$user['id']]);
        $pdo->prepare("UPDATE users SET kyc_status = 'pending' WHERE id = ?")
            ->execute([(int)$user['id']]);
        audit_log($pdo, 'kyc.resubmitted', 'kyc_details', (string)$user['id'], null, ['files' => $saved]);
        flash('success', $saved . ' document(s) resubmitted for review.');
        redirect(CUST_BASE . '/ekyc/status.php');
    }
}

onboarding_shell_open($pdo, $user, 2, 'Replace documents');
?>
<div class="card">
    <div class="card-icon is-warn"><i class="fa-solid fa-rotate"></i></div>
    <div class="card-head">
        <h2>Replace the flagged documents</h2>
        <p>Everything else you submitted stays as it is.</p>
    </div>

    <?php if (!$rejected): ?>
        <div class="notice is-info">Nothing is waiting on you right now.</div>
    <?php else: ?>
    <form method="post" enctype="multipart/form-data" action="<?= CUST_BASE ?>/ekyc/upload.php">
        <?= csrf_field() ?>
        <?php foreach ($rejected as $r):
            $spec = CUSTOMER_DOCUMENTS[$r['doc_code']] ?? null;
            if (!$spec) continue; ?>
            <div class="field">
                <label for="r_<?= e($r['doc_code']) ?>"><?= e($r['doc_label']) ?></label>
                <?php if (!empty($r['rejection_reason'])): ?>
                    <p class="hint" style="color:var(--bad);margin:0 0 8px;"><?= e($r['rejection_reason']) ?></p>
                <?php endif; ?>
                <input id="r_<?= e($r['doc_code']) ?>" type="file" name="<?= e($r['doc_code']) ?>"
                       accept="<?= e(implode(',', $spec['mime'])) ?>">
            </div>
        <?php endforeach; ?>
        <button type="submit" class="btn block">Resubmit</button>
    </form>
    <?php endif; ?>
</div>
<?php onboarding_shell_close(); ?>
