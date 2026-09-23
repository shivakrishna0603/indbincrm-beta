<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/bootstrap.php';


/* =========================================================
   SESSION
========================================================= */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


/* =========================================================
   LOGOUT
========================================================= */

if (isset($_GET['logout'])) {

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {

        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();

    header(
        'Location: ' . BASE_URL . '/index.php'
    );

    exit;
}


/* =========================================================
   LOGIN CHECK
========================================================= */

$agent = require_agent($pdo);

$user_id = (int)($agent['id'] ?? ($_SESSION['user_id'] ?? 0));

if ($user_id <= 0) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

/* Keep the session identity synchronized with the agent account
   returned by the same bootstrap used by the other Agent modules. */
$_SESSION['user_id'] = $user_id;
/* =========================================================
   HELPER FUNCTIONS
========================================================= */

function agentTableExists(
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


function agentColumnExists(
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


function agentFirstColumn(
    PDO $pdo,
    string $table,
    array $columns
): ?string {

    foreach ($columns as $column) {
        if (agentColumnExists($pdo, $table, $column)) {
            return $column;
        }
    }

    return null;
}


function agentNormalizeStatus($value): string
{
    return strtolower(trim((string)$value));
}


function agentIsClosedAssignment($status): bool
{
    $status = agentNormalizeStatus($status);

    return in_array(
        $status,
        [
            'rejected',
            'removed',
            'inactive',
            'cancelled',
            'canceled',
            'revoked',
            'closed',
            'deleted'
        ],
        true
    );
}


function agentAddCustomer(
    array $row,
    array &$customers,
    array &$customerKeys
): void {

    $id = trim((string)(
        $row['id']
        ?? $row['customer_id']
        ?? $row['customer_user_id']
        ?? ''
    ));

    if ($id === '') {
        return;
    }

    $key = $id;

    if (isset($customerKeys[$key])) {
        $index = $customerKeys[$key];

        foreach ($row as $field => $value) {
            if (
                ($customers[$index][$field] ?? '') === ''
                && $value !== null
                && $value !== ''
            ) {
                $customers[$index][$field] = $value;
            }
        }

        return;
    }

    $customerKeys[$key] = count($customers);
    $customers[] = $row;
}


function agentEsc($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}


/* =========================================================
   AGENT INFORMATION
========================================================= */

$agent_name =
    $_SESSION['full_name'] ??
    'Agent';

$agent_email =
    $_SESSION['email'] ??
    '';

$agent_role = '';

$agent_id =
    'IND_A_' .
    str_pad(
        (string)$user_id,
        4,
        '0',
        STR_PAD_LEFT
    );

$account_status = 'Active';


/* =========================================================
   GET USER DETAILS + ADMIN-ASSIGNED ROLE
========================================================= */

/*
   IMPORTANT:
   The dashboard must show the same operational role that Admin
   assigned during Role Assignment.

   Role source priority:
   1. agent_verification.role  - latest onboarding/admin role
   2. users.assigned_role      - admin assignment/fallback
   3. agent_hierarchy.role     - legacy fallback (handled below)

   Nothing is hardcoded. The logged-in user's ID is always used.
*/

if (agentTableExists($pdo, 'users')) {

    try {

        $userColumns = ['id'];

        foreach ([
            'full_name',
            'email',
            'assigned_role',
            'party_code',
            'agent_code',
            'agent_id',
            'user_code',
            'account_status'
        ] as $column) {

            if (
                agentColumnExists(
                    $pdo,
                    'users',
                    $column
                )
            ) {

                $userColumns[] = $column;
            }
        }

        $stmt = $pdo->prepare("
            SELECT
                " . implode(', ', $userColumns) . "
            FROM users
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->execute([
            $user_id
        ]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {

            if (!empty($user['full_name'])) {
                $agent_name = $user['full_name'];
            }

            if (!empty($user['email'])) {
                $agent_email = $user['email'];
            }

            if (!empty($user['account_status'])) {
                $account_status = ucfirst(
                    strtolower(
                        trim((string)$user['account_status'])
                    )
                );
            }

            /*
             * Keep users.assigned_role as the first fallback.
             * agent_verification.role below is then checked as the
             * authoritative onboarding/admin role when available.
             */
            if (
                array_key_exists('assigned_role', $user)
                && trim((string)$user['assigned_role']) !== ''
            ) {
                $agent_role = trim((string)$user['assigned_role']);
            }
        }

    } catch (Throwable $e) {
        // Continue to agent_verification / hierarchy sources.
    }
}


/* =========================================================
   ADMIN / ONBOARDING ROLE
========================================================= */

/*
   The Role Assignment page stores the selected role in
   agent_verification.role using agent_verification.agent_id = users.id.

   Use the latest non-empty verification role when available.
   This prevents the dashboard from displaying the generic
   "Agent" value when the actual assigned role is e.g. "Sales Agent".
*/

if (
    agentTableExists($pdo, 'agent_verification')
    && agentColumnExists($pdo, 'agent_verification', 'agent_id')
    && agentColumnExists($pdo, 'agent_verification', 'role')
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

        $verificationRole = $stmt->fetchColumn();

        if (
            $verificationRole !== false
            && trim((string)$verificationRole) !== ''
        ) {
            $agent_role = trim((string)$verificationRole);
        }

    } catch (Throwable $e) {
        // Keep users.assigned_role as fallback.
    }
}


/* =========================================================
   AGENT HIERARCHY
========================================================= */

if (
    agentTableExists(
        $pdo,
        'agent_hierarchy'
    ) &&
    agentColumnExists(
        $pdo,
        'agent_hierarchy',
        'user_id'
    )
) {

    try {

        $hierarchyColumns = [];

        foreach ([
            'agent_id',
            'agent_code',
            'role'
        ] as $column) {

            if (
                agentColumnExists(
                    $pdo,
                    'agent_hierarchy',
                    $column
                )
            ) {

                $hierarchyColumns[] =
                    $column;
            }
        }


        if (!empty($hierarchyColumns)) {

            $stmt = $pdo->prepare("
                SELECT
                    " . implode(
                        ', ',
                        $hierarchyColumns
                    ) . "
                FROM agent_hierarchy
                WHERE user_id = ?
                LIMIT 1
            ");

            $stmt->execute([
                $user_id
            ]);

            $hierarchy =
                $stmt->fetch(
                    PDO::FETCH_ASSOC
                );


            if ($hierarchy) {

                if (
                    !empty(
                        $hierarchy['agent_id']
                    )
                ) {

                    $agent_id =
                        $hierarchy['agent_id'];

                } elseif (
                    !empty(
                        $hierarchy['agent_code']
                    )
                ) {

                    $agent_id =
                        $hierarchy['agent_code'];
                }


                if (
                    $agent_role === ''
                    && !empty($hierarchy['role'])
                ) {
                    $agent_role = trim((string)$hierarchy['role']);
                }
            }
        }

    } catch (Throwable $e) {
        // Keep defaults.
    }
}


/* =========================================================
   CRM DATA SOURCE FOR DASHBOARD

   SOURCE OF TRUTH:
   1) customer_agent_assignments (Admin -> Agent customer assignment)
   2) applications.agent_id (the application assignment created by Admin)

   Both references use users.id for the agent and customers.id for the
   customer record. No customer, count or task is hardcoded.
========================================================= */

$assignedCustomers = [];
$agent_tasks = [];
$total_customers = 0;
$total_tasks = 0;
$completed_tasks = 0;
$active_tasks = 0;
$total_applications = 0;
$pending_applications = 0;
$approved_applications = 0;
$latestApplicationByCustomerUser = [];
$allApplicationsForAssignedCustomers = [];

/* ---------------------------------------------------------
   Helper: add/merge one real customer row.
--------------------------------------------------------- */
$customerMap = [];

$addCustomerRow = static function (array $row) use (&$customerMap): void {
    $id = (int)($row['id'] ?? 0);
    if ($id <= 0) {
        return;
    }

    if (!isset($customerMap[$id])) {
        $customerMap[$id] = $row;
        return;
    }

    foreach ($row as $key => $value) {
        if (
            (!isset($customerMap[$id][$key]) || $customerMap[$id][$key] === '' || $customerMap[$id][$key] === null)
            && $value !== ''
            && $value !== null
        ) {
            $customerMap[$id][$key] = $value;
        }
    }
};

/* ---------------------------------------------------------
   1. CUSTOMERS ASSIGNED THROUGH customer_agent_assignments
--------------------------------------------------------- */
if (
    agentTableExists($pdo, 'customer_agent_assignments') &&
    agentTableExists($pdo, 'customers')
) {
    try {
        $fields = [
            'c.id AS id',
            'c.user_id AS user_id',
            'ca.id AS assignment_id',
            'ca.assigned_at AS assigned_at',
            'ca.status AS assignment_status'
        ];

        foreach ([
            'name', 'mobile', 'email', 'requirement', 'requirements',
            'status', 'created_at', 'updated_at'
        ] as $column) {
            if (agentColumnExists($pdo, 'customers', $column)) {
                $fields[] = 'c.`' . $column . '` AS `' . $column . '`';
            }
        }

        if (
            agentTableExists($pdo, 'users') &&
            agentColumnExists($pdo, 'users', 'customer_code')
        ) {
            $fields[] = 'u.customer_code AS customer_code';
        }

        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $fields) . "
             FROM customer_agent_assignments ca
             INNER JOIN customers c ON c.id = ca.customer_id
             LEFT JOIN users u ON u.id = c.user_id
             WHERE ca.agent_user_id = ?
               AND LOWER(TRIM(COALESCE(ca.status, 'active'))) = 'active'
             ORDER BY ca.assigned_at DESC, ca.id DESC"
        );
        $stmt->execute([$user_id]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $addCustomerRow($row);
        }
    } catch (Throwable $e) {
        error_log('Agent dashboard assignment source: ' . $e->getMessage());
    }
}

/* ---------------------------------------------------------
   2. APPLICATION ASSIGNMENT FALLBACK

   Admin also stores the selected agent in applications.agent_id.
   If an older/partially migrated assignment row is missing, the
   application assignment still identifies the real customer assigned
   to this agent. This is database-driven; nothing is hardcoded.
--------------------------------------------------------- */
if (
    agentTableExists($pdo, 'applications') &&
    agentColumnExists($pdo, 'applications', 'agent_id') &&
    agentTableExists($pdo, 'customers') &&
    agentColumnExists($pdo, 'applications', 'customer_id')
) {
    try {
        $customerFields = [
            'c.id AS id',
            'c.user_id AS user_id'
        ];

        foreach ([
            'name', 'mobile', 'email', 'requirement', 'requirements',
            'status', 'created_at', 'updated_at'
        ] as $column) {
            if (agentColumnExists($pdo, 'customers', $column)) {
                $customerFields[] = 'c.`' . $column . '` AS `' . $column . '`';
            }
        }

        if (
            agentTableExists($pdo, 'users') &&
            agentColumnExists($pdo, 'users', 'customer_code')
        ) {
            $customerFields[] = 'u.customer_code AS customer_code';
        }

        $order = agentColumnExists($pdo, 'applications', 'updated_at')
            ? 'a.updated_at DESC, a.id DESC'
            : 'a.id DESC';

        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $customerFields) . "
             FROM applications a
             INNER JOIN customers c ON c.user_id = a.customer_id
             LEFT JOIN users u ON u.id = c.user_id
             WHERE a.agent_id = ?
             ORDER BY $order"
        );
        $stmt->execute([$user_id]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['assignment_status'] = 'active';
            $addCustomerRow($row);
        }
    } catch (Throwable $e) {
        error_log('Agent dashboard application customer fallback: ' . $e->getMessage());
    }
}

