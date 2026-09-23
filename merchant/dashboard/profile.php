<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../portal/shell.php';
require_once __DIR__ . '/../portal/merchant_shell.php';

$user = require_merchant($pdo);
$user_id = (int)$user['id'];

if ($user['account_status'] !== 'active') {
    header('Location: ../onboarding_complete/index.php');
    exit;
}

$businessTypes = ['Retail Store', 'Grocery / Kirana', 'Restaurant / Food', 'Electronics', 'Pharmacy', 'Apparel', 'Other'];
$supportChannels = ['phone' => 'Phone', 'email' => 'Email', 'whatsapp' => 'WhatsApp', 'chat' => 'Chat'];
$turnoverLabels = [
    'below_1L' => 'Below ₹1L', '1L_5L' => '₹1–5L', '5L_25L' => '₹5–25L',
    '25L_1Cr' => '₹25L–1Cr', 'above_1Cr' => 'Above ₹1Cr',
];
$statusColors = [
    'active' => '#16a34a', 'approved' => '#16a34a',
    'registered' => '#64748b', 'onboarding' => '#d97706',
    'pending' => '#d97706', 'resubmit' => '#d97706', 'rejected' => '#dc2626',
    'suspended' => '#dc2626', 'closed' => '#64748b', 'not_started' => '#64748b',
];

