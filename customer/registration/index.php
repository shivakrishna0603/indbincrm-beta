<?php
/**
 * Step 1: Registration.
 *
 * The account row is created by the root register.php. What is missing
 * there, and what happens here, is the mobile verification the flow chart
 * calls for ("Customer signs up ... Mobile Number Verification (OTP)").
 *
 * In the current tree the OTP is a JavaScript alert comparing against the
 * literal 441704, so the mobile number is recorded but never verified, and
 * index.php's login query matches on a mobile column that registration
 * never populates.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
require_once CUST_ROOT . '/portal/shell.php';

$user = require_customer($pdo);

if ((int)$user['mobile_verified'] === 1 && (int)$user['onboarding_step'] > 1) {
    redirect(step_url(effective_step($pdo, $user)));
}

$mobile    = (string)($user['mobile'] ?? '');
$otpSent   = !empty($_SESSION['otp_sent_to']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = post_str('action', 20);

    if ($action === 'send_otp') {
        $mobile = preg_replace('/\D/', '', post_str('mobile', 15));

        if (!valid_mobile($mobile)) {
            flash('error', 'Enter a 10-digit Indian mobile number.');
        } else {
            // Reject a number already tied to another account.
            $stmt = $pdo->prepare("SELECT id FROM users WHERE mobile = ? AND id <> ?");
            $stmt->execute([$mobile, (int)$user['id']]);
            if ($stmt->fetch()) {
                flash('error', 'That mobile number is registered to another account.');
            } elseif (($otp = otp_issue($pdo, (int)$user['id'], 'registration', $mobile)) === null) {
                flash('error', 'Too many codes requested. Try again in 15 minutes.');
            } else {
                $_SESSION['otp_sent_to'] = $mobile;
                flash('info', 'Code sent to ' . mask_tail($mobile, 4) . '. It expires in five minutes.');
                if (APP_ENV === 'local') {
                    // No SMS gateway on localhost.
                    flash('warn', 'Local mode: your code is ' . $otp);
                }
            }
        }
        redirect(CUST_BASE . '/registration/index.php');
    }

    if ($action === 'verify_otp') {
        $target = (string)($_SESSION['otp_sent_to'] ?? '');
        $otp    = preg_replace('/\D/', '', post_str('otp', 6));

        if ($target === '') {
            flash('error', 'Request a code first.');
        } elseif (!otp_verify($pdo, 'registration', $target, $otp)) {
            flash('error', 'That code is wrong or has expired.');
        } else {
            $pdo->prepare("UPDATE users SET mobile = ?, mobile_verified = 1 WHERE id = ?")
                ->execute([$target, (int)$user['id']]);
            unset($_SESSION['otp_sent_to']);

            record_consent($pdo, (int)$user['id'], 'dpdp_notice', true, '1.0');
            audit_log($pdo, 'mobile.verified', 'users', (string)$user['id'], null, ['mobile' => mask_tail($target)]);
            advance_step($pdo, (int)$user['id'], 2);

            flash('success', 'Mobile number verified.');
            redirect(step_url(2));
        }
        redirect(CUST_BASE . '/registration/index.php');
    }
}

onboarding_shell_open($pdo, $user, 1, 'Verify your mobile number');
?>
<div class="card">
    <div class="card-icon"><i class="fa-solid fa-mobile-screen-button"></i></div>
    <div class="card-head">
        <h2>Verify your mobile number</h2>
        <p>We send a six-digit code. Your number becomes your login and the channel for transaction alerts.</p>
    </div>

    <form method="post" action="<?= CUST_BASE ?>/registration/index.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="send_otp">
        <div class="field">
            <label for="mobile">Mobile number</label>
            <div class="inline-field">
                <input id="mobile" type="tel" name="mobile" inputmode="numeric" maxlength="10"
                       pattern="[6-9][0-9]{9}" required
                       value="<?= e($_SESSION['otp_sent_to'] ?? $mobile) ?>"
                       placeholder="9876543210">
                <button type="submit" class="btn"><?= $otpSent ? 'Resend' : 'Send code' ?></button>
            </div>
            <p class="hint">Three codes per number every 15 minutes.</p>
        </div>
    </form>

    <?php if ($otpSent): ?>
    <form method="post" action="<?= CUST_BASE ?>/registration/index.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="verify_otp">
        <div class="field">
            <label for="otp">Enter the code</label>
            <input id="otp" type="text" name="otp" inputmode="numeric" maxlength="6"
                   pattern="[0-9]{6}" autocomplete="one-time-code" required placeholder="••••••">
        </div>
        <button type="submit" class="btn block">Verify and continue</button>
    </form>
    <?php endif; ?>
</div>
<?php onboarding_shell_close(); ?>
