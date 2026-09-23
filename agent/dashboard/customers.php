<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/shell.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/*
 * Admin stores customer assignment as:
 * customer_agent_assignments.customer_id  = customers.id
 * customer_agent_assignments.agent_user_id = users.id
 * customer_agent_assignments.status = 'active'
 *
 * Admin applications.php also stores:
 * applications.agent_id = users.id
 * applications.customer_id = users.id (customer's user account)
 *
 * We use BOTH relationships. This prevents a customer from disappearing
 * from the Agent portal when an application is assigned but an old/missing
 * assignment row exists.
 */

$agent = require_agent($pdo);

$agentId = (int)($agent['id'] ?? ($_SESSION['user_id'] ?? 0));

if ($agentId <= 0) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

function customerPageEsc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$search = trim((string)($_GET['search'] ?? ''));
$customers = [];
$loadError = '';

try {
    /*
     * A customer belongs on this page when EITHER:
     * 1. Admin assigned the customer to this agent, OR
     * 2. An application for this customer belongs to this agent.
     *
     * The second condition is an intentional fallback because the
     * Dashboard application count is based on applications.agent_id.
     */
    $sql = "
        SELECT DISTINCT
            c.id AS customer_id,
            c.user_id AS customer_user_id,
            c.name AS customer_name,
            c.mobile AS customer_mobile,
            c.email AS customer_email,
            c.requirement AS customer_requirement,
            c.status AS customer_status,

            u.full_name AS customer_user_name,
            u.email AS customer_user_email,
            u.mobile AS customer_user_mobile,
            u.customer_code,

            ca.id AS assignment_id,
            ca.agent_user_id,
            ca.assigned_by,
            ca.assigned_at,
            ca.status AS assignment_status,

            a.id AS application_id,
            a.application_number,
            a.product_name,
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

        WHERE (
            ca.id IS NOT NULL
            OR a.id IS NOT NULL
        )
    ";

    $params = [$agentId, $agentId];

    if ($search !== '') {
        $sql .= "
            AND (
                c.name LIKE ?
                OR c.mobile LIKE ?
                OR c.email LIKE ?
                OR u.email LIKE ?
                OR u.customer_code LIKE ?
            )
        ";

        $term = '%' . $search . '%';
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
    }

    $sql .= "
        ORDER BY
            COALESCE(ca.assigned_at, a.created_at) DESC,
            c.id DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    $loadError = $e->getMessage();
    $customers = [];
}

$role = trim((string)($agent['assigned_role'] ?? $agent['role'] ?? 'Agent'));
$agent_id = trim((string)($agent['agent_id'] ?? $agent['party_code'] ?? $agentId));

agent_shell_open($pdo, $agentId, 'Customers', 'customers.php');
?>

