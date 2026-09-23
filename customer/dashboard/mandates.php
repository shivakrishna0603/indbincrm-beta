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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'link_payment') {
    csrf_verify();
    $errors = [];

    $instrument = post_str('instrument', 10);
    $holder     = post_str('account_holder', 120);
    $bankName   = post_str('bank_name', 100);
    $accountNo  = preg_replace('/\s+/', '', post_str('account_number', 30));
    $ifsc       = strtoupper(post_str('ifsc_code', 11));
    $upi        = post_str('upi_handle', 100);

    if ($instrument === 'bank') {
        if ($holder === '')                            $errors[] = 'Enter the account holder name.';
        if ($bankName === '')                          $errors[] = 'Enter the bank name.';
        if (!preg_match('/^[0-9]{9,18}$/', $accountNo)) $errors[] = 'Account number must be 9 to 18 digits.';
        if (!valid_ifsc($ifsc))                        $errors[] = 'IFSC must look like HDFC0001234.';
    } elseif ($instrument === 'upi') {
        if (!preg_match('/^[\w.\-]{2,60}@[a-zA-Z]{2,20}$/', $upi)) {
            $errors[] = 'Enter a UPI ID such as name@bank.';
        }
    } else {
        $errors[] = 'Choose bank account or UPI.';
    }

    if (!$errors) {
        try {
            // Deliberately NOT touching is_primary on any existing row -
            // "link another" adds a second method, it does not replace
            // the customer's current primary the way onboarding's own
            // profile form does.
            if ($instrument === 'bank') {
                $pdo->prepare(
                    "INSERT INTO payment_instruments
                        (user_id, instrument, account_holder, bank_name, account_last4,
                         account_enc, account_hash, ifsc_code, is_primary, verification)
                     VALUES (?, 'bank', ?, ?, ?, ?, ?, ?, 0, 'penny_drop_sent')
                     ON DUPLICATE KEY UPDATE
                        account_holder = VALUES(account_holder), bank_name = VALUES(bank_name),
                        ifsc_code = VALUES(ifsc_code)"
                )->execute([
                    $uid, $holder, $bankName, substr($accountNo, -4),
                    pii_encrypt($accountNo), pii_hash($accountNo), $ifsc,
                ]);
            } else {
                // A unique key containing NULL columns never collides, so
                // ON DUPLICATE KEY does not fire for UPI rows. Match by hand.
                $find = $pdo->prepare("SELECT id FROM payment_instruments
                                        WHERE user_id = ? AND instrument = 'upi' AND upi_handle = ?");
                $find->execute([$uid, $upi]);
                if (!$find->fetchColumn()) {
                    $pdo->prepare(
                        "INSERT INTO payment_instruments (user_id, instrument, upi_handle, is_primary, verification)
                         VALUES (?, 'upi', ?, 0, 'unverified')"
                    )->execute([$uid, $upi]);
                }
            }
            flash('success', 'Payment method linked.');
        } catch (Throwable $ex) {
            error_log('link payment method: ' . $ex->getMessage());
            flash('error', 'That could not be linked. Try again.');
        }
    } else {
        flash('error', implode(' ', $errors));
    }
    redirect(CUST_BASE . '/dashboard/mandates.php');
}

$stmt = $pdo->prepare("SELECT * FROM bank_mandates WHERE user_id = ? ORDER BY id DESC");
$stmt->execute([$uid]);
$mandates = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM payment_instruments WHERE user_id = ? ORDER BY is_primary DESC, id");
$stmt->execute([$uid]);
$instruments = $stmt->fetchAll();

portal_shell_open($pdo, $user, 'mandates', 'Payment methods and mandates');
?>
<div class="card wide" style="margin-bottom:20px;">
    <h2 style="font-size:16px;font-weight:800;color:var(--ink);margin-bottom:12px;">Linked methods</h2>
    <table class="table">
        <thead><tr><th scope="col">Type</th><th scope="col">Details</th>
        <th scope="col">Verified</th><th scope="col"></th></tr></thead>
        <tbody>
        <?php if (!$instruments): ?><tr><td colspan="4" style="color:var(--muted);">Nothing linked.</td></tr><?php endif; ?>
        <?php foreach ($instruments as $i): ?>
            <tr>
                <td><?= e(strtoupper($i['instrument'])) ?></td>
                <td><?= $i['instrument'] === 'upi'
                        ? e((string)$i['upi_handle'])
                        : e((string)$i['bank_name']) . ' ••••' . e((string)$i['account_last4']) ?></td>
                <td><span class="pill <?= $i['verification'] === 'verified' ? 'is-ok' : 'is-warn' ?>">
                    <?= e(str_replace('_', ' ', $i['verification'])) ?></span></td>
                <td><?= (int)$i['is_primary'] ? '<span class="pill is-ok">primary</span>' : '' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h2 style="font-size:16px;font-weight:800;color:var(--ink);margin:20px 0 12px;">Link another</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="link_payment">
        <div class="field">
            <label for="instrument">Method type</label>
            <select id="instrument" name="instrument" required onchange="togglePaymentFields(this.value)">
                <option value="bank">Bank account</option>
                <option value="upi">UPI ID</option>
            </select>
        </div>
        <div id="bank_fields">
            <div class="field">
                <label for="account_holder">Account holder name</label>
                <input id="account_holder" type="text" name="account_holder">
            </div>
            <div class="field">
                <label for="bank_name">Bank name</label>
                <input id="bank_name" type="text" name="bank_name">
            </div>
            <div class="field">
                <label for="account_number">Account number</label>
                <input id="account_number" type="text" name="account_number" autocomplete="off">
            </div>
            <div class="field">
                <label for="ifsc_code">IFSC code</label>
                <input id="ifsc_code" type="text" name="ifsc_code" placeholder="HDFC0001234">
            </div>
        </div>
        <div id="upi_fields" hidden>
            <div class="field">
                <label for="upi_handle">UPI ID</label>
                <input id="upi_handle" type="text" name="upi_handle" placeholder="name@bank">
            </div>
        </div>
        <button class="btn" style="margin-top:8px;">Link another</button>
    </form>
</div>

<script>
function togglePaymentFields(value) {
    document.getElementById('bank_fields').hidden = (value !== 'bank');
    document.getElementById('upi_fields').hidden  = (value !== 'upi');
}
togglePaymentFields(document.getElementById('instrument').value);
</script>


<div class="card wide">
    <h2 style="font-size:16px;font-weight:800;color:var(--ink);margin-bottom:12px;">Auto-debit mandates</h2>
    <table class="table">
        <thead><tr><th scope="col">UMRN</th><th scope="col">Bank</th><th scope="col">Cap</th>
        <th scope="col">Frequency</th><th scope="col">Status</th></tr></thead>
        <tbody>
        <?php if (!$mandates): ?>
            <tr><td colspan="5" style="color:var(--muted);">No mandate on file. One is created when you
                activate a product with instalments.</td></tr>
        <?php endif; ?>
        <?php foreach ($mandates as $m): ?>
            <tr>
                <td><?= e((string)($m['umrn'] ?: 'pending')) ?></td>
                <td><?= e($m['bank_name']) ?> ••••<?= e($m['account_last4']) ?></td>
                <td><?= money((float)$m['mandate_limit']) ?></td>
                <td><?= e($m['frequency']) ?></td>
                <td><span class="pill <?= $m['status'] === 'active' ? 'is-ok' : 'is-warn' ?>"><?= e($m['status']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php portal_shell_close(); ?>
