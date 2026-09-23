<?php

declare(strict_types=1);

require_once __DIR__ . '/../db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];

require_once __DIR__ . '/shell.php';

agent_shell_open(
    $pdo,
    $user_id,
    'Notifications',
    'notifications.php'
);


/*
|--------------------------------------------------------------------------
| NOTIFICATION DATA
|--------------------------------------------------------------------------
|
| Existing notifications from shell.php are retained.
|
*/

$notifications = [];

if (
    isset($agent_notifications) &&
    is_array($agent_notifications)
) {
    $notifications = $agent_notifications;
}


/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function tableExists(PDO $pdo, string $table): bool
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


function getTableColumns(PDO $pdo, string $table): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT column_name
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = ?
        ");

        $stmt->execute([$table]);

        return array_map(
            'strtolower',
            $stmt->fetchAll(PDO::FETCH_COLUMN)
        );

    } catch (Throwable $e) {
        return [];
    }
}


function findColumn(array $columns, array $possible): ?string
{
    foreach ($possible as $name) {

        if (in_array(strtolower($name), $columns, true)) {
            return $name;
        }
    }

    return null;
}


function notificationExists(
    array $notifications,
    string $title,
    string $message
): bool {

    foreach ($notifications as $notification) {

        if (!is_array($notification)) {
            continue;
        }

        $oldTitle = strtolower(
            trim((string)($notification['title'] ?? ''))
        );

        $oldMessage = strtolower(
            trim((string)($notification['message'] ?? ''))
        );

        if (
            $oldTitle === strtolower(trim($title)) &&
            $oldMessage === strtolower(trim($message))
        ) {
            return true;
        }
    }

    return false;
}


function addNotification(
    array &$notifications,
    string $type,
    string $title,
    string $message,
    string $date = '',
    string $source = 'Agent Portal'
): void {

    if (
        notificationExists(
            $notifications,
            $title,
            $message
        )
    ) {
        return;
    }

    $notifications[] = [
        'type'    => $type,
        'title'   => $title,
        'message' => $message,
        'date'    => $date,
        'source'  => $source
    ];
}


/*
|--------------------------------------------------------------------------
| FIND REAL DATABASE EVENTS
|--------------------------------------------------------------------------
*/

