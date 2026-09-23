<?php
/**
 * Per-agent settings only the admin can set: commission rate and role
 * designation (Field Agent / Sales Agent / Senior Agent / Service Agent).
 *
 * Commission already worked this way - agent/wallet/index.php refuses to
 * let an agent set their own rate, and agent_commission_payout is only
 * ever written from here. Role designation used to be the opposite: a
 * dropdown on agent/background/index.php let the agent pick their own
 * title during onboarding. That's been removed - agent_verification.role
 * now stays NULL until set here, exactly like commission_rate already did,
 * so both post-onboarding attributes are assigned the same way, in the
 * same place, after the agent is actually active.
 */

declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/shell.php';

$admin = require_admin($pdo);

// Same table agent/wallet/index.php creates, defensively repeated here so
// this page works even if no agent has ever opened their wallet yet.
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS agent_commission_payout (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL UNIQUE,
        commission_type VARCHAR(50) NOT NULL DEFAULT 'Percentage',
        commission_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        account_holder VARCHAR(150) NOT NULL DEFAULT '',
        account_number VARCHAR(100) NOT NULL DEFAULT '',
        bank_name VARCHAR(150) NOT NULL DEFAULT '',
        ifsc_code VARCHAR(30) NOT NULL DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )"
);

const AGENT_ROLES = ['Field Agent', 'Sales Agent', 'Senior Agent', 'Service Agent'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action  = $_POST['action'] ?? '';
    $agentId = post_int('user_id');

    $chk = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'agent'");
    $chk->execute([$agentId]);
    if (!$chk->fetch()) {
        flash('error', 'That is not an agent account.');
        redirect(BASE_URL . '/admin/agent_settings.php');
    }

    if ($action === 'set_commission') {
        $rate = (float)($_POST['commission_rate'] ?? -1);
        if ($rate < 0 || $rate > 100) {
            flash('error', 'Commission percentage must be between 0 and 100.');
        } else {
            $pdo->prepare(
                "INSERT INTO agent_commission_payout (user_id, commission_type, commission_rate)
                 VALUES (?, 'Percentage', ?)
                 ON DUPLICATE KEY UPDATE commission_rate = VALUES(commission_rate)"
            )->execute([$agentId, $rate]);
            audit_log($pdo, 'agent.commission_set', 'agent_commission_payout', (string)$agentId, null, ['rate' => $rate]);
            flash('success', 'Commission rate saved.');
        }
    } elseif ($action === 'set_role') {
        $role = post_str('role', 60);
        if (!in_array($role, AGENT_ROLES, true)) {
            flash('error', 'Choose one of the listed roles.');
        } else {
            // agent_verification's other columns are all nullable, so this
            // is safe even for an agent who has not submitted their own
            // background verification details yet - the row just picks
            // up the rest once they do, via agent/background/index.php's
            // own ON DUPLICATE KEY UPDATE, which no longer touches role.
            $pdo->prepare(
                "INSERT INTO agent_verification (agent_id, role) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE role = VALUES(role)"
            )->execute([$agentId, $role]);
            audit_log($pdo, 'agent.role_set', 'agent_verification', (string)$agentId, null, ['role' => $role]);
            flash('success', 'Role saved.');
        }
    }
    redirect(BASE_URL . '/admin/agent_settings.php');
}

$agents = $pdo->query(
    "SELECT u.id, u.full_name, u.party_code, u.account_status,
            p.commission_rate, v.role
       FROM users u
       LEFT JOIN agent_commission_payout p ON p.user_id = u.id
       LEFT JOIN agent_verification v ON v.agent_id = u.id
      WHERE u.role = 'agent'
      ORDER BY u.full_name"
)->fetchAll();

admin_shell_open($pdo, $admin, 'agents', 'Agent settings');
?>
<h1 class="admin-h1">Agent settings</h1>
<p class="admin-sub">Commission rate and role designation, set here after onboarding. Agents cannot change either themselves.</p>

<section class="panel">
    <table class="table">
        <thead><tr>
            <th scope="col">Agent</th><th scope="col">Agent ID</th>
            <th scope="col">Status</th><th scope="col">Commission %</th>
            <th scope="col">Role</th>
        </tr></thead>
        <tbody>
        <?php if (!$agents): ?>
            <tr><td colspan="5" style="color:var(--muted);">No agents yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($agents as $a): ?>
            <tr>
                <td><?= e($a['full_name']) ?></td>
                <td><?= e($a['party_code'] ?: '—') ?></td>
                <td><span class="pill <?= $a['account_status'] === 'active' ? 'is-ok' : 'is-warn' ?>">
                    <?= e($a['account_status']) ?></span></td>
                <td>
                    <form method="post" style="display:flex;gap:6px;align-items:center;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="set_commission">
                        <input type="hidden" name="user_id" value="<?= (int)$a['id'] ?>">
                        <input type="number" name="commission_rate" min="0" max="100" step="0.01"
                               value="<?= e($a['commission_rate'] !== null ? (string)$a['commission_rate'] : '') ?>"
                               placeholder="e.g. 7" style="width:80px;font-size:13px;padding:4px 6px;">
                        <button class="btn secondary" type="submit" style="min-height:30px;font-size:12px;padding:0 10px;">Save</button>
                    </form>
                </td>
                <td>
                    <form method="post" style="display:flex;gap:6px;align-items:center;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="set_role">
                        <input type="hidden" name="user_id" value="<?= (int)$a['id'] ?>">
                        <select name="role" onchange="this.form.submit()" style="font-size:12px;padding:4px 6px;">
                            <option value="">— Not assigned —</option>
                            <?php foreach (AGENT_ROLES as $r): ?>
                                <option value="<?= e($r) ?>" <?= $a['role'] === $r ? 'selected' : '' ?>><?= e($r) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <noscript><button class="btn secondary" type="submit" style="min-height:26px;font-size:11px;padding:0 8px;">Save</button></noscript>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php admin_shell_close(); ?>
