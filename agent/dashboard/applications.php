<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/shell.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$agent = require_agent($pdo);

$agentId = (int)($agent['id'] ?? ($_SESSION['user_id'] ?? 0));

if ($agentId <= 0) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

function applicationPageEsc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$applications = [];
$loadError = '';

/*
 * IMPORTANT:
 * Admin/applications.php sets applications.agent_id directly.
 * The Dashboard application count is also based on applications.agent_id.
 * Therefore this page MUST use applications.agent_id directly.
 */
try {
    $stmt = $pdo->prepare("
        SELECT
            a.*,

            /* Customer account: applications.customer_id = users.id */
            cu.full_name AS customer_user_name,
            cu.email AS customer_user_email,
            cu.mobile AS customer_user_mobile,
            cu.customer_code,

            /* Customer profile */
            c.id AS customer_row_id,
            c.user_id AS customer_user_id,
            c.name AS customer_name,
            c.mobile AS customer_mobile,
            c.email AS customer_email,
            c.requirement AS customer_requirement,
            c.status AS customer_status,

            /* Assignment information, if present */
            ca.id AS assignment_id,
            ca.status AS assignment_status,
            ca.assigned_at

        FROM applications a

        LEFT JOIN users cu
            ON cu.id = a.customer_id

        LEFT JOIN customers c
            ON c.user_id = a.customer_id

        LEFT JOIN customer_agent_assignments ca
            ON ca.customer_id = c.id
           AND ca.agent_user_id = a.agent_id
           AND ca.status = 'active'

        WHERE a.agent_id = ?

        ORDER BY a.id DESC
    ");

    $stmt->execute([$agentId]);
    $applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    $loadError = $e->getMessage();
}

/*
 * Optional: find Admin-assigned customers that do not have an application yet.
 * This is separate from the existing-application query, so one SQL failure
 * here can never hide an application that the Dashboard already counts.
 */
$assignedCustomers = [];

try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT
            ca.id AS assignment_id,
            ca.assigned_at,

            c.id AS customer_row_id,
            c.user_id AS customer_user_id,
            c.name AS customer_name,
            c.mobile AS customer_mobile,
            c.email AS customer_email,
            c.requirement,
            c.status AS customer_status,

            u.email AS user_email,
            u.mobile AS user_mobile,
            u.customer_code

        FROM customer_agent_assignments ca

        INNER JOIN customers c
            ON c.id = ca.customer_id

        LEFT JOIN users u
            ON u.id = c.user_id

        WHERE ca.agent_user_id = ?
          AND ca.status = 'active'
          AND NOT EXISTS (
              SELECT 1
              FROM applications a
              WHERE a.customer_id = c.user_id
                AND a.agent_id = ca.agent_user_id
          )

        ORDER BY ca.assigned_at DESC, ca.id DESC
    ");

    $stmt->execute([$agentId]);
    $assignedCustomers = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    /* Optional section only. Existing applications remain visible. */
}

$role = trim((string)($agent['assigned_role'] ?? $agent['role'] ?? 'Agent'));
$agent_id = trim((string)($agent['agent_id'] ?? $agent['party_code'] ?? $agentId));

agent_shell_open($pdo, $agentId, 'Applications', 'applications.php');
?>