try {

    $tablesStmt = $pdo->query("
        SELECT table_name
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_type = 'BASE TABLE'
    ");

    $tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);


    foreach ($tables as $table) {

        $table = (string)$table;

        /*
         * Never read notification tables themselves.
         */
        $lowerTable = strtolower($table);

        if (
            str_contains($lowerTable, 'notification') ||
            str_contains($lowerTable, 'session') ||
            str_contains($lowerTable, 'migration')
        ) {
            continue;
        }


        $columns = getTableColumns($pdo, $table);

        if (empty($columns)) {
            continue;
        }


        /*
         * Find agent relation.
         */
        $agentColumn = findColumn(
            $columns,
            [
                'agent_id',
                'assigned_agent_id',
                'assigned_to',
                'agent_user_id',
                'user_id'
            ]
        );


        /*
         * Find primary/id column.
         */
        $idColumn = findColumn(
            $columns,
            [
                'id',
                'customer_id',
                'application_id',
                'connection_id',
                'eligibility_id',
                'commission_id',
                'wallet_id',
                'agent_id'
            ]
        );


        /*
         * Find status.
         */
        $statusColumn = findColumn(
            $columns,
            [
                'status',
                'application_status',
                'eligibility_status',
                'connection_status',
                'customer_status',
                'commission_status'
            ]
        );


        /*
         * Find customer/name.
         */
        $nameColumn = findColumn(
            $columns,
            [
                'customer_name',
                'name',
                'full_name',
                'customer',
                'applicant_name'
            ]
        );


        /*
         * Find amount.
         */
        $amountColumn = findColumn(
            $columns,
            [
                'amount',
                'commission_amount',
                'credited_amount',
                'wallet_amount',
                'total_amount'
            ]
        );


        /*
         * Find percentage.
         */
        $percentageColumn = findColumn(
            $columns,
            [
                'commission_percentage',
                'commission_percent',
                'commission_rate',
                'percentage',
                'rate'
            ]
        );


        /*
         * Find timestamp.
         */
        $dateColumn = findColumn(
            $columns,
            [
                'created_at',
                'updated_at',
                'submitted_at',
                'assigned_at',
                'approved_at',
                'credited_at',
                'date',
                'created_on',
                'updated_on'
            ]
        );


        /*
         * Tables that have no agent relationship cannot safely
         * be shown as agent-specific notifications.
         */
        if ($agentColumn === null) {
            continue;
        }


        /*
         * Do not query arbitrary columns.
         */
        $safeTable = '`' . str_replace('`', '``', $table) . '`';

        $safeAgentColumn =
            '`' . str_replace('`', '``', $agentColumn) . '`';


        $select = "*";

        $sql = "
            SELECT {$select}
            FROM {$safeTable}
            WHERE {$safeAgentColumn} = ?
        ";

        /*
         * Limit the amount of data loaded from each table.
         */
        if ($dateColumn !== null) {

            $safeDateColumn =
                '`' . str_replace('`', '``', $dateColumn) . '`';

            $sql .= "
                ORDER BY {$safeDateColumn} DESC
                LIMIT 50
            ";

        } else {

            $sql .= "
                LIMIT 50
            ";
        }


        try {

            $stmt = $pdo->prepare($sql);

            $stmt->execute([$user_id]);

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (Throwable $e) {

            continue;
        }


        foreach ($rows as $row) {

            $status = '';

            if ($statusColumn !== null) {
                $status = strtolower(
                    trim((string)($row[$statusColumn] ?? ''))
                );
            }


            $name = '';

            if ($nameColumn !== null) {
                $name = trim(
                    (string)($row[$nameColumn] ?? '')
                );
            }


            $recordId = '';

            if ($idColumn !== null) {
                $recordId = trim(
                    (string)($row[$idColumn] ?? '')
                );
            }


            $date = '';

            if ($dateColumn !== null) {
                $date = trim(
                    (string)($row[$dateColumn] ?? '')
                );
            }


            /*
             * =====================================================
             * CUSTOMER EVENTS
             * =====================================================
             */

            if (
                str_contains($lowerTable, 'customer') ||
                str_contains($lowerTable, 'client')
            ) {

                /*
                 * New/active customer.
                 */
                if (
                    $status === 'active' ||
                    $status === 'new'
                ) {

                    $customerText =
                        $name !== ''
                            ? $name
                            : 'A customer';

                    addNotification(
                        $notifications,
                        'customer',
                        'Customer Update',
                        $customerText .
                        ' is now ' .
                        ($status !== '' ? $status : 'available') .
                        '.',
                        $date,
                        'Customers'
                    );
                }
            }


            /*
             * =====================================================
             * APPLICATION EVENTS
             * =====================================================
             */

            if (
                str_contains($lowerTable, 'application') ||
                str_contains($lowerTable, 'loan')
            ) {

                $applicationText =
                    $recordId !== ''
                        ? 'Application ' . $recordId
                        : 'An application';


                if ($status === 'submitted') {

                    addNotification(
                        $notifications,
                        'application',
                        'Application Submitted',
                        $applicationText .
                        ' has been submitted.',
                        $date,
                        'Applications'
                    );
                }


                if ($status === 'pending') {

                    addNotification(
                        $notifications,
                        'application',
                        'Application Pending',
                        $applicationText .
                        ' is pending.',
                        $date,
                        'Applications'
                    );
                }


                if (
                    $status === 'processing' ||
                    $status === 'on_process' ||
                    $status === 'on process' ||
                    $status === 'in_process' ||
                    $status === 'in process'
                ) {

                    addNotification(
                        $notifications,
                        'application',
                        'Application In Process',
                        $applicationText .
                        ' is currently being processed.',
                        $date,
                        'Applications'
                    );
                }


                if ($status === 'approved') {

                    addNotification(
                        $notifications,
                        'application',
                        'Application Approved',
                        $applicationText .
                        ' has been approved.',
                        $date,
                        'Applications'
                    );
                }


                if ($status === 'rejected') {

                    addNotification(
                        $notifications,
                        'application',
                        'Application Rejected',
                        $applicationText .
                        ' has been rejected.',
                        $date,
                        'Applications'
                    );
                }
            }


            /*
             * =====================================================
             * CONNECTION EVENTS
             * =====================================================
             */

            if (
                str_contains($lowerTable, 'connection')
            ) {

                addNotification(
                    $notifications,
                    'connection',
                    'Connection Updated',
                    $recordId !== ''
                        ? 'Connection ' . $recordId .
                          ' has been updated.'
                        : 'A connection has been updated.',
                    $date,
                    'Connections'
                );
            }


            /*
             * =====================================================
             * ELIGIBILITY EVENTS
             * =====================================================
             */

            if (
                str_contains($lowerTable, 'eligibility')
            ) {

                if ($status !== '') {

                    addNotification(
                        $notifications,
                        'eligibility',
                        'Eligibility Updated',
                        $recordId !== ''
                            ? 'Eligibility ' .
                              $recordId .
                              ' is now ' .
                              $status .
                              '.'
                            : 'Eligibility status is now ' .
                              $status .
                              '.',
                        $date,
                        'Eligibility'
                    );
                }
            }


            /*
             * =====================================================
             * COMMISSION EVENTS
             * =====================================================
             */

            if (
                str_contains($lowerTable, 'commission')
            ) {

                $amount = '';

                if ($amountColumn !== null) {

                    $amount =
                        trim(
                            (string)($row[$amountColumn] ?? '')
                        );
                }


                if (
                    $status === 'credited' ||
                    $status === 'paid' ||
                    $status === 'approved' ||
                    $status === 'assigned'
                ) {

                    $message =
                        'Your commission has been ' .
                        $status . '.';


                    if ($amount !== '') {

                        $message .=
                            ' Amount: ₹' . $amount . '.';
                    }


                    addNotification(
                        $notifications,
                        'commission',
                        'Commission Update',
                        $message,
                        $date,
                        'Wallet & Commission'
                    );
                }


                /*
                 * Commission percentage/rate.
                 */
                if ($percentageColumn !== null) {

                    $percentage =
                        trim(
                            (string)(
                                $row[$percentageColumn] ?? ''
                            )
                        );


                    if ($percentage !== '') {

                        addNotification(
                            $notifications,
                            'commission',
                            'Commission Rate Updated',
                            'Your commission rate is now ' .
                            $percentage .
                            '%.',
                            $date,
                            'Wallet & Commission'
                        );
                    }
                }
            }


            /*
             * =====================================================
             * WALLET EVENTS
             * =====================================================
             */

            if (
                str_contains($lowerTable, 'wallet') ||
                str_contains($lowerTable, 'transaction')
            ) {

                $amount = '';

                if ($amountColumn !== null) {

                    $amount =
                        trim(
                            (string)($row[$amountColumn] ?? '')
                        );
                }


                $message =
                    'Your wallet has been updated.';


                if ($amount !== '') {

                    $message .=
                        ' Amount: ₹' . $amount . '.';
                }


                addNotification(
                    $notifications,
                    'wallet',
                    'Wallet Updated',
                    $message,
                    $date,
                    'Wallet & Commission'
                );
            }


            /*
             * =====================================================
             * AGENT EVENTS
             * =====================================================
             */

            if (
                str_contains($lowerTable, 'agent')
            ) {

                if (
                    $status === 'active'
                ) {

                    addNotification(
                        $notifications,
                        'agent',
                        'Agent Active',
                        'Your agent account is active.',
                        $date,
                        'Agent Portal'
                    );
                }
            }


            /*
             * =====================================================
             * AREA EVENTS
             * =====================================================
             */

            if (
                str_contains($lowerTable, 'area') ||
                str_contains($lowerTable, 'location')
            ) {

                if ($status === 'active') {

                    addNotification(
                        $notifications,
                        'area',
                        'Area Active',
                        $name !== ''
                            ? $name . ' area is now active.'
                            : 'Your assigned area is now active.',
                        $date,
                        'Area'
                    );
                }
            }
        }
    }

} catch (Throwable $e) {

    /*
     * Do not break the agent dashboard if notification
     * discovery encounters an unrelated database table.
     */
}


/*
|--------------------------------------------------------------------------
| SORT NOTIFICATIONS
|--------------------------------------------------------------------------
|
| Newest first when a date is available.
|
*/

usort(
    $notifications,
    function ($a, $b) {

        $dateA = strtotime(
            (string)($a['date'] ?? '')
        );

        $dateB = strtotime(
            (string)($b['date'] ?? '')
        );

        return $dateB <=> $dateA;
    }
);


/*
|--------------------------------------------------------------------------
| NORMALIZE
|--------------------------------------------------------------------------
*/

$notifications = array_values(
    array_filter(
        $notifications,
        'is_array'
    )
);


/*
|--------------------------------------------------------------------------
| HELPERS FOR DISPLAY
|--------------------------------------------------------------------------
*/

function notificationTypeClass(string $type): string
{
    return match (strtolower(trim($type))) {

        'account'      => 'notification-account',
        'customer'     => 'notification-customer',
        'assignment'   => 'notification-assignment',
        'connection'   => 'notification-connection',
        'eligibility'  => 'notification-eligibility',
        'application'  => 'notification-application',
        'tracking'     => 'notification-tracking',
        'followup'     => 'notification-followup',
        'commission'   => 'notification-commission',
        'wallet'       => 'notification-wallet',
        'area'         => 'notification-area',
        'agent'        => 'notification-agent',

        default        => 'notification-default'
    };
}


function notificationIcon(string $type): string
{
    return match (strtolower(trim($type))) {

        'account'      => 'fa-user',
        'customer'     => 'fa-user-group',
        'assignment'   => 'fa-user-check',
        'connection'   => 'fa-link',
        'eligibility'  => 'fa-circle-check',
        'application'  => 'fa-file-lines',
        'tracking'     => 'fa-chart-line',
        'followup'     => 'fa-phone',
        'commission'   => 'fa-indian-rupee-sign',
        'wallet'       => 'fa-wallet',
        'area'         => 'fa-location-dot',
        'agent'        => 'fa-user-check',

        default        => 'fa-bell'
    };
}


$notificationCount = count($notifications);

?>

<style>

.agent-notifications-page {
    padding: 24px;
}

.agent-notification-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 22px;
}

