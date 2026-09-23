<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/shell.php';

$agent = require_agent($pdo);
$agentId = (int)($agent['id'] ?? 0);

if ($agentId <= 0) {
    exit('Agent session not found.');
}

function esc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$today = date('Y-m-d');
$message = '';
$messageType = '';

/*
 * FOLLOW-UP FLOW
 * 1. Admin assigns customer to agent.
 * 2. Agent sees the assigned customer's details/application.
 * 3. Agent schedules the next customer action.
 * 4. Follow-up remains pending until completed/cancelled.
 * 5. Completed/cancelled items move to history.
 */

/* Create follow-up storage if it does not exist. */
$pdo->exec("
    CREATE TABLE IF NOT EXISTS agent_followups (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id BIGINT UNSIGNED NOT NULL,
        agent_id INT UNSIGNED NOT NULL,
        application_id BIGINT UNSIGNED NULL,
        follow_up_date DATE NOT NULL,
        follow_up_type VARCHAR(60) NOT NULL DEFAULT 'Customer Call',
        next_action VARCHAR(255) NULL,
        notes TEXT NULL,
        status ENUM('pending','completed','cancelled') NOT NULL DEFAULT 'pending',
        completed_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_agent (agent_id),
        INDEX idx_customer (customer_id),
        INDEX idx_date (follow_up_date),
        INDEX idx_status (status)
    ) ENGINE=InnoDB
");

/*
 * Assigned customers.
 * The customer is considered available when Admin assigned the customer
 * OR an application belonging to that agent exists.
 */
$stmt = $pdo->prepare("
    SELECT DISTINCT
        c.id AS customer_id,
        c.user_id AS customer_user_id,
        c.name,
        c.mobile,
        c.email,
        c.requirement,
        u.customer_code,

        a.id AS application_id,
        a.application_number,
        a.product_name,
        a.amount,
        a.status AS application_status,
        a.current_stage

    FROM customers c

    LEFT JOIN users u
        ON u.id = c.user_id

    LEFT JOIN customer_agent_assignments ca
        ON ca.customer_id = c.id
        AND ca.agent_user_id = ?
        AND ca.status = 'active'

    LEFT JOIN applications a
        ON a.id = (
            SELECT a2.id
            FROM applications a2
            WHERE a2.customer_id = c.user_id
              AND a2.agent_id = ?
            ORDER BY a2.id DESC
            LIMIT 1
        )

    WHERE ca.id IS NOT NULL
       OR a.id IS NOT NULL

    ORDER BY c.name ASC
");

$stmt->execute([$agentId, $agentId]);
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Add follow-up. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'add') {
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $applicationId = (int)($_POST['application_id'] ?? 0);
        $date = trim((string)($_POST['follow_up_date'] ?? ''));
        $type = trim((string)($_POST['follow_up_type'] ?? ''));
        $nextAction = trim((string)($_POST['next_action'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));

        /* Confirm the selected customer belongs to this agent. */
        $check = $pdo->prepare("
            SELECT c.id
            FROM customers c
            LEFT JOIN customer_agent_assignments ca
                ON ca.customer_id = c.id
                AND ca.agent_user_id = ?
                AND ca.status = 'active'
            LEFT JOIN applications a
                ON a.customer_id = c.user_id
                AND a.agent_id = ?
            WHERE c.id = ?
              AND (ca.id IS NOT NULL OR a.id IS NOT NULL)
            LIMIT 1
        ");
        $check->execute([$agentId, $agentId, $customerId]);

        if (!$check->fetchColumn()) {
            $message = 'The selected customer is not assigned or linked to you.';
            $messageType = 'error';
        } elseif ($date === '') {
            $message = 'Please select a follow-up date.';
            $messageType = 'error';
        } elseif ($date < $today) {
            $message = 'Follow-up date cannot be before today.';
            $messageType = 'error';
        } elseif ($type === '') {
            $message = 'Please select the follow-up type.';
            $messageType = 'error';
        } else {
            /* Application is optional, but if supplied it must belong to this customer/agent. */
            $validApplicationId = null;

            if ($applicationId > 0) {
                $appCheck = $pdo->prepare("
                    SELECT a.id
                    FROM applications a
                    INNER JOIN customers c
                        ON c.user_id = a.customer_id
                    WHERE a.id = ?
                      AND c.id = ?
                      AND a.agent_id = ?
                    LIMIT 1
                ");
                $appCheck->execute([
                    $applicationId,
                    $customerId,
                    $agentId
                ]);

                $validApplicationId = $appCheck->fetchColumn() ?: null;
            }

            $insert = $pdo->prepare("
                INSERT INTO agent_followups
                (
                    customer_id,
                    agent_id,
                    application_id,
                    follow_up_date,
                    follow_up_type,
                    next_action,
                    notes
                )
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            $insert->execute([
                $customerId,
                $agentId,
                $validApplicationId,
                $date,
                $type,
                $nextAction !== '' ? $nextAction : null,
                $notes !== '' ? $notes : null
            ]);

            $message = 'Follow-up scheduled successfully.';
            $messageType = 'success';
        }
    }

    /* Complete or cancel an existing pending follow-up. */
    if ($action === 'complete' || $action === 'cancel') {
        $followupId = (int)($_POST['followup_id'] ?? 0);

        if ($followupId > 0) {
            if ($action === 'complete') {
                $q = $pdo->prepare("
                    UPDATE agent_followups
                    SET status = 'completed',
                        completed_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                      AND agent_id = ?
                      AND status = 'pending'
                ");
                $q->execute([$followupId, $agentId]);

                $message = $q->rowCount()
                    ? 'Follow-up completed.'
                    : 'Follow-up could not be completed.';
            } else {
                $q = $pdo->prepare("
                    UPDATE agent_followups
                    SET status = 'cancelled'
                    WHERE id = ?
                      AND agent_id = ?
                      AND status = 'pending'
                ");
                $q->execute([$followupId, $agentId]);

                $message = $q->rowCount()
                    ? 'Follow-up cancelled.'
                    : 'Follow-up could not be cancelled.';
            }

            $messageType = $q->rowCount() ? 'success' : 'error';
        }
    }
}

/* Reload customers after POST so the page always has current data. */
$stmt = $pdo->prepare("
    SELECT DISTINCT
        c.id AS customer_id,
        c.user_id AS customer_user_id,
        c.name,
        c.mobile,
        c.email,
        c.requirement,
        u.customer_code,
        a.id AS application_id,
        a.application_number,
        a.product_name,
        a.amount,
        a.status AS application_status,
        a.current_stage
    FROM customers c
    LEFT JOIN users u ON u.id = c.user_id
    LEFT JOIN customer_agent_assignments ca
        ON ca.customer_id = c.id
        AND ca.agent_user_id = ?
        AND ca.status = 'active'
    LEFT JOIN applications a
        ON a.id = (
            SELECT a2.id
            FROM applications a2
            WHERE a2.customer_id = c.user_id
              AND a2.agent_id = ?
            ORDER BY a2.id DESC
            LIMIT 1
        )
    WHERE ca.id IS NOT NULL OR a.id IS NOT NULL
    ORDER BY c.name ASC
");
$stmt->execute([$agentId, $agentId]);
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Pending follow-ups. */
$stmt = $pdo->prepare("
    SELECT
        f.id,
        f.customer_id,
        f.application_id,
        f.follow_up_date,
        f.follow_up_type,
        f.next_action,
        f.notes,
        c.name AS customer_name,
        c.mobile AS customer_mobile,
        u.customer_code,
        a.application_number,
        a.product_name,
        a.status AS application_status,
        a.current_stage
    FROM agent_followups f
    INNER JOIN customers c ON c.id = f.customer_id
    LEFT JOIN users u ON u.id = c.user_id
    LEFT JOIN applications a ON a.id = f.application_id
    WHERE f.agent_id = ?
      AND f.status = 'pending'
    ORDER BY f.follow_up_date ASC, f.id ASC
");
$stmt->execute([$agentId]);
$pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Recent history. */
$stmt = $pdo->prepare("
    SELECT
        f.follow_up_date,
        f.follow_up_type,
        f.next_action,
        f.status,
        f.updated_at,
        c.name AS customer_name,
        u.customer_code
    FROM agent_followups f
    INNER JOIN customers c ON c.id = f.customer_id
    LEFT JOIN users u ON u.id = c.user_id
    WHERE f.agent_id = ?
      AND f.status IN ('completed','cancelled')
    ORDER BY f.updated_at DESC
    LIMIT 10
");
$stmt->execute([$agentId]);
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);

$overdue = 0;
$todayCount = 0;
$upcoming = 0;

foreach ($pending as $f) {
    if ($f['follow_up_date'] < $today) {
        $overdue++;
    } elseif ($f['follow_up_date'] === $today) {
        $todayCount++;
    } else {
        $upcoming++;
    }
}

agent_shell_open($pdo, $agentId, 'Follow-ups', 'followups.php');
?>

<style>
.followups{width:100%}
.head{margin-bottom:20px}
.head h1{margin:0;font-size:27px;color:#172033;font-weight:800}
.head p{margin:6px 0 0;color:#718096;font-size:13px}

.msg{padding:11px 14px;border-radius:8px;margin-bottom:16px;font-size:13px;font-weight:600}
.success{background:#dcfce7;color:#166534}
.error{background:#fee2e2;color:#991b1b}

.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px}
.stat,.box{background:#fff;border:1px solid #e5e9f0;border-radius:12px}
.stat{padding:17px}
.stat span{display:block;color:#718096;font-size:12px;margin-bottom:6px}
.stat strong{font-size:24px;color:#172033}

.box{margin-bottom:20px;overflow:hidden}
.box-head{padding:16px 19px;border-bottom:1px solid #edf0f4}
.box-head h2{margin:0;color:#172033;font-size:16px}
.box-head p{margin:5px 0 0;color:#718096;font-size:12px}

.form{padding:19px}
.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:15px}
.field{display:flex;flex-direction:column}
.full{grid-column:1/-1}
label{margin-bottom:6px;color:#344563;font-size:12px;font-weight:700}
input,select,textarea{
    width:100%;box-sizing:border-box;padding:10px;
    border:1px solid #d8dee8;border-radius:7px;
    background:#fff;color:#172033;font-size:13px;outline:0
}
textarea{min-height:75px;resize:vertical}
input:focus,select:focus,textarea:focus{border-color:#16a34a}

.customer-info{
    grid-column:1/-1;
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:10px;
    padding:13px;
    background:#f8fafc;
    border-radius:9px;
}
.info span{display:block;color:#718096;font-size:10px;margin-bottom:3px}
.info strong{color:#172033;font-size:12px;word-break:break-word}

.actions{display:flex;justify-content:flex-end;margin-top:15px}
.btn{
    border:0;border-radius:7px;background:#16a34a;color:#fff;
    padding:10px 16px;font-size:12px;font-weight:800;cursor:pointer
}
.btn:hover{background:#15803d}

.table{overflow-x:auto}
table{width:100%;border-collapse:collapse}
th{padding:11px 14px;background:#f8fafc;color:#68758b;font-size:11px;text-align:left}
td{padding:13px 14px;border-top:1px solid #edf0f4;color:#344563;font-size:13px;vertical-align:middle}
.name{font-weight:800;color:#172033}
.sub{display:block;margin-top:3px;color:#94a3b8;font-size:10px}
.date{font-weight:700}
.date.overdue{color:#dc2626}
.date.today{color:#c2410c}
.date.upcoming{color:#15803d}
.app{font-weight:700;color:#172033}
.product{display:block;color:#718096;font-size:11px;margin-top:3px}
.row-actions{display:flex;gap:6px}
.small{
    padding:7px 10px;border:1px solid #d8dee8;
    border-radius:7px;background:#fff;color:#344563;
    font-size:11px;font-weight:700;cursor:pointer
}
.small:hover{border-color:#16a34a;color:#15803d}
.small.cancel:hover{border-color:#dc2626;color:#b91c1c}

.empty{padding:38px 20px;text-align:center;color:#718096;font-size:13px}
.empty strong{display:block;margin-bottom:5px;color:#344563;font-size:14px}

@media(max-width:850px){
    .stats,.grid,.customer-info{grid-template-columns:1fr}
    .full{grid-column:auto}
}
</style>

<div class="followups">

    <div class="head">
        <h1>Follow-ups</h1>
        <p>Manage the next action required for your assigned customers.</p>
    </div>

    <?php if ($message !== ''): ?>
        <div class="msg <?= esc($messageType) ?>">
            <?= esc($message) ?>
        </div>
    <?php endif; ?>

    <div class="stats">
        <div class="stat">
            <span>Overdue</span>
            <strong><?= $overdue ?></strong>
        </div>
        <div class="stat">
            <span>Today</span>
            <strong><?= $todayCount ?></strong>
        </div>
        <div class="stat">
            <span>Upcoming</span>
            <strong><?= $upcoming ?></strong>
        </div>
    </div>

    <div class="box">
        <div class="box-head">
            <h2>Schedule Follow-up</h2>
            <p>Select an assigned customer and record the next action.</p>
        </div>

        <?php if (!$customers): ?>
            <div class="empty">
                <strong>No customers assigned</strong>
                Customers assigned by Admin will appear here.
            </div>
        <?php else: ?>

            <form method="post" class="form">
                <input type="hidden" name="action" value="add">

                <div class="grid">

                    <div class="field">
                        <label>Customer *</label>
                        <select name="customer_id" id="customer_id" required>
                            <option value="">Select customer</option>

                            <?php foreach ($customers as $c): ?>
                                <option
                                    value="<?= (int)$c['customer_id'] ?>"
                                    data-mobile="<?= esc($c['mobile'] ?? '') ?>"
                                    data-email="<?= esc($c['email'] ?? '') ?>"
                                    data-requirement="<?= esc($c['requirement'] ?? '') ?>"
                                    data-application="<?= (int)($c['application_id'] ?? 0) ?>"
                                    data-application-number="<?= esc($c['application_number'] ?? '') ?>"
                                    data-product="<?= esc($c['product_name'] ?? '') ?>"
                                    data-status="<?= esc($c['application_status'] ?? '') ?>"
                                    data-stage="<?= esc($c['current_stage'] ?? '') ?>"
                                >
                                    <?= esc($c['name']) ?>
                                    <?= !empty($c['customer_code']) ? ' - ' . esc($c['customer_code']) : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label>Follow-up Date *</label>
                        <input
                            type="date"
                            name="follow_up_date"
                            min="<?= esc($today) ?>"
                            required
                        >
                    </div>

                    <div class="customer-info" id="customerInfo" style="display:none">
                        <div class="info">
                            <span>Mobile</span>
                            <strong id="infoMobile">—</strong>
                        </div>
                        <div class="info">
                            <span>Email</span>
                            <strong id="infoEmail">—</strong>
                        </div>
                        <div class="info">
                            <span>Requirement</span>
                            <strong id="infoRequirement">—</strong>
                        </div>
                        <div class="info">
                            <span>Application</span>
                            <strong id="infoApplication">—</strong>
                        </div>
                        <div class="info">
                            <span>Product</span>
                            <strong id="infoProduct">—</strong>
                        </div>
                        <div class="info">
                            <span>Application Status</span>
                            <strong id="infoStatus">—</strong>
                        </div>
                        <div class="info">
                            <span>Current Stage</span>
                            <strong id="infoStage">—</strong>
                        </div>
                    </div>

                    <input type="hidden" name="application_id" id="application_id">

                    <div class="field">
                        <label>Follow-up Type *</label>
                        <select name="follow_up_type" required>
                            <option value="">Select type</option>
                            <option value="Customer Call">Customer Call</option>
                            <option value="Document Collection">Document Collection</option>
                            <option value="Application Status">Application Status</option>
                            <option value="Eligibility Discussion">Eligibility Discussion</option>
                            <option value="Customer Update">Customer Update</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>

                    <div class="field">
                        <label>Next Action *</label>
                        <input
                            type="text"
                            name="next_action"
                            placeholder="Example: Collect income proof"
                            required
                        >
                    </div>

                    <div class="field full">
                        <label>Notes</label>
                        <textarea
                            name="notes"
                            placeholder="Add details from the customer conversation or next step..."
                        ></textarea>
                    </div>

                </div>

                <div class="actions">
                    <button class="btn" type="submit">Schedule Follow-up</button>
                </div>
            </form>

        <?php endif; ?>
    </div>

    <div class="box">
        <div class="box-head">
            <h2>Pending Follow-ups</h2>
            <p>Customer actions that are still pending.</p>
        </div>

        <?php if (!$pending): ?>
            <div class="empty">
                <strong>No pending follow-ups</strong>
                Scheduled follow-ups will appear here.
            </div>
        <?php else: ?>

            <div class="table">
                <table>
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Date</th>
                            <th>Purpose</th>
                            <th>Application</th>
                            <th>Next Action</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($pending as $f): ?>
                        <?php
                        $d = (string)$f['follow_up_date'];
                        $dateClass = $d < $today
                            ? 'overdue'
                            : ($d === $today ? 'today' : 'upcoming');
                        ?>
                        <tr>
                            <td>
                                <span class="name"><?= esc($f['customer_name']) ?></span>
                                <span class="sub"><?= esc($f['customer_code'] ?? '') ?></span>
                                <span class="sub"><?= esc($f['customer_mobile'] ?? '') ?></span>
                            </td>

                            <td>
                                <span class="date <?= $dateClass ?>">
                                    <?= esc(date('d M Y', strtotime($d))) ?>
                                </span>
                            </td>

                            <td><?= esc($f['follow_up_type']) ?></td>

                            <td>
                                <?php if (!empty($f['application_number'])): ?>
                                    <span class="app"><?= esc($f['application_number']) ?></span>
                                    <span class="product"><?= esc($f['product_name'] ?? '') ?></span>
                                <?php else: ?>
                                    <span class="product">No application</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?= esc($f['next_action'] ?: ($f['notes'] ?: '—')) ?>
                            </td>

                            <td>
                                <div class="row-actions">
                                    <form method="post">
                                        <input type="hidden" name="action" value="complete">
                                        <input type="hidden" name="followup_id" value="<?= (int)$f['id'] ?>">
                                        <button class="small" type="submit">Complete</button>
                                    </form>

                                    <form method="post">
                                        <input type="hidden" name="action" value="cancel">
                                        <input type="hidden" name="followup_id" value="<?= (int)$f['id'] ?>">
                                        <button class="small cancel" type="submit">Cancel</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>
    </div>

    <div class="box">
        <div class="box-head">
            <h2>Follow-up History</h2>
            <p>Recently completed or cancelled customer follow-ups.</p>
        </div>

        <?php if (!$history): ?>
            <div class="empty">No follow-up history yet.</div>
        <?php else: ?>

            <div class="table">
                <table>
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Next Action</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($history as $f): ?>
                        <tr>
                            <td>
                                <span class="name"><?= esc($f['customer_name']) ?></span>
                                <span class="sub"><?= esc($f['customer_code'] ?? '') ?></span>
                            </td>
                            <td>
                                <?= esc(date('d M Y', strtotime((string)$f['follow_up_date']))) ?>
                            </td>
                            <td><?= esc($f['follow_up_type']) ?></td>
                            <td><?= esc($f['next_action'] ?: '—') ?></td>
                            <td>
                                <?= esc(ucfirst((string)$f['status'])) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>
    </div>

</div>

<script>
const customerSelect = document.getElementById('customer_id');
const infoBox = document.getElementById('customerInfo');
const applicationInput = document.getElementById('application_id');

customerSelect?.addEventListener('change', function () {
    const option = this.options[this.selectedIndex];

    if (!option || !option.value) {
        infoBox.style.display = 'none';
        applicationInput.value = '';
        return;
    }

    document.getElementById('infoMobile').textContent =
        option.dataset.mobile || '—';

    document.getElementById('infoEmail').textContent =
        option.dataset.email || '—';

    document.getElementById('infoRequirement').textContent =
        option.dataset.requirement || '—';

    document.getElementById('infoApplication').textContent =
        option.dataset.applicationNumber || 'No application';

    document.getElementById('infoProduct').textContent =
        option.dataset.product || '—';

    document.getElementById('infoStatus').textContent =
        option.dataset.status || '—';

    document.getElementById('infoStage').textContent =
        option.dataset.stage || '—';

    applicationInput.value = option.dataset.application || '';

    infoBox.style.display = 'grid';
});
</script>

<?php agent_shell_close(); ?>
