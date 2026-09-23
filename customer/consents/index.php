<?php
/**
 * Consent centre. Shows every consent on record and lets the customer
 * withdraw the ones that are withdrawable.
 *
 * Withdrawal writes a new ledger row with granted = 0 rather than deleting
 * the original, so the history of what was authorised and when stays intact.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
require_once CUST_ROOT . '/portal/customer_shell.php';

$user = require_customer($pdo);

// Consents the customer may turn off without closing the account.
const WITHDRAWABLE = ['comm_sms', 'comm_whatsapp', 'comm_email'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    foreach (WITHDRAWABLE as $type) {
        record_consent($pdo, (int)$user['id'], $type, isset($_POST[$type]), '1.0');
    }
    audit_log($pdo, 'consent.updated', 'consents', (string)$user['id']);
    flash('success', 'Contact preferences saved.');
    redirect(CUST_BASE . '/consents/index.php');
}

// Latest state per consent type.
$stmt = $pdo->prepare(
    "SELECT c.* FROM consents c
       JOIN (SELECT consent_type, MAX(id) AS latest FROM consents
              WHERE user_id = ? GROUP BY consent_type) m
         ON m.latest = c.id
      ORDER BY c.consent_type"
);
$stmt->execute([(int)$user['id']]);
$current = [];
foreach ($stmt->fetchAll() as $row) {
    $current[$row['consent_type']] = $row;
}

$labels = [
    'dpdp_notice'        => 'Privacy notice accepted',
    'aadhaar_ekyc'       => 'Identity verification',
    'bureau_pull'        => 'Credit bureau enquiry',
    'account_aggregator' => 'Bank data sharing',
    'product_kfs'        => 'Product terms acknowledged',
    'terms'              => 'Terms of service',
    'comm_sms'           => 'SMS updates',
    'comm_whatsapp'      => 'WhatsApp updates',
    'comm_email'         => 'Email updates',
];

portal_shell_open($pdo, $user, 'profile', 'Your consents');
?>
<div class="card wide">
    <p style="font-size:13px;color:var(--muted);margin-bottom:20px;">
        Transaction and security messages are sent regardless of these settings.
    </p>

    <form method="post" action="<?= CUST_BASE ?>/consents/index.php">
        <?= csrf_field() ?>
        <table class="table">
            <thead><tr>
                <th scope="col">Consent</th><th scope="col">State</th>
                <th scope="col">Recorded</th><th scope="col">Policy</th><th scope="col"></th>
            </tr></thead>
            <tbody>
            <?php foreach ($labels as $type => $label):
                $row = $current[$type] ?? null; ?>
                <tr>
                    <td><?= e($label) ?></td>
                    <td>
                        <?php if (!$row): ?>
                            <span class="pill is-idle">Not asked</span>
                        <?php else: ?>
                            <span class="pill <?= (int)$row['granted'] ? 'is-ok' : 'is-idle' ?>">
                                <?= (int)$row['granted'] ? 'Given' : 'Withdrawn' ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td><?= $row ? e(date('j M Y, H:i', strtotime($row['created_at']))) : '—' ?></td>
                    <td style="color:var(--faint);font-size:12px;"><?= $row ? e($row['policy_version']) : '—' ?></td>
                    <td>
                        <?php if (in_array($type, WITHDRAWABLE, true)): ?>
                            <input type="checkbox" name="<?= e($type) ?>" value="1"
                                   style="width:auto;min-height:auto;"
                                   <?= $row && (int)$row['granted'] ? 'checked' : '' ?>>
                        <?php else: ?>
                            <span style="font-size:12px;color:var(--faint);">Required for the account</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <button class="btn" style="margin-top:20px;">Save preferences</button>
    </form>
</div>
<?php portal_shell_close(); ?>
