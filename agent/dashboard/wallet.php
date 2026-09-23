<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/shell.php';

$agent = require_agent($pdo);
$agentId = (int)$agent['id'];


/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function walletEsc($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function walletTableExists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = ?
        ");

        $stmt->execute([$table]);

        return (int)$stmt->fetchColumn() > 0;

    } catch (Throwable $e) {
        return false;
    }
}

function walletColumnExists(
    PDO $pdo,
    string $table,
    string $column
): bool {
    try {
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

    } catch (Throwable $e) {
        return false;
    }
}

function walletFirstColumn(
    PDO $pdo,
    string $table,
    array $candidates
): ?string {
    foreach ($candidates as $column) {
        if (walletColumnExists($pdo, $table, $column)) {
            return $column;
        }
    }

    return null;
}


/*
|--------------------------------------------------------------------------
| WALLET / COMMISSION DATA
|--------------------------------------------------------------------------
|
| The page reads the existing commission setup and payout records.
| It does not invent a commission percentage.
|
|--------------------------------------------------------------------------
*/

$commissionRate = null;
$totalCommission = 0.00;
$transactionCount = 0;
$walletRows = [];

$payoutTable = null;


/*
|--------------------------------------------------------------------------
| FIND EXISTING WALLET / COMMISSION TABLE
|--------------------------------------------------------------------------
*/

foreach ([
    'agent_commission_payout',
    'commissions',
    'wallet_transactions',
    'agent_wallet_transactions'
] as $table) {

    if (walletTableExists($pdo, $table)) {
        $payoutTable = $table;
        break;
    }
}


/*
|--------------------------------------------------------------------------
| COMMISSION RATE
|--------------------------------------------------------------------------
|
| Prefer the commission percentage/rate stored for the logged-in agent.
|--------------------------------------------------------------------------
*/

$rateTable = null;
$rateUserColumn = null;
$rateColumn = null;

foreach ([
    'agent_commission_payout',
    'commissions',
    'agent_hierarchy',
    'users'
] as $table) {

    if (!walletTableExists($pdo, $table)) {
        continue;
    }

    $userColumn = walletFirstColumn(
        $pdo,
        $table,
        [
            'user_id',
            'agent_id',
            'agent_user_id',
            'earner_id'
        ]
    );

    $percentageColumn = walletFirstColumn(
        $pdo,
        $table,
        [
            'commission_percentage',
            'commission_percent',
            'commission_rate',
            'percentage',
            'percent',
            'rate'
        ]
    );

    if (
        $userColumn !== null &&
        $percentageColumn !== null
    ) {

        $rateTable = $table;
        $rateUserColumn = $userColumn;
        $rateColumn = $percentageColumn;

        break;
    }
}


/*
|--------------------------------------------------------------------------
| READ COMMISSION RATE
|--------------------------------------------------------------------------
*/