.agent-notification-title {
    margin: 0;
    font-size: 26px;
    font-weight: 700;
    color: #111827;
}

.agent-notification-subtitle {
    margin: 6px 0 0;
    color: #6b7280;
    font-size: 14px;
}

.agent-notification-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 38px;
    height: 38px;
    padding: 0 12px;
    border-radius: 20px;
    background: #f3f4f6;
    color: #374151;
    font-size: 14px;
    font-weight: 700;
}

.agent-notification-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.agent-notification-card {
    display: flex;
    align-items: flex-start;
    gap: 15px;
    padding: 18px;
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
}

.agent-notification-icon {
    width: 44px;
    height: 44px;
    min-width: 44px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f3f4f6;
    color: #374151;
    font-size: 17px;
}

.notification-content {
    flex: 1;
    min-width: 0;
}

.notification-top-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
}

.notification-title {
    margin: 0;
    font-size: 15px;
    font-weight: 700;
    color: #111827;
}

.notification-date {
    flex-shrink: 0;
    font-size: 12px;
    color: #9ca3af;
}

.notification-message {
    margin: 6px 0 0;
    font-size: 14px;
    line-height: 1.5;
    color: #4b5563;
}

.notification-source {
    margin-top: 8px;
    font-size: 12px;
    color: #9ca3af;
}

