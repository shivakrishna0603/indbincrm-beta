<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/shell.php';

$agent = require_agent($pdo);
$agentId = (int)($agent['id'] ?? 0);

if ($agentId <= 0) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

function reqEsc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function cleanRequirement(?string $requirement): string
{
    $requirement = trim((string)$requirement);
    if ($requirement === '') {
        return '';
    }

    $clean = preg_replace('/\s*\|\s*Purpose\s*:\s*.+$/i', '', $requirement);
    return trim((string)$clean);
}

function extractPurpose(?string $requirement): string
{
    $requirement = trim((string)$requirement);
    if ($requirement === '') {
        return '';
    }

    if (preg_match('/\|\s*Purpose\s*:\s*(.+)$/i', $requirement, $matches)) {
        return trim($matches[1]);
    }

    return '';
}

function formatMoneyValue($value): string
{
    if ($value === null || $value === '') {
        return 'Not specified';
    }

    return '₹' . number_format((float)$value, 2);
}

/* =========================================================
   SAVE ADDITIONAL PURPOSE
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_requirement_detail') {

    csrf_verify();

    $applicationId = post_int('application_id');
    $customerId = post_int('customer_id');
    $purpose = trim((string)($_POST['purpose'] ?? ''));

    if ($applicationId <= 0 && $customerId <= 0) {
        header('Location: ' . BASE_URL . '/agent/dashboard/requirements.php');
        exit;
    }

    /* Verify that the application belongs to this agent. */
    $application = null;

    if ($applicationId > 0) {
        $stmt = $pdo->prepare("\n            SELECT id, customer_id\n            FROM applications\n            WHERE id = ?\n              AND agent_id = ?\n            LIMIT 1\n        ");
        $stmt->execute([$applicationId, $agentId]);
        $application = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /* If only customer_id was supplied, find this agent's latest application. */
    if (!$application && $customerId > 0) {
        $stmt = $pdo->prepare("\n            SELECT a.id, a.customer_id\n            FROM applications a\n            INNER JOIN customers c\n                ON c.user_id = a.customer_id\n            WHERE a.agent_id = ?\n              AND c.id = ?\n            ORDER BY a.id DESC\n            LIMIT 1\n        ");
        $stmt->execute([$agentId, $customerId]);
        $application = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$application) {
        header('Location: ' . BASE_URL . '/agent/dashboard/requirements.php');
        exit;
    }

    $customerUserId = (int)$application['customer_id'];

    $stmt = $pdo->prepare("\n        SELECT id, user_id, name, requirement\n        FROM customers\n        WHERE user_id = ?\n        LIMIT 1\n    ");
    $stmt->execute([$customerUserId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        header('Location: ' . BASE_URL . '/agent/dashboard/requirements.php');
        exit;
    }

    $requirement = cleanRequirement((string)($customer['requirement'] ?? ''));

    if ($requirement === '') {
        $stmt = $pdo->prepare("\n            SELECT product_name\n            FROM applications\n            WHERE id = ?\n              AND agent_id = ?\n              AND product_name IS NOT NULL\n              AND TRIM(product_name) <> ''\n            LIMIT 1\n        ");
        $stmt->execute([(int)$application['id'], $agentId]);
        $product = $stmt->fetchColumn();

        if ($product !== false) {
            $requirement = trim((string)$product);
        }
    }

    if ($requirement === '') {
        $requirement = 'Requirement not specified';
    }

    $finalRequirement = $requirement;

    if ($purpose !== '') {
        $finalRequirement .= ' | Purpose: ' . $purpose;
    }

    $stmt = $pdo->prepare("\n        UPDATE customers\n        SET requirement = ?\n        WHERE id = ?\n    ");
    $stmt->execute([$finalRequirement, (int)$customer['id']]);

    header('Location: ' . BASE_URL . '/agent/dashboard/requirements.php');
    exit;
}

/* =========================================================
   GET APPLICATIONS FOR THIS AGENT
   =========================================================

   IMPORTANT:
   The Admin application page assigns the agent directly with:

       applications.agent_id = users.id

   Therefore requirements.php must read applications.agent_id,
   not rely only on customer_agent_assignments.
*/

$search = trim((string)($_GET['search'] ?? ''));

$applications = [];
$loadError = '';

try {
    $sql = "
        SELECT
            a.id AS application_id,
            a.application_number,
            a.product_name,
            a.amount,
            a.status AS application_status,
            a.current_stage,
            a.created_at AS application_created_at,
            a.customer_id AS application_customer_user_id,
            a.agent_id AS application_agent_id,

            u.id AS customer_user_id,
            u.full_name AS user_full_name,
            u.email AS user_email,
            u.customer_code,
            u.party_code,

            c.id AS customer_id,
            c.name AS customer_name,
            c.mobile AS customer_mobile,
            c.email AS customer_email,
            c.requirement AS customer_requirement,
            c.status AS customer_status,
            c.created_at AS customer_created_at,

            ca.id AS assignment_id,
            ca.status AS assignment_status,
            ca.assigned_at

        FROM applications a

        INNER JOIN users u
            ON u.id = a.customer_id

        LEFT JOIN customers c
            ON c.user_id = u.id

        LEFT JOIN customer_agent_assignments ca
            ON ca.customer_id = c.id
           AND ca.agent_user_id = a.agent_id
           AND ca.status = 'active'

        WHERE a.agent_id = ?
    ";

    $params = [$agentId];

    if ($search !== '') {
        $sql .= "
            AND (
                u.full_name LIKE ?
                OR u.email LIKE ?
                OR u.customer_code LIKE ?
                OR u.party_code LIKE ?
                OR c.name LIKE ?
                OR c.mobile LIKE ?
                OR c.email LIKE ?
                OR a.application_number LIKE ?
                OR a.product_name LIKE ?
                OR c.requirement LIKE ?
            )
        ";

        $term = '%' . $search . '%';
        for ($i = 0; $i < 10; $i++) {
            $params[] = $term;
        }
    }

    $sql .= "
        ORDER BY a.id DESC
        LIMIT 200
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    $loadError = $e->getMessage();
    $applications = [];
}

/* =========================================================
   PREPARE DISPLAY VALUES
   ========================================================= */

$requirementsRecorded = 0;

foreach ($applications as &$application) {

    $storedRequirement = cleanRequirement(
        (string)($application['customer_requirement'] ?? '')
    );

    $productRequirement = trim(
        (string)($application['product_name'] ?? '')
    );

    /* Customer requirement first; application product is fallback. */
    $resolvedRequirement = $storedRequirement !== ''
        ? $storedRequirement
        : $productRequirement;

    $application['resolved_requirement'] = $resolvedRequirement;
    $application['purpose'] = extractPurpose(
        (string)($application['customer_requirement'] ?? '')
    );

    if ($resolvedRequirement !== '') {
        $requirementsRecorded++;
    }
}
unset($application);

$totalAssigned = count($applications);

$agentName = (string)(
    $agent['full_name']
    ?? $agent['name']
    ?? 'Agent'
);

agent_shell_open(
    $pdo,
    $agentId,
    'Customer Requirements',
    'requirements.php'
);
?>

<style>
.requirements-page{padding:28px 24px 40px;background:#f7f9fc;min-height:calc(100vh - 70px)}
.requirements-header{margin-bottom:22px}.requirements-header h1{margin:0;font-size:34px;line-height:1.2;color:#172b4d;font-weight:700}.requirements-header p{margin:10px 0 0;color:#344563;font-size:16px}
.stats-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px}.stat-card{background:#fff;border:1px solid #e4e7ec;border-radius:11px;padding:20px;min-height:105px}.stat-label{color:#72809a;font-size:13px;margin-bottom:8px}.stat-value{font-size:21px;font-weight:700;color:#172b4d}.stat-value.green{color:#16a34a}.stat-sub{margin-top:7px;font-size:12px;color:#9aa5b5}
.search-row{display:flex;gap:10px;margin-bottom:20px}.search-input{flex:1;height:44px;border:1px solid #d9dee8;border-radius:9px;padding:0 13px;font-size:14px;outline:none;box-sizing:border-box}.search-input:focus{border-color:#16a34a;box-shadow:0 0 0 3px rgba(22,163,74,.08)}.search-button{border:0;background:#16a34a;color:#fff;border-radius:9px;padding:0 19px;height:44px;cursor:pointer;font-weight:600}
.customers-card{background:#fff;border:1px solid #e4e7ec;border-radius:12px;overflow:hidden}.customers-card-header{padding:20px;border-bottom:1px solid #edf0f4}.customers-card-header h2{margin:0;font-size:18px;color:#172b4d}.customers-card-header p{margin:6px 0 0;color:#72809a;font-size:13px}
.customer-item{padding:22px;border-bottom:1px solid #edf0f4}.customer-item:last-child{border-bottom:0}.customer-top{display:flex;align-items:flex-start;justify-content:space-between;gap:15px;margin-bottom:18px}.customer-main{display:flex;align-items:center;gap:13px}.customer-avatar{width:42px;height:42px;border-radius:50%;background:#dcfce7;color:#16a34a;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:16px;flex-shrink:0}.customer-name{font-size:17px;font-weight:700;color:#172b4d}.customer-id{margin-top:4px;color:#72809a;font-size:12px}.customer-id strong{color:#344563;font-weight:600}.customer-status{padding:6px 10px;border-radius:999px;background:#dcfce7;color:#15803d;font-size:11px;font-weight:700;white-space:nowrap}
.customer-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}.info-box{border:1px solid #e5e9ef;border-radius:10px;padding:14px;background:#fff}.info-label{color:#72809a;font-size:11px;text-transform:uppercase;margin-bottom:7px}.info-value{color:#172b4d;font-size:14px;font-weight:500;word-break:break-word}.info-value.requirement{color:#16a34a;font-weight:600}.info-value.empty{color:#9aa5b5}
.requirement-detail{margin-top:14px;border:1px solid #e5e9ef;border-radius:10px;padding:16px;background:#f8fafc}.detail-title{font-size:12px;color:#72809a;margin-bottom:6px}.detail-value{font-size:14px;color:#172b4d;font-weight:500;line-height:1.5}.purpose-section{margin-top:14px;border:1px solid #dcefe3;border-radius:10px;padding:16px;background:#f7fff9}.purpose-section h3{margin:0 0 6px;font-size:14px;color:#172b4d}.purpose-section p{margin:0 0 12px;color:#72809a;font-size:12px}.purpose-form{display:flex;gap:9px}.purpose-input{flex:1;height:40px;border:1px solid #d9dee8;border-radius:8px;padding:0 11px;font-size:13px;outline:none}.purpose-input:focus{border-color:#16a34a}.purpose-button{border:0;background:#16a34a;color:#fff;border-radius:8px;padding:0 15px;font-weight:700;cursor:pointer}
.application-strip{margin-top:14px;padding:14px;border:1px solid #dcefe3;border-radius:10px;background:#f7fff9}.application-strip-title{font-size:12px;color:#72809a;text-transform:uppercase;margin-bottom:9px}.application-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.application-value{font-size:13px;color:#172b4d;font-weight:600}.application-label{font-size:10px;color:#72809a;text-transform:uppercase;margin-bottom:4px}.empty{background:#fff;border:1px dashed #cbd5e1;border-radius:14px;padding:50px 20px;text-align:center;color:#667085}.empty h3{margin:0 0 8px;color:#172033}.error{background:#fff1f2;color:#b42318;border:1px solid #fecdd3;border-radius:10px;padding:12px;margin-bottom:16px;font-size:12px;white-space:pre-wrap}.btn{display:inline-block;padding:9px 13px;border-radius:8px;text-decoration:none;font-size:12px;font-weight:700}.btn-primary{background:#16a34a;color:#fff}.btn-secondary{background:#eef2f6;color:#344054}.actions{display:flex;gap:8px;margin-top:14px}
@media(max-width:900px){.stats-grid,.customer-grid{grid-template-columns:1fr}.application-grid{grid-template-columns:repeat(2,1fr)}}
</style>

<div class="requirements-page">

    <div class="requirements-header">
        <h1>Customer Requirements</h1>
        <p>Review the requirement and application details already raised by customers assigned to you.</p>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Assigned Customers</div>
            <div class="stat-value green"><?= (int)$totalAssigned ?></div>
            <div class="stat-sub">Applications assigned to you by Admin</div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Requirements Recorded</div>
            <div class="stat-value green"><?= (int)$requirementsRecorded ?></div>
            <div class="stat-sub">Customer/application requirements available</div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Assigned Agent</div>
            <div class="stat-value green"><?= reqEsc($agentName) ?></div>
            <div class="stat-sub">Your current agent account</div>
        </div>
    </div>

    <form method="get" class="search-row">
        <input
            class="search-input"
            type="text"
            name="search"
            value="<?= reqEsc($search) ?>"
            placeholder="Search customer, mobile, ID, application or requirement"
        >
        <button class="search-button" type="submit">Search</button>
    </form>

    <?php if ($loadError !== ''): ?>
        <div class="error">
            Unable to load requirements:
            <?= reqEsc($loadError) ?>
        </div>
    <?php endif; ?>

    <section class="customers-card">

        <div class="customers-card-header">
            <h2>Assigned Customers &amp; Requirements</h2>
            <p>Details are loaded directly from the application and customer records assigned to this agent.</p>
        </div>

        <?php if (!$applications): ?>

            <div class="empty">
                <h3>No assigned applications found</h3>
                <p>
                    When Admin assigns an application to this agent, its customer and requirement details will appear here automatically.
                </p>
            </div>

        <?php else: ?>

            <?php foreach ($applications as $item): ?>

                <?php
                    $name = trim((string)($item['customer_name'] ?? ''));
                    if ($name === '') {
                        $name = trim((string)($item['user_full_name'] ?? 'Customer'));
                    }
                    if ($name === '') {
                        $name = 'Customer';
                    }

                    $initial = strtoupper(substr($name, 0, 1));
                    $customerCode = (string)(
                        $item['customer_code']
                        ?: $item['party_code']
                        ?: $item['customer_id']
                        ?: $item['customer_user_id']
                    );

                    $requirement = (string)($item['resolved_requirement'] ?? '');
                    $purpose = (string)($item['purpose'] ?? '');
                ?>

                <article class="customer-item">

                    <div class="customer-top">
                        <div class="customer-main">
                            <div class="customer-avatar">
                                <?= reqEsc($initial) ?>
                            </div>

                            <div>
                                <div class="customer-name">
                                    <?= reqEsc($name) ?>
                                </div>
                                <div class="customer-id">
                                    Customer ID:
                                    <strong><?= reqEsc($customerCode) ?></strong>
                                </div>
                            </div>
                        </div>

                        <div class="customer-status">
                            <?= reqEsc(ucwords(str_replace('_', ' ', (string)($item['application_status'] ?: 'Assigned'))) ) ?>
                        </div>
                    </div>

                    <div class="customer-grid">

                        <div class="info-box">
                            <div class="info-label">Mobile</div>
                            <div class="info-value <?= empty($item['customer_mobile']) ? 'empty' : '' ?>">
                                <?= reqEsc($item['customer_mobile'] ?: 'Not available') ?>
                            </div>
                        </div>

                        <div class="info-box">
                            <div class="info-label">Email</div>
                            <div class="info-value <?= empty($item['customer_email'] ?: $item['user_email']) ? 'empty' : '' ?>">
                                <?= reqEsc($item['customer_email'] ?: $item['user_email'] ?: 'Not available') ?>
                            </div>
                        </div>

                        <div class="info-box">
                            <div class="info-label">Application Number</div>
                            <div class="info-value">
                                <?= reqEsc($item['application_number'] ?: 'Not available') ?>
                            </div>
                        </div>

                    </div>

                    <div class="application-strip">
                        <div class="application-strip-title">Application Details</div>

                        <div class="application-grid">

                            <div>
                                <div class="application-label">Product</div>
                                <div class="application-value">
                                    <?= reqEsc($item['product_name'] ?: 'Not specified') ?>
                                </div>
                            </div>

                            <div>
                                <div class="application-label">Amount</div>
                                <div class="application-value">
                                    <?= formatMoneyValue($item['amount']) ?>
                                </div>
                            </div>

                            <div>
                                <div class="application-label">Stage</div>
                                <div class="application-value">
                                    <?= reqEsc($item['current_stage'] ?: 'Not specified') ?>
                                </div>
                            </div>

                            <div>
                                <div class="application-label">Status</div>
                                <div class="application-value">
                                    <?= reqEsc(ucwords(str_replace('_', ' ', (string)($item['application_status'] ?: 'Not specified')))) ?>
                                </div>
                            </div>

                        </div>
                    </div>

                    <div class="requirement-detail">
                        <div class="detail-title">Customer Requirement</div>
                        <div class="detail-value">
                            <?= reqEsc($requirement !== '' ? $requirement : 'No requirement specified') ?>
                        </div>
                    </div>

                    <?php if ($purpose !== ''): ?>
                        <div class="purpose-section">
                            <h3>Purpose</h3>
                            <p><?= reqEsc($purpose) ?></p>
                        </div>
                    <?php else: ?>
                        <div class="purpose-section">
                            <h3>Add Purpose / Additional Requirement</h3>
                            <p>If the application does not contain a purpose, you can record it here.</p>

                            <form method="post" class="purpose-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="save_requirement_detail">
                                <input type="hidden" name="application_id" value="<?= (int)$item['application_id'] ?>">
                                <input type="hidden" name="customer_id" value="<?= (int)$item['customer_id'] ?>">

                                <input
                                    class="purpose-input"
                                    type="text"
                                    name="purpose"
                                    placeholder="Enter purpose or additional requirement"
                                    maxlength="500"
                                    required
                                >

                                <button class="purpose-button" type="submit">
                                    Save
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>

                    <div class="actions">
                        <a
                            class="btn btn-primary"
                            href="<?= BASE_URL ?>/agent/dashboard/applications.php?application_id=<?= (int)$item['application_id'] ?>"
                        >
                            View Application
                        </a>

                        <a
                            class="btn btn-secondary"
                            href="<?= BASE_URL ?>/agent/dashboard/customers.php?customer_id=<?= (int)$item['customer_id'] ?>"
                        >
                            View Customer
                        </a>
                    </div>

                </article>

            <?php endforeach; ?>

        <?php endif; ?>

    </section>

</div>

<?php agent_shell_close(); ?>