<style>
.application-page{padding:28px 24px 50px;background:#f7f9fc;min-height:calc(100vh - 70px)}
.application-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px}
.application-head h1{margin:0;color:#172033;font-size:24px}.application-head p{margin:5px 0 0;color:#667085;font-size:13px}
.count-badge{background:#dcfce7;color:#15803d;border-radius:20px;padding:7px 12px;font-size:12px;font-weight:800}
.error{background:#fff1f2;color:#b42318;border:1px solid #fecdd3;border-radius:10px;padding:12px;margin-bottom:16px;font-size:12px;white-space:pre-wrap}
.application-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px}
.app-card{background:#fff;border:1px solid #e5e9ef;border-radius:14px;padding:18px;box-shadow:0 2px 8px rgba(16,24,40,.04)}
.app-top{display:flex;justify-content:space-between;gap:12px}.app-number{font-weight:800;color:#172033;font-size:15px}
.app-status{background:#eefdf3;color:#15803d;border-radius:20px;padding:5px 9px;font-size:11px;font-weight:700;height:max-content}
.customer-name{font-size:14px;font-weight:800;color:#344054;margin-top:12px}.customer-code{font-size:11px;color:#667085;margin-top:3px}
.details{margin-top:14px;display:grid;gap:8px}.detail{display:flex;justify-content:space-between;gap:12px;font-size:12px}.detail span:first-child{color:#667085}.detail span:last-child{color:#172033;font-weight:600;text-align:right}
.requirement{margin-top:14px;background:#f8fafc;border-radius:9px;padding:11px;font-size:12px;color:#344054}.stage{margin-top:10px;font-size:12px;color:#667085}
.empty{background:#fff;border:1px dashed #cbd5e1;border-radius:14px;padding:45px 20px;text-align:center;color:#667085}.empty h3{margin:0 0 8px;color:#172033}
.section-title{font-size:17px;color:#172033;margin:26px 0 12px}
.btn{display:inline-block;margin-top:15px;padding:9px 13px;border-radius:8px;text-decoration:none;font-size:12px;font-weight:700}.btn-primary{background:#16a34a;color:#fff}.btn-secondary{background:#eef2f6;color:#344054}
</style>

<div class="application-page">

    <div class="application-head">
        <div>
            <h1>Applications</h1>
            <p>Applications assigned to you and their customer details.</p>
        </div>

        <span class="count-badge">
            <?= count($applications) ?>
            Application<?= count($applications) === 1 ? '' : 's' ?>
        </span>
    </div>

    <?php if ($loadError !== ''): ?>
        <div class="error">
            Unable to load applications:
            <?= applicationPageEsc($loadError) ?>
        </div>
    <?php endif; ?>

    <?php if (!$applications): ?>

        <div class="empty">
            <h3>No applications found</h3>
            <p>
                Applications assigned to this agent will appear here automatically.
            </p>
        </div>

    <?php else: ?>

        <div class="application-grid">

            <?php foreach ($applications as $app): ?>

                <?php
                    $customerName = trim((string)($app['customer_name'] ?? ''));

                    if ($customerName === '') {
                        $customerName = trim(
                            (string)($app['customer_user_name'] ?? 'Customer')
                        );
                    }

                    $mobile = trim((string)($app['customer_mobile'] ?? ''));
                    if ($mobile === '') {
                        $mobile = trim(
                            (string)($app['customer_user_mobile'] ?? '')
                        );
                    }

                    $email = trim((string)($app['customer_email'] ?? ''));
                    if ($email === '') {
                        $email = trim(
                            (string)($app['customer_user_email'] ?? '')
                        );
                    }

                    $applicationNumber = trim(
                        (string)($app['application_number'] ?? '')
                    );

                    if ($applicationNumber === '') {
                        $applicationNumber = 'Application #' . (int)$app['id'];
                    }

                    $status = trim((string)($app['status'] ?? ''));
                    if ($status === '') {
                        $status = 'Submitted';
                    }

                    $stage = trim((string)($app['current_stage'] ?? ''));
                    if ($stage === '') {
                        $stage = $status;
                    }
                ?>

                <div class="app-card">

                    <div class="app-top">
                        <div>
                            <div class="app-number">
                                <?= applicationPageEsc($applicationNumber) ?>
                            </div>

                            <div class="customer-name">
                                <?= applicationPageEsc($customerName) ?>
                            </div>

                            <div class="customer-code">
                                Customer ID:
                                <?= applicationPageEsc(
                                    $app['customer_code']
                                    ?: ($app['customer_row_id'] ?: $app['customer_id'])
                                ) ?>
                            </div>
                        </div>

                        <span class="app-status">
                            <?= applicationPageEsc($status) ?>
                        </span>
                    </div>

                    <div class="details">

                        <div class="detail">
                            <span>Mobile</span>
                            <span><?= applicationPageEsc($mobile) ?></span>
                        </div>

                        <div class="detail">
                            <span>Email</span>
                            <span><?= applicationPageEsc($email) ?></span>
                        </div>

                        <div class="detail">
                            <span>Product</span>
                            <span><?= applicationPageEsc($app['product_name'] ?? '') ?></span>
                        </div>

                        <div class="detail">
                            <span>Amount</span>
                            <span><?= applicationPageEsc($app['amount'] ?? '') ?></span>
                        </div>

                        <div class="detail">
                            <span>Tenure</span>
                            <span><?= applicationPageEsc($app['tenure_months'] ?? '') ?></span>
                        </div>

                    </div>

                    <div class="requirement">
                        <strong>Requirement:</strong><br>
                        <?= applicationPageEsc(
                            $app['customer_requirement']
                            ?: 'No requirement specified'
                        ) ?>
                    </div>

                    <div class="stage">
                        Current stage:
                        <strong><?= applicationPageEsc($stage) ?></strong>
                    </div>

                    <?php if (!empty($app['customer_row_id'])): ?>
                        <a
                            class="btn btn-secondary"
                            href="<?= BASE_URL ?>/agent/dashboard/customers.php?customer_id=<?= (int)$app['customer_row_id'] ?>"
                        >
                            View Customer
                        </a>
                    <?php endif; ?>

                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>


    <?php if ($assignedCustomers): ?>

        <h2 class="section-title">
            Assigned Customers — Application Not Created
        </h2>

        <div class="application-grid">

            <?php foreach ($assignedCustomers as $customer): ?>

                <?php
                    $customerEmail = trim((string)($customer['customer_email'] ?? ''));
                    if ($customerEmail === '') {
                        $customerEmail = trim((string)($customer['user_email'] ?? ''));
                    }

                    $customerMobile = trim((string)($customer['customer_mobile'] ?? ''));
                    if ($customerMobile === '') {
                        $customerMobile = trim((string)($customer['user_mobile'] ?? ''));
                    }
                ?>

                <div class="app-card">

                    <div class="app-top">
                        <div>
                            <div class="app-number">
                                No Application Yet
                            </div>

                            <div class="customer-name">
                                <?= applicationPageEsc($customer['customer_name']) ?>
                            </div>

                            <div class="customer-code">
                                Customer ID:
                                <?= applicationPageEsc(
                                    $customer['customer_code']
                                    ?: $customer['customer_row_id']
                                ) ?>
                            </div>
                        </div>

                        <span class="app-status">Assigned</span>
                    </div>

                    <div class="details">
                        <div class="detail">
                            <span>Mobile</span>
                            <span><?= applicationPageEsc($customerMobile) ?></span>
                        </div>

                        <div class="detail">
                            <span>Email</span>
                            <span><?= applicationPageEsc($customerEmail) ?></span>
                        </div>
                    </div>

                    <div class="requirement">
                        <strong>Requirement:</strong><br>
                        <?= applicationPageEsc(
                            $customer['requirement']
                            ?: 'No requirement specified'
                        ) ?>
                    </div>

                    <a
                        class="btn btn-primary"
                        href="<?= BASE_URL ?>/agent/dashboard/applications.php?customer_id=<?= (int)$customer['customer_row_id'] ?>"
                    >
                        Open Customer Application
                    </a>

                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</div>

<?php agent_shell_close(); ?>
