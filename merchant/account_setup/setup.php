<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../portal/shell.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}
$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT role, full_name, party_code AS merchant_id, account_setup_completed_at FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (($user['role'] ?? '') !== 'merchant') {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}
// Note: intentionally NOT redirecting away if already completed — this page
// doubles as the "edit settings" screen, linked from status.php.

// The public-facing merchant code, if KYC approval has not already minted it.
//
// This used to write 'INDBINM' . id into users.merchant_id. That column is
// an INT UNSIGNED holding the id of the merchant a staff user belongs to,
// not a code, so under MySQL's default strict mode the UPDATE threw
// "Incorrect integer value" and the whole page died with a fatal. The code
// every other role gets lives in users.party_code, and issue_party_code()
// is what mints it, so this asks for the same thing the same way.
if (!$user['merchant_id']) {
    $user['merchant_id'] = issue_party_code($pdo, (int)$user_id) ?? '';
}

// Fetch the bank details already collected during Business Verification, as the "existing account" option
$bvStmt = $pdo->prepare("SELECT bank_account_number, ifsc_code FROM business_verifications WHERE user_id = ? ORDER BY submitted_at DESC LIMIT 1");
$bvStmt->execute([$user_id]);
$existingAccount = $bvStmt->fetch();

$color = '#f97316';
$lightColor = '#fff1e5';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $account_choice = $_POST['account_choice'] ?? 'existing';
    $payout_frequency = $_POST['payout_frequency'] ?? 'weekly';
    $payout_day = trim($_POST['payout_day'] ?? '');
    $minimum_threshold = trim($_POST['minimum_threshold'] ?? '0');
    $notify_sms = isset($_POST['notify_sms']) ? 1 : 0;
    $notify_email = isset($_POST['notify_email']) ? 1 : 0;

    $account_holder_name = trim($_POST['account_holder_name'] ?? '');
    $bank_account_number = trim($_POST['bank_account_number'] ?? '');
    $ifsc_code = strtoupper(trim($_POST['ifsc_code'] ?? ''));

    if ($account_choice === 'existing' && !$existingAccount) {
        $errors[] = "No existing account found — please add a new settlement account.";
        $account_choice = 'new';
    }

    if ($account_choice === 'new') {
        if (!$account_holder_name) $errors[] = "Account holder name is required.";
        if (!preg_match('/^\d{9,18}$/', $bank_account_number)) $errors[] = "Enter a valid bank account number.";
        if (!preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc_code)) $errors[] = "Enter a valid IFSC code.";
    }

    if (!in_array($payout_frequency, ['daily', 'weekly', 'monthly'])) {
        $errors[] = "Select a payout frequency.";
    }
    if ($payout_frequency !== 'daily' && !$payout_day) {
        $errors[] = "Select a payout day.";
    }
    if ($minimum_threshold === '' || !is_numeric($minimum_threshold) || (float)$minimum_threshold < 0) {
        $errors[] = "Enter a valid minimum payout threshold.";
    }

    if (empty($errors)) {
        // Deactivate any previously active settlement account, then insert the active one
        // The column is is_primary, and every read of this table already uses
        // that name: onboarding_complete, services and status all filter on
        // "is_primary = 1". Only this page ever called it is_active, so every
        // write here failed with "Unknown column" and no settlement account
        // was ever stored.
        $pdo->prepare("UPDATE settlement_accounts SET is_primary = 0 WHERE user_id = ?")->execute([$user_id]);

        if ($account_choice === 'existing') {
            $insertAccount = $pdo->prepare(
                "INSERT INTO settlement_accounts (user_id, account_holder, account_number, ifsc_code, is_primary)
                 VALUES (?, ?, ?, ?, 1)"
            );
            $insertAccount->execute([$user_id, $user['full_name'], $existingAccount['bank_account_number'], $existingAccount['ifsc_code']]);
        } else {
            $insertAccount = $pdo->prepare(
                "INSERT INTO settlement_accounts (user_id, account_holder, account_number, ifsc_code, is_primary)
                 VALUES (?, ?, ?, ?, 1)"
            );
            $insertAccount->execute([$user_id, $account_holder_name, $bank_account_number, $ifsc_code]);
        }

        // Upsert payout preferences
        // payout_preferences is keyed on user_id and has no id column, so
        // the existence check has to ask for a column that exists.
        $existingPref = $pdo->prepare("SELECT user_id FROM payout_preferences WHERE user_id = ?");
        $existingPref->execute([$user_id]);

        if ($existingPref->fetch()) {
            $pdo->prepare(
                "UPDATE payout_preferences SET payout_frequency=?, payout_day=?, minimum_threshold=?, notify_sms=?, notify_email=? WHERE user_id=?"
            )->execute([$payout_frequency, $payout_day ?: null, $minimum_threshold, $notify_sms, $notify_email, $user_id]);
        } else {
            $pdo->prepare(
                "INSERT INTO payout_preferences (user_id, payout_frequency, payout_day, minimum_threshold, notify_sms, notify_email)
                 VALUES (?, ?, ?, ?, ?, ?)"
            )->execute([$user_id, $payout_frequency, $payout_day ?: null, $minimum_threshold, $notify_sms, $notify_email]);
        }

        $pdo->prepare("UPDATE users SET account_setup_completed_at = NOW() WHERE id = ?")->execute([$user_id]);

        header('Location: status.php');
        exit;
    }
}
?>


    <?php render_shell_open($pdo, $user_id, 'account_setup', 'Account Setup'); ?>

    <div style="max-width:560px; margin:0 auto;">
        <div class="setup-card">

            <div class="icon-circle"><i class="fa-solid fa-gear"></i></div>
            <h1>Account Setup</h1>
            <p class="subtitle">Set your settlement account and payout preferences</p>

            <div class="merchant-id-box">
                <div class="label">Your merchant ID</div>
                <div class="value"><?= htmlspecialchars($user['merchant_id']) ?></div>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <?php foreach ($errors as $e) echo "<div>" . htmlspecialchars($e) . "</div>"; ?>
                </div>
            <?php endif; ?>

            <form method="POST" id="setupForm">

                <div class="section-label">Settlement bank account</div>

                <label class="radio-option">
                    <input type="radio" name="account_choice" value="existing" <?= $existingAccount ? 'checked' : 'disabled' ?> onchange="toggleAccountFields()">
                    <div>
                        <div class="opt-title">Use existing account</div>
                        <div class="opt-detail">
                            <?php if ($existingAccount): ?>
                                A/C ending •••<?= htmlspecialchars(substr($existingAccount['bank_account_number'], -4)) ?> &middot; IFSC <?= htmlspecialchars($existingAccount['ifsc_code']) ?>
                                <br>(from your Business Verification)
                            <?php else: ?>
                                No account on file yet — please add a new one below.
                            <?php endif; ?>
                        </div>
                    </div>
                </label>

                <label class="radio-option">
                    <input type="radio" name="account_choice" value="new" <?= $existingAccount ? '' : 'checked' ?> onchange="toggleAccountFields()">
                    <div>
                        <div class="opt-title">Add a new settlement account</div>
                        <div class="opt-detail">Use a different account for payouts</div>
                    </div>
                </label>

                <div id="newAccountFields">
                    <div class="form-group">
                        <label>Account holder name</label>
                        <input type="text" name="account_holder_name" placeholder="As per bank records">
                    </div>
                    <div class="two-col">
                        <div class="form-group">
                            <label>Bank account number</label>
                            <input type="text" name="bank_account_number" placeholder="123456789012">
                        </div>
                        <div class="form-group">
                            <label>IFSC code</label>
                            <input type="text" name="ifsc_code" placeholder="HDFC0001234" maxlength="11">
                        </div>
                    </div>
                </div>

                <div class="section-label">Payout preferences</div>

                <div class="form-group">
                    <label>Payout frequency</label>
                    <select name="payout_frequency" id="payoutFrequency" onchange="toggleDayField()">
                        <option value="daily">Daily</option>
                        <option value="weekly" selected>Weekly</option>
                        <option value="monthly">Monthly</option>
                    </select>
                </div>

                <div class="form-group" id="dayFieldWrap">
                    <label id="dayFieldLabel">Payout day</label>
                    <select name="payout_day" id="payoutDay"></select>
                </div>

                <div class="form-group">
                    <label>Minimum payout threshold (₹)</label>
                    <input type="number" name="minimum_threshold" min="0" step="1" value="0" placeholder="0">
                </div>

                <div class="checkbox-row">
                    <input type="checkbox" name="notify_sms" id="notify_sms" checked>
                    <label for="notify_sms">Notify me by SMS on each payout</label>
                </div>
                <div class="checkbox-row">
                    <input type="checkbox" name="notify_email" id="notify_email" checked>
                    <label for="notify_email">Notify me by email on each payout</label>
                </div>

                <button type="submit" class="submit-btn">Complete Account Setup</button>

            </form>

        </div>
    </div>

    <script>
        function toggleAccountFields() {
            const useNew = document.querySelector('input[name="account_choice"]:checked').value === 'new';
            document.getElementById('newAccountFields').style.display = useNew ? 'block' : 'none';
            document.querySelectorAll('#newAccountFields input').forEach(el => el.required = useNew);
        }

        function toggleDayField() {
            const freq = document.getElementById('payoutFrequency').value;
            const wrap = document.getElementById('dayFieldWrap');
            const select = document.getElementById('payoutDay');
            const label = document.getElementById('dayFieldLabel');

            if (freq === 'daily') {
                wrap.style.display = 'none';
                select.required = false;
                return;
            }
            wrap.style.display = 'block';
            select.required = true;
            select.innerHTML = '';

            if (freq === 'weekly') {
                label.innerText = 'Payout day of week';
                ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'].forEach(d => {
                    const opt = document.createElement('option');
                    opt.value = d; opt.innerText = d;
                    select.appendChild(opt);
                });
            } else {
                label.innerText = 'Payout day of month';
                for (let i = 1; i <= 28; i++) {
                    const opt = document.createElement('option');
                    opt.value = i; opt.innerText = i;
                    select.appendChild(opt);
                }
            }
        }

        toggleAccountFields();
        toggleDayField();
    </script>

    <?php render_shell_close(); ?>