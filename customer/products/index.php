<?php
/**
 * Step 5: Product activation.
 *
 * Flow chart: "Enable BNPL, Pay Later, Loans, Insurance, Rewards".
 * The current step 5 stores a single primary_category string per user,
 * which cannot express "BNPL active, health cover requested, loan declined".
 * Here each product is its own activation row with its own status and limit.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
require_once CUST_ROOT . '/portal/shell.php';
require_once CUST_ROOT . '/credit/scoring.php';

$user = require_customer($pdo);
require_step($pdo, $user, 5);

ensure_product_catalog_customer_facing($pdo);
$catalog = $pdo->query("SELECT * FROM product_catalog WHERE is_active = 1 AND customer_facing = 1 ORDER BY category, product_name")
               ->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM product_activations WHERE user_id = ?");
$stmt->execute([(int)$user['id']]);
$active = [];
foreach ($stmt->fetchAll() as $row) {
    $active[$row['product_code']] = $row;
}

$stmt = $pdo->prepare("SELECT * FROM credit_evaluations WHERE user_id = ? AND is_current = 1");
$stmt->execute([(int)$user['id']]);
$eval = $stmt->fetch() ?: null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $wanted = array_values(array_filter((array)($_POST['products'] ?? []), 'is_string'));
    if (!$wanted) {
        flash('error', 'Pick at least one product, or choose payments only.');
        redirect(CUST_BASE . '/products/index.php');
    }

    $byCode = [];
    foreach ($catalog as $p) {
        $byCode[$p['product_code']] = $p;
    }

    $needsCredit = false;
    foreach ($wanted as $code) {
        if (!isset($byCode[$code])) {
            flash('error', 'Unknown product.');
            redirect(CUST_BASE . '/products/index.php');
        }
        if ((int)$byCode[$code]['requires_credit'] === 1) {
            $needsCredit = true;
        }
    }

    // A credit product selected for the first time sends the customer back
    // to step 4, which they may have skipped.
    if ($needsCredit && !$eval) {
        foreach ($wanted as $code) {
            if ((int)$byCode[$code]['requires_credit'] === 1) {
                $pdo->prepare("INSERT IGNORE INTO product_activations (user_id, product_code, status)
                               VALUES (?,?, 'requested')")
                    ->execute([(int)$user['id'], $code]);
            }
        }
        flash('info', 'That product needs a credit check. It takes a moment.');
        redirect(step_url(4));
    }

    $pdo->beginTransaction();
    try {
        foreach ($wanted as $code) {
            $spec  = $byCode[$code];
            $score = $eval ? (int)$eval['credit_score'] : 0;

            $status = 'active';
            $limit  = 0.00;

            if ((int)$spec['requires_credit'] === 1) {
                if ($score < (int)$spec['min_score']) {
                    $status = 'declined';
                } elseif (($eval['decision'] ?? '') === 'manual_review') {
                    $status = 'requested';
                } else {
                    $limit = $spec['product_code'] === 'DB_BNPL'
                        ? (float)$eval['bnpl_limit']
                        : (float)$eval['max_credit_limit'];
                }
            }

            // The key fact statement has to be acknowledged before activation.
            $consentId = null;
            if ((int)$spec['requires_kfs'] === 1 && $status !== 'declined') {
                $consentId = record_consent($pdo, (int)$user['id'], 'product_kfs', true, '1.0', $code);
            }

            $pdo->prepare(
                "INSERT INTO product_activations
                    (user_id, product_code, status, assigned_limit, evaluation_id, kfs_consent_id, activated_at)
                 VALUES (?,?,?,?,?,?, IF(? = 'active', NOW(), NULL))
                 ON DUPLICATE KEY UPDATE
                    status = VALUES(status), assigned_limit = VALUES(assigned_limit),
                    evaluation_id = VALUES(evaluation_id), kfs_consent_id = VALUES(kfs_consent_id),
                    activated_at = VALUES(activated_at)"
            )->execute([
                (int)$user['id'], $code, $status, $limit,
                $eval['id'] ?? null, $consentId, $status,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('product activation: ' . $e->getMessage());
        flash('error', 'Activation did not complete. Try again.');
        redirect(CUST_BASE . '/products/index.php');
    }

    audit_log($pdo, 'products.activated', 'product_activations', null, null, ['codes' => $wanted]);
    advance_step($pdo, (int)$user['id'], 6);
    flash('success', 'Products set up.');
    redirect(step_url(6));
}

onboarding_shell_open($pdo, $user, 5, 'Product activation');
?>
<div class="card">
    <div class="card-icon"><i class="fa-solid fa-sliders"></i></div>
    <div class="card-head">
        <h2>Choose what to switch on</h2>
        <p>You can add or drop any of these later from your dashboard.</p>
    </div>

    <?php if ($eval && $eval['decision'] === 'manual_review'): ?>
        <div class="notice is-warn">
            Your credit limit is still with a reviewer, so credit products will show as requested
            until that clears.
        </div>
    <?php endif; ?>

    <?php if (!$catalog): ?>
        <div class="notice is-bad">
            No products are configured right now. Contact support rather than
            resubmitting this page - there is nothing here for the form to save yet.
        </div>
    <?php else: ?>

    <form method="post" action="<?= CUST_BASE ?>/products/index.php">
        <?= csrf_field() ?>

        <?php
        $currentCategory = null;
        foreach ($catalog as $p):
            if ($p['category'] !== $currentCategory):
                $currentCategory = $p['category']; ?>
                <h3 style="font-size:13px;font-weight:700;color:var(--muted);margin:20px 0 10px;">
                    <?= e(product_category_label($currentCategory)) ?>
                </h3>
            <?php endif;

            $existing = $active[$p['product_code']] ?? null;
            $needsCheck = (int)$p['requires_credit'] === 1;

            // Only disable a product when we have actually assessed the
            // customer and they fell short. Disabling it merely because no
            // score exists yet left every credit product permanently greyed
            // out, since the score is what step 4 produces and step 4 was
            // being skipped.
            $blocked = $needsCheck && $eval
                    && (int)$eval['credit_score'] < (int)$p['min_score'];
            $eligible = !$blocked;
            $needsScore = $needsCheck && !$eval;
        ?>
            <label style="display:flex;gap:12px;align-items:flex-start;padding:12px;border:1px solid var(--line);
                          border-radius:12px;margin-bottom:10px;<?= $eligible ? '' : 'opacity:.55;' ?>">
                <input type="checkbox" name="products[]" value="<?= e($p['product_code']) ?>"
                       style="width:auto;min-height:auto;margin-top:3px;"
                       <?= $existing && $existing['status'] === 'active' ? 'checked' : '' ?>
                       <?= $eligible ? '' : 'disabled' ?>>
                <span style="flex:1;">
                    <strong style="display:block;color:var(--ink);font-size:14px;"><?= e($p['product_name']) ?></strong>
                    <span style="font-size:12px;color:var(--muted);">
                        <?php if ($blocked): ?>
                            Needs a score of <?= (int)$p['min_score'] ?>. Yours is
                            <?= (int)$eval['credit_score'] ?>.
                        <?php elseif ($needsScore): ?>
                            Pick this and we will run a quick credit check first.
                        <?php elseif ($needsCheck): ?>
                            Approved on your score of <?= (int)$eval['credit_score'] ?>.
                        <?php else: ?>
                            Available to every verified customer.
                        <?php endif; ?>
                    </span>
                    <?php if ((int)$p['requires_kfs'] === 1): ?>
                        <span style="display:block;font-size:12px;color:var(--faint);margin-top:4px;">
                            Selecting this records your acknowledgement of the key fact statement:
                            rate, tenure, fees and total cost.
                        </span>
                    <?php endif; ?>
                    <?php if ($existing): ?>
                        <span class="pill <?= $existing['status'] === 'active' ? 'is-ok' : ($existing['status'] === 'declined' ? 'is-bad' : 'is-warn') ?>"
                              style="margin-top:6px;"><?= e($existing['status']) ?></span>
                    <?php endif; ?>
                </span>
            </label>
        <?php endforeach; ?>

        <button type="submit" class="btn block" style="margin-top:16px;">Activate and continue</button>
    </form>
    <?php endif; ?>
</div>
<?php onboarding_shell_close(); ?>
