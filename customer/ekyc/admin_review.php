<?php
/**
 * eKYC review queue for back-office staff.
 *
 * Approving here is the only path that sets users.kyc_status = 'approved',
 * which in turn is what lets issue_customer_code() mint a customer ID.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../../admin/shell.php'; // was the separate customer/portal/admin_shell.php (its own 'Back office' branding) - consolidated so this renders inside the same admin dashboard shell

$admin = require_admin($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $targetId = post_int('user_id');
    $decision = post_str('decision', 20);
    $reason   = post_str('reason', 255);
    $docId    = post_int('document_id');

    // Per-document verdict.
    if ($docId > 0 && in_array($decision, ['verify_doc', 'reject_doc'], true)) {
        $pdo->prepare("UPDATE customer_documents
                          SET status = ?, reviewer_id = ?, reviewed_at = NOW(), rejection_reason = ?
                        WHERE id = ? AND user_id = ?")
            ->execute([
                $decision === 'verify_doc' ? 'verified' : 'rejected',
                (int)$admin['id'],
                $decision === 'reject_doc' ? ($reason ?: 'Not legible.') : null,
                $docId, $targetId,
            ]);
        audit_log($pdo, 'kyc.document_' . $decision, 'customer_documents', (string)$docId, null,
                  ['reason' => $reason], $targetId);
        flash('success', 'Document updated.');
        redirect(CUST_BASE . '/ekyc/admin_review.php?user_id=' . $targetId);
    }

    // Case verdict.
    if (in_array($decision, ['approve', 'reject', 'resubmit'], true) && $targetId > 0) {
        if ($decision === 'approve') {
            // Do not approve a case that still has a rejected document.
            $chk = $pdo->prepare("SELECT COUNT(*) FROM customer_documents
                                   WHERE user_id = ? AND stage = 'ekyc' AND is_current = 1 AND status = 'rejected'");
            $chk->execute([$targetId]);
            if ((int)$chk->fetchColumn() > 0) {
                flash('error', 'Clear the rejected documents before approving the case.');
                redirect(CUST_BASE . '/ekyc/admin_review.php?user_id=' . $targetId);
            }
        }
        if ($decision !== 'approve' && $reason === '') {
            flash('error', 'A reason is required when you reject or bounce a case.');
            redirect(CUST_BASE . '/ekyc/admin_review.php?user_id=' . $targetId);
        }

        $map = ['approve' => 'approved', 'reject' => 'rejected', 'resubmit' => 'resubmit'];

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE kyc_details
                              SET status = ?, reviewer_id = ?, reviewed_at = NOW(), rejection_reason = ?
                            WHERE user_id = ?")
                ->execute([$map[$decision], (int)$admin['id'], $reason ?: null, $targetId]);

            $pdo->prepare("UPDATE users SET kyc_status = ? WHERE id = ?")
                ->execute([$map[$decision], $targetId]);

            if ($decision === 'approve') {
                $pdo->prepare("UPDATE customer_documents
                                  SET status = 'verified', reviewer_id = ?, reviewed_at = NOW()
                                WHERE user_id = ? AND stage = 'ekyc' AND is_current = 1 AND status = 'pending'")
                    ->execute([(int)$admin['id'], $targetId]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('ekyc admin_review: ' . $e->getMessage());
            flash('error', 'Could not save the decision.');
            redirect(CUST_BASE . '/ekyc/admin_review.php?user_id=' . $targetId);
        }

        audit_log($pdo, 'kyc.' . $decision, 'kyc_details', (string)$targetId, null, ['reason' => $reason], $targetId);

        notify($pdo, $targetId,
            $decision === 'approve' ? 'Identity verified' : 'Action needed on your KYC',
            $decision === 'approve'
                ? 'Your documents cleared verification.'
                : 'Your submission needs attention: ' . $reason);

        if ($decision === 'approve') {
            issue_customer_code($pdo, $targetId);
        }

        flash('success', 'Case marked ' . $map[$decision] . '.');
        redirect(CUST_BASE . '/ekyc/admin_review.php');
    }
}

$focusId = (int)($_GET['user_id'] ?? 0);

$queue = $pdo->query(
    "SELECT k.user_id, k.full_name, k.doc_type, k.doc_number_last4, k.kyc_mode,
            k.status, k.created_at, u.email, u.mobile,
            (SELECT COUNT(*) FROM customer_documents d
              WHERE d.user_id = k.user_id AND d.stage='ekyc' AND d.is_current=1) AS doc_count
       FROM kyc_details k
       JOIN users u ON u.id = k.user_id
      WHERE k.status IN ('pending','resubmit')
      ORDER BY k.created_at ASC
      LIMIT 100"
)->fetchAll();

$focus = null;
$focusDocs = [];
if ($focusId > 0) {
    $s = $pdo->prepare("SELECT k.*, u.email, u.mobile FROM kyc_details k
                          JOIN users u ON u.id = k.user_id WHERE k.user_id = ?");
    $s->execute([$focusId]);
    $focus = $s->fetch() ?: null;

    $s = $pdo->prepare("SELECT * FROM customer_documents
                         WHERE user_id = ? AND stage = 'ekyc' AND is_current = 1 ORDER BY id");
    $s->execute([$focusId]);
    $focusDocs = $s->fetchAll();
}
admin_shell_open($pdo, $admin, 'ekyc', 'eKYC review queue');
?>
<p style="font-size:13px;color:var(--muted);margin-bottom:18px;">
    Open a case, judge each document, then record a verdict on the case itself.
    Approving is what sets the account to verified and issues the customer ID.
</p>

<div class="card wide" style="margin-bottom:24px;">
        <table class="table">
            <thead><tr>
                <th scope="col">Customer</th><th scope="col">Contact</th>
                <th scope="col">Document</th><th scope="col">Mode</th>
                <th scope="col">Files</th><th scope="col">Waiting since</th><th scope="col"></th>
            </tr></thead>
            <tbody>
            <?php if (!$queue): ?>
                <tr><td colspan="7" style="color:var(--muted);">Queue is clear.</td></tr>
            <?php endif; ?>
            <?php foreach ($queue as $q): ?>
                <tr>
                    <td><?= e($q['full_name']) ?><br><span style="color:var(--faint);font-size:12px;">#<?= (int)$q['user_id'] ?></span></td>
                    <td><?= e($q['email']) ?><br><?= e(mask_tail((string)$q['mobile'])) ?></td>
                    <td><?= e($q['doc_type']) ?> &bull;&bull;&bull;&bull;<?= e((string)$q['doc_number_last4']) ?></td>
                    <td><?= e(str_replace('_', ' ', $q['kyc_mode'])) ?></td>
                    <td><?= (int)$q['doc_count'] ?></td>
                    <td><?= e(date('j M, H:i', strtotime($q['created_at']))) ?></td>
                    <td><a class="btn secondary" style="min-height:34px;font-size:13px;"
                           href="?user_id=<?= (int)$q['user_id'] ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($focus): ?>
    <div class="card wide">
        <h2 style="font-size:18px;font-weight:800;color:var(--ink);margin-bottom:4px;">
            <?= e($focus['full_name']) ?>
        </h2>
        <p style="font-size:13px;color:var(--muted);margin-bottom:20px;">
            <?= e($focus['email']) ?> &bull; <?= e(mask_tail((string)$focus['mobile'])) ?>
            &bull; DOB <?= e((string)$focus['dob']) ?>
        </p>

        <table class="table" style="margin-bottom:20px;">
            <thead><tr><th scope="col">Document</th><th scope="col">Uploaded</th><th scope="col">Status</th><th scope="col">Action</th></tr></thead>
            <tbody>
            <?php foreach ($focusDocs as $d): ?>
                <tr>
                    <td>
                        <a href="<?= CUST_BASE ?>/documents/serve.php?id=<?= (int)$d['id'] ?>" target="_blank" rel="noopener">
                            <?= e($d['doc_label']) ?>
                        </a>
                        <div style="color:var(--faint);font-size:11px;">
                            <?= e($d['mime_type']) ?> &bull; <?= number_format($d['size_bytes'] / 1024) ?> KB
                            &bull; v<?= (int)$d['version'] ?>
                        </div>
                    </td>
                    <td><?= e(date('j M, H:i', strtotime($d['created_at']))) ?></td>
                    <td><span class="pill <?= $d['status'] === 'verified' ? 'is-ok' : ($d['status'] === 'rejected' ? 'is-bad' : 'is-warn') ?>">
                        <?= e($d['status']) ?></span></td>
                    <td>
                        <form method="post" style="display:flex;gap:6px;align-items:center;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="user_id" value="<?= (int)$focus['user_id'] ?>">
                            <input type="hidden" name="document_id" value="<?= (int)$d['id'] ?>">
                            <input type="text" name="reason" placeholder="Reason if rejecting"
                                   style="min-height:34px;font-size:12px;max-width:200px;">
                            <button class="btn" name="decision" value="verify_doc" style="min-height:34px;font-size:12px;">Verify</button>
                            <button class="btn secondary" name="decision" value="reject_doc" style="min-height:34px;font-size:12px;">Reject</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="user_id" value="<?= (int)$focus['user_id'] ?>">
            <div class="field">
                <label for="reason">Case note</label>
                <textarea id="reason" name="reason" placeholder="Required when rejecting or bouncing back."></textarea>
            </div>
            <div style="display:flex;gap:10px;">
                <button class="btn" name="decision" value="approve">Approve case</button>
                <button class="btn secondary" name="decision" value="resubmit">Ask for resubmission</button>
                <button class="btn secondary" name="decision" value="reject">Reject case</button>
            </div>
        </form>
    </div>
    <?php endif; ?>
<?php admin_shell_close(); ?>
