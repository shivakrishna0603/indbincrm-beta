<?php
/**
 * Document review queue, across every stage.
 *
 * ekyc/admin_review.php judges documents case by case. This one works the
 * other way round: oldest pending document first, whatever stage it came
 * from. It catches the ones that arrive outside an eKYC case, such as an
 * income proof uploaded at step 4 or a replacement sent from the vault,
 * which otherwise sit in 'pending' with nobody looking at them.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../../admin/shell.php'; // was the separate customer/portal/admin_shell.php (its own 'Back office' branding) - consolidated so this renders inside the same admin dashboard shell

$admin = require_admin($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $docId    = post_int('document_id');
    $decision = post_str('decision', 20);
    $reason   = post_str('reason', 255);

    $stmt = $pdo->prepare("SELECT * FROM customer_documents WHERE id = ?");
    $stmt->execute([$docId]);
    $doc = $stmt->fetch();

    if (!$doc) {
        flash('error', 'That document no longer exists.');
        redirect(CUST_BASE . '/documents/admin_review.php');
    }
    if ($decision === 'reject' && $reason === '') {
        flash('error', 'Tell the customer what was wrong, or they will upload the same file again.');
        redirect(CUST_BASE . '/documents/admin_review.php');
    }

    $status = $decision === 'verify' ? 'verified' : 'rejected';

    $pdo->prepare("UPDATE customer_documents
                      SET status = ?, reviewer_id = ?, reviewed_at = NOW(), rejection_reason = ?
                    WHERE id = ?")
        ->execute([$status, (int)$admin['id'], $status === 'rejected' ? $reason : null, $docId]);

    audit_log($pdo, 'document.' . $status, 'customer_documents', (string)$docId, null,
              ['reason' => $reason], (int)$doc['user_id']);

    // A rejected eKYC document has to reopen the case, or the customer is
    // told to fix something on a case that still reads "in review".
    if ($status === 'rejected' && $doc['stage'] === 'ekyc') {
        $pdo->prepare("UPDATE kyc_details SET status = 'resubmit', rejection_reason = ? WHERE user_id = ?")
            ->execute([$reason, (int)$doc['user_id']]);
        $pdo->prepare("UPDATE users SET kyc_status = 'resubmit' WHERE id = ?")
            ->execute([(int)$doc['user_id']]);
    }

    notify($pdo, (int)$doc['user_id'],
        $status === 'verified' ? 'Document verified' : 'Document needs replacing',
        $status === 'verified'
            ? $doc['doc_label'] . ' has been checked and accepted.'
            : $doc['doc_label'] . ': ' . $reason);

    flash('success', $doc['doc_label'] . ' marked ' . $status . '.');
    redirect(CUST_BASE . '/documents/admin_review.php');
}

$stage = post_str('stage', 20) ?: (string)($_GET['stage'] ?? '');
$valid = ['ekyc', 'profile', 'credit', 'products', 'support', 'other'];

$sql = "SELECT d.*, u.full_name, u.email
          FROM customer_documents d
          JOIN users u ON u.id = d.user_id
         WHERE d.status = 'pending' AND d.is_current = 1";
$args = [];
if (in_array($stage, $valid, true)) {
    $sql .= " AND d.stage = ?";
    $args[] = $stage;
}
$sql .= " ORDER BY d.created_at ASC LIMIT 100";

$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$queue = $stmt->fetchAll();

admin_shell_open($pdo, $admin, 'ekyc', 'Document review');
?>
<div class="card wide">
    <form method="get" style="display:flex;gap:10px;align-items:flex-end;margin-bottom:20px;">
        <div class="field" style="margin:0;">
            <label for="stage">Stage</label>
            <select id="stage" name="stage" style="max-width:220px;">
                <option value="">All stages</option>
                <?php foreach ($valid as $v): ?>
                    <option value="<?= e($v) ?>" <?= $stage === $v ? 'selected' : '' ?>><?= e($v) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn">Filter</button>
    </form>

    <table class="table">
        <thead><tr>
            <th scope="col">Customer</th><th scope="col">Document</th>
            <th scope="col">Stage</th><th scope="col">Waiting since</th><th scope="col">Decision</th>
        </tr></thead>
        <tbody>
        <?php if (!$queue): ?>
            <tr><td colspan="5" style="color:var(--muted);">Nothing pending.</td></tr>
        <?php endif; ?>
        <?php foreach ($queue as $d): ?>
            <tr>
                <td>
                    <?= e($d['full_name']) ?>
                    <div style="color:var(--faint);font-size:12px;"><?= e($d['email']) ?></div>
                </td>
                <td>
                    <a href="<?= CUST_BASE ?>/documents/serve.php?id=<?= (int)$d['id'] ?>"
                       target="_blank" rel="noopener"><?= e($d['doc_label']) ?></a>
                    <div style="color:var(--faint);font-size:11px;">
                        <?= e($d['mime_type']) ?>
                        &bull; <?= number_format($d['size_bytes'] / 1024) ?> KB
                        &bull; v<?= (int)$d['version'] ?>
                    </div>
                </td>
                <td><?= e($d['stage']) ?></td>
                <td><?= e(date('j M, H:i', strtotime($d['created_at']))) ?></td>
                <td>
                    <form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="document_id" value="<?= (int)$d['id'] ?>">
                        <input type="text" name="reason" placeholder="Reason if rejecting"
                               style="min-height:34px;font-size:12px;max-width:190px;">
                        <button class="btn" name="decision" value="verify"
                                style="min-height:34px;font-size:12px;">Verify</button>
                        <button class="btn secondary" name="decision" value="reject"
                                style="min-height:34px;font-size:12px;">Reject</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php admin_shell_close(); ?>