/**
 * Profile edits split three ways:
 *   - plain columns (name, business, support channel) save on one POST;
 *   - password change needs the current password;
 *   - email and mobile are identity fields, so they only move after an OTP
 *     is delivered to the *new* value and confirmed. Nothing here can silently
 *     swap a verified contact detail.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = post_str('action', 30);

    if ($action === 'save_details') {
        $fullName = trim(post_str('full_name', 150));
        $bizName  = trim(post_str('business_name', 180));
        $bizType  = trim(post_str('business_type', 100));
        $support  = post_str('preferred_support_channel', 30);

        $errors = [];
        if ($fullName === '') $errors[] = 'Enter your full name.';
        if ($bizName === '')  $errors[] = 'Enter your business name.';
        if ($bizType !== '' && !in_array($bizType, $businessTypes, true)) $errors[] = 'Choose a valid business type.';
        if ($support !== '' && !isset($supportChannels[$support])) $errors[] = 'Choose a valid support channel.';

        if ($errors) {
            foreach ($errors as $er) flash('error', $er);
        } else {
            $pdo->prepare(
                "UPDATE users SET full_name = ?, business_name = ?, business_type = ?, preferred_support_channel = ? WHERE id = ?"
            )->execute([$fullName, $bizName, $bizType ?: null, $support ?: null, $user_id]);
            $_SESSION['full_name'] = $fullName;
            audit_log($pdo, 'profile.updated', 'users', (string)$user_id, null, ['section' => 'account']);
            flash('success', 'Profile details saved.');
        }
        redirect('profile.php');
    }

    if ($action === 'change_password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new     = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        $errors = [];
        if ($current === '' || !password_verify($current, (string)$user['password_hash'])) {
            $errors[] = 'Your current password is not correct.';
        }
        if (mb_strlen($new) < 8) $errors[] = 'New password must be at least 8 characters.';
        if ($new !== $confirm)   $errors[] = 'The new passwords do not match.';
        if ($new !== '' && $new === $current) $errors[] = 'Choose a password different from your current one.';

        if ($errors) {
            foreach ($errors as $er) flash('error', $er);
        } else {
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
                ->execute([password_hash($new, PASSWORD_DEFAULT), $user_id]);
            audit_log($pdo, 'profile.password_changed', 'users', (string)$user_id);
            flash('success', 'Password updated.');
        }
        redirect('profile.php');
    }

    if ($action === 'send_mobile_otp' || $action === 'send_email_otp') {
        $isMobile = $action === 'send_mobile_otp';
        $raw   = post_str($isMobile ? 'mobile' : 'email', $isMobile ? 15 : 190);
        $dest  = $isMobile ? preg_replace('/\D/', '', $raw) : strtolower(trim($raw));
        $col   = $isMobile ? 'mobile' : 'email';
        $label = $isMobile ? 'mobile number' : 'email address';

        $errors = [];
        if ($isMobile) {
            if (!valid_mobile($dest)) $errors[] = 'Enter a 10-digit Indian mobile number.';
        } elseif (!filter_var($dest, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }
        if (!$errors && $dest === (string)($user[$col] ?? '')) {
            $errors[] = 'That is already your current ' . $label . '.';
        }
        if (!$errors) {
            $dup = $pdo->prepare("SELECT id FROM users WHERE $col = ? AND id <> ?");
            $dup->execute([$dest, $user_id]);
            if ($dup->fetch()) $errors[] = 'That ' . $label . ' belongs to another account.';
        }

        if ($errors) {
            foreach ($errors as $er) flash('error', $er);
        } else {
            $otp = otp_issue($pdo, $user_id, 'login', $dest);
            if ($otp === null) {
                flash('error', 'Too many codes requested. Try again in 15 minutes.');
            } else {
                $_SESSION[$isMobile ? 'profile_mobile_to' : 'profile_email_to'] = $dest;
                flash('info', 'A 6-digit code was sent to ' . ($isMobile ? mask_tail($dest) : $dest) . '.');
                if (APP_ENV === 'local') flash('warn', 'Local mode: your code is ' . $otp);
            }
        }
        redirect('profile.php');
    }

    if ($action === 'verify_mobile_otp' || $action === 'verify_email_otp') {
        $isMobile = $action === 'verify_mobile_otp';
        $sessKey  = $isMobile ? 'profile_mobile_to' : 'profile_email_to';
        $target   = (string)($_SESSION[$sessKey] ?? '');
        $code     = preg_replace('/\D/', '', post_str('otp', 6));

        if ($target === '') {
            flash('error', 'Request a code first.');
        } elseif (!otp_verify($pdo, 'login', $target, $code)) {
            flash('error', 'That code is wrong or has expired.');
        } else {
            if ($isMobile) {
                $pdo->prepare("UPDATE users SET mobile = ?, mobile_verified = 1 WHERE id = ?")
                    ->execute([$target, $user_id]);
            } else {
                $pdo->prepare("UPDATE users SET email = ?, email_verified = 1 WHERE id = ?")
                    ->execute([$target, $user_id]);
            }
            unset($_SESSION[$sessKey]);
            audit_log($pdo, 'profile.contact_changed', 'users', (string)$user_id, null, ['field' => $isMobile ? 'mobile' : 'email']);
            flash('success', ($isMobile ? 'Mobile number' : 'Email address') . ' updated and verified.');
        }
        redirect('profile.php');
    }

    if ($action === 'cancel_otp') {
        $which = post_str('which', 10);
        if ($which === 'mobile') unset($_SESSION['profile_mobile_to']);
        if ($which === 'email')  unset($_SESSION['profile_email_to']);
        redirect('profile.php');
    }

    redirect('profile.php');
}

$bizStmt = $pdo->prepare("SELECT * FROM business_verifications WHERE user_id = ? ORDER BY id DESC LIMIT 1");
$bizStmt->execute([$user_id]);
$biz = $bizStmt->fetch() ?: [];

$accStmt = $pdo->prepare("SELECT * FROM settlement_accounts WHERE user_id = ? AND is_primary = 1 ORDER BY created_at DESC LIMIT 1");
$accStmt->execute([$user_id]);
$settlement = $accStmt->fetch() ?: [];

$prefStmt = $pdo->prepare("SELECT * FROM payout_preferences WHERE user_id = ?");
$prefStmt->execute([$user_id]);
$prefs = $prefStmt->fetch() ?: [];

$mobileTo = (string)($_SESSION['profile_mobile_to'] ?? '');
$emailTo  = (string)($_SESSION['profile_email_to'] ?? '');

$displayName = $user['full_name'] !== '' ? $user['full_name'] : ($user['business_name'] !== '' ? $user['business_name'] : 'Merchant');
$parts = preg_split('/\s+/', trim($displayName)) ?: [];
$initials = strtoupper(mb_substr($parts[0] ?? 'M', 0, 1) . (count($parts) > 1 ? mb_substr(end($parts), 0, 1) : ''));
$merchantId = $user['party_code'] ?: ($user['merchant_id'] ?? '');
$bizTypeOptions = $businessTypes;
if (!empty($user['business_type']) && !in_array($user['business_type'], $bizTypeOptions, true)) {
    $bizTypeOptions[] = $user['business_type'];
}

render_merchant_shell_open($pdo, $user_id, 'profile', 'My profile', false);
?>

<style>
    .form-group { margin-bottom: 14px; }
    .form-group label { display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; color: var(--ink); }
    .form-group input, .form-group select { width: 100%; padding: 10px; border: 1px solid var(--field); border-radius: 8px; font-size: 14px; font-family: inherit; }
    .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    @media (max-width: 720px) { .two-col { grid-template-columns: 1fr; } }
    .submit-btn { padding: 11px 24px; border-radius: 8px; border: none; background: var(--brand); color: #fff; font-weight: 700; font-size: 14px; cursor: pointer; }
    .btn-ghost { padding: 10px 18px; border-radius: 8px; border: 1px solid var(--line); background: var(--surface); color: var(--muted); font-weight: 700; font-size: 13px; cursor: pointer; }

    .profile-head { display: flex; align-items: center; gap: 18px; flex-wrap: wrap; margin-bottom: 22px; }
    .profile-avatar { width: 64px; height: 64px; border-radius: 50%; background: var(--brand-soft); color: var(--brand-deep); display: flex; align-items: center; justify-content: center; font-size: 22px; font-weight: 800; }
    .profile-head h1 { font-size: 22px; font-weight: 800; color: var(--ink); }
    .profile-head .meta { font-size: 13px; color: var(--muted); margin-top: 3px; }

    /* Page title row: a back arrow pinned to the left, then the heading
       and its one-line description stacked to the right of it. */
    .page-top { display: flex; align-items: center; gap: 14px; margin-bottom: 20px; }
    .back-arrow { width: 42px; height: 42px; flex: 0 0 42px; border-radius: 12px; border: 1px solid var(--line); background: var(--surface); color: var(--muted); display: inline-flex; align-items: center; justify-content: center; font-size: 16px; text-decoration: none; transition: border-color .15s ease, color .15s ease, background .15s ease; }
    .back-arrow:hover { border-color: var(--brand); color: var(--brand); background: var(--brand-soft); }
    .page-top-text { min-width: 0; }
    .page-top-text h1 { font-size: 24px; font-weight: 800; color: var(--ink); line-height: 1.2; margin: 0; }
    .page-top-text p { font-size: 13px; color: var(--muted); margin-top: 3px; }

    .pill { display: inline-block; font-size: 11px; font-weight: 800; padding: 3px 10px; border-radius: 20px; }
    .otp-box { background: var(--canvas); border: 1px dashed var(--line); border-radius: 10px; padding: 14px 16px; margin-top: 12px; }
    .otp-box .otp-title { font-size: 13px; font-weight: 700; color: var(--ink); margin-bottom: 10px; }
    .otp-box form { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
    .otp-box input { width: 140px; padding: 10px; border: 1px solid var(--field); border-radius: 8px; font-size: 16px; letter-spacing: 3px; text-align: center; }
    .inline-form { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
    .inline-form .form-group { flex: 1; min-width: 200px; margin-bottom: 0; }
    .hint-line { font-size: 12px; color: var(--muted); margin-top: 8px; }
    .detail-block { border: 1px solid var(--line); border-radius: 10px; overflow: hidden; }
    .detail-row { display: flex; justify-content: space-between; gap: 16px; padding: 11px 14px; border-bottom: 1px solid var(--line); font-size: 13px; }
    .detail-row:last-child { border-bottom: none; }
    .detail-row span:first-child { color: var(--muted); }
    .detail-row strong { color: var(--ink); font-weight: 700; text-align: right; }
</style>

<div class="page-top">
    <a class="back-arrow" href="index.php" aria-label="Back to dashboard" title="Back to dashboard">
        <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
    </a>
    <div class="page-top-text">
        <h1>My profile</h1>
        <p>Manage your account details, contact information and password</p>
    </div>
</div>

<div class="profile-head">
    <div class="profile-avatar"><?= htmlspecialchars($initials) ?></div>
    <div>
        <h1><?= htmlspecialchars($displayName) ?></h1>
        <div class="meta">
            <?= $merchantId !== '' ? htmlspecialchars($merchantId) . ' &middot; ' : '' ?>
            <span class="pill" style="background:<?= ($statusColors[$user['account_status']] ?? '#64748b') ?>1a;color:<?= $statusColors[$user['account_status']] ?? '#64748b' ?>;">
                <?= htmlspecialchars(ucfirst($user['account_status'])) ?>
            </span>
        </div>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <div>
            <h2>Account details</h2>
            <p class="panel-sub">Your name and business information. Changes save straight away.</p>
        </div>
    </div>

    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_details">
        <div class="two-col">
            <div class="form-group">
                <label for="full_name">Full name</label>
                <input id="full_name" type="text" name="full_name" maxlength="150" required
                       value="<?= htmlspecialchars((string)$user['full_name']) ?>">
            </div>
            <div class="form-group">
                <label for="business_name">Business name</label>
                <input id="business_name" type="text" name="business_name" maxlength="180" required
                       value="<?= htmlspecialchars((string)$user['business_name']) ?>">
            </div>
        </div>
        <div class="two-col">
            <div class="form-group">
                <label for="business_type">Business type</label>
                <select id="business_type" name="business_type">
                    <option value="">Not set</option>
                    <?php foreach ($bizTypeOptions as $bt): ?>
                        <option value="<?= htmlspecialchars($bt) ?>" <?= $user['business_type'] === $bt ? 'selected' : '' ?>>
                            <?= htmlspecialchars($bt) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="preferred_support_channel">Preferred support channel</label>
                <select id="preferred_support_channel" name="preferred_support_channel">
                    <option value="">Not set</option>
                    <?php foreach ($supportChannels as $ck => $cl): ?>
                        <option value="<?= $ck ?>" <?= $user['preferred_support_channel'] === $ck ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cl) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <button type="submit" class="submit-btn">Save changes</button>
    </form>
</div>

<div class="panel">
    <div class="panel-head">
        <div>
            <h2>Mobile number</h2>
            <p class="panel-sub">Used for login, OTPs and support calls.</p>
        </div>
    </div>

    <div class="detail-row" style="border:0;padding:0 0 12px;">
        <span>Current</span>
        <strong>
            <?= $user['mobile'] ? htmlspecialchars(mask_tail((string)$user['mobile'])) : 'Not set' ?>
            <?php if ((int)$user['mobile_verified'] === 1): ?>
                <span class="pill" style="background:#dcfce7;color:#16a34a;margin-left:6px;"><i class="fa-solid fa-circle-check"></i> Verified</span>
            <?php else: ?>
                <span class="pill" style="background:#fef3c7;color:#b45309;margin-left:6px;">Unverified</span>
            <?php endif; ?>
        </strong>
    </div>

    <?php if ($mobileTo !== ''): ?>
        <div class="otp-box">
            <div class="otp-title">Enter the code sent to <?= htmlspecialchars(mask_tail($mobileTo)) ?></div>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="verify_mobile_otp">
                <input type="text" name="otp" inputmode="numeric" maxlength="6" pattern="[0-9]{6}"
                       autocomplete="one-time-code" required placeholder="••••••">
                <button type="submit" class="submit-btn">Verify &amp; save</button>
            </form>
            <form method="POST" style="margin-top:8px;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cancel_otp">
                <input type="hidden" name="which" value="mobile">
                <button type="submit" class="btn-ghost">Cancel</button>
            </form>
            <p class="hint-line">Three codes per number every 15 minutes.</p>
        </div>
    <?php else: ?>
        <form method="POST" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="send_mobile_otp">
            <div class="form-group">
                <label for="mobile">New mobile number</label>
                <input id="mobile" type="tel" name="mobile" inputmode="numeric" maxlength="10"
                       pattern="[6-9][0-9]{9}" placeholder="9876543210" required>
            </div>
            <button type="submit" class="submit-btn">Send code</button>
        </form>
        <p class="hint-line">We send a one-time code to the new number to confirm it is yours.</p>
    <?php endif; ?>
</div>

<div class="panel">
    <div class="panel-head">
        <div>
            <h2>Email address</h2>
            <p class="panel-sub">Used for statements, notifications and login.</p>
        </div>
    </div>

    <div class="detail-row" style="border:0;padding:0 0 12px;">
        <span>Current</span>
        <strong>
            <?= htmlspecialchars((string)$user['email']) ?>
            <?php if ((int)$user['email_verified'] === 1): ?>
                <span class="pill" style="background:#dcfce7;color:#16a34a;margin-left:6px;"><i class="fa-solid fa-circle-check"></i> Verified</span>
            <?php else: ?>
                <span class="pill" style="background:#fef3c7;color:#b45309;margin-left:6px;">Unverified</span>
            <?php endif; ?>
        </strong>
    </div>

    <?php if ($emailTo !== ''): ?>
        <div class="otp-box">
            <div class="otp-title">Enter the code sent to <?= htmlspecialchars($emailTo) ?></div>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="verify_email_otp">
                <input type="text" name="otp" inputmode="numeric" maxlength="6" pattern="[0-9]{6}"
                       autocomplete="one-time-code" required placeholder="••••••">
                <button type="submit" class="submit-btn">Verify &amp; save</button>
            </form>
            <form method="POST" style="margin-top:8px;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cancel_otp">
                <input type="hidden" name="which" value="email">
                <button type="submit" class="btn-ghost">Cancel</button>
            </form>
            <p class="hint-line">Three codes per address every 15 minutes.</p>
        </div>
    <?php else: ?>
        <form method="POST" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="send_email_otp">
            <div class="form-group">
                <label for="email">New email address</label>
                <input id="email" type="email" name="email" maxlength="190" placeholder="you@example.com" required>
            </div>
            <button type="submit" class="submit-btn">Send code</button>
        </form>
        <p class="hint-line">We send a one-time code to the new address to confirm it is yours.</p>
    <?php endif; ?>
</div>

<div class="panel">
    <div class="panel-head">
        <div>
            <h2>Password</h2>
            <p class="panel-sub">At least 8 characters. You stay signed in after changing it.</p>
        </div>
    </div>

    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="change_password">
        <div class="form-group">
            <label for="current_password">Current password</label>
            <input id="current_password" type="password" name="current_password" autocomplete="current-password" required>
        </div>
        <div class="two-col">
            <div class="form-group">
                <label for="new_password">New password</label>
                <input id="new_password" type="password" name="new_password" minlength="8" autocomplete="new-password" required>
            </div>
            <div class="form-group">
                <label for="confirm_password">Confirm new password</label>
                <input id="confirm_password" type="password" name="confirm_password" minlength="8" autocomplete="new-password" required>
            </div>
        </div>
        <button type="submit" class="submit-btn">Update password</button>
    </form>
</div>

<div class="panel">
    <div class="panel-head">
        <div>
            <h2>Business profile</h2>
            <p class="panel-sub">Submitted for verification. Contact support to change these.</p>
        </div>
    </div>
    <?php if (!$biz): ?>
        <p style="font-size:13px;color:var(--muted);">No business details on file yet.</p>
    <?php else: ?>
        <div class="detail-block">
            <div class="detail-row"><span>Verification status</span><strong><?= htmlspecialchars(ucfirst(str_replace('_', ' ', (string)$biz['status']))) ?></strong></div>
            <div class="detail-row"><span>GSTIN</span><strong><?= htmlspecialchars($biz['gstin'] ?: '—') ?></strong></div>
            <div class="detail-row"><span>Business address</span><strong><?= htmlspecialchars($biz['business_address'] ?: '—') ?></strong></div>
            <div class="detail-row"><span>Years in business</span><strong><?= $biz['years_in_business'] !== null ? htmlspecialchars((string)(float)$biz['years_in_business']) : '—' ?></strong></div>
            <div class="detail-row"><span>Monthly turnover</span><strong><?= htmlspecialchars($turnoverLabels[$biz['monthly_turnover'] ?? ''] ?? '—') ?></strong></div>
            <div class="detail-row"><span>Bank account</span><strong><?= $biz['bank_account_number'] ? htmlspecialchars(mask_tail((string)$biz['bank_account_number'], 4)) : '—' ?></strong></div>
            <div class="detail-row"><span>IFSC</span><strong><?= htmlspecialchars($biz['ifsc_code'] ?: '—') ?></strong></div>
        </div>
    <?php endif; ?>
</div>

<div class="panel">
    <div class="panel-head">
        <div>
            <h2>Settlement &amp; payouts</h2>
            <p class="panel-sub">Your payout account, managed during account setup.</p>
        </div>
    </div>
    <?php if ($settlement): ?>
        <div class="detail-block" style="margin-bottom:14px;">
            <div class="detail-row"><span>Account holder</span><strong><?= htmlspecialchars($settlement['account_holder']) ?></strong></div>
            <div class="detail-row"><span>Bank</span><strong><?= htmlspecialchars($settlement['bank_name'] ?: '—') ?></strong></div>
            <div class="detail-row"><span>Account number</span><strong><?= htmlspecialchars(mask_tail((string)$settlement['account_number'], 4)) ?></strong></div>
            <div class="detail-row"><span>IFSC</span><strong><?= htmlspecialchars($settlement['ifsc_code']) ?></strong></div>
        </div>
    <?php endif; ?>
    <?php if ($prefs): ?>
        <div class="detail-block">
            <div class="detail-row"><span>Payout frequency</span><strong><?= htmlspecialchars(ucfirst((string)$prefs['payout_frequency'])) ?></strong></div>
            <?php if (!empty($prefs['payout_day'])): ?>
                <div class="detail-row"><span>Payout day</span><strong><?= htmlspecialchars((string)$prefs['payout_day']) ?></strong></div>
            <?php endif; ?>
            <div class="detail-row"><span>Minimum threshold</span><strong>₹<?= number_format((float)$prefs['minimum_threshold'], 0) ?></strong></div>
            <div class="detail-row"><span>Payout notifications</span><strong><?= (int)$prefs['notify_sms'] ? 'SMS' : '' ?><?= ((int)$prefs['notify_sms'] && (int)$prefs['notify_email']) ? ' + ' : '' ?><?= (int)$prefs['notify_email'] ? 'Email' : '' ?><?= (!(int)$prefs['notify_sms'] && !(int)$prefs['notify_email']) ? 'None' : '' ?></strong></div>
        </div>
    <?php endif; ?>
    <p class="hint-line">
        To change your settlement account or payout preferences, use
        <a href="../account_setup/setup.php" style="color:var(--brand);font-weight:700;">account setup</a>.
    </p>
</div>

<div class="panel">
    <div class="panel-head">
        <div>
            <h2>Verification &amp; limits</h2>
            <p class="panel-sub">Set by our review team. Read-only here.</p>
        </div>
    </div>
    <div class="detail-block">
        <div class="detail-row"><span>Merchant ID</span><strong><?= $merchantId !== '' ? htmlspecialchars($merchantId) : '—' ?></strong></div>
        <div class="detail-row"><span>Account status</span><strong><?= htmlspecialchars(ucfirst((string)$user['account_status'])) ?></strong></div>
        <div class="detail-row"><span>KYC status</span><strong><?= htmlspecialchars(ucfirst(str_replace('_', ' ', (string)$user['kyc_status']))) ?></strong></div>
        <div class="detail-row"><span>Business verification</span><strong><?= htmlspecialchars(ucfirst(str_replace('_', ' ', (string)$user['business_verification_status']))) ?></strong></div>
        <div class="detail-row"><span>Risk score</span><strong><?= $user['risk_score'] !== null ? (int)$user['risk_score'] . '/100' : 'Not scored' ?><?= !empty($user['risk_category']) ? ' (' . htmlspecialchars(ucfirst((string)$user['risk_category'])) . ')' : '' ?></strong></div>
        <div class="detail-row"><span>Credit limit</span><strong>₹<?= number_format((float)$user['credit_limit'], 0) ?></strong></div>
        <div class="detail-row"><span>Member since</span><strong><?= htmlspecialchars(date('d M Y', strtotime((string)$user['created_at']))) ?></strong></div>
    </div>
</div>

<?php render_shell_close(); ?>