$assignedCustomers = array_values($customerMap);
$total_customers = count($assignedCustomers);

/* ---------------------------------------------------------
   3. APPLICATIONS FOR THE ACTUAL ASSIGNED CUSTOMERS

   applications.customer_id = customers.user_id in this CRM.
   Therefore application records are collected by the assigned
   customers' user IDs, not by an invented customer ID.
--------------------------------------------------------- */

if (
    agentTableExists($pdo, 'applications') &&
    agentColumnExists($pdo, 'applications', 'customer_id') &&
    $total_customers > 0
) {
    try {
        $customerUserIds = [];
        foreach ($assignedCustomers as $customer) {
            $customerUserId = (int)($customer['user_id'] ?? 0);
            if ($customerUserId > 0) {
                $customerUserIds[$customerUserId] = true;
            }
        }
        $customerUserIds = array_keys($customerUserIds);

        if (!empty($customerUserIds)) {
            $placeholders = implode(',', array_fill(0, count($customerUserIds), '?'));
            $applicationFields = [
                'a.id AS application_id',
                'a.customer_id AS application_customer_user_id'
            ];

            foreach ([
                'application_number', 'product_name', 'status',
                'current_stage', 'amount', 'created_at', 'updated_at'
            ] as $column) {
                if (agentColumnExists($pdo, 'applications', $column)) {
                    $applicationFields[] = 'a.`' . $column . '` AS `' . $column . '`';
                }
            }

            $order = agentColumnExists($pdo, 'applications', 'updated_at')
                ? 'a.updated_at DESC, a.id DESC'
                : 'a.id DESC';

            $stmt = $pdo->prepare(
                "SELECT " . implode(', ', $applicationFields) . "
                 FROM applications a
                 WHERE a.customer_id IN ($placeholders)
                 ORDER BY $order"
            );
            $stmt->execute($customerUserIds);
            $allApplicationsForAssignedCustomers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $total_applications = count($allApplicationsForAssignedCustomers);

            foreach ($allApplicationsForAssignedCustomers as $application) {
                $status = strtolower(trim((string)($application['status'] ?? '')));

                if (in_array($status, [
                    'approved', 'completed', 'complete', 'closed',
                    'success', 'successful'
                ], true)) {
                    $approved_applications++;
                } elseif (in_array($status, [
                    'pending', 'submitted', 'under review',
                    'processing', 'in progress'
                ], true)) {
                    $pending_applications++;
                }

                $customerUserId = (int)($application['application_customer_user_id'] ?? 0);
                if (
                    $customerUserId > 0 &&
                    !isset($latestApplicationByCustomerUser[$customerUserId])
                ) {
                    $latestApplicationByCustomerUser[$customerUserId] = $application;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('Agent dashboard application query: ' . $e->getMessage());
    }
}

/* ---------------------------------------------------------
   4. CURRENT APPLICATION COUNTS

   The dashboard shows the CURRENT application for each assigned
   customer. Historical/older application rows are not counted as
   additional current applications. The latest application is the
   one used for the application card and My Tasks.
--------------------------------------------------------- */

$total_applications = count($latestApplicationByCustomerUser);
$pending_applications = 0;
$approved_applications = 0;

foreach ($latestApplicationByCustomerUser as $application) {
    $status = strtolower(trim((string)($application['status'] ?? '')));

    if (in_array($status, [
        'approved', 'completed', 'complete', 'closed',
        'success', 'successful'
    ], true)) {
        $approved_applications++;
    } elseif (in_array($status, [
        'pending', 'submitted', 'under review',
        'processing', 'in progress'
    ], true)) {
        $pending_applications++;
    }
}


/* ---------------------------------------------------------
   5. BUILD MY TASKS FROM THE REAL ASSIGNED CUSTOMERS
--------------------------------------------------------- */
foreach ($assignedCustomers as $customer) {
    $customerId = (int)($customer['id'] ?? 0);
    $customerUserId = (int)($customer['user_id'] ?? 0);

    if ($customerId <= 0) {
        continue;
    }

    $customerName = trim((string)($customer['name'] ?? ''));
    if ($customerName === '') {
        $customerName = 'Customer';
    }

    $requirement = trim((string)($customer['requirement'] ?? ''));
    if ($requirement === '') {
        $requirement = trim((string)($customer['requirements'] ?? ''));
    }

    $application = $latestApplicationByCustomerUser[$customerUserId] ?? null;

    $task = $customer;
    $task['customer_name'] = $customerName;
    $task['customer_id'] = $customerId;

    if ($application) {
        $task['application_id'] = $application['application_id'] ?? null;
        $task['application_number'] = $application['application_number'] ?? '';
        $task['product_name'] = $application['product_name'] ?? '';
        $task['application_status'] = $application['status'] ?? '';
        $task['current_stage'] = $application['current_stage'] ?? '';
        $task['application_created_at'] = $application['created_at'] ?? '';
        $task['application_updated_at'] = $application['updated_at'] ?? '';

        $stage = trim((string)($application['current_stage'] ?? ''));
        $status = trim((string)($application['status'] ?? ''));
        $product = trim((string)($application['product_name'] ?? ''));

        $task['task_title'] =
            $stage !== '' ? $stage :
            ($product !== '' ? $product : 'Application Processing');

        $task['taskDescription'] =
            $requirement !== ''
            ? $requirement
            : 'Application is currently being processed.';

        $task['status'] = $status !== '' ? $status : 'In Progress';
        $task['task_priority'] = 'Normal';
        $task['task_date'] =
            trim((string)($application['updated_at'] ?? '')) !== ''
            ? $application['updated_at']
            : ($application['created_at'] ?? '');

        $normalizedStatus = strtolower(trim($task['status']));
        $isCompleted = in_array($normalizedStatus, [
            'approved', 'completed', 'complete', 'closed',
            'success', 'successful', 'rejected'
        ], true);
    } else {
        $task['task_title'] = $requirement !== ''
            ? $requirement
            : 'Customer Follow-up';

        $task['taskDescription'] = $requirement !== ''
            ? $requirement
            : 'Follow up with the assigned customer.';

        $task['status'] = 'Assigned';
        $task['task_priority'] = 'Normal';
        $task['task_date'] = $customer['assigned_at'] ?? $customer['created_at'] ?? '';
        $isCompleted = false;
    }

    $task['task_completed'] = $isCompleted;
    $agent_tasks[] = $task;
}

$total_tasks = count($agent_tasks);

foreach ($agent_tasks as $task) {
    if (!empty($task['task_completed'])) {
        $completed_tasks++;
    }
}

$active_tasks = max(0, $total_tasks - $completed_tasks);


/* =========================================================
   FOLLOW-UP COUNT
========================================================= */

$total_followups = 0;

if (
    agentTableExists(
        $pdo,
        'leads'
    ) &&
    agentColumnExists(
        $pdo,
        'leads',
        'agent_id'
    )
) {

    try {

        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM leads
            WHERE agent_id = ?
        ");

        $stmt->execute([
            $user_id
        ]);

        $total_followups =
            (int)$stmt->fetchColumn();

    } catch (Throwable $e) {
        $total_followups = 0;
    }
}


/* =========================================================
   COMMISSION
========================================================= */

$total_commission = 0;

if (
    agentTableExists(
        $pdo,
        'commissions'
    )
) {

    $earnerColumn = null;

    if (
        agentColumnExists(
            $pdo,
            'commissions',
            'earner_id'
        )
    ) {

        $earnerColumn =
            'earner_id';

    } elseif (
        agentColumnExists(
            $pdo,
            'commissions',
            'agent_id'
        )
    ) {

        $earnerColumn =
            'agent_id';
    }


    $amountColumn = null;

    foreach ([
        'amount',
        'commission_amount',
        'total'
    ] as $column) {

        if (
            agentColumnExists(
                $pdo,
                'commissions',
                $column
            )
        ) {

            $amountColumn =
                $column;

            break;
        }
    }


    if (
        $earnerColumn !== null &&
        $amountColumn !== null
    ) {

        try {

            $stmt = $pdo->prepare("
                SELECT COALESCE(
                    SUM($amountColumn),
                    0
                )
                FROM commissions
                WHERE $earnerColumn = ?
            ");

            $stmt->execute([
                $user_id
            ]);

            $total_commission =
                (float)$stmt->fetchColumn();

        } catch (Throwable $e) {
            $total_commission = 0;
        }
    }
}


/* =========================================================
   DASHBOARD ROLE BRIDGE
========================================================= */

/*
   shell.php uses $role for the header/profile display.
   Keep it synchronized with the database-backed dashboard role.
*/
$role = trim((string)$agent_role);


/* =========================================================
   DASHBOARD SHELL
========================================================= */

/* Keep the common shell header/profile synchronized with the
   same database-driven role displayed in the dashboard. */
$role = $agent_role;

require_once __DIR__ . '/shell.php';

agent_shell_open(
    $pdo,
    $user_id,
    'dashboard',
    'Dashboard'
);

?>


<style>

/* =========================================================
   DASHBOARD
========================================================= */

.content {
    padding: 28px;
}


/* =========================================================
   WELCOME
========================================================= */

.welcome {

    background: #dcfce7;

    border: 1px solid #bbf7d0;

    border-radius: 18px;

    padding: 26px;

    margin-bottom: 24px;

    color: #166534;
}

.welcome h1 {

    margin: 0;

    font-size: 27px;

    color: #14532d;
}

.agent-details {

    display: flex;

    gap: 12px;

    flex-wrap: wrap;

    margin-top: 17px;
}

.agent-detail {

    background: #ffffff;

    border: 1px solid #bbf7d0;

    border-radius: 9px;

    padding: 8px 13px;

    font-size: 13px;

    color: #166534;
}


/* =========================================================
   STAT CARDS
========================================================= */

.stats {

    display: grid;

    grid-template-columns:
        repeat(5, minmax(0, 1fr));

    gap: 18px;

    margin-bottom: 24px;
}

.stat-link {
    display: block;
    color: inherit;
    text-decoration: none;
}

.stat-link .stat-card {
    cursor: pointer;
    transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
}

.stat-link:hover .stat-card {
    transform: translateY(-2px);
    border-color: #bbf7d0;
    box-shadow: 0 8px 20px rgba(22,163,74,.10);
}

.stat-link:focus-visible {
    outline: 2px solid #16a34a;
    outline-offset: 3px;
    border-radius: 16px;
}

.stat-card {

    background: #ffffff;

    border: 1px solid #e5e7eb;

    border-radius: 16px;

    padding: 21px;

    box-shadow:
        0 4px 15px
        rgba(0,0,0,.04);
}

.stat-header {

    display: flex;

    justify-content: space-between;

    align-items: center;
}

.stat-title {

    color: #6b7280;

    font-size: 13px;
}

.stat-icon {

    width: 40px;

    height: 40px;

    border-radius: 11px;

    background: #dcfce7;

    color: #16a34a;

    display: flex;

    align-items: center;

    justify-content: center;

    font-size: 18px;
}

.stat-value {

    margin-top: 13px;

    font-size: 25px;

    font-weight: 700;

    color: #111827;
}

@media (max-width: 1100px) {
    .stats {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

@media (max-width: 650px) {
    .stats {
        grid-template-columns: 1fr;
    }
}


/* =========================================================
   TASK SECTION
========================================================= */

.task-section {

    background: #f0fdf4;

    border: 1px solid #bbf7d0;

    border-radius: 16px;

    padding: 23px;

    margin-bottom: 24px;
}

.task-header {

    display: flex;

    justify-content: space-between;

    align-items: center;

    margin-bottom: 18px;
}

.task-heading {

    display: flex;

    align-items: center;

    gap: 11px;
}

.task-heading-icon {

    width: 40px;

    height: 40px;

    border-radius: 10px;

    background: #dcfce7;

    color: #16a34a;

    display: flex;

    align-items: center;

    justify-content: center;

    font-size: 18px;
}

.task-heading h2 {

    margin: 0;

    font-size: 19px;

    color: #14532d;
}

.task-heading p {

    margin: 3px 0 0;

    font-size: 12px;

    color: #4b5563;
}

.task-count {

    background: #ffffff;

    color: #15803d;

    border: 1px solid #bbf7d0;

    border-radius: 20px;

    padding: 7px 13px;

    font-size: 12px;

    font-weight: 600;
}


/* =========================================================
   TASK LIST
========================================================= */

.task-list {

    display: flex;

    flex-direction: column;

    gap: 11px;
}

.task-item {

    background: #ffffff;

    border: 1px solid #d1fae5;

    border-radius: 12px;

    padding: 17px;

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 15px;
}

.task-item:hover {

    border-color: #86efac;
}

.task-left {

    flex: 1;
}

.task-title {

    font-size: 14px;

    font-weight: 600;

    color: #111827;

    margin-bottom: 5px;
}

.task-description {

    font-size: 12px;

    color: #6b7280;

    line-height: 1.5;
}

.task-details {

    display: flex;

    gap: 13px;

    flex-wrap: wrap;

    margin-top: 8px;

    font-size: 11px;

    color: #6b7280;
}

.task-priority {

    padding: 6px 10px;

    border-radius: 18px;

    font-size: 11px;

    font-weight: 600;

    background: #fef3c7;

    color: #92400e;

    white-space: nowrap;
}

.task-status {

    padding: 6px 10px;

    border-radius: 18px;

    font-size: 11px;

    font-weight: 600;

    background: #dcfce7;

    color: #15803d;

    white-space: nowrap;
}


/* =========================================================
   NO TASK
========================================================= */

.no-task {

    background: #ffffff;

    border: 1px dashed #bbf7d0;

    border-radius: 12px;

    padding: 28px;

    text-align: center;

    color: #6b7280;

    font-size: 13px;
}

.no-task-icon {

    font-size: 31px;

    margin-bottom: 8px;
}

.no-task strong {

    color: #374151;

    display: block;

    margin-bottom: 4px;
}


/* =========================================================
   NORMAL SECTIONS
========================================================= */

.section {

    background: #ffffff;

    border: 1px solid #e5e7eb;

    border-radius: 16px;

    padding: 23px;

    margin-bottom: 24px;
}

.section-title {

    display: flex;

    justify-content: space-between;

    align-items: center;

    margin-bottom: 18px;
}

.section-title h2 {

    margin: 0;

    font-size: 19px;

    color: #111827;
}

.section-title span {

    color: #6b7280;

    font-size: 12px;
}


/* =========================================================
   WORK OVERVIEW
========================================================= */

.overview-grid {

    display: grid;

    grid-template-columns:
        repeat(4, 1fr);

    gap: 15px;
}

.overview-item {

    background: #f9fafb;

    border-radius: 12px;

    padding: 17px;
}

.overview-item h3 {

    margin: 0 0 7px;

    font-size: 13px;

    color: #374151;
}

.overview-number {

    font-size: 22px;

    font-weight: 700;

    color: #111827;
}

.overview-item p {

    margin: 5px 0 0;

    color: #6b7280;

    font-size: 11px;
}


/* =========================================================
   PROGRESS
========================================================= */

.progress-grid {

    display: grid;

    grid-template-columns:
        repeat(3, 1fr);

    gap: 18px;
}

.progress-card {

    border: 1px solid #e5e7eb;

    border-radius: 12px;

    padding: 18px;
}

.progress-header {

    display: flex;

    justify-content: space-between;

    margin-bottom: 11px;

    font-size: 13px;
}

.progress-header strong {

    color: #16a34a;
}

.progress-bar {

    height: 8px;

    background: #e5e7eb;

    border-radius: 10px;

    overflow: hidden;
}

.progress-fill {

    height: 100%;

    background: #16a34a;

    border-radius: 10px;
}

.progress-text {

    margin-top: 8px;

    font-size: 11px;

    color: #6b7280;
}


/* =========================================================
   QUICK ACTIONS
========================================================= */

.quick-grid {

    display: grid;

    grid-template-columns:
        repeat(4, 1fr);

    gap: 15px;
}

.quick-action {

    text-decoration: none;

    color: inherit;

    border: 1px solid #e5e7eb;

    border-radius: 13px;

    padding: 17px;

    transition: .2s;
}

.quick-action:hover {

    background: #f0fdf4;

    border-color: #86efac;
}

.quick-icon {

    width: 39px;

    height: 39px;

    border-radius: 10px;

    background: #dcfce7;

    color: #16a34a;

    display: flex;

    align-items: center;

    justify-content: center;

    margin-bottom: 11px;
}

.quick-action h3 {

    margin: 0 0 5px;

    font-size: 13px;

    color: #111827;
}

.quick-action p {

    margin: 0;

    font-size: 11px;

    color: #6b7280;
}


/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 1100px) {

    .stats {

        grid-template-columns:
            repeat(2, 1fr);
    }

    .overview-grid {

        grid-template-columns:
            repeat(2, 1fr);
    }

    .quick-grid {

        grid-template-columns:
            repeat(2, 1fr);
    }

    .progress-grid {

        grid-template-columns: 1fr;
    }
}


@media (max-width: 650px) {

    .content {

        padding: 15px;
    }

    .stats,
    .overview-grid,
    .quick-grid {

        grid-template-columns: 1fr;
    }

    .task-item {

        flex-direction: column;

        align-items: flex-start;
    }

    .task-header {

        align-items: flex-start;

        gap: 10px;
    }
}

</style>


<!-- =========================================================
     DASHBOARD CONTENT
========================================================= -->

<div class="content">


    <!-- =====================================================
         AGENT INFORMATION
    ====================================================== -->

    <div class="welcome">

        <h1>
            Welcome,
            <?= agentEsc($agent_name) ?>
        </h1>


        <div class="agent-details">

            <div class="agent-detail">

                <strong>
                    Agent ID:
                </strong>

                <?= agentEsc($agent_id) ?>

            </div>


            <div class="agent-detail">

                <strong>
                    Role:
                </strong>

                <?= agentEsc($agent_role !== '' ? $agent_role : 'Role Not Assigned') ?>

            </div>


            <div class="agent-detail">

                <strong>
                    Status:
                </strong>

                <?= agentEsc($account_status) ?>

            </div>

        </div>

    </div>


    <!-- =====================================================
         STAT CARDS
    ====================================================== -->

    <div class="stats">


    <!-- CUSTOMERS -->
    <a class="stat-link" href="customers.php" aria-label="Open Customers">

        <div class="stat-card">

            <div class="stat-header">

                <div class="stat-title">
                    Customers
                </div>

                <div class="stat-icon">
                    <i class="fa-solid fa-users"></i>
                </div>

            </div>

            <div class="stat-value">
                <?= $total_customers ?>
            </div>

        </div>

    </a>


    <!-- APPLICATIONS -->
    <a class="stat-link" href="applications.php" aria-label="Open Applications">

        <div class="stat-card">

            <div class="stat-header">

                <div class="stat-title">
                    Applications
                </div>

                <div class="stat-icon">
                    <i class="fa-solid fa-file-lines"></i>
                </div>

            </div>

            <div class="stat-value">
                <?= $total_applications ?>
            </div>

        </div>

    </a>


    <!-- PENDING FOLLOW-UPS -->
    <a class="stat-link" href="followups.php" aria-label="Open Follow-ups">

        <div class="stat-card">

            <div class="stat-header">

                <div class="stat-title">
                    Pending Follow-ups
                </div>

                <div class="stat-icon">
                    <i class="fa-solid fa-bell"></i>
                </div>

            </div>

            <div class="stat-value">
                <?= $total_followups ?>
            </div>

        </div>

    </a>


    <!-- COMMISSION -->
    <a class="stat-link" href="wallet.php" aria-label="Open Wallet and Commission">

        <div class="stat-card">

            <div class="stat-header">

                <div class="stat-title">
                    Commission
                </div>

                <div class="stat-icon">
                    ₹
                </div>

            </div>

            <div class="stat-value">
                ₹<?= number_format($total_commission, 2) ?>
            </div>

        </div>

    </a>


    <!-- ACCOUNT STATUS -->
    <div class="stat-card">

        <div class="stat-header">

            <div class="stat-title">
                Account Status
            </div>

            <div class="stat-icon">
                ✓
            </div>

        </div>

        <div class="stat-value">
            <?= agentEsc($account_status) ?>
        </div>

    </div>


</div>


<div class="task-section">


        <div class="task-header">


            <div class="task-heading">

                <div class="task-heading-icon">
                    ✓
                </div>


                <div>

                    <h2>
                        My Tasks
                    </h2>

                    <p>
                        Customer requirements assigned
                        to you by Admin
                    </p>

                </div>

            </div>


            <div class="task-count">

                <?= $total_tasks ?>

                <?= $total_tasks === 1
                    ? 'Task'
                    : 'Tasks' ?>

            </div>


        </div>


        <?php if (!empty($agent_tasks)): ?>


            <div class="task-list">


                <?php foreach (
                    $agent_tasks
                    as $task
                ): ?>


                    <?php

                    $taskTitle =
                        $task['task_title']
                        ?? $task['title']
                        ?? $task[
                            'task_title'
                        ]
                        ?? $task[
                            'requirement_title'
                        ]
                        ?? $task['subject']
                        ?? $task['service']
                        ?? 'Customer Requirement';


                    $taskDescription =
                        $task['taskDescription']
                        ?? $task['requirement']
                        ?? $task['description']
                        ?? $task['details']
                        ?? 'Customer requirement assigned to you.';


                    /*
                     * Current workflow status comes from the
                     * latest application when an application exists.
                     * Otherwise the active Admin -> Agent assignment
                     * is shown as Requirement Assigned / Customer Assigned.
                     */
                    $taskStatus =
                        $task['status']
                        ?? 'Assigned';


                    $taskPriority =
                        $task['task_priority']
                        ?? $task['priority']
                        ?? 'Normal';


                    $taskDate =
                        $task['task_date']
                        ?? $task['assigned_at']
                        ?? $task['created_at']
                        ?? '';


                    $customerName =
                        $task['customer_name']
                        ?? $task['customer']
                        ?? $task[
                            'full_name'
                        ]
                        ?? '';

                    ?>


                    <div class="task-item">


                        <div class="task-left">


                            <div class="task-title">

                                <?= agentEsc(
                                    $taskTitle
                                ) ?>

                            </div>


                            <div class="task-description">

                                <?= agentEsc(
                                    $taskDescription
                                ) ?>

                            </div>


                            <div class="task-details">


                                <?php if (
                                    $customerName !== ''
                                ): ?>

                                    <span>

                                        👤
                                        <?= agentEsc(
                                            $customerName
                                        ) ?>

                                    </span>

                                <?php endif; ?>


                                <?php if (
                                    !empty($task['customer_code'])
                                ): ?>

                                    <span>

                                        🆔
                                        <?= agentEsc(
                                            $task['customer_code']
                                        ) ?>

                                    </span>

                                <?php endif; ?>


                                <?php if (
                                    $taskDate !== ''
                                ): ?>

                                    <span>

                                        📅
                                        <?= agentEsc(
                                            $taskDate
                                        ) ?>

                                    </span>

                                <?php endif; ?>


                            </div>


                        </div>


                        <div class="task-priority">

                            <?= agentEsc(
                                ucfirst(
                                    strtolower(
                                        (string)$taskPriority
                                    )
                                )
                            ) ?>

                        </div>


                        <div class="task-status">

                            <?= agentEsc(
                                ucfirst(
                                    strtolower(
                                        (string)$taskStatus
                                    )
                                )
                            ) ?>

                        </div>


                    </div>


                <?php endforeach; ?>


            </div>


        <?php else: ?>


            <div class="no-task">

                <div class="no-task-icon">
                    📋
                </div>


                <strong>
                    No tasks assigned yet
                </strong>


                <div>
                    When Admin assigns a customer
                    requirement to you, it will
                    appear here.
                </div>

            </div>


        <?php endif; ?>


    </div>


    <!-- =====================================================
         WORK OVERVIEW
    ====================================================== -->

    <div class="section">


        <div class="section-title">

            <h2>
                Work Overview
            </h2>

            <span>
                Your current activity
            </span>

        </div>


        <div class="overview-grid">


            <div class="overview-item">

                <h3>
                    Applications
                </h3>

                <div class="overview-number">
                    <?= $total_applications ?>
                </div>

                <p>
                    Total applications
                </p>

            </div>


            <div class="overview-item">

                <h3>
                    Follow-ups
                </h3>

                <div class="overview-number">
                    <?= $total_followups ?>
                </div>

                <p>
                    Customer follow-ups
                </p>

            </div>


            <div class="overview-item">

                <h3>
                    Tasks
                </h3>

                <div class="overview-number">
                    <?= $total_tasks ?>
                </div>

                <p>
                    Assigned tasks
                </p>

            </div>


            <div class="overview-item">

                <h3>
                    Commission
                </h3>

                <div class="overview-number">

                    ₹<?= number_format(
                        $total_commission,
                        0
                    ) ?>

                </div>

                <p>
                    Total commission
                </p>

            </div>


        </div>

    </div>


    <!-- =====================================================
         PROGRESS & ATTENTION
    ====================================================== -->

    <div class="section">


        <div class="section-title">

            <h2>
                Progress & Attention
            </h2>

            <span>
                Track your work
            </span>

        </div>


        <div class="progress-grid">


            <?php

            $applicationProgress =
                $total_applications > 0
                ? round(
                    (
                        $approved_applications /
                        $total_applications
                    ) * 100
                )
                : 0;

            ?>


            <div class="progress-card">

                <div class="progress-header">

                    <span>
                        Application Progress
                    </span>

                    <strong>
                        <?= $applicationProgress ?>%
                    </strong>

                </div>


                <div class="progress-bar">

                    <div
                        class="progress-fill"
                        style="
                            width:
                            <?= $applicationProgress ?>%;
                        "
                    ></div>

                </div>


                <div class="progress-text">

                    <?= $approved_applications ?>

                    completed /

                    <?= $total_applications ?>

                    total

                </div>

            </div>


            <div class="progress-card">

                <div class="progress-header">

                    <span>
                        Task Progress
                    </span>

                    <strong>

                        <?= $total_tasks > 0
                            ? round(($completed_tasks / $total_tasks) * 100) . '%'
                            : '0%' ?>

                    </strong>

                </div>


                <div class="progress-bar">

                    <div
                        class="progress-fill"
                        style="
                            width:
                            <?= $total_tasks > 0
                                ? round(($completed_tasks / $total_tasks) * 100)
                                : 0 ?>%;
                        "
                    ></div>

                </div>


                <div class="progress-text">

                    <?= $completed_tasks ?> completed / <?= $total_tasks ?> total tasks

                </div>

            </div>


            <div class="progress-card">

                <div class="progress-header">

                    <span>
                        Account Progress
                    </span>

                    <strong>
                        <?= agentEsc(
                            $account_status
                        ) ?>
                    </strong>

                </div>


                <div class="progress-bar">

                    <div
                        class="progress-fill"
                        style="
                            width:
                            <?= strtolower(
                                $account_status
                            ) === 'active'
                                ? '100'
                                : '50' ?>%;
                        "
                    ></div>

                </div>


                <div class="progress-text">

                    Current account status

                </div>

            </div>


        </div>

    </div>


    <!-- =====================================================
         QUICK ACTIONS
    ====================================================== -->

    <div class="section">


        <div class="section-title">

            <h2>
                Quick Actions
            </h2>

            <span>
                Access your modules
            </span>

        </div>


        <div class="quick-grid">


            <a
                href="<?= BASE_URL ?>/agent/dashboard/applications.php"
                class="quick-action"
            >

                <div class="quick-icon">
                    📄
                </div>

                <h3>
                    Applications
                </h3>

                <p>
                    View and manage applications
                </p>

            </a>


            <a
                href="<?= BASE_URL ?>/agent/dashboard/followups.php"
                class="quick-action"
            >

                <div class="quick-icon">
                    🔔
                </div>

                <h3>
                    Follow-ups
                </h3>

                <p>
                    Manage customer follow-ups
                </p>

            </a>


            <a
                href="<?= BASE_URL ?>/agent/dashboard/reports.php"
                class="quick-action"
            >

                <div class="quick-icon">
                    📊
                </div>

                <h3>
                    Reports
                </h3>

                <p>
                    View your reports
                </p>

            </a>


            <a
                href="<?= BASE_URL ?>/agent/dashboard/customers.php"
                class="quick-action"
            >

                <div class="quick-icon">
                    👥
                </div>

                <h3>
                    Customers
                </h3>

                <p>
                    View <?= (int)$total_customers ?> assigned customer<?= $total_customers === 1 ? '' : 's' ?>
                </p>

            </a>


        </div>

    </div>


</div>



    <!-- =====================================================
         MY ASSIGNED CUSTOMERS
    ====================================================== -->

    <div class="section">

        <div class="section-title">

            <h2>
                My Customers
            </h2>

            <span>
                Customers assigned to you
            </span>

        </div>

        <?php if (empty($assignedCustomers)): ?>

            <div class="no-task">

                <div class="no-task-icon">
                    👤
                </div>

                <strong>
                    No customers assigned yet
                </strong>

                <div>
                    Customers assigned by Admin will appear here.
                </div>

            </div>

        <?php else: ?>

            <div class="overview-grid">

                <?php foreach ($assignedCustomers as $customer): ?>

                    <div class="overview-item">

                        <h3>
                            <?= agentEsc($customer['name'] ?? 'Customer') ?>
                        </h3>

                        <p>
                            Mobile:
                            <?= agentEsc($customer['mobile'] ?? '-') ?>
                        </p>

                        <p>
                            Requirement:
                            <?= agentEsc($customer['requirement'] ?? '-') ?>
                        </p>

                        <p>
                            Status:
                            <?= agentEsc($customer['status'] ?? 'Assigned') ?>
                        </p>

                        <p style="margin-top:12px;">

                            <a
                                href="<?= BASE_URL ?>/agent/dashboard/customers.php?id=<?= (int)$customer['id'] ?>"
                                style="
                                    color:#16a34a;
                                    text-decoration:none;
                                    font-weight:600;
                                "
                            >
                                View Customer →
                            </a>

                        </p>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </div>

<?php

agent_shell_close();

?>
