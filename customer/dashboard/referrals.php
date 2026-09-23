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
    $name  = post_str('referee_name', 120);
    $phone = preg_replace('/\D/', '', post_str('referee_phone', 15));

    if ($name === '' || !valid_mobile($phone)) {
        flash('error', 'Enter a name and a 10-digit mobile number.');
    } elseif ($phone === (string)$user['mobile']) {
        flash('error', 'You cannot refer yourself.');
    } else {
        try {
            // UNIQUE (user_id, referee_phone) stops the same person being
            // referred twice for two lots of points.
            $pdo->prepare("INSERT INTO referrals (user_id, referee_name, referee_phone, status, reward_points)
                           VALUES (?,?,?, 'invited', 250)")
                ->execute([$uid, $name, $phone]);
            flash('success', 'Invitation recorded. Points land once they finish onboarding.');
        } catch (PDOException $ex) {
            flash('error', ((int)$ex->getCode() === 23000)
                ? 'You have already referred that number.'
                : 'Could not save the referral.');
        }
    }
    redirect(CUST_BASE . '/dashboard/referrals.php');
}

$stmt = $pdo->prepare("SELECT * FROM referrals WHERE user_id = ? ORDER BY id DESC");
$stmt->execute([$uid]);
$rows = $stmt->fetchAll();

portal_shell_open($pdo, $user, 'referrals', 'Referrals');
?>
<div class="card wide" style="margin-bottom:20px;">
    <p style="font-size:13px;color:var(--muted);margin-bottom:16px;">
        250 points when someone you refer completes onboarding. Nothing is paid at invitation.
    </p>
    <form method="post">
        <?= csrf_field() ?>
        <div class="field">
            <label for="referee_name">Their name</label>
            <input id="referee_name" type="text" name="referee_name" required>
        </div>
        <div class="field">
            <label for="referee_phone">Their mobile</label>
            <input id="referee_phone" type="tel" name="referee_phone" inputmode="numeric"
                   maxlength="10" pattern="[6-9][0-9]{9}" required>
        </div>
        <button class="btn">Send invitation</button>
    </form>
</div>

<div class="card wide">
    <table class="table">
        <thead><tr><th scope="col">Name</th><th scope="col">Mobile</th>
        <th scope="col">Invited</th><th scope="col">Status</th>
        <th scope="col" style="text-align:right;">Points</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="5" style="color:var(--muted);">No referrals yet.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= e($r['referee_name']) ?></td>
                <td><?= e(mask_tail((string)$r['referee_phone'])) ?></td>
                <td><?= e(date('j M Y', strtotime($r['created_at']))) ?></td>
                <td><span class="pill <?= $r['status'] === 'onboarded' ? 'is-ok' : 'is-warn' ?>"><?= e($r['status']) ?></span></td>
                <td style="text-align:right;"><?= $r['rewarded_at'] ? (int)$r['reward_points'] : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php portal_shell_close(); ?>