.agent-notification-empty {
    padding: 50px 20px;
    text-align: center;
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
}

.agent-notification-empty-icon {
    width: 58px;
    height: 58px;
    margin: 0 auto 14px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f3f4f6;
    color: #6b7280;
    font-size: 22px;
}

.agent-notification-empty h3 {
    margin: 0;
    font-size: 17px;
    color: #111827;
}

.agent-notification-empty p {
    margin: 7px 0 0;
    color: #6b7280;
    font-size: 14px;
}

@media (max-width: 700px) {

    .agent-notifications-page {
        padding: 15px;
    }

    .agent-notification-header {
        align-items: flex-start;
    }

    .notification-top-row {
        align-items: flex-start;
        flex-direction: column;
        gap: 4px;
    }

    .notification-date {
        font-size: 11px;
    }
}

</style>


<div class="agent-notifications-page">

    <div class="agent-notification-header">

        <div>

            <h1 class="agent-notification-title">
                Notifications
            </h1>

            <p class="agent-notification-subtitle">
                Stay updated with activities and important changes.
            </p>

        </div>

        <div class="agent-notification-count">
            <?= $notificationCount ?>
        </div>

    </div>


    <?php if (empty($notifications)): ?>

        <div class="agent-notification-empty">

            <div class="agent-notification-empty-icon">
                <i class="fa-regular fa-bell"></i>
            </div>

            <h3>
                No notifications
            </h3>

            <p>
                You don't have any new notifications at the moment.
            </p>

        </div>

    <?php else: ?>

        <div class="agent-notification-list">

            <?php foreach ($notifications as $notification): ?>

                <?php

                $type = (string)(
                    $notification['type'] ?? 'default'
                );

                $title = (string)(
                    $notification['title'] ?? 'Notification'
                );

                $message = (string)(
                    $notification['message'] ?? ''
                );

                $date = (string)(
                    $notification['date'] ?? ''
                );

                $source = (string)(
                    $notification['source'] ?? 'Agent Portal'
                );

                $typeClass =
                    notificationTypeClass($type);

                $icon =
                    notificationIcon($type);

                ?>

                <div class="agent-notification-card">

                    <div
                        class="agent-notification-icon <?= htmlspecialchars(
                            $typeClass,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                    >

                        <i
                            class="fa-solid <?= htmlspecialchars(
                                $icon,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>"
                        ></i>

                    </div>


                    <div class="notification-content">

                        <div class="notification-top-row">

                            <h3 class="notification-title">

                                <?= htmlspecialchars(
                                    $title,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>

                            </h3>


                            <?php if ($date !== ''): ?>

                                <span class="notification-date">

                                    <?= htmlspecialchars(
                                        $date,
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>

                                </span>

                            <?php endif; ?>

                        </div>


                        <?php if ($message !== ''): ?>

                            <p class="notification-message">

                                <?= htmlspecialchars(
                                    $message,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>

                            </p>

                        <?php endif; ?>


                        <?php if ($source !== ''): ?>

                            <div class="notification-source">

                                <?= htmlspecialchars(
                                    $source,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>

                            </div>

                        <?php endif; ?>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</div>


<?php

agent_shell_close();

?>