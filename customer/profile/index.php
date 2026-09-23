<?php
/**
 * Step 3: Profile creation.
 *
 * Flow chart: "Set Preferences, Communication Consent, Link Bank / UPI /
 * Payment Method". The current onboarding.php step 3 only asks for bank
 * details and calls it "Profile Creation & Financial Linking", while the
 * preferences it names are actually collected in step 5.
 *
 * Profile data goes to customer_profiles, the payment method goes to
 * payment_instruments, and each communication channel gets its own row in
 * the consent ledger rather than a single users.comm_consent flag.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
require_once CUST_ROOT . '/portal/shell.php';

$user = require_customer($pdo);
require_step($pdo, $user, 3);

$stmt = $pdo->prepare("SELECT * FROM customer_profiles WHERE user_id = ?");
$stmt->execute([(int)$user['id']]);
$profile = $stmt->fetch() ?: [];

$stmt = $pdo->prepare("SELECT * FROM payment_instruments WHERE user_id = ? AND is_primary = 1");
$stmt->execute([(int)$user['id']]);
$primary = $stmt->fetch() ?: null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $errors = [];

    $dob        = post_str('dob', 10);
    $line1      = post_str('address_line1', 150);
    $line2      = post_str('address_line2', 150);
    $city       = post_str('city', 80);
    $state      = post_str('state', 80);
    $pincode    = post_str('pincode', 6);
    $sameAsId   = isset($_POST['address_same_as_id']) ? 1 : 0;
    $occupation = post_str('occupation', 80);
    $employment = post_str('employment_type', 20);
    $incomeBand = post_str('annual_income_band', 10);
    $language   = post_str('preferred_language', 40) ?: 'English';

    $instrument = post_str('instrument', 10);
    $holder     = post_str('account_holder', 120);
    $bankName   = post_str('bank_name', 100);
    $accountNo  = preg_replace('/\s+/', '', post_str('account_number', 30));
    $ifsc       = strtoupper(post_str('ifsc_code', 11));
    $upi        = post_str('upi_handle', 100);

    if ($line1 === '')                 $errors[] = 'Enter your address.';
    if ($city === '')                  $errors[] = 'Enter your city.';
    if (!valid_pincode($pincode))      $errors[] = 'Enter a valid six-digit PIN code.';

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
        $errors[] = 'Choose how you want to pay and be paid.';
    }

    // Address proof only when the current address differs from the ID address.
    if (!$errors && $sameAsId === 0
        && ($_FILES['ADDRESS_PROOF']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $existing = $pdo->prepare("SELECT COUNT(*) FROM customer_documents
                                    WHERE user_id = ? AND doc_code = 'ADDRESS_PROOF' AND is_current = 1");
        $existing->execute([(int)$user['id']]);
        if ((int)$existing->fetchColumn() === 0) {
            $errors[] = 'Upload proof of your current address, since it differs from your ID.';
        }
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "INSERT INTO customer_profiles
                    (user_id, dob, address_line1, address_line2, city, state, pincode,
                     address_same_as_id, occupation, employment_type, annual_income_band, preferred_language)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    dob = VALUES(dob), address_line1 = VALUES(address_line1),
                    address_line2 = VALUES(address_line2), city = VALUES(city),
                    state = VALUES(state), pincode = VALUES(pincode),
                    address_same_as_id = VALUES(address_same_as_id),
                    occupation = VALUES(occupation), employment_type = VALUES(employment_type),
                    annual_income_band = VALUES(annual_income_band),
                    preferred_language = VALUES(preferred_language)"
            )->execute([
                (int)$user['id'], $dob ?: null, $line1, $line2 ?: null, $city, $state ?: null,
                $pincode, $sameAsId, $occupation ?: null, $employment ?: null,
                $incomeBand ?: null, $language,
            ]);

            // One primary instrument at a time.
            $pdo->prepare("UPDATE payment_instruments SET is_primary = 0 WHERE user_id = ?")
                ->execute([(int)$user['id']]);

            if ($instrument === 'bank') {
                $pdo->prepare(
                    "INSERT INTO payment_instruments
                        (user_id, instrument, account_holder, bank_name, account_last4,
                         account_enc, account_hash, ifsc_code, is_primary, verification)
                     VALUES (?, 'bank', ?, ?, ?, ?, ?, ?, 1, 'penny_drop_sent')
                     ON DUPLICATE KEY UPDATE
                        account_holder = VALUES(account_holder), bank_name = VALUES(bank_name),
                        ifsc_code = VALUES(ifsc_code), is_primary = 1"
                )->execute([
                    (int)$user['id'], $holder, $bankName, substr($accountNo, -4),
                    pii_encrypt($accountNo), pii_hash($accountNo), $ifsc,
                ]);
            } else {
                // A unique key containing NULL columns never collides, so
                // ON DUPLICATE KEY does not fire for UPI rows. Match by hand.
                $find = $pdo->prepare("SELECT id FROM payment_instruments
                                        WHERE user_id = ? AND instrument = 'upi' AND upi_handle = ?");
                $find->execute([(int)$user['id'], $upi]);

                if ($existingId = $find->fetchColumn()) {
                    $pdo->prepare("UPDATE payment_instruments SET is_primary = 1 WHERE id = ?")
                        ->execute([(int)$existingId]);
                } else {
                    $pdo->prepare(
                        "INSERT INTO payment_instruments (user_id, instrument, upi_handle, is_primary, verification)
                         VALUES (?, 'upi', ?, 1, 'unverified')"
                    )->execute([(int)$user['id'], $upi]);
                }
            }

            // Address proof, if supplied.
            if (($_FILES['ADDRESS_PROOF']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $r = store_document($pdo, (int)$user['id'], 'ADDRESS_PROOF', $_FILES['ADDRESS_PROOF'], 'profile');
                if (!$r['ok']) throw new RuntimeException($r['error']);
            }
            if (($_FILES['BANK_PROOF']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $r = store_document($pdo, (int)$user['id'], 'BANK_PROOF', $_FILES['BANK_PROOF'], 'profile',
                                    $instrument === 'bank' ? substr($accountNo, -4) : null);
                if (!$r['ok']) throw new RuntimeException($r['error']);
            }

            // Communication consent: one ledger row per channel, opt-in only.
            foreach (['comm_sms', 'comm_whatsapp', 'comm_email'] as $channel) {
                record_consent($pdo, (int)$user['id'], $channel, isset($_POST[$channel]), '1.0');
            }

            $pdo->commit();
            audit_log($pdo, 'profile.saved', 'customer_profiles', (string)$user['id']);
            advance_step($pdo, (int)$user['id'], 4);

            flash('success', 'Profile saved.');
            redirect(step_url(4));

        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = $ex->getMessage();
        }
    }

    foreach ($errors as $m) flash('error', $m);
}

onboarding_shell_open($pdo, $user, 3, 'Profile creation');
?>
<div class="card">
    <div class="card-icon"><i class="fa-solid fa-address-card"></i></div>
    <div class="card-head">
        <h2>Complete your profile</h2>
        <p>Where you live, how you want to hear from us, and where money moves.</p>
    </div>

    <form method="post" enctype="multipart/form-data" action="<?= CUST_BASE ?>/profile/index.php">
        <?= csrf_field() ?>

        <div class="field">
            <label for="address_line1">Address</label>
            <input id="address_line1" type="text" name="address_line1" required
                   value="<?= e($profile['address_line1'] ?? '') ?>" placeholder="Flat, building, street">
        </div>
        <div class="field">
            <label for="address_line2">Area or landmark</label>
            <input id="address_line2" type="text" name="address_line2"
                   value="<?= e($profile['address_line2'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="city">City</label>
            <input id="city" type="text" name="city" required value="<?= e($profile['city'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="state">State</label>
            <input id="state" type="text" name="state" value="<?= e($profile['state'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="pincode">PIN code</label>
            <input id="pincode" type="text" name="pincode" inputmode="numeric" maxlength="6"
                   pattern="[1-9][0-9]{5}" required value="<?= e($profile['pincode'] ?? '') ?>">
        </div>

        <div class="field">
            <label style="display:flex;gap:10px;align-items:center;font-weight:600;">
                <input type="checkbox" name="address_same_as_id" value="1"
                       style="width:auto;min-height:auto;"
                       <?= (int)($profile['address_same_as_id'] ?? 1) === 1 ? 'checked' : '' ?>>
                <span>This is the same address as on my ID</span>
            </label>
            <p class="hint">Untick this and attach proof below if you have moved.</p>
        </div>

        <div class="field">
            <label for="ADDRESS_PROOF">Current address proof (only if it differs from your ID)</label>
            <input id="ADDRESS_PROOF" type="file" name="ADDRESS_PROOF" accept="image/jpeg,image/png,application/pdf">
        </div>

        <div class="field">
            <label for="employment_type">Employment</label>
            <select id="employment_type" name="employment_type">
                <option value="">Prefer not to say</option>
                <option value="salaried">Salaried</option>
                <option value="self_employed">Self employed</option>
                <option value="student">Student</option>
                <option value="retired">Retired</option>
                <option value="other">Other</option>
            </select>
        </div>
        <div class="field">
            <label for="annual_income_band">Annual income</label>
            <select id="annual_income_band" name="annual_income_band">
                <option value="">Prefer not to say</option>
                <option value="lt_3l">Below 3 lakh</option>
                <option value="3l_6l">3 to 6 lakh</option>
                <option value="6l_12l">6 to 12 lakh</option>
                <option value="12l_25l">12 to 25 lakh</option>
                <option value="gt_25l">Above 25 lakh</option>
            </select>
            <p class="hint">Used only if you later apply for a credit product.</p>
        </div>
        <div class="field">
            <label for="preferred_language">Preferred language</label>
            <select id="preferred_language" name="preferred_language">
                <?php foreach (['English','Hindi','Telugu','Tamil','Kannada','Marathi','Bengali'] as $lang): ?>
                    <option value="<?= e($lang) ?>"
                        <?= ($profile['preferred_language'] ?? 'English') === $lang ? 'selected' : '' ?>><?= e($lang) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <hr style="border:none;border-top:1px solid var(--line);margin:28px 0;">

        <div class="field">
            <label for="instrument">Link a payment method</label>
            <select id="instrument" name="instrument" required onchange="togglePaymentFields(this.value)">
                <option value="bank" <?= ($primary['instrument'] ?? '') === 'bank' ? 'selected' : '' ?>>Bank account</option>
                <option value="upi"  <?= ($primary['instrument'] ?? '') === 'upi'  ? 'selected' : '' ?>>UPI ID</option>
            </select>
        </div>

        <div id="bank_fields">
            <div class="field">
                <label for="account_holder">Account holder name</label>
                <input id="account_holder" type="text" name="account_holder"
                       value="<?= e($primary['account_holder'] ?? $user['full_name']) ?>">
            </div>
            <div class="field">
                <label for="bank_name">Bank</label>
                <input id="bank_name" type="text" name="bank_name" value="<?= e($primary['bank_name'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="account_number">Account number</label>
                <input id="account_number" type="text" name="account_number" inputmode="numeric" autocomplete="off"
                       placeholder="<?= $primary && $primary['account_last4'] ? '••••••' . e($primary['account_last4']) : '' ?>">
                <p class="hint">We send one rupee to confirm the account, then reverse it.</p>
            </div>
            <div class="field">
                <label for="ifsc_code">IFSC</label>
                <input id="ifsc_code" type="text" name="ifsc_code" maxlength="11"
                       style="text-transform:uppercase;" value="<?= e($primary['ifsc_code'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="BANK_PROOF">Bank proof</label>
                <input id="BANK_PROOF" type="file" name="BANK_PROOF" accept="image/jpeg,image/png,application/pdf">
                <p class="hint">Cancelled cheque, passbook first page or a statement header.</p>
            </div>
        </div>

        <div id="upi_fields" hidden>
            <div class="field">
                <label for="upi_handle">UPI ID</label>
                <input id="upi_handle" type="text" name="upi_handle"
                       value="<?= e($primary['upi_handle'] ?? '') ?>" placeholder="name@bank">
            </div>
        </div>

        <hr style="border:none;border-top:1px solid var(--line);margin:28px 0;">

        <fieldset style="border:none;">
            <legend style="font-size:14px;font-weight:700;color:#1e293b;margin-bottom:10px;">
                How should we contact you?
            </legend>
            <p class="hint" style="margin:0 0 12px;">
                Transaction and security alerts are sent regardless. These cover offers and updates.
            </p>
            <?php foreach (['comm_sms' => 'SMS', 'comm_whatsapp' => 'WhatsApp', 'comm_email' => 'Email'] as $key => $label): ?>
                <label style="display:flex;gap:10px;align-items:center;font-weight:600;margin-bottom:10px;">
                    <input type="checkbox" name="<?= e($key) ?>" value="1" style="width:auto;min-height:auto;">
                    <span><?= e($label) ?></span>
                </label>
            <?php endforeach; ?>
        </fieldset>

        <button type="submit" class="btn block" style="margin-top:16px;">Save and continue</button>
    </form>
</div>

<script>
function togglePaymentFields(value) {
    document.getElementById('bank_fields').hidden = (value !== 'bank');
    document.getElementById('upi_fields').hidden  = (value !== 'upi');
}
togglePaymentFields(document.getElementById('instrument').value);
</script>
<?php onboarding_shell_close(); ?>
