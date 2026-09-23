<?php
/**
 * Unified approvals queue.
 *
 * Every verification waiting on a human, across all three modules, in one
 * place. Previously each module had its own review page and nothing showed
 * the total, so a merchant application could sit for a week because whoever
 * was on duty only ever opened the customer queue.
 */

declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/shell.php';
require_once __DIR__ . '/../customer/config/steps.php';
require_once __DIR__ . '/../customer/includes/guard.php';

$admin = require_admin($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $queue    = post_str('queue', 30);
    $rowId    = post_int('row_id');
    $decision = post_str('decision', 12);
    $reason   = post_str('reason', 255);

    if (!in_array($decision, ['approve', 'reject'], true)) {
        flash('error', 'Unknown decision.');
        redirect(BASE_URL . '/admin/approvals.php');
    }
    if ($decision === 'reject' && $reason === '') {
        flash('error', 'A rejection needs a reason the applicant can act on.');
        redirect(BASE_URL . '/admin/approvals.php?queue=' . urlencode($queue));
    }
    if (!admin_can($admin, 'can_approve_kyc')) {
        flash('error', 'Your account cannot approve verifications.');
        redirect(BASE_URL . '/admin/approvals.php');
    }

    $ok = $decision === 'approve';

    try {
        switch ($queue) {
            case 'kyc':
                $stmt = $pdo->prepare("SELECT user_id FROM kyc_details WHERE id = ?");
                $stmt->execute([$rowId]);
                $uid = (int)$stmt->fetchColumn();

                $pdo->prepare("UPDATE kyc_details SET status = ?, reviewer_id = ?,
                                      reviewed_at = NOW(), rejection_reason = ? WHERE id = ?")
                    ->execute([$ok ? 'approved' : 'rejected', (int)$admin['id'], $ok ? null : $reason, $rowId]);
                $pdo->prepare("UPDATE users SET kyc_status = ? WHERE id = ?")
                    ->execute([$ok ? 'approved' : 'rejected', $uid]);

                if ($ok) {
                    issue_customer_code($pdo, $uid);
                }
                notify($pdo, $uid, $ok ? 'Identity verified' : 'KYC needs attention',
                       $ok ? 'Your documents cleared verification.' : $reason);
                break;

            case 'kycdoc':
                $stmt = $pdo->prepare("SELECT user_id FROM kyc_documents WHERE id = ?");
                $stmt->execute([$rowId]);
                $uid = (int)$stmt->fetchColumn();

                $pdo->prepare("UPDATE kyc_documents SET status = ?, reviewer_id = ?,
                                      reviewed_at = NOW(), rejection_reason = ? WHERE id = ?")
                    ->execute([$ok ? 'approved' : 'rejected', (int)$admin['id'], $ok ? null : $reason, $rowId]);
                $pdo->prepare("UPDATE users SET kyc_status = ? WHERE id = ?")
                    ->execute([$ok ? 'approved' : 'rejected', $uid]);

                if ($ok) {
                    issue_party_code($pdo, $uid);
                }
                notify($pdo, $uid, $ok ? 'Identity verified' : 'Document rejected',
                       $ok ? 'Your ID has been accepted.' : $reason);
                break;

            case 'business':
                $stmt = $pdo->prepare("SELECT user_id FROM business_verifications WHERE id = ?");
                $stmt->execute([$rowId]);
                $uid = (int)$stmt->fetchColumn();

                $pdo->prepare("UPDATE business_verifications SET status = ?, reviewer_id = ?,
                                      reviewed_at = NOW(), rejection_reason = ? WHERE id = ?")
                    ->execute([$ok ? 'approved' : 'rejected', (int)$admin['id'], $ok ? null : $reason, $rowId]);
                $pdo->prepare("UPDATE users SET business_verification_status = ? WHERE id = ?")
                    ->execute([$ok ? 'approved' : 'rejected', $uid]);

                notify($pdo, $uid, $ok ? 'Business verified' : 'Business details rejected',
                       $ok ? 'Your shop details have been accepted.' : $reason);
                break;

            case 'agentbg':
                // Agent background & field verification. The three status
                // columns move together from this queue; the finer-grained
                // reference-only / field-only decision stays on the detail
                // page in agent/admin/background_verification.php.
                $stmt = $pdo->prepare("SELECT agent_id FROM agent_verification WHERE id = ?");
                $stmt->execute([$rowId]);
                $uid = (int)$stmt->fetchColumn();

                $mark = $ok ? 'verified' : 'failed';
                $pdo->prepare("UPDATE agent_verification
                                  SET reference_status  = ?,
                                      field_status      = ?,
                                      background_status = ?,
                                      admin_remarks     = ?,
                                      reviewed_at       = NOW()
                                WHERE id = ?")
                    ->execute([$mark, $mark, $mark, $ok ? null : $reason, $rowId]);

                notify($pdo, $uid,
                       $ok ? 'Background check cleared' : 'Background check needs attention',
                       $ok ? 'Your references and field verification have been confirmed.' : $reason);
                break;

            case 'application':
                // Goes through the workflow, so the stage history, the
                // notifications to all three parties and the commission
                // accrual all happen rather than just a status column moving.
                if ($ok) {
                    advance_application($pdo, $rowId, 'approved', (int)$admin['id'], 'Approved in review');
                } else {
                    reject_application($pdo, $rowId, $reason, (int)$admin['id']);
                }
                break;

            default:
                flash('error', 'Unknown queue.');
                redirect(BASE_URL . '/admin/approvals.php');
        }

        flash('success', 'Decision recorded.');
    } catch (Throwable $ex) {
        error_log('approvals: ' . $ex->getMessage());
        flash('error', 'Could not save that decision.');
    }

    redirect(BASE_URL . '/admin/approvals.php?queue=' . urlencode($queue));
}

$queue = (string)($_GET['queue'] ?? 'kyc');

$queues = [
    'kyc'         => ['Customer KYC',          'kyc_details',            "status IN ('pending','resubmit')"],
    'kycdoc'      => ['Agent & merchant KYC',  'kyc_documents',          "status = 'pending'"],
    'business'    => ['Business verification', 'business_verifications', "status = 'pending'"],
    'agentbg'     => ['Agent background',      'agent_verification',     "background_status = 'pending'"],
    'application' => ['Applications',          'applications',           "status = 'under_review'"],
];
if (!isset($queues[$queue])) {
    $queue = 'kyc';
}

$tallies = [];
foreach ($queues as $k => [$label, $table, $where]) {
    $tallies[$k] = (int)$pdo->query("SELECT COUNT(*) FROM {$table} WHERE {$where}")->fetchColumn();
}

// Table and predicate come from the whitelist above, never from the query
// string, so the interpolation below cannot be steered by a visitor.
[$label, $table, $where] = $queues[$queue];

$sql = match ($queue) {
    'kyc' => "SELECT k.id, k.full_name AS who, k.doc_type AS detail, k.created_at,
                     u.email, u.role, u.id AS user_id
                FROM kyc_details k JOIN users u ON u.id = k.user_id
               WHERE k.status IN ('pending','resubmit') ORDER BY k.created_at LIMIT 100",
    'kycdoc' => "SELECT d.id, d.full_name_on_document AS who, d.document_type AS detail, d.created_at,
                        u.email, u.role, u.id AS user_id
                   FROM kyc_documents d JOIN users u ON u.id = d.user_id
                  WHERE d.status = 'pending' ORDER BY d.created_at LIMIT 100",
    'business' => "SELECT b.id, u.business_name AS who, b.gstin AS detail, b.created_at,
                          u.email, u.role, u.id AS user_id
                     FROM business_verifications b JOIN users u ON u.id = b.user_id
                    WHERE b.status = 'pending' ORDER BY b.created_at LIMIT 100",
    'agentbg' => "SELECT v.id, u.full_name AS who,
                         CONCAT(COALESCE(v.area, '-'), ' / ref: ', COALESCE(v.reference_name, '-')) AS detail,
                         v.created_at, u.email, u.role, u.id AS user_id
                    FROM agent_verification v JOIN users u ON u.id = v.agent_id
                   WHERE v.background_status = 'pending' ORDER BY v.created_at LIMIT 100",
    default => "SELECT a.id, a.product_name AS who, a.application_number AS detail, a.created_at,
                       u.email, u.role, u.id AS user_id
                  FROM applications a LEFT JOIN users u ON u.id = a.customer_id
                 WHERE a.status = 'under_review' ORDER BY a.created_at LIMIT 100",
};


$rows = $pdo->query($sql)->fetchAll();

admin_shell_open($pdo, $admin, 'approvals', 'Approvals');
?>
<h1 class="admin-h1">Approvals</h1>
<p class="admin-sub">Everything across the three modules that is waiting on a person.</p>

<div class="kpi-grid">
    <?php foreach ($queues as $k => [$qLabel, , ]): ?>
        <a class="kpi" style="text-decoration:none;<?= $k === $queue ? 'border-color:var(--brand);' : '' ?>"
           href="?queue=<?= e($k) ?>">
            <div>
                <span class="kpi-label"><?= e($qLabel) ?></span>
                <strong class="kpi-value"><?= $tallies[$k] ?></strong>
                <span class="kpi-delta muted"><?= $tallies[$k] === 0 ? 'Clear' : 'Waiting' ?></span>
            </div>
        </a>
    <?php endforeach; ?>
</div>

<section class="panel">
    <h2><?= e($label) ?></h2>
    <table class="table" style="margin-top:14px;">
        <thead><tr>
            <th scope="col">Applicant</th><th scope="col">Detail</th>
            <th scope="col">Role</th><th scope="col">Waiting since</th><th scope="col">Decision</th>
        </tr></thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="5" style="color:var(--muted);">This queue is clear.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= e((string)$r['who']) ?>
                    <div style="color:var(--faint);font-size:12px;"><?= e((string)$r['email']) ?></div></td>
                <td><?= e((string)$r['detail']) ?></td>
                <td><span class="pill is-idle"><?= e((string)$r['role']) ?></span></td>
                <td><?= e(date('j M, H:i', strtotime((string)$r['created_at']))) ?></td>
                <td>
                    <form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="queue" value="<?= e($queue) ?>">
                        <input type="hidden" name="row_id" value="<?= (int)$r['id'] ?>">
                        <input type="text" name="reason" placeholder="Reason if rejecting"
                               style="min-height:34px;font-size:12px;max-width:190px;">
                        <button class="btn" name="decision" value="approve"
                                style="min-height:34px;font-size:12px;">Approve</button>
                        <button class="btn secondary" name="decision" value="reject"
                                style="min-height:34px;font-size:12px;">Reject</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php admin_shell_close(); ?>
