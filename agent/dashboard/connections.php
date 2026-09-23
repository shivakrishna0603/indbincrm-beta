<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/shell.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/* =========================================================
   AGENT
   ========================================================= */

$agent = require_agent($pdo);

$agentId = (int)(
    $agent['id']
    ?? $_SESSION['user_id']
    ?? 0
);

if ($agentId <= 0) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}


/* =========================================================
   HELPERS
   ========================================================= */

function connectionEsc($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}


/**
 * Check whether a table exists.
 */
function connectionTableExists(
    PDO $pdo,
    string $table
): bool {

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = ?
    ");

    $stmt->execute([$table]);

    return (int)$stmt->fetchColumn() > 0;
}


/**
 * Check whether a column exists.
 */
function connectionColumnExists(
    PDO $pdo,
    string $table,
    string $column
): bool {

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = ?
          AND column_name = ?
    ");

    $stmt->execute([
        $table,
        $column
    ]);

    return (int)$stmt->fetchColumn() > 0;
}


/**
 * Return first existing column from a list.
 */
function connectionFirstColumn(
    PDO $pdo,
    string $table,
    array $columns
): ?string {

    foreach ($columns as $column) {

        if (
            connectionColumnExists(
                $pdo,
                $table,
                $column
            )
        ) {
            return $column;
        }
    }

    return null;
}


/* =========================================================
   DATA
   ========================================================= */

$customers = [];
$applications = [];
$merchants = [];

$loadError = '';

