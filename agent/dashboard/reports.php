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
    exit('Agent session not found.');
}

/* Do not create e(), because core/helpers.php already has it. */
function reportEsc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function reportTable(PDO $pdo, string $table): bool
{
    $q = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = ?
    ");
    $q->execute([$table]);
    return (int)$q->fetchColumn() > 0;
}

function reportColumn(PDO $pdo, string $table, string $column): bool
{
    $q = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = ?
          AND column_name = ?
    ");
    $q->execute([$table, $column]);
    return (int)$q->fetchColumn() > 0;
}

function reportFirstColumn(PDO $pdo, string $table, array $columns): ?string
{
    foreach ($columns as $column) {
        if (reportColumn($pdo, $table, $column)) {
            return $column;
        }
    }
    return null;
}

/*
|--------------------------------------------------------------------------
| CUSTOMER SOURCE
|--------------------------------------------------------------------------
| The important fix:
|
| The Applications module is treated as a valid source of customers.
| If one application exists for this agent, its customer is counted even
| when customer_agent_assignments uses a different ID representation.
|
| Customer matching supports:
|   customers.id
|   customers.user_id
|   users.id
|   applications.customer_id
|--------------------------------------------------------------------------
*/

$customers = [];
$customerIndex = [];
$applications = [];

function customerKey(array $customer, $fallback = null): string
{
    if (!empty($customer['user_id'])) {
        return 'U:' . (string)$customer['user_id'];
    }

    if (!empty($customer['customer_id'])) {
        return 'C:' . (string)$customer['customer_id'];
    }

    return 'R:' . (string)$fallback;
}

function addReportCustomer(
    array $customer,
    array &$customers,
    array &$customerIndex,
    $fallback = null
): void {
    $key = customerKey($customer, $fallback);

    if ($key === 'R:') {
        return;
    }

    if (isset($customerIndex[$key])) {
        $index = $customerIndex[$key];

        foreach ($customer as $field => $value) {
            if (
                (!isset($customers[$index][$field]) ||
                 $customers[$index][$field] === '' ||
                 $customers[$index][$field] === null)
                && $value !== '' && $value !== null
            ) {
                $customers[$index][$field] = $value;
            }
        }

        return;
    }

    $customerIndex[$key] = count($customers);
    $customers[] = $customer;
}

