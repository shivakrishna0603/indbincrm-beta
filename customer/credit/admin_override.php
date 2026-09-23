<?php
/**
 * Manual credit decision. Writes a new evaluation row rather than editing
 * the machine decision in place, so the original score stays on record.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../../admin/shell.php'; // was the separate customer/portal/admin_shell.php (its own 'Back office' branding) - consolidated so this renders inside the same admin dashboard shell

$admin = require_admin($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $targetId = post_int('user_id');
    $limit    = round((float)($_POST['limit'] ?? 0), 2);
    $risk     = post_str('risk_category', 20);
    $reason   = post_str('reason', 255);

    if ($targetId <= 0 || $reason === '') {
        flash('error', 'A customer and a written reason are both required.');
        redirect(CUST_BASE . '/credit/admin_override.php');
    }
    if ($limit < 0 || $limit > 2000000) {
        flash('error', 'Limit must be between 0 and 20,00,000.');
        redirect(CUST_BASE . '/credit/admin_override.php');
    }

    $prev = $pdo->prepare("SELECT * FROM credit_evaluations WHERE user_id = ? AND is_current = 1");
    $prev->execute([$targetId]);
    $before = $prev->fetch() ?: null;

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE credit_evaluations SET is_current = 0 WHERE user_id = ?")->execute([$targetId]);

        $n = $pdo->prepare("SELECT COALESCE(MAX(evaluation_no),0)+1 FROM credit_evaluations WHERE user_id = ?");
        $n->execute([$targetId]);

        $pdo->prepare(
            "INSERT INTO credit_evaluations
                (user_id, evaluation_no, is_current, engine, model_version, credit_score,
                 risk_category, max_credit_limit, bnpl_limit, decision, override_by,
                 override_reason, valid_until)
             VALUES (?,?,1,'manual','manual',?,?,?,?, 'auto_approved', ?, ?, DATE_ADD(CURDATE(), INTERVAL 90 DAY))"
        )->execute([
            $targetId, (int)$n->fetchColumn(),
            (int)($before['credit_score'] ?? 650), $risk, $limit, round($limit * 0.20, 2),
            (int)$admin['id'], $reason,
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('credit override: ' . $e->getMessage());
        flash('error', 'Could not save the override.');
        redirect(CUST_BASE . '/credit/admin_override.php');
    }

    audit_log($pdo, 'credit.override', 'credit_evaluations', (string)$targetId,
              $before ? ['limit' => $before['max_credit_limit']] : null,
              ['limit' => $limit, 'reason' => $reason], $targetId);

    notify($pdo, $targetId, 'Credit limit updated', 'Your limit is now ' . money($limit) . '.');
    flash('success', 'Override recorded.');
    redirect(CUST_BASE . '/credit/admin_override.php');
}

$pending = $pdo->query(
    "SELECT ce.*, u.full_name, u.email
       FROM credit_evaluations ce
       JOIN users u ON u.id = ce.user_id
      WHERE ce.is_current = 1 AND ce.decision = 'manual_review'
      ORDER BY ce.created_at ASC LIMIT 100"
)->fetchAll();
admin_shell_open($pdo, $admin, 'ekyc', 'Credit decisions');
?>
<p style="font-size:13px;color:var(--muted);margin-bottom:18px;">
    These scored above the auto-approval ceiling or had something missing.
    Setting a limit here writes a new evaluation; the original machine score stays on record.
</p>

<div class="card wide">
        <table class="table">
            <thead><tr>
                <th scope="col">Customer</th><th scope="col">Score</th><th scope="col">System limit</th>
                <th scope="col">Decision</th>
            </tr></thead>
            <tbody>
            <?php if (!$pending): ?><tr><td colspan="4" style="color:var(--muted);">Nothing waiting.</td></tr><?php endif; ?>
            <?php foreach ($pending as $p): ?>
                <tr>
                    <td><?= e($p['full_name']) ?><br><span style="color:var(--faint);font-size:12px;"><?= e($p['email']) ?></span></td>
                    <td><?= (int)$p['credit_score'] ?> (<?= e($p['risk_category']) ?>)</td>
                    <td><?= money((float)$p['max_credit_limit']) ?></td>
                    <td>
                        <form method="post" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="user_id" value="<?= (int)$p['user_id'] ?>">
                            <input type="number" name="limit" step="1000" min="0" required
                                   value="<?= (int)$p['max_credit_limit'] ?>"
                                   style="min-height:34px;max-width:120px;font-size:12px;">
                            <select name="risk_category" style="min-height:34px;max-width:120px;font-size:12px;">
                                <option value="low">low</option>
                                <option value="moderate" selected>moderate</option>
                                <option value="high">high</option>
                                <option value="declined">declined</option>
                            </select>
                            <input type="text" name="reason" required placeholder="Reason"
                                   style="min-height:34px;font-size:12px;max-width:220px;">
                            <button class="btn" style="min-height:34px;font-size:12px;">Save</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php admin_shell_close(); ?>
