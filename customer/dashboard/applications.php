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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $name   = post_str('product_name', 150);
    $cat    = post_str('category', 100);
    $amount = round((float)($_POST['amount'] ?? 0), 2);
    $notes  = post_str('notes', 1000);

    if ($name === '' || $cat === '') {
        flash('error', 'Pick a product and a category.');
    } elseif ($amount <= 0 || $amount > 5000000) {
        flash('error', 'Enter an amount between 1 and 50,00,000.');
    } else {
        // Goes through the workflow rather than straight into the table.
        // That assigns the customer's existing agent so they keep the
        // commission, writes the first stage event, and notifies everyone on
        // the application. A direct INSERT would also fail: the compatibility
        // view does not expose application_number, which is NOT NULL.
        try {
            $appId = create_application($pdo, [
                'customer_id'  => $uid,
                'product_name' => $name,
                'category'     => $cat,
                'amount'       => $amount,
                'notes'        => $notes ?: null,
            ]);
            flash('success', 'Requirement raised. An agent picks it up from here.');
        } catch (Throwable $ex) {
            error_log('customer application: ' . $ex->getMessage());
            flash('error', 'That could not be submitted. Try again.');
        }
    }
    redirect(CUST_BASE . '/dashboard/applications.php');
}

$stmt = $pdo->prepare("SELECT * FROM customer_applications WHERE user_id = ? ORDER BY id DESC");
$stmt->execute([$uid]);
$rows = $stmt->fetchAll();

ensure_product_catalog_customer_facing($pdo);
$catalog = $pdo->query("SELECT product_code, product_name, category FROM product_catalog
                         WHERE is_active = 1 AND customer_facing = 1 ORDER BY product_name")->fetchAll();

$preselect = $_GET['product'] ?? '';
$preselectCat = '';
foreach ($catalog as $c) {
    if ($c['product_name'] === $preselect) { $preselectCat = $c['category']; break; }
}

portal_shell_open($pdo, $user, 'applications', 'Applications');
?>
<div class="card wide" style="margin-bottom:20px;">
    <h2 style="font-size:16px;font-weight:800;color:var(--ink);margin-bottom:14px;">Raise a requirement</h2>
    <form method="post">
        <?= csrf_field() ?>
        <div class="field">
            <label for="product_name">Product</label>
            <select id="product_name" name="product_name" required onchange="
                this.form.category.value = this.options[this.selectedIndex].dataset.cat || '';">
                <option value="" disabled <?= $preselect === '' ? 'selected' : '' ?>>Choose a product</option>
                <?php foreach ($catalog as $c): ?>
                    <option value="<?= e($c['product_name']) ?>" data-cat="<?= e($c['category']) ?>"
                        <?= $preselect === $c['product_name'] ? 'selected' : '' ?>>
                        <?= e($c['product_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <input type="hidden" name="category" value="<?= e($preselectCat) ?>">
        <div class="field">
            <label for="amount">Amount needed</label>
            <input id="amount" type="number" name="amount" min="1" max="5000000" step="100" required>
        </div>
        <div class="field">
            <label for="notes">Anything we should know</label>
            <textarea id="notes" name="notes" placeholder="Optional"></textarea>
        </div>
        <button class="btn">Submit</button>
    </form>
</div>

<div class="card wide">
    <h2 style="font-size:16px;font-weight:800;color:var(--ink);margin-bottom:12px;">History</h2>
    <?php if (!$rows): ?>
        <p style="color:var(--muted);font-size:13px;">Nothing raised yet.</p>
    <?php else: ?>
        <table class="table">
            <thead><tr><th scope="col">Product</th><th scope="col">Amount</th>
            <th scope="col">Raised</th><th scope="col">Status</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r):
                $pill = match ($r['status']) {
                    'approved', 'disbursed' => 'is-ok',
                    'rejected', 'withdrawn' => 'is-bad',
                    default => 'is-warn',
                }; ?>
                <tr>
                    <td><?= e($r['product_name']) ?></td>
                    <td><?= money((float)$r['amount']) ?></td>
                    <td><?= e(date('j M Y', strtotime($r['created_at']))) ?></td>
                    <td><span class="pill <?= $pill ?>"><?= e(str_replace('_', ' ', $r['status'])) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php portal_shell_close(); ?>
