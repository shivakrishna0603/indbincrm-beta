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
    $category = post_str('category', 60);
    $subject  = post_str('subject', 180);
    $body     = post_str('body', 4000);

    if ($category === '' || $subject === '' || $body === '') {
        flash('error', 'Fill in the category, subject and description.');
    } else {
        $code = 'TKT' . date('ymd') . strtoupper(bin2hex(random_bytes(3)));
        $pdo->prepare("INSERT INTO support_tickets (user_id, ticket_code, category, subject, body)
                       VALUES (?,?,?,?,?)")
            ->execute([$uid, $code, $category, $subject, $body]);
        audit_log($pdo, 'support.ticket_raised', 'support_tickets', $code);
        flash('success', 'Ticket ' . $code . ' raised.');
    }
    redirect(CUST_BASE . '/dashboard/support.php');
}

$stmt = $pdo->prepare("SELECT * FROM support_tickets WHERE user_id = ? ORDER BY id DESC");
$stmt->execute([$uid]);
$rows = $stmt->fetchAll();

portal_shell_open($pdo, $user, 'support', 'Support');
?>
<div class="card wide" style="margin-bottom:20px;">
    <h2 style="font-size:16px;font-weight:800;color:var(--ink);margin-bottom:14px;">Raise a ticket</h2>
    <form method="post">
        <?= csrf_field() ?>
        <div class="field">
            <label for="category">What is it about</label>
            <select id="category" name="category" required>
                <option value="" disabled selected>Choose one</option>
                <option value="KYC">Identity verification</option>
                <option value="Payments">Payments and repayments</option>
                <option value="Credit">Credit limit</option>
                <option value="Rewards">Rewards and points</option>
                <option value="Account">Account and login</option>
                <option value="Other">Something else</option>
            </select>
        </div>
        <div class="field">
            <label for="subject">Subject</label>
            <input id="subject" type="text" name="subject" required maxlength="180">
        </div>
        <div class="field">
            <label for="body">What happened</label>
            <textarea id="body" name="body" required></textarea>
        </div>
        <button class="btn">Submit</button>
    </form>
</div>

<div class="card wide">
    <table class="table">
        <thead><tr><th scope="col">Ticket</th><th scope="col">Subject</th>
        <th scope="col">Raised</th><th scope="col">Status</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="4" style="color:var(--muted);">No tickets.</td></tr><?php endif; ?>
        <?php foreach ($rows as $t): ?>
            <tr>
                <td><?= e($t['ticket_code']) ?></td>
                <td><?= e($t['subject']) ?><br><span style="font-size:12px;color:var(--faint);"><?= e($t['category']) ?></span></td>
                <td><?= e(date('j M Y', strtotime($t['created_at']))) ?></td>
                <td><span class="pill <?= in_array($t['status'], ['resolved','closed'], true) ? 'is-ok' : 'is-warn' ?>">
                    <?= e(str_replace('_', ' ', $t['status'])) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php portal_shell_close(); ?>
