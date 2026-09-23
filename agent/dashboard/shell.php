<?php
declare(strict_types=1);

/*
 * ============================================================
 * INDBIN CRM - COMMON AGENT PORTAL SHELL
 * ============================================================
 *
 * IMPORTANT:
 * The header/sidebar notification badge uses ONLY the same
 * notification list loaded by this shell.
 *
 * The count is NOT taken from pending follow-ups.
 * The count is NOT calculated separately on each page.
 * There is NO popup.
 *
 * This keeps the existing project's notification source
 * and makes the same list/count available on every page
 * that uses this shell.
 *
 * Example:
 *
 * 3 actual notifications -> badge = 3
 * 4 actual notifications -> badge = 4
 * 0 actual notifications -> no badge
 * ============================================================
 */


/* ============================================================
   ESCAPE
   ============================================================ */

if (!function_exists('agent_shell_esc')) {

    function agent_shell_esc($value): string
    {
        return htmlspecialchars(
            (string)$value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}


/* ============================================================
   TABLE EXISTS
   ============================================================ */

if (!function_exists('agent_shell_table_exists')) {

    function agent_shell_table_exists(
        PDO $pdo,
        string $table
    ): bool {

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
}


/* ============================================================
   GET AGENT NOTIFICATIONS
   ============================================================
 *
 * Notifications are generated ONLY from real agent records.
 * Follow-ups/leads are NOT notifications here.
 *
 * The exact same array is used by the shell and notifications.php,
 * so the header badge, sidebar badge and notification page always
 * show the same count.
 * ============================================================ */

if (!function_exists('agent_shell_get_table_columns')) {

    function agent_shell_get_table_columns(
        PDO $pdo,
        string $table
    ): array {

        try {

            $stmt = $pdo->prepare("
                SELECT column_name
                FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = ?
                ORDER BY ordinal_position
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
}


if (!function_exists('agent_shell_find_column')) {

    function agent_shell_find_column(
        array $columns,
        array $possible
    ): ?string {

        foreach ($possible as $column) {

            $column = strtolower($column);

            if (in_array($column, $columns, true)) {
                return $column;
            }
        }

        return null;
    }
}


if (!function_exists('agent_shell_add_notification')) {

    function agent_shell_add_notification(
        array &$notifications,
        string $type,
        string $title,
        string $message,
        $date = null,
        string $source = 'Agent Portal',
        ?string $uniqueKey = null
    ): void {

        /*
         * Prevent the exact same event from being added twice
         * during the same request.
         */
        $uniqueKey = $uniqueKey
            ?? strtolower(
                $type . '|' . $title . '|' . $message . '|' . (string)$date
            );

        foreach ($notifications as $existing) {

            if (
                isset($existing['_key'])
                &&
                $existing['_key'] === $uniqueKey
            ) {
                return;
            }
        }

        $displayDate = '';

        if ($date !== null && $date !== '') {

            $timestamp = strtotime((string)$date);

            $displayDate =
                $timestamp !== false
                    ? date('d M Y, H:i', $timestamp)
                    : (string)$date;
        }

        if ($displayDate === '') {
            $displayDate = date('d M Y, H:i');
        }

        $notifications[] = [
            'title'   => $title,
            'message' => $message,
            'date'    => $displayDate,
            'source'  => $source,
            'type'    => $type,
            '_key'    => $uniqueKey
        ];
    }
}


/* ============================================================
   GET AGENT NOTIFICATIONS
   ============================================================

 * All notifications come from actual data already stored in the
 * database and from the existing agent assignment/application data.
 *
 * The SAME returned array is used by:
 *   - header bell
 *   - sidebar badge
 *   - notifications.php
 *
 * No hardcoded customer names, application IDs, amounts or
 * commission percentages are created here.
 * ============================================================ */

if (!function_exists('agent_shell_get_notifications')) {

    function agent_shell_get_notifications(
        PDO $pdo,
        int $user_id,
        string $agent_code
    ): array {

        $notifications = [];


        /* ========================================================
           1. AGENT ACCOUNT ACTIVE
           ======================================================== */

        try {

            $stmt = $pdo->prepare("
                SELECT account_status
                FROM users
                WHERE id = ?
                LIMIT 1
            ");

            $stmt->execute([$user_id]);

            $accountStatus = strtolower(
                trim((string)($stmt->fetchColumn() ?? ''))
            );

            if ($accountStatus === 'active') {

                $agentCodeText =
                    $agent_code !== ''
                        ? $agent_code
                        : 'your Agent ID';

                agent_shell_add_notification(
                    $notifications,
                    'account',
                    'Account active',
                    'Your agent account is active. Agent ID: '
                    . $agentCodeText
                    . '.',
                    date('Y-m-d H:i:s'),
                    'Agent Portal',
                    'account-active-' . $user_id
                );
            }

        } catch (Throwable $e) {
            // Keep the portal working if account data cannot be read.
        }


        /* ========================================================
           2. CUSTOMER ASSIGNED / CUSTOMER ACTIVE / CONTACT ACTIVE
           ======================================================== */

        if (
            agent_shell_table_exists($pdo, 'customer_agent_assignments')
            &&
            agent_shell_table_exists($pdo, 'customers')
        ) {

            try {

                $stmt = $pdo->prepare("
                    SELECT
                        ca.id AS assignment_id,
                        ca.customer_id,
                        ca.assigned_at,
                        ca.status AS assignment_status,
                        c.user_id,
                        c.name,
                        c.requirement,
                        c.status AS customer_status,
                        u.customer_code,
                        u.status AS user_status,
                        u.account_status
                    FROM customer_agent_assignments ca
                    INNER JOIN customers c
                        ON c.id = ca.customer_id
                    LEFT JOIN users u
                        ON u.id = c.user_id
                    WHERE ca.agent_user_id = ?
                    ORDER BY ca.assigned_at DESC, ca.id DESC
                ");

                $stmt->execute([$user_id]);

                $assignedCustomers = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($assignedCustomers as $customer) {

                    $customerName = trim(
                        (string)($customer['name'] ?? 'Customer')
                    );

                    if ($customerName === '') {
                        $customerName = 'Customer';
                    }

                    $customerCode = trim(
                        (string)($customer['customer_code'] ?? '')
                    );

                    if ($customerCode === '') {
                        $customerCode = 'Customer #' .
                            (string)($customer['customer_id'] ?? '');
                    }

                    $assignmentStatus = strtolower(
                        trim((string)($customer['assignment_status'] ?? ''))
                    );

                    /*
                     * Notify when the customer is assigned to this agent.
                     * Only active/current assignments are shown as an
                     * assignment notification.
                     */
                    if (
                        $assignmentStatus === ''
                        ||
                        in_array(
                            $assignmentStatus,
                            ['active', 'assigned'],
                            true
                        )
                    ) {

                        $message =
                            'Admin has assigned '
                            . $customerName
                            . ' ('
                            . $customerCode
                            . ') to you.';

                        $requirement = trim(
                            (string)($customer['requirement'] ?? '')
                        );

                        if ($requirement !== '') {
                            $message .=
                                ' Customer requirement: '
                                . $requirement
                                . '.';
                        }

                        agent_shell_add_notification(
                            $notifications,
                            'assignment',
                            'New customer assigned',
                            $message,
                            $customer['assigned_at'] ?? null,
                            'Customer Assignment',
                            'customer-assignment-' .
                            (string)($customer['assignment_id'] ?? $customer['customer_id'] ?? '')
                        );
                    }

                    /*
                     * Customer/contact active notification.
                     * This uses the actual status stored in the database.
                     */
                    $customerStatus = strtolower(
                        trim((string)(
                            $customer['customer_status']
                            ?? $customer['user_status']
                            ?? $customer['account_status']
                            ?? ''
                        ))
                    );

                    if ($customerStatus === 'active') {

                        agent_shell_add_notification(
                            $notifications,
                            'customer',
                            'Customer active',
                            $customerName
                            . ' ('
                            . $customerCode
                            . ') is active.',
                            $customer['assigned_at'] ?? null,
                            'Customers',
                            'customer-active-' .
                            (string)($customer['customer_id'] ?? '')
                        );
                    }
                }

            } catch (Throwable $e) {
                // Keep the portal working if customer data cannot be read.
            }
        }


        /* ========================================================
           3. APPLICATION EVENTS
           ======================================================== */

        if (
            agent_shell_table_exists($pdo, 'customer_agent_assignments')
            &&
            agent_shell_table_exists($pdo, 'customers')
            &&
            agent_shell_table_exists($pdo, 'applications')
        ) {

            try {

                $stmt = $pdo->prepare("
                    SELECT
                        c.id AS customer_id,
                        c.user_id,
                        c.name,
                        u.customer_code,
                        a.id AS application_id,
                        a.application_number,
                        a.product_name,
                        a.status AS application_status,
                        a.updated_at,
                        a.created_at
                    FROM customer_agent_assignments ca
                    INNER JOIN customers c
                        ON c.id = ca.customer_id
                    LEFT JOIN users u
                        ON u.id = c.user_id
                    INNER JOIN applications a
                        ON a.customer_id = c.user_id
                    WHERE ca.agent_user_id = ?
                      AND ca.status = 'active'
                    ORDER BY a.updated_at DESC, a.id DESC
                ");

                $stmt->execute([$user_id]);

                $applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($applications as $application) {

                    $customerName = trim(
                        (string)($application['name'] ?? 'Customer')
                    );

                    if ($customerName === '') {
                        $customerName = 'Customer';
                    }

                    $customerCode = trim(
                        (string)($application['customer_code'] ?? '')
                    );

                    if ($customerCode === '') {
                        $customerCode = 'Customer #' .
                            (string)($application['customer_id'] ?? '');
                    }

                    $productName = trim(
                        (string)($application['product_name'] ?? 'Application')
                    );

                    if ($productName === '') {
                        $productName = 'Application';
                    }

                    $applicationNumber = trim(
                        (string)($application['application_number'] ?? '')
                    );

                    if ($applicationNumber === '') {
                        $applicationNumber = 'APP-' .
                            (string)($application['application_id'] ?? '');
                    }

                    $applicationStatus = trim(
                        (string)($application['application_status'] ?? '')
                    );

                    if ($applicationStatus === '') {
                        $applicationStatus = 'Submitted';
                    }

                    $statusText = ucfirst(
                        strtolower(str_replace('_', ' ', $applicationStatus))
                    );

                    $title = 'Application update';

                    if (strtolower($applicationStatus) === 'submitted') {
                        $title = 'Application submitted';
                    } elseif (strtolower($applicationStatus) === 'pending') {
                        $title = 'Application pending';
                    } elseif (
                        in_array(
                            strtolower($applicationStatus),
                            ['processing', 'on process', 'on_process', 'in process', 'in_process'],
                            true
                        )
                    ) {
                        $title = 'Application in process';
                    } elseif (strtolower($applicationStatus) === 'approved') {
                        $title = 'Application approved';
                    } elseif (strtolower($applicationStatus) === 'rejected') {
                        $title = 'Application rejected';
                    }

                    agent_shell_add_notification(
                        $notifications,
                        'application',
                        $title,
                        $customerName
                        . ' ('
                        . $customerCode
                        . ')\'s '
                        . $productName
                        . ' application '
                        . $applicationNumber
                        . ' is currently '
                        . $statusText
                        . '.',
                        $application['updated_at']
                            ?? $application['created_at']
                            ?? null,
                        'Application',
                        'application-' .
                        (string)($application['application_id'] ?? '')
                        . '-'
                        . strtolower($applicationStatus)
                    );
                }

            } catch (Throwable $e) {
                // Keep the portal working if application data cannot be read.
            }
        }


        /* ========================================================
           4. GENERIC MODULE EVENTS
           ========================================================

           These modules are read only when the corresponding table
           exists and has an actual relation to the logged-in agent.

           This avoids hardcoding records while supporting different
           versions of the CRM database.
        */

        $moduleDefinitions = [

            'connection' => [
                'tables' => [
                    'connections',
                    'agent_connections'
                ],
                'title' => 'Connection updated',
                'source' => 'Connections',
                'type' => 'connection',
                'idColumns' => [
                    'connection_id',
                    'id'
                ],
                'agentColumns' => [
                    'agent_user_id',
                    'agent_id',
                    'assigned_agent_id',
                    'user_id'
                ],
                'statusColumns' => [
                    'status',
                    'connection_status'
                ],
                'dateColumns' => [
                    'updated_at',
                    'created_at',
                    'connected_at'
                ]
            ],

            'eligibility' => [
                'tables' => [
                    'agent_eligibility_checks',
                    'eligibility',
                    'eligibilities',
                    'agent_eligibility'
                ],
                'title' => 'Eligibility updated',
                'source' => 'Eligibility',
                'type' => 'eligibility',
                'idColumns' => [
                    'eligibility_id',
                    'id'
                ],
                'agentColumns' => [
                    'agent_user_id',
                    'agent_id',
                    'assigned_agent_id',
                    'user_id',
                    'earner_id'
                ],
                'statusColumns' => [
                    'eligibility_status',
                    'status'
                ],
                'dateColumns' => [
                    'updated_at',
                    'created_at'
                ]
            ],

            'wallet' => [
                'tables' => [
                    'agent_wallet_transactions',
                    'wallet_transactions',
                    'agent_commission_payout',
                    'wallet',
                    'wallets',
                    'transactions'
                ],
                'title' => 'Wallet updated',
                'source' => 'Wallet & Commission',
                'type' => 'wallet',
                'idColumns' => [
                    'wallet_id',
                    'transaction_id',
                    'id'
                ],
                'agentColumns' => [
                    'agent_user_id',
                    'agent_id',
                    'user_id',
                    'earner_id'
                ],
                'statusColumns' => [
                    'status',
                    'payout_status',
                    'transaction_status'
                ],
                'amountColumns' => [
                    'commission_amount',
                    'amount',
                    'payout_amount',
                    'credited_amount',
                    'credit_amount',
                    'wallet_amount',
                    'transaction_amount',
                    'total'
                ],
                'dateColumns' => [
                    'updated_at',
                    'created_at',
                    'credited_at',
                    'transaction_date'
                ]
            ],

            'commission' => [
                'tables' => [
                    'agent_commission_payout',
                    'commissions',
                    'commission',
                    'agent_commissions',
                    'commission_transactions'
                ],
                'title' => 'Commission updated',
                'source' => 'Wallet & Commission',
                'type' => 'commission',
                'idColumns' => [
                    'commission_id',
                    'id'
                ],
                'agentColumns' => [
                    'agent_user_id',
                    'agent_id',
                    'assigned_agent_id',
                    'user_id',
                    'earner_id'
                ],
                'statusColumns' => [
                    'status',
                    'commission_status',
                    'payout_status',
                    'transaction_status'
                ],
                'amountColumns' => [
                    'amount',
                    'commission_amount',
                    'credited_amount'
                ],
                'percentageColumns' => [
                    'commission_percentage',
                    'commission_percent',
                    'commission_rate',
                    'percentage',
                    'percent',
                    'rate'
                ],
                'dateColumns' => [
                    'updated_at',
                    'created_at',
                    'credited_at',
                    'assigned_at'
                ]
            ],

            'area' => [
                'tables' => [
                    'areas',
                    'agent_areas',
                    'area_assignments'
                ],
                'title' => 'Area updated',
                'source' => 'Area',
                'type' => 'area',
                'idColumns' => [
                    'area_id',
                    'id'
                ],
                'agentColumns' => [
                    'agent_user_id',
                    'agent_id',
                    'assigned_agent_id',
                    'user_id',
                    'earner_id'
                ],
                'statusColumns' => [
                    'status',
                    'area_status'
                ],
                'nameColumns' => [
                    'area_name',
                    'name',
                    'area'
                ],
                'dateColumns' => [
                    'updated_at',
                    'created_at',
                    'assigned_at'
                ]
            ]
        ];


        foreach ($moduleDefinitions as $moduleKey => $definition) {

            $tableName = null;

            foreach ($definition['tables'] as $candidateTable) {

                if (agent_shell_table_exists($pdo, $candidateTable)) {
                    $tableName = $candidateTable;
                    break;
                }
            }

            if ($tableName === null) {
                continue;
            }

            $columns = agent_shell_get_table_columns(
                $pdo,
                $tableName
            );

            if (empty($columns)) {
                continue;
            }

            $agentColumn = agent_shell_find_column(
                $columns,
                $definition['agentColumns']
            );

            if ($agentColumn === null) {
                continue;
            }

            $idColumn = agent_shell_find_column(
                $columns,
                $definition['idColumns']
            );

            $statusColumn = agent_shell_find_column(
                $columns,
                $definition['statusColumns'] ?? []
            );

            $dateColumn = agent_shell_find_column(
                $columns,
                $definition['dateColumns'] ?? []
            );

            $amountColumn = agent_shell_find_column(
                $columns,
                $definition['amountColumns'] ?? []
            );

            $percentageColumn = agent_shell_find_column(
                $columns,
                $definition['percentageColumns'] ?? []
            );

            $nameColumn = agent_shell_find_column(
                $columns,
                $definition['nameColumns'] ?? []
            );

            $safeTable = '`' . str_replace('`', '``', $tableName) . '`';
            $safeAgentColumn = '`' . str_replace('`', '``', $agentColumn) . '`';

            $selectParts = ['*'];

            $orderSql = '';

            if ($dateColumn !== null) {

                $safeDateColumn =
                    '`' . str_replace('`', '``', $dateColumn) . '`';

                $orderSql =
                    ' ORDER BY '
                    . $safeDateColumn
                    . ' DESC';
            }

            $sql =
                'SELECT '
                . implode(', ', $selectParts)
                . ' FROM '
                . $safeTable
                . ' WHERE '
                . $safeAgentColumn
                . ' = ?'
                . $orderSql
                . ' LIMIT 100';

            try {

                $stmt = $pdo->prepare($sql);
                $stmt->execute([$user_id]);

                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            } catch (Throwable $e) {
                continue;
            }

            foreach ($rows as $row) {

                $recordId = $idColumn !== null
                    ? trim((string)($row[$idColumn] ?? ''))
                    : '';

                $status = $statusColumn !== null
                    ? trim((string)($row[$statusColumn] ?? ''))
                    : '';

                $statusLower = strtolower($status);

                $eventDate = $dateColumn !== null
                    ? ($row[$dateColumn] ?? null)
                    : null;

                $name = $nameColumn !== null
                    ? trim((string)($row[$nameColumn] ?? ''))
                    : '';

                $amount = $amountColumn !== null
                    ? trim((string)($row[$amountColumn] ?? ''))
                    : '';

                $percentage = $percentageColumn !== null
                    ? trim((string)($row[$percentageColumn] ?? ''))
                    : '';


                /* ------------------------------------------------
                   CONNECTION
                   ------------------------------------------------ */

                if ($moduleKey === 'connection') {

                    $message =
                        $recordId !== ''
                            ? 'Connection ' . $recordId . ' is '
                              . ($status !== '' ? strtolower($status) : 'updated')
                              . '.'
                            : 'A connection has been updated.';

                    agent_shell_add_notification(
                        $notifications,
                        'connection',
                        'Connection updated',
                        $message,
                        $eventDate,
                        'Connections',
                        'connection-' . $recordId . '-' . $statusLower
                    );
                }


                /* ------------------------------------------------
                   ELIGIBILITY
                   ------------------------------------------------ */

                if ($moduleKey === 'eligibility') {

                    $message =
                        $recordId !== ''
                            ? 'Eligibility ' . $recordId
                            : 'Eligibility';

                    if ($status !== '') {
                        $message .=
                            ' is now ' . strtolower($status) . '.';
                    } else {
                        $message .= ' has been updated.';
                    }

                    agent_shell_add_notification(
                        $notifications,
                        'eligibility',
                        'Eligibility updated',
                        $message,
                        $eventDate,
                        'Eligibility',
                        'eligibility-' . $recordId . '-' . $statusLower
                    );
                }


                /* ------------------------------------------------
                   WALLET
                   ------------------------------------------------ */

                if ($moduleKey === 'wallet') {

                    $message = 'Your wallet has been updated.';

                    if ($amount !== '') {
                        $message .= ' Amount: ₹' . $amount . '.';
                    }

                    if ($status !== '') {
                        $message .=
                            ' Status: ' . ucfirst(strtolower($status)) . '.';
                    }

                    agent_shell_add_notification(
                        $notifications,
                        'wallet',
                        'Wallet updated',
                        $message,
                        $eventDate,
                        'Wallet & Commission',
                        'wallet-' . $recordId . '-' . $statusLower . '-' . $amount
                    );
                }


                /* ------------------------------------------------
                   COMMISSION
                   ------------------------------------------------ */

                if ($moduleKey === 'commission') {

                    if ($percentage !== '') {

                        agent_shell_add_notification(
                            $notifications,
                            'commission',
                            'Commission rate updated',
                            'Your commission rate is now '
                            . $percentage
                            . '%.',
                            $eventDate,
                            'Wallet & Commission',
                            'commission-rate-' . $recordId . '-' . $percentage
                        );
                    }

                    $commissionStatuses = [
                        'assigned',
                        'credited',
                        'approved',
                        'paid',
                        'completed',
                        'success',
                        'successful'
                    ];

                    if (
                        $status === ''
                        ||
                        in_array($statusLower, $commissionStatuses, true)
                    ) {

                        $message =
                            'Your commission has been '
                            . ($status !== ''
                                ? strtolower($status)
                                : 'updated')
                            . '.';

                        if ($amount !== '') {
                            $message .=
                                ' Amount: ₹' . $amount . '.';
                        }

                        agent_shell_add_notification(
                            $notifications,
                            'commission',
                            'Commission updated',
                            $message,
                            $eventDate,
                            'Wallet & Commission',
                            'commission-' . $recordId . '-' . $statusLower . '-' . $amount
                        );
                    }
                }


                /* ------------------------------------------------
                   AREA
                   ------------------------------------------------ */

                if ($moduleKey === 'area') {

                    $areaName =
                        $name !== ''
                            ? $name
                            : 'Your assigned area';

                    $message =
                        $areaName
                        . ' is '
                        . ($status !== ''
                            ? strtolower($status)
                            : 'updated')
                        . '.';

                    agent_shell_add_notification(
                        $notifications,
                        'area',
                        'Area updated',
                        $message,
                        $eventDate,
                        'Area',
                        'area-' . $recordId . '-' . $statusLower
                    );
                }
            }
        }


        /* ========================================================
           REMOVE INTERNAL KEYS
           ======================================================== */

        foreach ($notifications as &$notification) {
            unset($notification['_key']);
        }
        unset($notification);


        /* ========================================================
           SORT NEWEST FIRST
           ======================================================== */

        usort(
            $notifications,
            function (array $a, array $b): int {

                $timeA = strtotime(
                    (string)($a['date'] ?? '')
                );

                $timeB = strtotime(
                    (string)($b['date'] ?? '')
                );

                return ($timeB ?: 0) <=> ($timeA ?: 0);
            }
        );

        return array_values($notifications);
    }
}


/* ============================================================
   OPEN SHELL
   ============================================================ */

if (!function_exists('agent_shell_open')) {

    function agent_shell_open(
        PDO $pdo,
        int $user_id,
        string $page_title = 'Dashboard',
        string $active_page = 'index.php'
    ): void {

        global
            $agent_name,
            $agent_role,
            $role,
            $agent_id,
            $pending_followups,
            $pending_followups_list,
            $agent_notifications,
            $notification_count;


        /* ======================================================
           GET USER
           ====================================================== */

        $name =
            trim(
                (string)(
                    $agent_name ?? ''
                )
            );

        $current_role =
            trim(
                (string)(
                    $agent_role
                    ?? ($role ?? '')
                )
            );

        $code =
            trim(
                (string)(
                    $agent_id ?? ''
                )
            );

        $user = [];


        try {

            $stmt = $pdo->prepare("
                SELECT *
                FROM users
                WHERE id = ?
                LIMIT 1
            ");

            $stmt->execute([
                $user_id
            ]);

            $user =
                $stmt->fetch(
                    PDO::FETCH_ASSOC
                ) ?: [];


            if ($name === '') {

                $name =
                    trim(
                        (string)(
                            $user['full_name']
                            ?? ''
                        )
                    );
            }


            if ($current_role === '') {

                /*
                 * First use the role stored on the logged-in user.
                 * This is the fallback when no onboarding/admin role
                 * has been stored yet.
                 */
                $current_role =
                    trim(
                        (string)(
                            $user['role']
                            ?? $user['user_role']
                            ?? $user['assigned_role']
                            ?? ''
                        )
                    );
            }

        } catch (Throwable $e) {

            $user = [];
        }


        /* ======================================================
           FALLBACK NAME
           ====================================================== */

        if (
            $name === ''
            &&
            !empty($_SESSION['full_name'])
        ) {

            $name =
                trim(
                    (string)$_SESSION['full_name']
                );
        }


        if ($name === '') {

            $name = 'Agent';
        }


        /* ======================================================
           AUTHORITATIVE ADMIN / ONBOARDING ROLE

           The Dashboard gets the operational role from
           agent_verification.role when it exists.
           Use the exact same source here so Dashboard,
           Requirements, Eligibility, Connections, etc.
           all show the same role.
        ====================================================== */

        if (
            agent_shell_table_exists(
                $pdo,
                'agent_verification'
            )
        ) {

            try {

                $stmt = $pdo->prepare("
                    SELECT role
                    FROM agent_verification
                    WHERE agent_id = ?
                      AND role IS NOT NULL
                      AND TRIM(role) <> ''
                    ORDER BY id DESC
                    LIMIT 1
                ");

                $stmt->execute([
                    $user_id
                ]);

                $verificationRole =
                    $stmt->fetchColumn();

                if (
                    $verificationRole !== false
                    && trim((string)$verificationRole) !== ''
                ) {
                    $current_role =
                        trim((string)$verificationRole);
                }

            } catch (Throwable $e) {
                // Keep the role already obtained from users.
            }
        }


        if ($current_role === '') {

            $current_role = 'Agent';
        }


        /* ======================================================
           GET AGENT ID
           ====================================================== */

        if ($code === '') {

            if (
                agent_shell_table_exists(
                    $pdo,
                    'agent_hierarchy'
                )
            ) {

                try {

                    $stmt = $pdo->prepare("
                        SELECT agent_id
                        FROM agent_hierarchy
                        WHERE user_id = ?
                        LIMIT 1
                    ");

                    $stmt->execute([
                        $user_id
                    ]);

                    $saved_code =
                        $stmt->fetchColumn();


                    if (
                        $saved_code !== false
                    ) {

                        $code =
                            trim(
                                (string)$saved_code
                            );
                    }

                } catch (Throwable $e) {

                    $code = '';
                }
            }
        }


        /* ======================================================
           NOTIFICATIONS
           ======================================================
           
           THIS IS THE ONLY PLACE WHERE THE COUNT IS CREATED.
           ====================================================== */

        $agent_notifications =
            agent_shell_get_notifications(
                $pdo,
                $user_id,
                $code
            );


        /*
         * ONE SOURCE OF TRUTH:
         * notifications.php uses $agent_notifications too.
         * Therefore the header and sidebar MUST count this exact
         * array. Never use a hardcoded number and never use
         * pending follow-ups for this badge.
         */
        $notification_count = count($agent_notifications);

        /*
         * Kept only for compatibility with existing dashboard code.
         * It is NOT used as the notification source.
         */
        $pending_followups_list =
            $agent_notifications;


        /*
         * IMPORTANT:
         *
         * Notifications and pending follow-ups are separate.
         * Never copy the notification count into $pending_followups.
         *
         * Existing dashboard pages can keep their own real
         * pending-follow-up calculation.
         */
        if (!isset($pending_followups)) {
            $pending_followups = 0;
        }


        /*
         * Badge.
         */
        $notification_badge =
            $notification_count > 99
                ? '99+'
                : (string)$notification_count;


        /* ======================================================
           INITIAL
           ====================================================== */

        $initial =
            strtoupper(
                substr(
                    $name,
                    0,
                    1
                )
            );


        /* ======================================================
           SIDEBAR
           ====================================================== */

        $sidebar_items = [

            [
                'index.php',
                'fa-house',
                'Dashboard'
            ],

            [
                'customers.php',
                'fa-users',
                'Customers'
            ],

            [
                'requirements.php',
                'fa-clipboard-list',
                'Requirements'
            ],

            [
                'eligibility.php',
                'fa-circle-check',
                'Eligibility'
            ],

            [
                'connections.php',
                'fa-link',
                'Connections'
            ],

            [
                'applications.php',
                'fa-file-lines',
                'Applications'
            ],

            [
                'documents.php',
                'fa-folder-open',
                'Documents'
            ],

            [
                'followups.php',
                'fa-clock',
                'Follow-ups'
            ],

            [
                'tracking.php',
                'fa-chart-line',
                'Application Tracking'
            ],

            [
                'wallet.php',
                'fa-wallet',
                'Wallet & Commission'
            ],

            [
                'reports.php',
                'fa-chart-column',
                'Reports'
            ],

            [
                'notifications.php',
                'fa-bell',
                'Notifications'
            ],

            [
                'profile.php',
                'fa-user',
                'Profile'
            ],

            [
                'support.php',
                'fa-headset',
                'Support & Help'
            ],

            [
                'settings.php',
                'fa-gear',
                'Settings'
            ]

        ];


        /* ======================================================
           ACTIVE PAGE
           ====================================================== */

        $active_key =
            strtolower(
                trim(
                    $active_page
                )
            );


        foreach (
            $sidebar_items
            as $item
        ) {

            if (
                $active_key
                ===
                strtolower(
                    $item[2]
                )
            ) {

                $active_page =
                    $item[0];

                break;
            }
        }


        if ($active_page === '') {

            $active_page =
                basename(
                    $_SERVER['PHP_SELF']
                    ?? 'index.php'
                );
        }


        /* ======================================================
           GLOBALS
           ====================================================== */

        $agent_name =
            $name;

        $agent_role =
            $current_role;

        $role =
            $current_role;

        $agent_id =
            $code;

        ?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>
    <?= agent_shell_esc($page_title) ?>
    | INDBIN CRM
</title>

<link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
>

<style>

*{
    box-sizing:border-box;
}

html,
body{
    margin:0;
    padding:0;
    min-height:100%;
}

body{
    font-family:
        "Segoe UI",
        Arial,
        Helvetica,
        sans-serif;

    background:#f6f8fb;
    color:#172033;
}

a{
    color:inherit;
}

button{
    font-family:inherit;
}


/* ============================================================
   APP
   ============================================================ */

.app{
    min-height:100vh;
    display:flex;
}


/* ============================================================
   SIDEBAR
   ============================================================ */

.sidebar{
    width:232px;
    min-width:232px;
    min-height:100vh;

    background:#fff;

    border-right:1px solid #e5e7eb;

    padding:22px 10px 14px;

    display:flex;
    flex-direction:column;

    z-index:1000;

    transition:width .25s ease, min-width .25s ease, transform .25s ease;
}

.brand{
    padding:4px 8px 21px;
    text-align:center;
}

.brand-main{
    font-size:24px;
    font-weight:800;
    letter-spacing:.6px;
    color:#17305f;
}

.brand-sub{
    margin-top:6px;
    font-size:11px;
    font-weight:600;
    color:#7c8796;
}


/* ============================================================
   NAV
   ============================================================ */

.nav{
    display:flex;
    flex-direction:column;
    gap:3px;
    flex:1;
}

.nav a{
    min-height:40px;

    display:flex;
    align-items:center;

    gap:11px;

    padding:9px 10px;

    border-radius:9px;

    text-decoration:none;

    color:#344054;

    font-size:12.5px;
    font-weight:550;

    position:relative;

    transition:
        background .15s ease,
        color .15s ease;
}

.nav a:hover{
    background:#edf9f2;
    color:#117443;
}

.nav a.active{
    background:#d8f1e3;
    color:#0e7040;

    font-weight:750;

    box-shadow:
        inset 3px 0 0 #16a05a;
}

.nav-icon{
    width:21px;
    min-width:21px;

    text-align:center;

    font-size:14px;
}


/* ============================================================
   SIDEBAR NOTIFICATION BADGE
   ============================================================ */

.sidebar-notification-count{
    margin-left:auto;

    min-width:17px;
    height:17px;

    padding:0 5px;

    border-radius:10px;

    background:#d92d20;

    color:#fff;

    font-size:9px;

    line-height:17px;

    font-weight:800;

    text-align:center;
}


/* ============================================================
   SIDEBAR BOTTOM
   ============================================================ */

.sidebar-bottom{
    margin-top:auto;

    padding-top:12px;

    border-top:1px solid #edf0f3;
}

.logout{
    min-height:40px;

    padding:9px 10px;

    display:flex;
    align-items:center;

    gap:11px;

    text-decoration:none;

    border-radius:9px;

    color:#475467;

    font-size:12.5px;
}

.logout:hover{
    background:#f3f5f7;
}


/* ============================================================
   MAIN
   ============================================================ */

.main{
    flex:1;
    min-width:0;
    min-height:100vh;
}


/* ============================================================
   TOPBAR
   ============================================================ */

.topbar{
    height:68px;

    background:#fff;

    border-bottom:1px solid #e5e7eb;

    display:flex;
    align-items:center;

    padding:0 22px;

    gap:14px;

    position:sticky;
    top:0;

    z-index:900;
}

.menu-toggle{
    width:38px;
    height:38px;

    border:0;

    border-radius:8px;

    background:transparent;

    color:#344054;

    cursor:pointer;

    font-size:19px;

    display:flex;
    align-items:center;
    justify-content:center;
}

.menu-toggle:hover{
    background:#f1f4f7;
    color:#158a50;
}

.page-title{
    font-size:15px;
    font-weight:750;
    color:#172033;
}

.top-right{
    margin-left:auto;

    display:flex;
    align-items:center;

    gap:14px;
}


/* ============================================================
   NOTIFICATION BELL
   ============================================================
   
   DIRECT LINK.
   
   NO BUTTON.
   NO POPUP.
   ============================================================ */

.notification-wrap{
    position:relative;
}

.notification{
    width:40px;
    height:40px;

    padding:0;

    border-radius:50%;

    background:transparent;

    color:#344054;

    display:flex;
    align-items:center;
    justify-content:center;

    font-size:18px;

    position:relative;

    text-decoration:none;
}

.notification:hover{
    background:#f2f8f4;
    color:#158a50;
}


/* ============================================================
   HEADER BADGE
   ============================================================ */

.notification-count{
    position:absolute;

    top:1px;
    right:0;

    min-width:16px;
    height:16px;

    padding:0 4px;

    border-radius:10px;

    background:#d92d20;

    color:#fff;

    font-size:9px;

    line-height:16px;

    font-weight:800;

    text-align:center;
}


/* ============================================================
   PROFILE
   ============================================================ */

.profile-wrap{
    position:relative;
}

.profile-button{
    border:0;

    background:transparent;

    padding:2px;

    display:flex;
    align-items:center;

    gap:9px;

    cursor:pointer;

    color:#172033;

    border-radius:9px;
}

.profile-button:hover{
    background:#f6f8f7;
}

.profile-circle{
    width:38px;
    height:38px;

    border-radius:50%;

    background:#e0f3e9;
    color:#14834b;

    display:flex;
    align-items:center;
    justify-content:center;

    font-size:14px;

    font-weight:800;
}

.profile-text{
    display:flex;
    flex-direction:column;
    align-items:flex-start;

    line-height:1.2;
}

.profile-name{
    font-size:12px;
    font-weight:750;
}

.profile-role{
    margin-top:2px;

    color:#158a50;

    font-size:10px;
    font-weight:650;
}

.profile-arrow{
    color:#7a8494;
    font-size:9px;
}


/* ============================================================
   PROFILE DROPDOWN
   ============================================================ */

.dropdown-panel{
    position:absolute;

    right:0;

    top:calc(100% + 10px);

    width:285px;

    background:#fff;

    border:1px solid #e3e7ed;

    border-radius:12px;

    box-shadow:
        0 15px 40px rgba(16,24,40,.13);

    overflow:hidden;

    display:none;

    z-index:2000;
}

.dropdown-panel.open{
    display:block;
}

.dropdown-head{
    padding:13px 15px;

    background:#fafcfb;

    border-bottom:1px solid #edf0f3;
}

.dropdown-head strong{
    display:block;
    font-size:12px;
}

.dropdown-head span{
    display:block;

    margin-top:3px;

    color:#7a8494;

    font-size:10px;
}

.profile-menu-item{
    display:flex;
    align-items:center;

    gap:10px;

    padding:11px 15px;

    text-decoration:none;

    color:#344054;

    font-size:11px;
}

.profile-menu-item:hover{
    background:#f2faf5;
    color:#117443;
}

.dropdown-divider{
    height:1px;
    background:#edf0f3;
}


/* ============================================================
   CONTENT
   ============================================================ */

.content{
    width:100%;

    max-width:1500px;

    margin:0 auto;

    padding:22px 24px 28px;
}


/* ============================================================
   SIDEBAR TOGGLE
   ============================================================ */

.sidebar.hidden{
    width:0;
    min-width:0;
    padding-left:0;
    padding-right:0;
    border-right:0;
    overflow:hidden;
}

.sidebar{
    transition:width .25s ease, min-width .25s ease, padding .25s ease, border .25s ease, transform .25s ease;
}


/* ============================================================
   MOBILE
   ============================================================ */

.mobile-overlay{
    display:none;
}

@media(max-width:900px){

    .sidebar{
        position:fixed;
        left:0;
        top:0;
        bottom:0;
        width:232px;
        min-width:232px;
        height:100vh;
        overflow-y:auto;
        box-shadow:
            12px 0 30px rgba(16,24,40,.12);
    }

    .sidebar.hidden{
        width:232px;
        min-width:232px;
        padding:22px 10px 14px;
        border-right:1px solid #e5e7eb;
        transform:translateX(-100%);
    }

    .sidebar:not(.hidden){
        transform:translateX(0);
    }

    .mobile-overlay{
        position:fixed;
        inset:0;
        background:
            rgba(15,23,42,.24);
        z-index:950;
    }

    .mobile-overlay.open{
        display:block;
    }

    .content{
        padding:18px;
    }

    .profile-text{
        display:none;
    }
}

</style>

</head>


<body>

<div class="app">


    <!-- ======================================================
         SIDEBAR
         ====================================================== -->

    <aside
        class="sidebar"
        id="agentSidebar"
    >

        <div class="brand">

            <div class="brand-main">
                INDBIN
            </div>

            <div class="brand-sub">
                Agent Portal
            </div>

        </div>


        <nav class="nav">

            <?php foreach (
                $sidebar_items
                as $item
            ): ?>

                <?php

                $is_active =
                    strtolower(
                        $item[0]
                    )
                    ===
                    strtolower(
                        $active_page
                    );

                $is_notification =
                    strtolower(
                        $item[0]
                    )
                    ===
                    'notifications.php';

                ?>


                <a
                    href="<?= agent_shell_esc($item[0]) ?>"
                    class="<?= $is_active ? 'active' : '' ?>"
                >

                    <span class="nav-icon">

                        <i
                            class="fa-solid
                            <?= agent_shell_esc($item[1]) ?>"
                        ></i>

                    </span>


                    <span>

                        <?= agent_shell_esc(
                            $item[2]
                        ) ?>

                    </span>


                    <?php if (
                        $is_notification
                        &&
                        $notification_count > 0
                    ): ?>

                        <span
                            class="sidebar-notification-count"
                        >

                            <?= agent_shell_esc(
                                $notification_badge
                            ) ?>

                        </span>

                    <?php endif; ?>

                </a>

            <?php endforeach; ?>

        </nav>


        <div class="sidebar-bottom">

            <a
                class="logout"
                href="logout.php"
            >

                <span class="nav-icon">

                    <i
                        class="
                        fa-solid
                        fa-right-from-bracket
                        "
                    ></i>

                </span>

                <span>
                    Logout
                </span>

            </a>

        </div>

    </aside>


    <!-- ======================================================
         MOBILE OVERLAY
         ====================================================== -->

    <div
        class="mobile-overlay"
        id="mobileOverlay"
    ></div>


    <!-- ======================================================
         MAIN
         ====================================================== -->

    <main class="main">


        <!-- ==================================================
             HEADER
             ================================================== -->

        <header class="topbar">


            <button
                type="button"
                class="menu-toggle"
                id="agentMenuToggle"
                aria-label="Open menu"
            >

                <i class="fa-solid fa-bars"></i>

            </button>


            <div class="top-right">


                <!-- ==========================================
                     NOTIFICATION
                     ========================================== -->

                <div class="notification-wrap">

                    <a
                        href="<?= agent_shell_esc(
                            defined('BASE_URL')
                                ? BASE_URL . '/agent/dashboard/notifications.php'
                                : 'notifications.php'
                        ) ?>"
                        class="notification"
                        aria-label="Notifications"
                        title="Notifications"
                    >

                        <i
                            class="fa-regular fa-bell"
                        ></i>


                        <?php if (
                            $notification_count > 0
                        ): ?>

                            <span
                                class="notification-count"
                            >

                                <?= agent_shell_esc(
                                    $notification_badge
                                ) ?>

                            </span>

                        <?php endif; ?>

                    </a>

                </div>


                <!-- ==========================================
                     PROFILE
                     ========================================== -->

                <div class="profile-wrap">

                    <button
                        type="button"
                        class="profile-button"
                        id="agentProfileButton"
                        aria-expanded="false"
                    >

                        <span class="profile-circle">

                            <?= agent_shell_esc(
                                $initial
                            ) ?>

                        </span>


                        <span class="profile-text">

                            <span class="profile-name">

                                <?= agent_shell_esc(
                                    $name
                                ) ?>

                            </span>


                            <span class="profile-role">

                                <?= agent_shell_esc(
                                    $current_role
                                ) ?>

                            </span>

                        </span>


                        <i
                            class="
                            fa-solid
                            fa-chevron-down
                            profile-arrow
                            "
                        ></i>

                    </button>


                    <div
                        class="dropdown-panel"
                        id="agentProfilePanel"
                    >

                        <div class="dropdown-head">

                            <strong>

                                <?= agent_shell_esc(
                                    $name
                                ) ?>

                            </strong>

                            <span>

                                Agent ID:

                                <?= $code !== ''
                                    ? agent_shell_esc($code)
                                    : 'Not assigned'
                                ?>

                            </span>

                        </div>


                        <a
                            class="profile-menu-item"
                            href="profile.php"
                        >

                            <i
                                class="
                                fa-regular
                                fa-user
                                "
                            ></i>

                            <span>
                                Profile
                            </span>

                        </a>


                        <a
                            class="profile-menu-item"
                            href="settings.php"
                        >

                            <i
                                class="
                                fa-solid
                                fa-gear
                                "
                            ></i>

                            <span>
                                Settings
                            </span>

                        </a>


                        <div
                            class="dropdown-divider"
                        ></div>


                        <a
                            class="profile-menu-item"
                            href="logout.php"
                        >

                            <i
                                class="
                                fa-solid
                                fa-right-from-bracket
                                "
                            ></i>

                            <span>
                                Logout
                            </span>

                        </a>

                    </div>

                </div>

            </div>

        </header>


        <!-- ==================================================
             CONTENT
             ================================================== -->

        <div class="content">

<?php
    }
}


/* ============================================================
   CLOSE SHELL
   ============================================================ */

if (!function_exists('agent_shell_close')) {

    function agent_shell_close(): void
    {
        ?>

        </div>

    </main>

</div>


<script>

(function () {

    const sidebar =
        document.getElementById(
            'agentSidebar'
        );

    const overlay =
        document.getElementById(
            'mobileOverlay'
        );

    const menuToggle =
        document.getElementById(
            'agentMenuToggle'
        );


    const profileButton =
        document.getElementById(
            'agentProfileButton'
        );

    const profilePanel =
        document.getElementById(
            'agentProfilePanel'
        );


    function closeProfile() {

        if (profilePanel) {

            profilePanel.classList.remove(
                'open'
            );
        }

        if (profileButton) {

            profileButton.setAttribute(
                'aria-expanded',
                'false'
            );
        }
    }


    /* ========================================================
       SIDEBAR MENU TOGGLE
       ======================================================== */

    if (menuToggle && sidebar) {

        menuToggle.addEventListener('click', function () {

            sidebar.classList.toggle('hidden');

            if (overlay) {
                overlay.classList.remove('open');
            }

        });


        if (overlay) {

            overlay.addEventListener('click', function () {

                sidebar.classList.add('hidden');
                overlay.classList.remove('open');

            });

        }
    }


    /* ========================================================
       PROFILE
       ======================================================== */

    if (
        profileButton
        &&
        profilePanel
    ) {

        profileButton.addEventListener(
            'click',
            function (event) {

                event.stopPropagation();

                const open =
                    profilePanel.classList.toggle(
                        'open'
                    );

                profileButton.setAttribute(
                    'aria-expanded',
                    open
                        ? 'true'
                        : 'false'
                );

            }
        );


        profilePanel.addEventListener(
            'click',
            function (event) {

                event.stopPropagation();

            }
        );
    }


    /* ========================================================
       CLOSE PROFILE
       ======================================================== */

    document.addEventListener(
        'click',
        function () {

            closeProfile();

        }
    );


    /* ========================================================
       CLOSE MOBILE MENU AFTER CLICK
       ======================================================== */

    document
        .querySelectorAll('.nav a')
        .forEach(
            function (link) {

                link.addEventListener(
                    'click',
                    function () {

                        if (
                            sidebar
                            &&
                            overlay
                        ) {

                            sidebar.classList.remove(
                                'open'
                            );

                            overlay.classList.remove(
                                'open'
                            );
                        }

                    }
                );

            }
        );

})();

</script>


</body>

</html>

<?php
    }
}
?>