<style>
.customer-page{padding:28px 24px 50px;background:#f7f9fc;min-height:calc(100vh - 70px)}
.customer-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px}
.customer-head h1{margin:0;color:#172033;font-size:24px}.customer-head p{margin:5px 0 0;color:#667085;font-size:13px}
.customer-toolbar{background:#fff;border:1px solid #e5e9ef;border-radius:12px;padding:12px;margin-bottom:18px}
.customer-toolbar form{display:flex;gap:10px}.customer-toolbar input{flex:1;height:40px;border:1px solid #d9e0e7;border-radius:8px;padding:0 12px;font-size:13px}
.customer-toolbar button{border:0;border-radius:8px;background:#16a34a;color:#fff;padding:0 18px;font-weight:700;cursor:pointer}
.customer-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(310px,1fr));gap:16px}
.customer-card{background:#fff;border:1px solid #e5e9ef;border-radius:14px;padding:18px;box-shadow:0 2px 8px rgba(16,24,40,.04)}
.customer-top{display:flex;justify-content:space-between;gap:12px}.customer-name{font-size:17px;font-weight:800;color:#172033}.customer-code{font-size:11px;color:#667085;margin-top:4px}
.badge{display:inline-flex;padding:5px 9px;border-radius:20px;background:#dcfce7;color:#15803d;font-size:11px;font-weight:700;height:max-content}
.customer-details{margin-top:16px;display:grid;gap:9px}.detail{display:flex;justify-content:space-between;gap:12px;font-size:12px}.detail span:first-child{color:#667085}.detail span:last-child{color:#172033;font-weight:600;text-align:right}
.customer-requirement{margin-top:14px;padding:12px;background:#f8fafc;border-radius:9px;font-size:12px;color:#344054}.customer-actions{margin-top:16px;display:flex;gap:8px;flex-wrap:wrap}
.btn{display:inline-block;padding:9px 13px;border-radius:8px;text-decoration:none;font-size:12px;font-weight:700}.btn-primary{background:#16a34a;color:#fff}.btn-secondary{background:#eef2f6;color:#344054}
.empty{background:#fff;border:1px dashed #cbd5e1;border-radius:14px;padding:50px 20px;text-align:center;color:#667085}.empty h3{margin:0 0 8px;color:#172033}
.error{background:#fff1f2;color:#b42318;border:1px solid #fecdd3;border-radius:10px;padding:12px;margin-bottom:16px;font-size:12px;white-space:pre-wrap}
</style>

<div class="customer-page">
    <div class="customer-head">
        <div>
            <h1>Customers</h1>
            <p>Customers assigned to you by Admin.</p>
        </div>
        <strong><?= count($customers) ?> Customer<?= count($customers) === 1 ? '' : 's' ?></strong>
    </div>

    <div class="customer-toolbar">
        <form method="get">
            <input type="text" name="search" value="<?= customerPageEsc($search) ?>" placeholder="Search name, mobile, email or customer ID">
            <button type="submit">Search</button>
        </form>
    </div>

    <?php if ($loadError !== ''): ?>
        <div class="error">Unable to load assigned customers: <?= customerPageEsc($loadError) ?></div>
    <?php endif; ?>

    <?php if (!$customers): ?>
        <div class="empty">
            <h3>No customers assigned</h3>
            <p>When Admin assigns a customer to this agent, the customer will appear here automatically.</p>
        </div>
    <?php else: ?>
        <div class="customer-grid">
            <?php foreach ($customers as $customer): ?>
                <?php
                    $customerEmail = trim((string)($customer['customer_email'] ?? ''));
                    if ($customerEmail === '') {
                        $customerEmail = trim((string)($customer['customer_user_email'] ?? ''));
                    }

                    $applicationStatus = trim((string)($customer['application_status'] ?? ''));
                    $applicationStatus = $applicationStatus !== '' ? $applicationStatus : 'Not created';
                ?>
                <div class="customer-card">
                    <div class="customer-top">
                        <div>
                            <div class="customer-name"><?= customerPageEsc($customer['customer_name']) ?></div>
                            <div class="customer-code">
                                Customer ID:
                                <?= customerPageEsc($customer['customer_code'] ?: $customer['customer_id']) ?>
                            </div>
                        </div>
                        <span class="badge">Assigned</span>
                    </div>

                    <div class="customer-details">
                        <div class="detail"><span>Mobile</span><span><?= customerPageEsc($customer['customer_mobile'] ?: $customer['customer_user_mobile']) ?></span></div>
                        <div class="detail"><span>Email</span><span><?= customerPageEsc($customerEmail) ?></span></div>
                        <div class="detail"><span>Application</span><span><?= customerPageEsc($customer['application_number'] ?: 'Not created') ?></span></div>
                        <div class="detail"><span>Application Status</span><span><?= customerPageEsc($applicationStatus) ?></span></div>
                    </div>

                    <div class="customer-requirement">
                        <strong>Requirement:</strong><br>
                        <?= customerPageEsc($customer['customer_requirement'] ?: 'No requirement specified') ?>
                    </div>

                    <div class="customer-actions">
                        <a class="btn btn-primary" href="<?= BASE_URL ?>/agent/dashboard/applications.php?customer_id=<?= (int)$customer['customer_id'] ?>">Open Application</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php agent_shell_close(); ?>