/*
|--------------------------------------------------------------------------
| A. READ APPLICATIONS FIRST
|--------------------------------------------------------------------------
*/
if (
    reportTable($pdo, 'applications') &&
    reportColumn($pdo, 'applications', 'agent_id')
) {
    $fields = ['a.id'];

    foreach ([
        'application_number',
        'customer_id',
        'product_name',
        'amount',
        'status',
        'current_stage',
        'created_at',
        'tenure'
    ] as $field) {
        $fields[] = reportColumn($pdo, 'applications', $field)
            ? "a.`$field`"
            : "NULL AS `$field`";
    }

    $order = reportColumn($pdo, 'applications', 'created_at')
        ? 'a.created_at DESC, a.id DESC'
        : 'a.id DESC';

    $q = $pdo->prepare("
        SELECT " . implode(',', $fields) . "
        FROM applications a
        WHERE a.agent_id = ?
        ORDER BY $order
    ");
    $q->execute([$agentId]);
    $applications = $q->fetchAll(PDO::FETCH_ASSOC);
}

/*
|--------------------------------------------------------------------------
| B. FOR EVERY APPLICATION CUSTOMER, RESOLVE THE REAL CUSTOMER
|--------------------------------------------------------------------------
| We deliberately do NOT require applications.customer_id to equal only
| customers.id. It may point to customers.id OR customers.user_id OR users.id.
|--------------------------------------------------------------------------
*/

foreach ($applications as $application) {
    $ref = $application['customer_id'] ?? null;

    if ($ref === null || $ref === '') {
        continue;
    }

    $resolved = null;

    if (reportTable($pdo, 'customers')) {
        $name = reportColumn($pdo, 'customers', 'name')
            ? 'c.name' : "''";
        $mobile = reportColumn($pdo, 'customers', 'mobile')
            ? 'c.mobile' : "''";
        $email = reportColumn($pdo, 'customers', 'email')
            ? 'c.email' : "''";
        $requirement = reportColumn($pdo, 'customers', 'requirement')
            ? 'c.requirement' : "''";
        $status = reportColumn($pdo, 'customers', 'status')
            ? 'c.status' : "''";
        $userId = reportColumn($pdo, 'customers', 'user_id')
            ? 'c.user_id' : 'NULL';

        /*
         * The OR is intentional:
         * application.customer_id can represent the customer row ID
         * OR the customer's user ID.
         */
        $q = $pdo->prepare("
            SELECT
                c.id AS customer_id,
                $userId AS user_id,
                $name AS name,
                $mobile AS mobile,
                $email AS email,
                $requirement AS requirement,
                $status AS customer_status
            FROM customers c
            WHERE c.id = ?
            " . (reportColumn($pdo, 'customers', 'user_id')
                ? "OR c.user_id = ?"
                : "") . "
            ORDER BY c.id DESC
            LIMIT 1
        ");

        if (reportColumn($pdo, 'customers', 'user_id')) {
            $q->execute([$ref, $ref]);
        } else {
            $q->execute([$ref]);
        }

        $resolved = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /*
     * If there is no customers row, still count the application customer.
     * This is what prevents Applications=1 / Customers=0.
     */
    if (!$resolved) {
        $resolved = [
            'customer_id' => (string)$ref,
            'user_id' => null,
            'name' => 'Customer',
            'mobile' => '',
            'email' => '',
            'requirement' => '',
            'customer_status' => 'Assigned'
        ];
    }

    addReportCustomer($resolved, $customers, $customerIndex, $ref);
}

/*
|--------------------------------------------------------------------------
| C. READ ADMIN ASSIGNMENTS TOO
|--------------------------------------------------------------------------
*/
if (
    reportTable($pdo, 'customer_agent_assignments') &&
    reportTable($pdo, 'customers')
) {
    $agentColumn = reportFirstColumn($pdo, 'customer_agent_assignments', [
        'agent_user_id',
        'agent_id',
        'assigned_agent_id',
        'user_id'
    ]);

    if ($agentColumn) {
        $statusCondition = '';

        if (reportColumn($pdo, 'customer_agent_assignments', 'status')) {
            $statusCondition = "
                AND (
                    ca.status IS NULL
                    OR LOWER(TRIM(ca.status)) IN
                    ('active','assigned','accepted','in_progress')
                )
            ";
        }

        $name = reportColumn($pdo, 'customers', 'name')
            ? 'c.name' : "''";
        $mobile = reportColumn($pdo, 'customers', 'mobile')
            ? 'c.mobile' : "''";
        $email = reportColumn($pdo, 'customers', 'email')
            ? 'c.email' : "''";
        $requirement = reportColumn($pdo, 'customers', 'requirement')
            ? 'c.requirement' : "''";
        $status = reportColumn($pdo, 'customers', 'status')
            ? 'c.status' : "''";
        $userId = reportColumn($pdo, 'customers', 'user_id')
            ? 'c.user_id' : 'NULL';

        $q = $pdo->prepare("
            SELECT
                c.id AS customer_id,
                $userId AS user_id,
                $name AS name,
                $mobile AS mobile,
                $email AS email,
                $requirement AS requirement,
                $status AS customer_status
            FROM customer_agent_assignments ca
            INNER JOIN customers c ON c.id = ca.customer_id
            WHERE ca.`$agentColumn` = ?
            $statusCondition
            ORDER BY c.id DESC
        ");

        $q->execute([$agentId]);

        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $customer) {
            addReportCustomer($customer, $customers, $customerIndex);
        }
    }
}

/*
|--------------------------------------------------------------------------
| D. APPLICATION STATUS
|--------------------------------------------------------------------------
*/
$statusCounts = [
    'Submitted' => 0,
    'Under Review' => 0,
    'Approved' => 0,
    'Rejected' => 0,
    'Completed' => 0
];

foreach ($applications as $application) {
    $status = strtolower(trim((string)($application['status'] ?? '')));

    if (in_array($status, [
        'submitted',
        'draft',
        'requirement_raised'
    ], true)) {
        $statusCounts['Submitted']++;
    } elseif (in_array($status, [
        'under_review',
        'under review',
        'processing',
        'kyc_check',
        'credit_review'
    ], true)) {
        $statusCounts['Under Review']++;
    } elseif ($status === 'approved') {
        $statusCounts['Approved']++;
    } elseif ($status === 'rejected') {
        $statusCounts['Rejected']++;
    } elseif (in_array($status, [
        'completed',
        'disbursed'
    ], true)) {
        $statusCounts['Completed']++;
    }
}

$totalCustomers = count($customers);
$totalApplications = count($applications);
$approved = $statusCounts['Approved'];
$completed = $statusCounts['Completed'];

$approvalRate = $totalApplications > 0
    ? round(($approved / $totalApplications) * 100)
    : 0;

$completionRate = $totalApplications > 0
    ? round(($completed / $totalApplications) * 100)
    : 0;

/*
|--------------------------------------------------------------------------
| E. REQUIREMENTS
|--------------------------------------------------------------------------
*/
$requirements = [];

foreach ($customers as $customer) {
    $requirement = trim((string)($customer['requirement'] ?? ''));

    if ($requirement === '') {
        $requirement = 'Requirement not specified';
    }

    $requirements[$requirement] =
        ($requirements[$requirement] ?? 0) + 1;
}

arsort($requirements);

/*
|--------------------------------------------------------------------------
| F. PRODUCTS
|--------------------------------------------------------------------------
*/
$products = [];

foreach ($applications as $application) {
    $product = trim((string)($application['product_name'] ?? ''));

    if ($product === '') {
        $product = 'Product not specified';
    }

    $products[$product] = ($products[$product] ?? 0) + 1;
}

arsort($products);

/*
|--------------------------------------------------------------------------
| G. FOLLOW-UPS
|--------------------------------------------------------------------------
*/
$followups = 0;

foreach ([
    'followups',
    'agent_followups',
    'customer_followups',
    'leads'
] as $table) {
    if (!reportTable($pdo, $table)) {
        continue;
    }

    $agentColumn = reportFirstColumn($pdo, $table, [
        'agent_id',
        'agent_user_id',
        'assigned_agent_id'
    ]);

    if (!$agentColumn) {
        continue;
    }

    try {
        $q = $pdo->prepare("
            SELECT COUNT(*)
            FROM `$table`
            WHERE `$agentColumn` = ?
        ");
        $q->execute([$agentId]);
        $followups = (int)$q->fetchColumn();
        break;
    } catch (Throwable $e) {
        continue;
    }
}

/*
|--------------------------------------------------------------------------
| H. DOCUMENTS
|--------------------------------------------------------------------------
*/
$documents = 0;
$verifiedDocuments = 0;
$pendingDocuments = 0;
$rejectedDocuments = 0;

if (
    reportTable($pdo, 'documents') &&
    reportColumn($pdo, 'documents', 'customer_id') &&
    $customers
) {
    $ids = [];

    foreach ($customers as $customer) {
        if (!empty($customer['customer_id'])) {
            $ids[] = $customer['customer_id'];
        }
    }

    $ids = array_values(array_unique($ids));

    if ($ids) {
        $marks = implode(',', array_fill(0, count($ids), '?'));

        try {
            $q = $pdo->prepare("
                SELECT COUNT(*)
                FROM documents
                WHERE customer_id IN ($marks)
            ");
            $q->execute($ids);
            $documents = (int)$q->fetchColumn();

            if (reportColumn($pdo, 'documents', 'status')) {
                $q = $pdo->prepare("
                    SELECT LOWER(TRIM(status)) AS doc_status,
                           COUNT(*) AS total
                    FROM documents
                    WHERE customer_id IN ($marks)
                    GROUP BY LOWER(TRIM(status))
                ");
                $q->execute($ids);

                foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $n = (int)$row['total'];

                    if (in_array($row['doc_status'], [
                        'verified',
                        'approved',
                        'approved_by_admin'
                    ], true)) {
                        $verifiedDocuments += $n;
                    } elseif (in_array($row['doc_status'], [
                        'rejected',
                        'declined'
                    ], true)) {
                        $rejectedDocuments += $n;
                    } else {
                        $pendingDocuments += $n;
                    }
                }
            }
        } catch (Throwable $e) {
            // Optional document table/columns should never break Reports.
        }
    }
}

/*
|--------------------------------------------------------------------------
| I. COMMISSION
|--------------------------------------------------------------------------
*/
$commission = 0.0;

foreach ([
    'agent_commission_payout',
    'commissions',
    'wallet_transactions',
    'agent_wallet_transactions'
] as $table) {
    if (!reportTable($pdo, $table)) {
        continue;
    }

    $agentColumn = reportFirstColumn($pdo, $table, [
        'agent_id',
        'agent_user_id',
        'user_id',
        'earner_id'
    ]);

    $amountColumn = reportFirstColumn($pdo, $table, [
        'commission_amount',
        'amount',
        'payout_amount',
        'credited_amount',
        'credit_amount'
    ]);

    if (!$agentColumn || !$amountColumn) {
        continue;
    }

    try {
        $q = $pdo->prepare("
            SELECT COALESCE(SUM(`$amountColumn`), 0)
            FROM `$table`
            WHERE `$agentColumn` = ?
        ");
        $q->execute([$agentId]);
        $commission = (float)$q->fetchColumn();
        break;
    } catch (Throwable $e) {
        continue;
    }
}

/*
|--------------------------------------------------------------------------
| J. SIX-MONTH APPLICATION TREND
|--------------------------------------------------------------------------
*/
$months = [];

for ($i = 5; $i >= 0; $i--) {
    $key = date('Y-m', strtotime("-$i months"));

    $months[$key] = [
        'label' => date('M', strtotime($key . '-01')),
        'count' => 0
    ];
}

foreach ($applications as $application) {
    if (empty($application['created_at'])) {
        continue;
    }

    $key = date(
        'Y-m',
        strtotime((string)$application['created_at'])
    );

    if (isset($months[$key])) {
        $months[$key]['count']++;
    }
}

$maxMonth = max(
    1,
    ...array_column($months, 'count')
);

agent_shell_open(
    $pdo,
    $agentId,
    'Reports',
    'reports.php'
);
?>

<style>
.report-page{
    padding:28px 24px 50px;
    background:#f7f9fc;
    min-height:calc(100vh - 70px);
}
.report-head{
    display:flex;
    justify-content:space-between;
    align-items:flex-end;
    margin-bottom:20px;
}
.report-head h1{
    margin:0;
    color:#172033;
    font-size:28px;
    font-weight:800;
}
.report-head p{
    margin:6px 0 0;
    color:#718096;
    font-size:13px;
}
.date{
    background:#fff;
    border:1px solid #e4e7ec;
    border-radius:9px;
    padding:9px 13px;
    color:#667085;
    font-size:11px;
}
.kpis{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:14px;
    margin-bottom:16px;
}
.kpi{
    background:#fff;
    border:1px solid #dcefe3;
    border-radius:13px;
    padding:17px;
}
.kpi small{
    color:#718096;
    font-size:11px;
}
.kpi strong{
    display:block;
    margin-top:7px;
    color:#172033;
    font-size:24px;
}
.green{
    color:#16a34a!important;
}
.grid{
    display:grid;
    grid-template-columns:1.45fr .75fr;
    gap:16px;
    margin-bottom:16px;
}
.card{
    background:#fff;
    border:1px solid #dcefe3;
    border-radius:13px;
    overflow:hidden;
}
.card-head{
    padding:16px 18px;
    border-bottom:1px solid #edf0f4;
}
.card-head h2{
    margin:0;
    color:#172033;
    font-size:15px;
}
.card-head p{
    margin:5px 0 0;
    color:#7b8799;
    font-size:11px;
}
.card-body{
    padding:18px;
}
table{
    width:100%;
    border-collapse:collapse;
}
th{
    padding:10px;
    background:#f8fafc;
    color:#667085;
    font-size:10px;
    text-align:left;
}
td{
    padding:11px 10px;
    border-top:1px solid #edf0f4;
    color:#344054;
    font-size:11px;
    vertical-align:top;
}
td strong{
    color:#172033;
}
.badge{
    display:inline-block;
    padding:4px 8px;
    border-radius:99px;
    background:#ecfdf3;
    color:#15803d;
    font-size:10px;
    font-weight:700;
}
.empty{
    text-align:center;
    padding:28px;
    color:#667085;
    font-size:12px;
}
.status{
    display:grid;
    gap:13px;
}
.status-row{
    display:grid;
    grid-template-columns:100px 1fr 25px;
    gap:8px;
    align-items:center;
}
.status-row span{
    font-size:11px;
    color:#475467;
}
.track{
    height:8px;
    background:#edf1f5;
    border-radius:99px;
    overflow:hidden;
}
.fill{
    height:100%;
    background:#16a34a;
}
.list{
    display:grid;
    gap:10px;
}
.item{
    display:flex;
    justify-content:space-between;
    padding:10px 0;
    border-bottom:1px solid #edf0f4;
}
.item:last-child{
    border-bottom:0;
}
.item span{
    color:#667085;
    font-size:11px;
}
.item strong{
    color:#172033;
    font-size:11px;
}
.chart{
    height:220px;
    display:flex;
    align-items:flex-end;
    gap:14px;
    padding:15px 8px 8px;
}
.bar-col{
    flex:1;
    height:200px;
    display:flex;
    flex-direction:column;
    justify-content:flex-end;
    align-items:center;
}
.bar-value{
    font-size:10px;
    font-weight:700;
    color:#344054;
    margin-bottom:5px;
}
.bar{
    width:40px;
    max-width:75%;
    min-height:4px;
    background:#16a34a;
    border-radius:6px 6px 2px 2px;
}
.bar-label{
    font-size:10px;
    color:#7b8799;
    margin-top:8px;
}
@media(max-width:1000px){
    .kpis{
        grid-template-columns:repeat(2,1fr);
    }
    .grid{
        grid-template-columns:1fr;
    }
}
@media(max-width:600px){
    .report-page{
        padding:20px 14px;
    }
    .kpis{
        grid-template-columns:1fr;
    }
    .report-head{
        display:block;
    }
    .date{
        display:inline-block;
        margin-top:12px;
    }
}
</style>

<div class="report-page">

    <div class="report-head">
        <div>
            <h1>Reports</h1>
            <p>
                Live report based on the actual customers and applications
                handled by this agent.
            </p>
        </div>

        <div class="date">
            <?= reportEsc(date('d M Y')) ?>
        </div>
    </div>

    <!-- REAL COUNTS -->
    <div class="kpis">

        <div class="kpi">
            <small>Assigned Customers</small>
            <strong><?= $totalCustomers ?></strong>
        </div>

        <div class="kpi">
            <small>Applications Handled</small>
            <strong><?= $totalApplications ?></strong>
        </div>

        <div class="kpi">
            <small>Completed Applications</small>
            <strong class="green"><?= $completed ?></strong>
        </div>

        <div class="kpi">
            <small>Credited Commission</small>
            <strong>
                ₹<?= reportEsc(number_format($commission, 2)) ?>
            </strong>
        </div>

    </div>

    <div class="kpis">

        <div class="kpi">
            <small>Follow-ups</small>
            <strong><?= $followups ?></strong>
        </div>

        <div class="kpi">
            <small>Documents</small>
            <strong><?= $documents ?></strong>
        </div>

        <div class="kpi">
            <small>Approval Rate</small>
            <strong><?= $approvalRate ?>%</strong>
        </div>

        <div class="kpi">
            <small>Completed Rate</small>
            <strong><?= $completionRate ?>%</strong>
        </div>

    </div>

    <!-- TREND + STATUS -->
    <div class="grid">

        <div class="card">

            <div class="card-head">
                <h2>Application Trend</h2>
                <p>Applications actually created for this agent.</p>
            </div>

            <div class="card-body">

                <div class="chart">

                    <?php foreach ($months as $month): ?>

                        <?php
                        $height = $month['count'] > 0
                            ? max(
                                8,
                                (int)round(
                                    ($month['count'] / $maxMonth) * 175
                                )
                            )
                            : 4;
                        ?>

                        <div class="bar-col">

                            <div class="bar-value">
                                <?= (int)$month['count'] ?>
                            </div>

                            <div
                                class="bar"
                                style="height:<?= $height ?>px"
                            ></div>

                            <div class="bar-label">
                                <?= reportEsc($month['label']) ?>
                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

            </div>

        </div>

        <div class="card">

            <div class="card-head">
                <h2>Application Status</h2>
                <p>Current status from actual application records.</p>
            </div>

            <div class="card-body">

                <div class="status">

                    <?php foreach ($statusCounts as $label => $count): ?>

                        <?php
                        $percent = $totalApplications > 0
                            ? (int)round(
                                ($count / $totalApplications) * 100
                            )
                            : 0;
                        ?>

                        <div class="status-row">

                            <span>
                                <?= reportEsc($label) ?>
                            </span>

                            <div class="track">
                                <div
                                    class="fill"
                                    style="width:<?= $percent ?>%"
                                ></div>
                            </div>

                            <strong><?= $count ?></strong>

                        </div>

                    <?php endforeach; ?>

                </div>

            </div>

        </div>

    </div>

    <!-- CUSTOMER DETAILS -->
    <div class="grid">

        <div class="card">

            <div class="card-head">
                <h2>Assigned Customer Details</h2>
                <p>
                    Customers coming from Admin assignment or an application
                    handled by this agent.
                </p>
            </div>

            <div class="card-body">

                <?php if (!$customers): ?>

                    <div class="empty">
                        No customer is currently linked to this agent.
                    </div>

                <?php else: ?>

                    <div style="overflow:auto">

                        <table>

                            <thead>
                                <tr>
                                    <th>Customer</th>
                                    <th>ID</th>
                                    <th>Mobile</th>
                                    <th>Requirement</th>
                                    <th>Status</th>
                                </tr>
                            </thead>

                            <tbody>

                            <?php foreach ($customers as $customer): ?>

                                <tr>

                                    <td>
                                        <strong>
                                            <?= reportEsc(
                                                $customer['name']
                                                ?: 'Customer'
                                            ) ?>
                                        </strong>

                                        <?php if (!empty($customer['email'])): ?>
                                            <br>
                                            <?= reportEsc(
                                                $customer['email']
                                            ) ?>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?= reportEsc(
                                            $customer['customer_id']
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= reportEsc(
                                            $customer['mobile']
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= reportEsc(
                                            $customer['requirement']
                                            ?: 'Requirement not specified'
                                        ) ?>
                                    </td>

                                    <td>
                                        <span class="badge">
                                            <?= reportEsc(
                                                $customer['customer_status']
                                                ?: 'Assigned'
                                            ) ?>
                                        </span>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php endif; ?>

            </div>

        </div>

        <!-- REQUIREMENTS -->

        <div class="card">

            <div class="card-head">
                <h2>Customer Requirements</h2>
                <p>
                    Requirements stored against the actual customer records.
                </p>
            </div>

            <div class="card-body">

                <?php if (!$requirements): ?>

                    <div class="empty">
                        No requirement information available.
                    </div>

                <?php else: ?>

                    <div class="list">

                        <?php foreach (
                            $requirements as $requirement => $count
                        ): ?>

                            <div class="item">

                                <span>
                                    <?= reportEsc($requirement) ?>
                                </span>

                                <strong>
                                    <?= (int)$count ?>
                                </strong>

                            </div>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>

            </div>

        </div>

    </div>

    <!-- APPLICATIONS -->

    <div class="card" style="margin-bottom:16px">

        <div class="card-head">

            <h2>Applications</h2>

            <p>
                Applications actually linked to this agent.
            </p>

        </div>

        <div class="card-body">

            <?php if (!$applications): ?>

                <div class="empty">
                    No applications found.
                </div>

            <?php else: ?>

                <div style="overflow:auto">

                    <table>

                        <thead>
                            <tr>
                                <th>Application</th>
                                <th>Customer ID</th>
                                <th>Product</th>
                                <th>Amount</th>
                                <th>Tenure</th>
                                <th>Status</th>
                                <th>Stage</th>
                            </tr>
                        </thead>

                        <tbody>

                        <?php foreach ($applications as $application): ?>

                            <tr>

                                <td>
                                    <strong>
                                        <?= reportEsc(
                                            $application['application_number']
                                            ?: 'APP-' . $application['id']
                                        ) ?>
                                    </strong>
                                </td>

                                <td>
                                    <?= reportEsc(
                                        $application['customer_id']
                                    ) ?>
                                </td>

                                <td>
                                    <?= reportEsc(
                                        $application['product_name']
                                        ?: 'Not specified'
                                    ) ?>
                                </td>

                                <td>
                                    <?php if (
                                        $application['amount'] !== null &&
                                        $application['amount'] !== ''
                                    ): ?>

                                        ₹<?= reportEsc(
                                            number_format(
                                                (float)$application['amount'],
                                                2
                                            )
                                        ) ?>

                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?= reportEsc(
                                        $application['tenure'] ?: '—'
                                    ) ?>
                                </td>

                                <td>
                                    <span class="badge">
                                        <?= reportEsc(
                                            $application['status']
                                            ?: 'Not specified'
                                        ) ?>
                                    </span>
                                </td>

                                <td>
                                    <?= reportEsc(
                                        $application['current_stage']
                                        ?: '—'
                                    ) ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

        </div>

    </div>

    <!-- PRODUCTS + DOCUMENTS -->

    <div class="grid">

        <div class="card">

            <div class="card-head">

                <h2>Products / Services Used</h2>

                <p>
                    Taken from actual application records.
                </p>

            </div>

            <div class="card-body">

                <?php if (!$products): ?>

                    <div class="empty">
                        No product information available.
                    </div>

                <?php else: ?>

                    <div class="list">

                        <?php foreach ($products as $product => $count): ?>

                            <div class="item">

                                <span>
                                    <?= reportEsc($product) ?>
                                </span>

                                <strong>
                                    <?= (int)$count ?>
                                    application<?= $count === 1 ? '' : 's' ?>
                                </strong>

                            </div>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>

            </div>

        </div>

        <div class="card">

            <div class="card-head">

                <h2>Document Verification</h2>

                <p>
                    Documents belonging to the actual customers.
                </p>

            </div>

            <div class="card-body">

                <div class="list">

                    <div class="item">
                        <span>Documents submitted</span>
                        <strong><?= $documents ?></strong>
                    </div>

                    <div class="item">
                        <span>Verified by Admin</span>
                        <strong><?= $verifiedDocuments ?></strong>
                    </div>

                    <div class="item">
                        <span>Pending verification</span>
                        <strong><?= $pendingDocuments ?></strong>
                    </div>

                    <div class="item">
                        <span>Rejected</span>
                        <strong><?= $rejectedDocuments ?></strong>
                    </div>

                    <div class="item">
                        <span>Follow-ups</span>
                        <strong><?= $followups ?></strong>
                    </div>

                </div>

            </div>

        </div>

    </div>

</div>

<?php agent_shell_close(); ?>