if (
    $rateTable !== null &&
    $rateUserColumn !== null &&
    $rateColumn !== null
) {

    try {

        $stmt = $pdo->prepare("
            SELECT `$rateColumn`
            FROM `$rateTable`
            WHERE `$rateUserColumn` = ?
              AND `$rateColumn` IS NOT NULL
            ORDER BY id DESC
            LIMIT 1
        ");

        $stmt->execute([
            $agentId
        ]);

        $rate = $stmt->fetchColumn();

        if (
            $rate !== false &&
            $rate !== null &&
            $rate !== ''
        ) {

            $commissionRate = (float)$rate;
        }

    } catch (Throwable $e) {

        $commissionRate = null;
    }
}


/*
|--------------------------------------------------------------------------
| READ WALLET / COMMISSION TRANSACTIONS
|--------------------------------------------------------------------------
*/

if ($payoutTable !== null) {

    $userColumn = walletFirstColumn(
        $pdo,
        $payoutTable,
        [
            'user_id',
            'agent_id',
            'agent_user_id',
            'earner_id'
        ]
    );

    $amountColumn = walletFirstColumn(
        $pdo,
        $payoutTable,
        [
            'commission_amount',
            'amount',
            'payout_amount',
            'credited_amount',
            'credit_amount',
            'total'
        ]
    );

    $referenceColumn = walletFirstColumn(
        $pdo,
        $payoutTable,
        [
            'reference_no',
            'reference_number',
            'transaction_id',
            'transaction_number',
            'payout_reference'
        ]
    );

    $statusColumn = walletFirstColumn(
        $pdo,
        $payoutTable,
        [
            'status',
            'payout_status',
            'transaction_status'
        ]
    );

    $dateColumn = walletFirstColumn(
        $pdo,
        $payoutTable,
        [
            'created_at',
            'paid_at',
            'payout_date',
            'transaction_date',
            'credited_at',
            'updated_at'
        ]
    );


    /*
    |--------------------------------------------------------------------------
    | TOTAL COMMISSION
    |--------------------------------------------------------------------------
    */

    if (
        $userColumn !== null &&
        $amountColumn !== null
    ) {

        try {

            $stmt = $pdo->prepare("
                SELECT COALESCE(
                    SUM(`$amountColumn`),
                    0
                )
                FROM `$payoutTable`
                WHERE `$userColumn` = ?
            ");

            $stmt->execute([
                $agentId
            ]);

            $totalCommission =
                (float)$stmt->fetchColumn();

        } catch (Throwable $e) {

            $totalCommission = 0.00;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | TRANSACTION COUNT
    |--------------------------------------------------------------------------
    */

    if ($userColumn !== null) {

        try {

            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM `$payoutTable`
                WHERE `$userColumn` = ?
            ");

            $stmt->execute([
                $agentId
            ]);

            $transactionCount =
                (int)$stmt->fetchColumn();

        } catch (Throwable $e) {

            $transactionCount = 0;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | TRANSACTION LIST
    |--------------------------------------------------------------------------
    */

    if ($userColumn !== null) {

        try {

            $selectParts = [
                'id'
            ];

            if ($amountColumn !== null) {
                $selectParts[] =
                    "`$amountColumn` AS wallet_amount";
            }

            if ($referenceColumn !== null) {
                $selectParts[] =
                    "`$referenceColumn` AS wallet_reference";
            }

            if ($statusColumn !== null) {
                $selectParts[] =
                    "`$statusColumn` AS wallet_status";
            }

            if ($dateColumn !== null) {
                $selectParts[] =
                    "`$dateColumn` AS wallet_date";
            }

            if ($commissionRate === null && $rateTable === $payoutTable) {

                $possibleRateColumn =
                    walletFirstColumn(
                        $pdo,
                        $payoutTable,
                        [
                            'commission_percentage',
                            'commission_percent',
                            'commission_rate',
                            'percentage',
                            'percent',
                            'rate'
                        ]
                    );

                if ($possibleRateColumn !== null) {

                    $selectParts[] =
                        "`$possibleRateColumn` AS wallet_rate";
                }
            }

            $orderBy =
                $dateColumn !== null
                ? "`$dateColumn` DESC"
                : "id DESC";

            $sql = "
                SELECT
                    " . implode(",\n", $selectParts) . "
                FROM `$payoutTable`
                WHERE `$userColumn` = ?
                ORDER BY $orderBy
                LIMIT 50
            ";

            $stmt = $pdo->prepare($sql);

            $stmt->execute([
                $agentId
            ]);

            $walletRows =
                $stmt->fetchAll(
                    PDO::FETCH_ASSOC
                );

        } catch (Throwable $e) {

            $walletRows = [];
        }
    }
}


/*
|--------------------------------------------------------------------------
| FALLBACK COMMISSION RATE FROM TRANSACTION ROW
|--------------------------------------------------------------------------
*/

if ($commissionRate === null) {

    foreach ($walletRows as $row) {

        if (
            isset($row['wallet_rate']) &&
            $row['wallet_rate'] !== ''
        ) {

            $commissionRate =
                (float)$row['wallet_rate'];

            break;
        }
    }
}


/*
|--------------------------------------------------------------------------
| AGENT CODE
|--------------------------------------------------------------------------
*/

$agentCode = '';

try {

    $stmt = $pdo->prepare("
        SELECT
            COALESCE(
                NULLIF(party_code, ''),
                NULLIF(agent_code, '')
            )
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $agentId
    ]);

    $agentCode =
        trim(
            (string)(
                $stmt->fetchColumn() ?: ''
            )
        );

} catch (Throwable $e) {

    $agentCode = '';
}


/*
|--------------------------------------------------------------------------
| FALLBACK AGENT ID FROM HIERARCHY
|--------------------------------------------------------------------------
*/

if ($agentCode === '' && walletTableExists($pdo, 'agent_hierarchy')) {

    try {

        $hierarchyColumn =
            walletFirstColumn(
                $pdo,
                'agent_hierarchy',
                [
                    'agent_id',
                    'agent_code'
                ]
            );

        if ($hierarchyColumn !== null) {

            $stmt = $pdo->prepare("
                SELECT `$hierarchyColumn`
                FROM agent_hierarchy
                WHERE user_id = ?
                LIMIT 1
            ");

            $stmt->execute([
                $agentId
            ]);

            $agentCode =
                trim(
                    (string)(
                        $stmt->fetchColumn() ?: ''
                    )
                );
        }

    } catch (Throwable $e) {

        $agentCode = '';
    }
}

if ($agentCode === '') {
    $agentCode = 'Not assigned';
}


/*
|--------------------------------------------------------------------------
| OPEN AGENT SHELL
|--------------------------------------------------------------------------
*/

agent_shell_open(
    $pdo,
    $agentId,
    'wallet',
    'Wallet & Commission'
);

?>

<style>

.wallet-page {
    width: 100%;
}

.wallet-header {
    margin-bottom: 20px;
}

.wallet-header h1 {
    margin: 0;
    color: #172033;
    font-size: 27px;
    font-weight: 800;
}

.wallet-header p {
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

.stat-card {
    padding: 17px 18px;
    background: #fff;
    border: 1px solid #e5e9f0;
    border-radius: 11px;
}

.stat-value {
    color: #172033;
    font-size: 23px;
    font-weight: 800;
    word-break: break-word;
}

.stat-label {
    margin-top: 5px;
    color: #718096;
    font-size: 11px;
}

.commission-rate {
    color: #15803d;
}

.wallet-card {
    background: #fff;
    border: 1px solid #e5e9f0;
    border-radius: 12px;
    overflow: hidden;
}

.wallet-card-header {
    padding: 17px 18px;
    border-bottom: 1px solid #edf0f4;
}

.wallet-card-header h2 {
    margin: 0;
    color: #172033;
    font-size: 15px;
    font-weight: 800;
}

.wallet-card-header p {
    margin: 5px 0 0;
    color: #718096;
    font-size: 11px;
}

.table-wrap {
    overflow-x: auto;
}

.wallet-table {
    width: 100%;
    border-collapse: collapse;
}

.wallet-table th {
    padding: 11px 13px;
    background: #f8fafc;
    border-bottom: 1px solid #edf0f4;
    color: #68758b;
    font-size: 10px;
    font-weight: 800;
    text-align: left;
    text-transform: uppercase;
}

.wallet-table td {
    padding: 13px;
    border-bottom: 1px solid #edf0f4;
    color: #344563;
    font-size: 12px;
    vertical-align: middle;
}

.wallet-table tr:last-child td {
    border-bottom: 0;
}

.reference {
    color: #172033;
    font-weight: 800;
}

.amount {
    color: #15803d;
    font-weight: 800;
}

.status {
    display: inline-flex;
    padding: 5px 9px;
    border-radius: 999px;
    background: #f1f5f9;
    color: #475569;
    font-size: 10px;
    font-weight: 800;
    white-space: nowrap;
}

.status.paid,
.status.credited,
.status.completed,
.status.approved {
    background: #dcfce7;
    color: #15803d;
}

.status.pending,
.status.processing {
    background: #fff7ed;
    color: #c2410c;
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

.commission-note {
    margin-top: 14px;
    color: #94a3b8;
    font-size: 10px;
}

@media (max-width: 900px) {

    .stats-grid {
        grid-template-columns: 1fr;
    }
}

</style>


<div class="wallet-page">


    <!-- =========================================================
         HEADER
    ========================================================== -->

    <div class="wallet-header">

        <h1>
            Wallet & Commission
        </h1>

        <p>
            View commission credited from completed customer transactions.
        </p>

    </div>


    <!-- =========================================================
         STATISTICS
    ========================================================== -->

    <div class="stats-grid">


        <!-- TOTAL COMMISSION -->

        <div class="stat-card">

            <div class="stat-value">

                ₹<?= walletEsc(
                    number_format(
                        $totalCommission,
                        2
                    )
                ) ?>

            </div>

            <div class="stat-label">
                Credited commission
            </div>

        </div>


        <!-- TRANSACTIONS -->

        <div class="stat-card">

            <div class="stat-value">

                <?= $transactionCount ?>

            </div>

            <div class="stat-label">
                Wallet transactions shown
            </div>

        </div>


        <!-- COMMISSION PERCENTAGE -->

        <div class="stat-card">

            <div class="stat-value commission-rate">

                <?php if ($commissionRate !== null): ?>

                    <?= walletEsc(
                        rtrim(
                            rtrim(
                                number_format(
                                    $commissionRate,
                                    2
                                ),
                                '0'
                            ),
                            '.'
                        )
                    ) ?>%

                <?php else: ?>

                    Not configured

                <?php endif; ?>

            </div>

            <div class="stat-label">
                Commission percentage
            </div>

        </div>


    </div>


    <!-- =========================================================
         AGENT ID
    ========================================================== -->

    <div class="stats-grid">


        <div class="stat-card">

            <div class="stat-value">

                <?= walletEsc(
                    $agentCode
                ) ?>

            </div>

            <div class="stat-label">
                Agent ID
            </div>

        </div>


    </div>


    <!-- =========================================================
         WALLET TRANSACTIONS
    ========================================================== -->

    <div class="wallet-card">


        <div class="wallet-card-header">

            <h2>
                Wallet Transactions
            </h2>

            <p>
                Recent wallet activity
            </p>

        </div>


        <div class="table-wrap">


            <?php if (empty($walletRows)): ?>

                <div class="empty-state">

                    <strong>
                        No wallet transactions
                    </strong>

                    Wallet entries will appear when commission
                    or other agent transactions are recorded.

                </div>

            <?php else: ?>


                <table class="wallet-table">

                    <thead>

                        <tr>

                            <th>
                                Reference
                            </th>

                            <th>
                                Commission
                            </th>

                            <th>
                                Status
                            </th>

                            <th>
                                Date
                            </th>

                        </tr>

                    </thead>


                    <tbody>


                    <?php foreach ($walletRows as $row): ?>

                        <?php

                        $reference =
                            trim(
                                (string)(
                                    $row[
                                        'wallet_reference'
                                    ] ?? ''
                                )
                            );

                        if ($reference === '') {

                            $reference =
                                'WALLET-' .
                                (string)(
                                    $row['id'] ?? ''
                                );
                        }


                        $amount =
                            (float)(
                                $row[
                                    'wallet_amount'
                                ] ?? 0
                            );


                        $status =
                            strtolower(
                                trim(
                                    (string)(
                                        $row[
                                            'wallet_status'
                                        ] ?? 'credited'
                                    )
                                )
                            );


                        $displayStatus =
                            ucfirst(
                                str_replace(
                                    '_',
                                    ' ',
                                    $status
                                )
                            );


                        $date =
                            trim(
                                (string)(
                                    $row[
                                        'wallet_date'
                                    ] ?? ''
                                )
                            );

                        ?>


                        <tr>


                            <td>

                                <span class="reference">

                                    <?= walletEsc(
                                        $reference
                                    ) ?>

                                </span>

                            </td>


                            <td>

                                <span class="amount">

                                    ₹<?= walletEsc(
                                        number_format(
                                            $amount,
                                            2
                                        )
                                    ) ?>

                                </span>

                            </td>


                            <td>

                                <span
                                    class="status <?= walletEsc(
                                        preg_replace(
                                            '/[^a-z0-9_-]/',
                                            '',
                                            $status
                                        )
                                    ) ?>"
                                >

                                    <?= walletEsc(
                                        $displayStatus
                                    ) ?>

                                </span>

                            </td>


                            <td>

                                <span class="date-text">

                                    <?php if ($date !== ''): ?>

                                        <?= walletEsc(
                                            date(
                                                'd M Y',
                                                strtotime(
                                                    $date
                                                )
                                            )
                                        ) ?>

                                    <?php else: ?>

                                        —

                                    <?php endif; ?>

                                </span>

                            </td>


                        </tr>


                    <?php endforeach; ?>


                    </tbody>

                </table>


            <?php endif; ?>


        </div>

    </div>


    <?php if ($commissionRate === null): ?>

        <div class="commission-note">

            Commission percentage will appear here when a commission
            rate has been assigned to this agent in the CRM.

        </div>

    <?php endif; ?>


</div>


<?php

agent_shell_close();

?>
