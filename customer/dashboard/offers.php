<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
require_once CUST_ROOT . '/portal/customer_shell.php';

$user = require_customer($pdo);
if ($user['kyc_status'] !== 'approved' || empty($user['customer_code'])) {
    flash('info', 'Your dashboard opens once identity verification clears.');
    redirect(step_url(effective_step($pdo, $user)));
}
$uid = (int)$user['id'];

$stmt = $pdo->prepare("SELECT * FROM credit_evaluations WHERE user_id = ? AND is_current = 1");
$stmt->execute([$uid]);
$eval = $stmt->fetch() ?: null;

$stmt = $pdo->prepare("SELECT product_code, status FROM product_activations WHERE user_id = ?");
$stmt->execute([$uid]);
$mine = array_column($stmt->fetchAll(), 'status', 'product_code');

ensure_product_catalog_customer_facing($pdo);
$catalog = $pdo->query("SELECT * FROM product_catalog WHERE is_active = 1 AND customer_facing = 1
                         ORDER BY category, product_name")->fetchAll();

portal_shell_open($pdo, $user, 'offers', 'Offers');
?>
<div class="tile-grid">
<?php foreach ($catalog as $p):
    $held     = $mine[$p['product_code']] ?? null;
    $eligible = (int)$p['requires_credit'] === 0
             || ($eval && (int)$eval['credit_score'] >= (int)$p['min_score']); ?>
    <div class="tile" style="<?= $eligible ? '' : 'opacity:.6;' ?>">
        <span><?= e(product_category_label($p['category'])) ?></span>
        <strong style="font-size:17px;"><?= e($p['product_name']) ?></strong>
        <p style="font-size:12px;color:var(--muted);margin-top:8px;">
            <?php if ((int)$p['requires_credit'] === 1): ?>
                Needs a credit check, minimum score <?= (int)$p['min_score'] ?>.
                <?php if ($eval): ?> Yours is <?= (int)$eval['credit_score'] ?>.<?php endif; ?>
            <?php else: ?>
                Open to every verified customer.
            <?php endif; ?>
        </p>
        <div style="margin-top:12px;">
        <?php if ($held === 'active'): ?>
            <span class="pill is-ok">Active</span>
        <?php elseif ($held): ?>
            <span class="pill is-warn"><?= e($held) ?></span>
        <?php elseif ($eligible): ?>
            <a class="btn" style="min-height:36px;font-size:13px;"
               href="<?= CUST_BASE ?>/dashboard/applications.php?product=<?= urlencode($p['product_name']) ?>">Apply</a>
        <?php else: ?>
            <span class="pill is-idle">Not eligible yet</span>
            <a class="btn secondary" style="min-height:32px;font-size:12px;margin-top:6px;display:inline-block;"
               href="<?= CUST_BASE ?>/dashboard/applications.php?product=<?= urlencode($p['product_name']) ?>">Ask anyway &rsaquo;</a>
        <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
</div>
<?php portal_shell_close(); ?>
