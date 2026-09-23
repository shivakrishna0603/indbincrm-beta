<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/shell.php';

$agent = require_agent($pdo);
$agentId = (int)$agent['id'];


/*
|--------------------------------------------------------------------------
| HELPER
|--------------------------------------------------------------------------
*/

function trackingEsc($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}


/*
|--------------------------------------------------------------------------
| GET APPLICATIONS HANDLED BY THIS AGENT
|--------------------------------------------------------------------------
|
| applications.agent_id = users.id of the assigned agent
| applications.customer_id = users.id of the customer
|--------------------------------------------------------------------------
*/

$applications = [];

try {

    $stmt = $pdo->prepare("
        SELECT
            a.id AS application_id,
            a.application_number,
            a.customer_id AS customer_user_id,
            a.agent_id,
            a.merchant_id,
            a.product_code,
            a.product_name,
            a.category,
            a.amount,
            a.tenure_months,
            a.current_stage,
            a.status AS application_status,
            a.notes,
            a.created_at,
            a.updated_at,
            a.submitted_at,

            c.id AS customer_id,
            c.name AS customer_name,
            c.mobile AS customer_mobile,

            u.customer_code

        FROM applications a

        LEFT JOIN customers c
            ON c.user_id = a.customer_id

        LEFT JOIN users u
            ON u.id = a.customer_id

        WHERE a.agent_id = ?

        ORDER BY
            a.updated_at DESC,
            a.id DESC
    ");

    $stmt->execute([
        $agentId
    ]);

    $applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {

    $applications = [];
}


/*
|--------------------------------------------------------------------------
| STATUS COUNTS
|--------------------------------------------------------------------------
*/

$pendingCount = 0;
$progressCount = 0;
$approvedCount = 0;
$completedCount = 0;

foreach ($applications as $application) {

    $status = strtolower(
        trim(
            (string)(
                $application['application_status']
                ?? ''
            )
        )
    );

    $stage = strtolower(
        trim(
            (string)(
                $application['current_stage']
                ?? ''
            )
        )
    );


    /*
    | Pending:
    | draft / requirement_raised / submitted
    */

    if (
        in_array(
            $status,
            [
                'draft',
                'requirement_raised',
                'submitted'
            ],
            true
        )
    ) {

        $pendingCount++;

    }


    /*
    | In Progress:
    | under_review / kyc_check / credit_review
    */

    if (
        in_array(
            $status,
            [
                'under_review'
            ],
            true
        )
        ||
        in_array(
            $stage,
            [
                'kyc_check',
                'credit_review'
            ],
            true
        )
    ) {

        $progressCount++;

    }


    /*
    | Approved
    */

    if (
        $status === 'approved'
    ) {

        $approvedCount++;

    }


    /*
    | Completed / Disbursed
    */

    if (
        in_array(
            $status,
            [
                'completed',
                'disbursed'
            ],
            true
        )
    ) {

        $completedCount++;

    }
}


/*
|--------------------------------------------------------------------------
| OPEN AGENT SHELL
|--------------------------------------------------------------------------
*/

agent_shell_open(
    $pdo,
    $agentId,
    'tracking',
    'Application Tracking'
);

?>

<style>

.tracking-page {
    width: 100%;
}

.tracking-header {
    margin-bottom: 20px;
}

.tracking-header h1 {
    margin: 0;
    color: #172033;
    font-size: 27px;
    font-weight: 800;
}

.tracking-header p {
    margin: 7px 0 0;
    color: #718096;
    font-size: 13px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    margin-bottom: 14px;
}

.stats-grid.second-row {
    grid-template-columns: 1fr;
    max-width: calc((100% - 28px) / 3);
    margin-bottom: 16px;
}

.stat-card {
    padding: 17px 18px;
    background: #fff;
    border: 1px solid #e5e9f0;
    border-radius: 11px;
}

.stat-value {
    color: #172033;
    font-size: 24px;
    font-weight: 800;
}

.stat-label {
    margin-top: 5px;
    color: #718096;
    font-size: 11px;
}

.application-card {
    background: #fff;
    border: 1px solid #e5e9f0;
    border-radius: 12px;
    overflow: hidden;
}

.application-card-header {
    padding: 17px 18px;
    border-bottom: 1px solid #edf0f4;
}

.application-card-header h2 {
    margin: 0;
    color: #172033;
    font-size: 15px;
    font-weight: 800;
}

.application-card-header p {
    margin: 5px 0 0;
    color: #718096;
    font-size: 11px;
}

.table-wrap {
    overflow-x: auto;
}

.application-table {
    width: 100%;
    border-collapse: collapse;
}

.application-table th {
    padding: 12px 14px;
    background: #f8fafc;
    border-bottom: 1px solid #edf0f4;
    color: #68758b;
    font-size: 10px;
    font-weight: 800;
    text-align: left;
    text-transform: uppercase;
}

.application-table td {
    padding: 14px;
    border-bottom: 1px solid #edf0f4;
    color: #344563;
    font-size: 12px;
    vertical-align: middle;
}

.application-table tr:last-child td {
    border-bottom: 0;
}

.application-number {
    display: block;
    color: #172033;
    font-weight: 800;
}

.application-product {
    display: block;
    margin-top: 4px;
    color: #718096;
    font-size: 10px;
}

.customer-name {
    display: block;
    color: #172033;
    font-weight: 800;
}

.customer-code {
    display: block;
    margin-top: 4px;
    color: #94a3b8;
    font-size: 10px;
}

.amount {
    color: #172033;
    font-weight: 700;
}

.status {
    display: inline-flex;
    padding: 5px 9px;
    border-radius: 999px;
    font-size: 10px;
    font-weight: 800;
    white-space: nowrap;
}

.status.pending {
    background: #fff7ed;
    color: #c2410c;
}

.status.progress {
    background: #eff6ff;
    color: #2563eb;
}

.status.approved {
    background: #dcfce7;
    color: #15803d;
}

.status.completed {
    background: #dcfce7;
    color: #166534;
}

.stage {
    color: #475569;
    font-size: 11px;
    font-weight: 700;
}

.date-text {
    color: #64748b;
    font-size: 11px;
}

.empty-state {
    padding: 48px 20px;
    color: #94a3b8;
    font-size: 12px;
    text-align: center;
}

.empty-state strong {
    display: block;
    margin-bottom: 5px;
    color: #344563;
    font-size: 13px;
}

@media (max-width: 900px) {

    .stats-grid {
        grid-template-columns: 1fr;
    }

    .stats-grid.second-row {
        max-width: none;
    }
}

</style>


<div class="tracking-page">


    <!-- =========================================================
         HEADER
    ========================================================== -->

    <div class="tracking-header">

        <h1>
            Application Tracking
        </h1>

        <p>
            Track customer applications after submission.
        </p>

    </div>


    <!-- =========================================================
         FIRST THREE STATISTICS
    ========================================================== -->

    <div class="stats-grid">


        <div class="stat-card">

            <div class="stat-value">
                <?= $pendingCount ?>
            </div>

            <div class="stat-label">
                Pending applications
            </div>

        </div>


        <div class="stat-card">

            <div class="stat-value">
                <?= $progressCount ?>
            </div>

            <div class="stat-label">
                In Progress applications
            </div>

        </div>


        <div class="stat-card">

            <div class="stat-value">
                <?= $approvedCount ?>
            </div>

            <div class="stat-label">
                Approved applications
            </div>

        </div>


    </div>


    <!-- =========================================================
         COMPLETED STATISTIC
    ========================================================== -->

    <div class="stats-grid second-row">


        <div class="stat-card">

            <div class="stat-value">
                <?= $completedCount ?>
            </div>

            <div class="stat-label">
                Completed applications
            </div>

        </div>


    </div>


    <!-- =========================================================
         APPLICATION STATUS
    ========================================================== -->

    <div class="application-card">


        <div class="application-card-header">

            <h2>
                Application Status
            </h2>

            <p>
                Customer applications handled by this agent
            </p>

        </div>


        <div class="table-wrap">


            <?php if (empty($applications)): ?>

                <div class="empty-state">

                    <strong>
                        No applications to track
                    </strong>

                    Submitted applications will appear here.

                </div>

            <?php else: ?>


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
                                Current Stage
                            </th>

                            <th>
                                Updated
                            </th>

                        </tr>

                    </thead>


                    <tbody>


                    <?php foreach ($applications as $application): ?>

                        <?php

                        $status = strtolower(
                            trim(
                                (string)(
                                    $application[
                                        'application_status'
                                    ] ?? 'submitted'
                                )
                            )
                        );

                        $stage = strtolower(
                            trim(
                                (string)(
                                    $application[
                                        'current_stage'
                                    ] ?? 'submitted'
                                )
                            )
                        );


                        if (
                            in_array(
                                $status,
                                [
                                    'approved'
                                ],
                                true
                            )
                        ) {

                            $statusClass = 'approved';

                        } elseif (
                            in_array(
                                $status,
                                [
                                    'completed',
                                    'disbursed'
                                ],
                                true
                            )
                        ) {

                            $statusClass = 'completed';

                        } elseif (
                            $status === 'under_review'
                            ||
                            in_array(
                                $stage,
                                [
                                    'kyc_check',
                                    'credit_review'
                                ],
                                true
                            )
                        ) {

                            $statusClass = 'progress';

                        } else {

                            $statusClass = 'pending';
                        }


                        $displayStatus = ucfirst(
                            str_replace(
                                '_',
                                ' ',
                                $status
                            )
                        );


                        $displayStage = ucfirst(
                            str_replace(
                                '_',
                                ' ',
                                $stage
                            )
                        );

                        ?>


                        <tr>


                            <!-- APPLICATION -->

                            <td>

                                <span class="application-number">

                                    <?= trackingEsc(
                                        $application[
                                            'application_number'
                                        ] ?: (
                                            'APP-' .
                                            $application[
                                                'application_id'
                                            ]
                                        )
                                    ) ?>

                                </span>

                            </td>


                            <!-- CUSTOMER -->

                            <td>

                                <span class="customer-name">

                                    <?= trackingEsc(
                                        $application[
                                            'customer_name'
                                        ] ?? 'Customer'
                                    ) ?>

                                </span>

                                <span class="customer-code">

                                    <?= trackingEsc(
                                        $application[
                                            'customer_code'
                                        ] ?? ''
                                    ) ?>

                                </span>

                            </td>


                            <!-- PRODUCT -->

                            <td>

                                <span class="application-product">

                                    <?= trackingEsc(
                                        $application[
                                            'product_name'
                                        ] ?? 'Product'
                                    ) ?>

                                </span>

                            </td>


                            <!-- AMOUNT -->

                            <td>

                                <span class="amount">

                                    ₹<?= trackingEsc(
                                        number_format(
                                            (float)(
                                                $application[
                                                    'amount'
                                                ] ?? 0
                                            ),
                                            2
                                        )
                                    ) ?>

                                </span>

                            </td>


                            <!-- STATUS -->

                            <td>

                                <span
                                    class="status <?= trackingEsc(
                                        $statusClass
                                    ) ?>"
                                >

                                    <?= trackingEsc(
                                        $displayStatus
                                    ) ?>

                                </span>

                            </td>


                            <!-- CURRENT STAGE -->

                            <td>

                                <span class="stage">

                                    <?= trackingEsc(
                                        $displayStage
                                    ) ?>

                                </span>

                            </td>


                            <!-- UPDATED -->

                            <td>

                                <span class="date-text">

                                    <?= trackingEsc(
                                        !empty(
                                            $application[
                                                'updated_at'
                                            ]
                                        )
                                        ? date(
                                            'd M Y',
                                            strtotime(
                                                (string)$application[
                                                    'updated_at'
                                                ]
                                            )
                                        )
                                        : '—'
                                    ) ?>

                                </span>

                            </td>


                        </tr>


                    <?php endforeach; ?>


                    </tbody>

                </table>


            <?php endif; ?>


        </div>

    </div>


</div>


<?php

agent_shell_close();

?>
