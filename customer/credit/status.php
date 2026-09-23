<?php
/** Credit history for the customer: every evaluation, newest first. */

declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
require_once CUST_ROOT . '/portal/customer_shell.php';

$user = require_customer($pdo);

$stmt = $pdo->prepare("SELECT * FROM credit_evaluations WHERE user_id = ? ORDER BY evaluation_no DESC");
$stmt->execute([(int)$user['id']]);
$evals = $stmt->fetchAll();

portal_shell_open($pdo, $user, 'credit', 'Credit and limits');
?>
<div class="card wide">
    <?php if (!$evals): ?>
        <p style="color:var(--muted);">No credit assessment yet. One runs when you apply for a
           credit-linked product.</p>
        <a class="btn" style="margin-top:16px;" href="<?= CUST_BASE ?>/dashboard/offers.php">See what is available</a>
    <?php else: ?>
        <table class="table">
            <thead><tr>
                <th scope="col">#</th><th scope="col">Date</th><th scope="col">Score</th>
                <th scope="col">Band</th><th scope="col">Limit</th><th scope="col">Decision</th>
                <th scope="col">Engine</th>
            </tr></thead>
            <tbody>
            <?php foreach ($evals as $ev): ?>
                <tr>
                    <td><?= (int)$ev['evaluation_no'] ?><?= (int)$ev['is_current'] ? ' <span class="pill is-ok">current</span>' : '' ?></td>
                    <td><?= e(date('j M Y', strtotime($ev['created_at']))) ?></td>
                    <td><?= (int)$ev['credit_score'] ?></td>
                    <td><?= e($ev['risk_category']) ?></td>
                    <td><?= money((float)$ev['max_credit_limit']) ?></td>
                    <td><?= e(str_replace('_', ' ', $ev['decision'])) ?></td>
                    <td style="color:var(--faint);font-size:12px;"><?= e((string)$ev['model_version']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php portal_shell_close(); ?>