try {

    /* =====================================================
       1. ASSIGNED CUSTOMERS
       ===================================================== */

    /*
     * IMPORTANT:
     *
     * Customer is considered connected when:
     *
     * A) Admin assigned the customer to this agent
     *
     * OR
     *
     * B) An application belonging to this agent
     *    exists for that customer.
     *
     * This prevents the dashboard/Connections page
     * from incorrectly showing 0.
     */

    $customerSql = "
        SELECT DISTINCT

            c.id AS customer_id,

            c.user_id AS customer_user_id,

            c.name AS customer_name,

            c.mobile AS customer_mobile,

            c.email AS customer_email,

            c.requirement AS customer_requirement,

            c.status AS customer_status,

            u.customer_code,

            u.email AS user_email,

            ca.assigned_at,

            ca.status AS assignment_status

        FROM customers c

        LEFT JOIN users u
            ON u.id = c.user_id

        LEFT JOIN customer_agent_assignments ca

            ON ca.customer_id = c.id

            AND ca.agent_user_id = ?

            AND ca.status = 'active'

        WHERE

            ca.id IS NOT NULL

            OR EXISTS (

                SELECT 1

                FROM applications ax

                WHERE ax.customer_id = c.user_id

                  AND ax.agent_id = ?

            )

        ORDER BY

            COALESCE(
                ca.assigned_at,
                c.id
            ) DESC,

            c.id DESC
    ";


    $customerStmt =
        $pdo->prepare(
            $customerSql
        );


    $customerStmt->execute([
        $agentId,
        $agentId
    ]);


    $customers =
        $customerStmt->fetchAll(
            PDO::FETCH_ASSOC
        );


    /* =====================================================
       2. APPLICATIONS
       ===================================================== */

    /*
     * Applications are taken directly from the
     * applications table for this logged-in agent.
     */

    $applicationStmt = $pdo->prepare("
        SELECT

            a.id AS application_id,

            a.application_number,

            a.customer_id,

            a.agent_id,

            a.product_code,

            a.product_name,

            a.status AS application_status,

            a.current_stage,

            a.amount AS application_amount,

            a.tenure_months AS application_tenure,

            a.created_at AS application_created_at,

            c.id AS customer_row_id,

            c.name AS customer_name,

            c.mobile AS customer_mobile,

            c.email AS customer_email,

            u.customer_code

        FROM applications a

        LEFT JOIN customers c
            ON c.user_id = a.customer_id

        LEFT JOIN users u
            ON u.id = c.user_id

        WHERE a.agent_id = ?

        ORDER BY a.id DESC
    ");


    $applicationStmt->execute([
        $agentId
    ]);


    $applications =
        $applicationStmt->fetchAll(
            PDO::FETCH_ASSOC
        );


    /* =====================================================
       3. MERCHANTS
       ===================================================== */

    /*
     * Merchant is secondary on this page.
     *
     * We do NOT make the whole Connections page
     * dependent on the merchant table.
     */

    if (
        connectionTableExists(
            $pdo,
            'merchants'
        )
    ) {

        $merchantNameColumn =
            connectionFirstColumn(
                $pdo,
                'merchants',
                [
                    'merchant_name',
                    'name',
                    'business_name',
                    'company_name'
                ]
            );


        $merchantStatusColumn =
            connectionFirstColumn(
                $pdo,
                'merchants',
                [
                    'status',
                    'merchant_status'
                ]
            );


        $merchantIdColumn =
            connectionFirstColumn(
                $pdo,
                'merchants',
                [
                    'id',
                    'merchant_id'
                ]
            );


        if (
            $merchantNameColumn
            && $merchantIdColumn
        ) {

            $merchantStatusSql = '';

            if ($merchantStatusColumn) {

                $merchantStatusSql = "
                    WHERE (
                        `$merchantStatusColumn`
                        IS NULL

                        OR

                        LOWER(
                            `$merchantStatusColumn`
                        ) NOT IN (
                            'rejected',
                            'inactive',
                            'disabled'
                        )
                    )
                ";
            }


            $merchantSql = "
                SELECT

                    `$merchantIdColumn`
                    AS merchant_id,

                    `$merchantNameColumn`
                    AS merchant_name

                FROM merchants

                $merchantStatusSql

                ORDER BY
                    `$merchantNameColumn` ASC
            ";


            $merchantStmt =
                $pdo->query(
                    $merchantSql
                );


            if ($merchantStmt) {

                $merchants =
                    $merchantStmt->fetchAll(
                        PDO::FETCH_ASSOC
                    );
            }
        }
    }

} catch (Throwable $e) {

    $loadError =
        $e->getMessage();

    /*
     * Do not destroy the page if one optional
     * section has a database problem.
     */

    if (!$customers) {
        $customers = [];
    }

    if (!$applications) {
        $applications = [];
    }

    if (!$merchants) {
        $merchants = [];
    }
}


/* =========================================================
   COUNTS
   ========================================================= */

$customerCount =
    count($customers);

$applicationCount =
    count($applications);

$merchantCount =
    count($merchants);


/* =========================================================
   OPEN AGENT SHELL
   ========================================================= */

agent_shell_open(
    $pdo,
    $agentId,
    'Connections',
    'connections.php'
);

?>

<style>

/* =========================================================
   PAGE
   ========================================================= */

.connections-page {
    padding: 28px 24px 50px;
    background: #f7f9fc;
    min-height: calc(100vh - 70px);
}


/* =========================================================
   HEADER
   ========================================================= */

.connections-header {
    margin-bottom: 20px;
}

.connections-header h1 {
    margin: 0;
    color: #172033;
    font-size: 30px;
    font-weight: 800;
}

.connections-header p {
    margin: 6px 0 0;
    color: #667085;
    font-size: 13px;
}


/* =========================================================
   ERROR
   ========================================================= */

.connection-error {
    background: #fff1f2;
    color: #b42318;
    border: 1px solid #fecdd3;
    border-radius: 10px;
    padding: 12px 14px;
    margin-bottom: 18px;
    font-size: 12px;
}


/* =========================================================
   SUMMARY CARDS
   ========================================================= */

.connection-summary {
    display: grid;
    grid-template-columns:
        repeat(3, minmax(0, 1fr));

    gap: 15px;

    margin-bottom: 18px;
}

.summary-card {
    background: #fff;
    border: 1px solid #e5e9ef;
    border-radius: 12px;
    padding: 18px;
}

.summary-number {
    font-size: 28px;
    font-weight: 800;
    color: #172033;
}

.summary-label {
    margin-top: 5px;
    color: #667085;
    font-size: 11px;
}


/* =========================================================
   MAIN GRID
   ========================================================= */

.connection-grid {
    display: grid;

    grid-template-columns:
        minmax(0, 1fr)
        minmax(0, 1fr);

    gap: 15px;

    margin-bottom: 18px;
}


/* =========================================================
   PANEL
   ========================================================= */

.connection-panel {
    background: #fff;
    border: 1px solid #e5e9ef;
    border-radius: 13px;
    overflow: hidden;
}

.panel-header {
    padding: 17px 18px;
    border-bottom: 1px solid #edf0f3;
}

.panel-header h2 {
    margin: 0;
    color: #172033;
    font-size: 16px;
    font-weight: 800;
}

.panel-header p {
    margin: 5px 0 0;
    color: #98a2b3;
    font-size: 11px;
}


/* =========================================================
   CUSTOMER LIST
   ========================================================= */

.customer-list {
    max-height: 330px;
    overflow-y: auto;
}

.customer-row {
    display: flex;
    align-items: center;
    gap: 12px;

    padding: 13px 17px;

    border-bottom: 1px solid #f0f2f5;
}

.customer-row:last-child {
    border-bottom: 0;
}

.customer-avatar {
    width: 38px;
    height: 38px;
    min-width: 38px;

    border-radius: 50%;

    background: #dcfce7;
    color: #15803d;

    display: flex;
    align-items: center;
    justify-content: center;

    font-size: 13px;
    font-weight: 800;
}

.customer-info {
    flex: 1;
    min-width: 0;
}

.customer-name {
    color: #172033;
    font-size: 13px;
    font-weight: 750;
}

.customer-meta {
    margin-top: 3px;
    color: #98a2b3;
    font-size: 10px;
}

.customer-status {
    background: #ecfdf3;
    color: #087443;
    padding: 5px 8px;
    border-radius: 15px;
    font-size: 9px;
    font-weight: 750;
}


/* =========================================================
   MERCHANTS
   ========================================================= */

.merchant-list {
    max-height: 330px;
    overflow-y: auto;
}

.merchant-row {
    padding: 13px 17px;
    border-bottom: 1px solid #f0f2f5;
}

.merchant-row:last-child {
    border-bottom: 0;
}

.merchant-name {
    color: #172033;
    font-size: 12px;
    font-weight: 750;
}


/* =========================================================
   EMPTY
   ========================================================= */

.connection-empty {
    min-height: 180px;

    display: flex;
    align-items: center;
    justify-content: center;

    flex-direction: column;

    text-align: center;

    padding: 20px;

    color: #98a2b3;
}

.connection-empty strong {
    color: #344054;
    font-size: 13px;
}

.connection-empty span {
    margin-top: 6px;
    font-size: 11px;
}


/* =========================================================
   APPLICATION PANEL
   ========================================================= */

.application-panel {
    background: #fff;
    border: 1px solid #e5e9ef;
    border-radius: 13px;
    overflow: hidden;
}

.application-header {
    padding: 17px 18px;
    border-bottom: 1px solid #edf0f3;
}

.application-header h2 {
    margin: 0;
    color: #172033;
    font-size: 16px;
    font-weight: 800;
}

.application-header p {
    margin: 5px 0 0;
    color: #98a2b3;
    font-size: 11px;
}


/* =========================================================
   APPLICATION TABLE
   ========================================================= */

.application-table-wrapper {
    overflow-x: auto;
}

.application-table {
    width: 100%;
    border-collapse: collapse;
}

.application-table th {
    background: #f8fafc;
    color: #667085;

    padding: 11px 14px;

    text-align: left;

    font-size: 9px;
    font-weight: 800;

    text-transform: uppercase;

    white-space: nowrap;
}

.application-table td {
    padding: 13px 14px;

    border-top: 1px solid #edf0f3;

    color: #344054;

    font-size: 11px;

    white-space: nowrap;
}

.application-number {
    color: #172033;
    font-weight: 800;
}

.product-name {
    color: #667085;
    margin-top: 3px;
    font-size: 9px;
}

.amount {
    color: #172033;
    font-weight: 750;
}


/* =========================================================
   BADGES
   ========================================================= */

.badge {
    display: inline-flex;

    padding: 5px 9px;

    border-radius: 15px;

    font-size: 9px;

    font-weight: 750;
}

.badge-submitted {
    background: #eef4ff;
    color: #315b9b;
}

.badge-approved {
    background: #dcfce7;
    color: #15803d;
}

.badge-pending {
    background: #fff4d6;
    color: #a45a00;
}

.badge-rejected {
    background: #fee4e2;
    color: #b42318;
}

.badge-default {
    background: #f2f4f7;
    color: #475467;
}


/* =========================================================
   APPLICATION LINK
   ========================================================= */

.application-link {
    color: #16a34a;
    text-decoration: none;
    font-weight: 800;
}

.application-link:hover {
    text-decoration: underline;
}


/* =========================================================
   RESPONSIVE
   ========================================================= */

@media (max-width: 900px) {

    .connection-grid {
        grid-template-columns: 1fr;
    }

    .connection-summary {
        grid-template-columns: 1fr;
    }
}

</style>


<div class="connections-page">


    <!-- =====================================================
         HEADER
         ===================================================== -->

    <div class="connections-header">

        <h1>
            Connections
        </h1>

        <p>
            View customers and applications connected to you.
        </p>

    </div>


    <?php if ($loadError !== ''): ?>

        <div class="connection-error">

            Some connection information could not be loaded.

            <br>

            <small>
                <?= connectionEsc($loadError) ?>
            </small>

        </div>

    <?php endif; ?>


    <!-- =====================================================
         SUMMARY
         ===================================================== -->

    <div class="connection-summary">


        <div class="summary-card">

            <div class="summary-number">
                <?= $customerCount ?>
            </div>

            <div class="summary-label">
                Customers connected to you
            </div>

        </div>


        <div class="summary-card">

            <div class="summary-number">
                <?= $merchantCount ?>
            </div>

            <div class="summary-label">
                Merchants available
            </div>

        </div>


        <div class="summary-card">

            <div class="summary-number">
                <?= $applicationCount ?>
            </div>

            <div class="summary-label">
                Applications linked to you
            </div>

        </div>


    </div>


    <!-- =====================================================
         CUSTOMER + MERCHANT
         ===================================================== -->

    <div class="connection-grid">


        <!-- =================================================
             CUSTOMER CONNECTIONS
             ================================================= -->

        <div class="connection-panel">


            <div class="panel-header">

                <h2>
                    Customer Connections
                </h2>

                <p>
                    Customers assigned to this agent
                </p>

            </div>


            <?php if (!$customers): ?>

                <div class="connection-empty">

                    <strong>
                        No customer connections
                    </strong>

                    <span>
                        Customers assigned by Admin
                        will appear here.
                    </span>

                </div>

            <?php else: ?>


                <div class="customer-list">


                    <?php foreach ($customers as $customer): ?>

                        <?php

                        $customerName =
                            trim(
                                (string)(
                                    $customer[
                                        'customer_name'
                                    ] ?? ''
                                )
                            );

                        $initial =
                            strtoupper(
                                substr(
                                    $customerName
                                    ?: 'C',
                                    0,
                                    1
                                )
                            );

                        $customerCode =
                            $customer[
                                'customer_code'
                            ]
                            ?? $customer[
                                'customer_id'
                            ];

                        ?>


                        <div class="customer-row">


                            <div class="customer-avatar">

                                <?= connectionEsc(
                                    $initial
                                ) ?>

                            </div>


                            <div class="customer-info">

                                <div class="customer-name">

                                    <?= connectionEsc(
                                        $customerName
                                        ?: 'Customer'
                                    ) ?>

                                </div>


                                <div class="customer-meta">

                                    ID:
                                    <?= connectionEsc(
                                        $customerCode
                                    ) ?>

                                    &nbsp; • &nbsp;

                                    <?= connectionEsc(
                                        $customer[
                                            'customer_mobile'
                                        ]
                                        ?? 'No mobile'
                                    ) ?>

                                </div>

                            </div>


                            <div class="customer-status">

                                Connected

                            </div>


                        </div>


                    <?php endforeach; ?>


                </div>


            <?php endif; ?>


        </div>


        <!-- =================================================
             MERCHANT CONNECTIONS
             ================================================= -->

        <div class="connection-panel">


            <div class="panel-header">

                <h2>
                    Merchant Connections
                </h2>

                <p>
                    Merchant sources for products and services
                </p>

            </div>


            <?php if (!$merchants): ?>

                <div class="connection-empty">

                    <strong>
                        No merchants available
                    </strong>

                    <span>
                        Merchant information will appear
                        after merchant onboarding.
                    </span>

                </div>

            <?php else: ?>


                <div class="merchant-list">


                    <?php foreach ($merchants as $merchant): ?>

                        <div class="merchant-row">

                            <div class="merchant-name">

                                <?= connectionEsc(
                                    $merchant[
                                        'merchant_name'
                                    ]
                                ) ?>

                            </div>

                        </div>

                    <?php endforeach; ?>


                </div>


            <?php endif; ?>


        </div>


    </div>


    <!-- =====================================================
         APPLICATION CONNECTIONS
         ===================================================== -->

    <div class="application-panel">


        <div class="application-header">

            <h2>
                Application Connections
            </h2>

            <p>
                Applications raised by customers connected
                to this agent.
            </p>

        </div>


        <?php if (!$applications): ?>


            <div class="connection-empty">

                <strong>
                    No applications connected
                </strong>

                <span>
                    Applications raised by your assigned
                    customers will appear here.
                </span>

            </div>


        <?php else: ?>


            <div class="application-table-wrapper">


                <table class="application-table">


                    <thead>

                        <tr>

                            <th>
                                Application
                            </th>

                            <th>
                                Customer
                            </th>

                            <th>
                                Product
                            </th>

                            <th>
                                Amount
                            </th>

                            <th>
                                Status
                            </th>

                            <th>
                                Stage
                            </th>

                        </tr>

                    </thead>


                    <tbody>


                    <?php foreach (
                        $applications
                        as $application
                    ): ?>


                        <?php

                        $status =
                            strtolower(
                                trim(
                                    (string)(
                                        $application[
                                            'application_status'
                                        ] ?? ''
                                    )
                                )
                            );


                        $statusClass =
                            match ($status) {

                                'approved',
                                'completed',
                                'success',
                                'active'
                                    => 'badge-approved',

                                'pending',
                                'under_review',
                                'review'
                                    => 'badge-pending',

                                'rejected',
                                'cancelled'
                                    => 'badge-rejected',

                                'submitted'
                                    => 'badge-submitted',

                                default
                                    => 'badge-default'
                            };


                        $stage =
                            trim(
                                (string)(
                                    $application[
                                        'current_stage'
                                    ] ?? ''
                                )
                            );


                        if ($stage === '') {
                            $stage = $status ?: 'Submitted';
                        }


                        $amount =
                            $application[
                                'application_amount'
                            ];


                        ?>


                        <tr>


                            <!-- APPLICATION -->

                            <td>

                                <a
                                    class="application-link"
                                    href="<?= BASE_URL ?>/agent/dashboard/applications.php?application_id=<?= (int)$application['application_id'] ?>"
                                >

                                    <?= connectionEsc(
                                        $application[
                                            'application_number'
                                        ]
                                        ?: (
                                            'APP-'
                                            . $application[
                                                'application_id'
                                            ]
                                        )
                                    ) ?>

                                </a>

                            </td>


                            <!-- CUSTOMER -->

                            <td>

                                <strong>

                                    <?= connectionEsc(
                                        $application[
                                            'customer_name'
                                        ]
                                        ?: 'Customer'
                                    ) ?>

                                </strong>

                                <div
                                    style="
                                    color:#98a2b3;
                                    margin-top:3px;
                                    font-size:9px;
                                    "
                                >

                                    <?= connectionEsc(
                                        $application[
                                            'customer_code'
                                        ]
                                        ?: $application[
                                            'customer_row_id'
                                        ]
                                    ) ?>

                                </div>

                            </td>


                            <!-- PRODUCT -->

                            <td>

                                <?= connectionEsc(
                                    $application[
                                        'product_name'
                                    ]
                                    ?: (
                                        $application[
                                            'product_code'
                                        ]
                                        ?: 'Product'
                                    )
                                ) ?>

                            </td>


                            <!-- AMOUNT -->

                            <td class="amount">

                                <?php if (
                                    $amount !== null
                                    && $amount !== ''
                                    && is_numeric($amount)
                                ): ?>

                                    ₹<?= number_format(
                                        (float)$amount,
                                        2
                                    ) ?>

                                <?php else: ?>

                                    Not specified

                                <?php endif; ?>

                            </td>


                            <!-- STATUS -->

                            <td>

                                <span
                                    class="badge
                                    <?= $statusClass ?>"
                                >

                                    <?= connectionEsc(
                                        ucwords(
                                            str_replace(
                                                '_',
                                                ' ',
                                                $status
                                                ?: 'Submitted'
                                            )
                                        )
                                    ) ?>

                                </span>

                            </td>


                            <!-- STAGE -->

                            <td>

                                <span
                                    class="badge badge-default"
                                >

                                    <?= connectionEsc(
                                        ucwords(
                                            str_replace(
                                                '_',
                                                ' ',
                                                $stage
                                            )
                                        )
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


<?php

agent_shell_close();

?>