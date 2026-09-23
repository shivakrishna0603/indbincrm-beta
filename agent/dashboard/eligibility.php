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

$agentId = (int)($agent['id'] ?? $_SESSION['user_id'] ?? 0);

if ($agentId <= 0) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}


/* =========================================================
   HELPERS
   ========================================================= */

function eligibilityEsc($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function displayValue($value, string $fallback = 'Not provided'): string
{
    $value = trim((string)$value);

    return $value !== '' ? $value : $fallback;
}

function customerAge($dob): ?int
{
    if (!$dob) {
        return null;
    }

    try {
        $birth = new DateTime((string)$dob);
        $today = new DateTime('today');

        return $birth->diff($today)->y;
    } catch (Throwable $e) {
        return null;
    }
}

function nullableInt($value): ?int
{
    if ($value === null || trim((string)$value) === '') {
        return null;
    }

    return is_numeric($value) ? (int)$value : null;
}

function nullableFloat($value): ?float
{
    if ($value === null || trim((string)$value) === '') {
        return null;
    }

    return is_numeric($value) ? (float)$value : null;
}


/* =========================================================
   ELIGIBILITY STORAGE
   ========================================================= */

try {

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS agent_eligibility_checks (

            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

            customer_id INT NOT NULL,

            agent_id INT NOT NULL,

            age INT NULL,

            employment_type VARCHAR(100) NULL,

            employer_name VARCHAR(190) NULL,

            total_experience DECIMAL(6,2) NULL,

            current_experience DECIMAL(6,2) NULL,

            monthly_income DECIMAL(12,2) NULL,

            existing_emi DECIMAL(12,2) NULL,

            loan_amount DECIMAL(12,2) NULL,

            loan_tenure INT NULL,

            credit_score INT NULL,

            eligibility_checked TINYINT(1)
                NOT NULL DEFAULT 0,

            eligibility_passed TINYINT(1)
                NOT NULL DEFAULT 0,

            eligibility_message TEXT NULL,

            created_at TIMESTAMP
                NOT NULL DEFAULT CURRENT_TIMESTAMP,

            updated_at TIMESTAMP
                NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,

            UNIQUE KEY uq_agent_customer
                (customer_id, agent_id),

            INDEX idx_agent_id (agent_id),

            INDEX idx_customer_id (customer_id)

        )
    ");

} catch (Throwable $e) {
    // Continue. The table may already exist.
}


/* =========================================================
   SAVE CUSTOMER INFORMATION
   ========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = trim(
        (string)($_POST['action'] ?? '')
    );

    $customerId = (int)(
        $_POST['customer_id'] ?? 0
    );


    /* -----------------------------------------------------
       CUSTOMER INFORMATION EDIT
       ----------------------------------------------------- */

    if (
        $action === 'save_customer'
        && $customerId > 0
    ) {

        csrf_verify();


        /*
         * Verify that the customer belongs to this agent.
         */

        $verify = $pdo->prepare("
            SELECT c.id
            FROM customers c

            WHERE c.id = ?

              AND (
                    EXISTS (
                        SELECT 1
                        FROM customer_agent_assignments ca

                        WHERE ca.customer_id = c.id

                          AND ca.agent_user_id = ?

                          AND ca.status = 'active'
                    )

                    OR

                    EXISTS (
                        SELECT 1
                        FROM applications a

                        WHERE a.customer_id = c.user_id

                          AND a.agent_id = ?
                    )
              )

            LIMIT 1
        ");

        $verify->execute([
            $customerId,
            $agentId,
            $agentId
        ]);

        if (!$verify->fetchColumn()) {
            http_response_code(403);
            exit('This customer is not assigned to you.');
        }


        $name = trim(
            (string)(
                $_POST['customer_name'] ?? ''
            )
        );

        $mobile = trim(
            (string)(
                $_POST['customer_mobile'] ?? ''
            )
        );

        $email = trim(
            (string)(
                $_POST['customer_email'] ?? ''
            )
        );

        $requirement = trim(
            (string)(
                $_POST['customer_requirement'] ?? ''
            )
        );


        if ($name === '') {
            exit('Customer name is required.');
        }


        /*
         * Update only the customer information.
         */

        $update = $pdo->prepare("
            UPDATE customers

            SET
                name = ?,
                mobile = ?,
                email = ?,
                requirement = ?

            WHERE id = ?

            LIMIT 1
        ");

        $update->execute([
            $name,
            $mobile !== '' ? $mobile : null,
            $email !== '' ? $email : null,
            $requirement !== '' ? $requirement : null,
            $customerId
        ]);


        header(
            'Location: eligibility.php?customer_updated=1#customer-'
            . $customerId
        );

        exit;
    }


    /* -----------------------------------------------------
       SAVE ELIGIBILITY INFORMATION
       ----------------------------------------------------- */

    if (
        $action === 'save_eligibility'
        && $customerId > 0
    ) {

        csrf_verify();


        /*
         * Get customer/application information.
         */

        $stmt = $pdo->prepare("
            SELECT

                c.id AS customer_id,

                c.user_id,

                c.name,

                c.mobile,

                c.email,

                c.requirement,

                cp.dob,

                cp.employment_type,

                cp.occupation,

                ce.credit_score,

                a.product_code,

                a.product_name,

                a.amount AS application_amount,

                a.tenure_months AS application_tenure

            FROM customers c

            LEFT JOIN customer_profiles cp
                ON cp.user_id = c.user_id

            LEFT JOIN credit_evaluations ce
                ON ce.user_id = c.user_id
                AND ce.is_current = 1

            LEFT JOIN applications a
                ON a.id = (

                    SELECT a2.id

                    FROM applications a2

                    WHERE a2.customer_id = c.user_id

                      AND a2.agent_id = ?

                    ORDER BY a2.id DESC

                    LIMIT 1

                )

            WHERE c.id = ?

              AND (
                    EXISTS (
                        SELECT 1
                        FROM customer_agent_assignments ca

                        WHERE ca.customer_id = c.id

                          AND ca.agent_user_id = ?

                          AND ca.status = 'active'
                    )

                    OR

                    EXISTS (
                        SELECT 1
                        FROM applications ax

                        WHERE ax.customer_id = c.user_id

                          AND ax.agent_id = ?
                    )
              )

            LIMIT 1
        ");

        $stmt->execute([
            $agentId,
            $customerId,
            $agentId,
            $agentId
        ]);

        $customer = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$customer) {
            http_response_code(403);
            exit('Customer is not assigned to this agent.');
        }


        $age = nullableInt(
            $_POST['age'] ?? null
        );

        if ($age === null) {
            $age = customerAge(
                $customer['dob'] ?? null
            );
        }


        $employmentType = trim(
            (string)(
                $_POST['employment_type']
                ?? $customer['employment_type']
                ?? ''
            )
        );


        $employerName = trim(
            (string)(
                $_POST['employer_name'] ?? ''
            )
        );


        $totalExperience = nullableFloat(
            $_POST['total_experience'] ?? null
        );


        $currentExperience = nullableFloat(
            $_POST['current_experience'] ?? null
        );


        $monthlyIncome = nullableFloat(
            $_POST['monthly_income'] ?? null
        );


        $existingEmi = nullableFloat(
            $_POST['existing_emi'] ?? null
        );


        $loanAmount = nullableFloat(
            $_POST['loan_amount']
            ?? $customer['application_amount']
            ?? null
        );


        $loanTenure = nullableInt(
            $_POST['loan_tenure']
            ?? $customer['application_tenure']
            ?? null
        );


        $creditScore = nullableInt(
            $_POST['credit_score']
            ?? $customer['credit_score']
            ?? null
        );


        /*
         * Product criteria come from database.
         */

        $product = null;

        $productCode = trim(
            (string)(
                $customer['product_code'] ?? ''
            )
        );


        if ($productCode !== '') {

            try {

                $productStmt = $pdo->prepare("
                    SELECT
                        product_code,
                        product_name,
                        category,
                        description,
                        requires_credit,
                        requires_kfs,
                        min_score,
                        status,
                        is_active

                    FROM product_catalog

                    WHERE product_code = ?

                      AND is_active = 1

                    LIMIT 1
                ");

                $productStmt->execute([
                    $productCode
                ]);

                $product =
                    $productStmt->fetch(
                        PDO::FETCH_ASSOC
                    ) ?: null;

            } catch (Throwable $e) {
                $product = null;
            }
        }


        /*
         * Eligibility result.
         */

        $passed = true;

        $message =
            'Eligibility information verified.';


        if (
            $product
            && (int)$product['requires_credit'] === 1
        ) {

            if ($creditScore === null) {

                $passed = false;

                $message =
                    'Credit score is required for this product.';

            } elseif (
                (int)$product['min_score'] > 0
                && $creditScore <
                   (int)$product['min_score']
            ) {

                $passed = false;

                $message =
                    'Credit score is below the configured minimum.';
            }
        }


        /*
         * Save eligibility.
         */

        $save = $pdo->prepare("
            INSERT INTO agent_eligibility_checks
            (
                customer_id,
                agent_id,
                age,
                employment_type,
                employer_name,
                total_experience,
                current_experience,
                monthly_income,
                existing_emi,
                loan_amount,
                loan_tenure,
                credit_score,
                eligibility_checked,
                eligibility_passed,
                eligibility_message
            )

            VALUES
            (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?
            )

            ON DUPLICATE KEY UPDATE

                age = VALUES(age),

                employment_type =
                    VALUES(employment_type),

                employer_name =
                    VALUES(employer_name),

                total_experience =
                    VALUES(total_experience),

                current_experience =
                    VALUES(current_experience),

                monthly_income =
                    VALUES(monthly_income),

                existing_emi =
                    VALUES(existing_emi),

                loan_amount =
                    VALUES(loan_amount),

                loan_tenure =
                    VALUES(loan_tenure),

                credit_score =
                    VALUES(credit_score),

                eligibility_checked = 1,

                eligibility_passed =
                    VALUES(eligibility_passed),

                eligibility_message =
                    VALUES(eligibility_message),

                updated_at =
                    CURRENT_TIMESTAMP
        ");

        $save->execute([
            $customerId,
            $agentId,
            $age,
            $employmentType !== ''
                ? $employmentType
                : null,
            $employerName !== ''
                ? $employerName
                : null,
            $totalExperience,
            $currentExperience,
            $monthlyIncome,
            $existingEmi,
            $loanAmount,
            $loanTenure,
            $creditScore,
            $passed ? 1 : 0,
            $message
        ]);


        header(
            'Location: eligibility.php?saved=1#customer-'
            . $customerId
        );

        exit;
    }
}


/* =========================================================
   LOAD CUSTOMERS
   ========================================================= */

$customers = [];

$loadError = '';

try {

    $stmt = $pdo->prepare("
        SELECT

            c.id AS customer_id,

            c.user_id,

            c.name,

            c.mobile,

            c.email,

            c.requirement,

            c.status AS customer_status,


            u.customer_code,

            u.email AS user_email,


            cp.dob,

            cp.occupation,

            cp.employment_type,

            cp.annual_income_band,


            ce.credit_score,

            ce.bureau_score,

            ce.risk_category,

            ce.decision AS credit_decision,


            ca.assigned_at,

            ca.status AS assignment_status,


            a.id AS application_id,

            a.application_number,

            a.product_code,

            a.product_name,

            a.category AS application_category,

            a.purpose AS application_purpose,

            a.amount AS application_amount,

            a.tenure_months AS application_tenure,

            a.status AS application_status,

            a.current_stage


        FROM customers c


        LEFT JOIN users u
            ON u.id = c.user_id


        LEFT JOIN customer_profiles cp
            ON cp.user_id = c.user_id


        LEFT JOIN credit_evaluations ce
            ON ce.user_id = c.user_id
            AND ce.is_current = 1


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


        WHERE ca.id IS NOT NULL

           OR a.id IS NOT NULL


        ORDER BY

            COALESCE(
                ca.assigned_at,
                a.id
            ) DESC,

            c.id DESC
    ");

    $stmt->execute([
        $agentId,
        $agentId
    ]);

    $customers =
        $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {

    $loadError = $e->getMessage();

    $customers = [];
}


/* =========================================================
   LOAD SAVED ELIGIBILITY
   ========================================================= */

$eligibility = [];

if ($customers) {

    $ids = array_map(
        static function ($row) {
            return (int)$row['customer_id'];
        },
        $customers
    );

    $ids = array_values(
        array_unique($ids)
    );


    if ($ids) {

        $placeholders =
            implode(
                ',',
                array_fill(
                    0,
                    count($ids),
                    '?'
                )
            );


        try {

            $stmt = $pdo->prepare("
                SELECT *

                FROM agent_eligibility_checks

                WHERE agent_id = ?

                  AND customer_id IN (
                      $placeholders
                  )
            ");

            $stmt->execute(
                array_merge(
                    [$agentId],
                    $ids
                )
            );


            foreach (
                $stmt->fetchAll(
                    PDO::FETCH_ASSOC
                ) as $row
            ) {

                $eligibility[
                    (int)$row['customer_id']
                ] = $row;
            }

        } catch (Throwable $e) {
            // No saved eligibility yet.
        }
    }
}


/* =========================================================
   SUMMARY
   ========================================================= */

$totalCustomers =
    count($customers);

$submittedCount = 0;

foreach ($customers as $customer) {

    $id = (int)$customer['customer_id'];

    if (
        isset($eligibility[$id])
        && (int)(
            $eligibility[$id]['eligibility_checked']
            ?? 0
        ) === 1
    ) {
        $submittedCount++;
    }
}

$pendingCount =
    max(
        0,
        $totalCustomers - $submittedCount
    );


/* =========================================================
   SHELL
   ========================================================= */

agent_shell_open(
    $pdo,
    $agentId,
    'Eligibility Check',
    'eligibility.php'
);

?>

<style>

.eligibility-page {
    padding: 28px 24px 50px;
    background: #f7f9fc;
    min-height: calc(100vh - 70px);
}

.page-header {
    margin-bottom: 20px;
}

.page-header h1 {
    margin: 0;
    color: #17305f;
    font-size: 30px;
}

.page-header p {
    margin: 6px 0 0;
    color: #667085;
    font-size: 14px;
}


/* SUMMARY */

.summary-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 15px;
    margin-bottom: 18px;
}

.summary-card {
    background: #fff;
    border: 1px solid #e5e9ef;
    border-radius: 13px;
    padding: 18px;
}

.summary-label {
    color: #667085;
    font-size: 12px;
}

.summary-value {
    margin-top: 7px;
    font-size: 28px;
    font-weight: 800;
}

.submitted .summary-value {
    color: #16a34a;
}

.pending .summary-value {
    color: #c47a00;
}


/* SEARCH */

.search-box {
    background: #fff;
    border: 1px solid #e5e9ef;
    border-radius: 12px;
    padding: 12px;
    margin-bottom: 18px;
}

.search-box input {
    width: 100%;
    height: 42px;
    box-sizing: border-box;
    border: 1px solid #d7dde5;
    border-radius: 8px;
    padding: 0 12px;
}


/* ERROR */

.error-box {
    background: #fff1f2;
    color: #b42318;
    border: 1px solid #fecdd3;
    border-radius: 9px;
    padding: 12px;
    margin-bottom: 15px;
}


/* SUCCESS */

.success-box {
    background: #ecfdf3;
    color: #087443;
    border: 1px solid #b7ebcc;
    border-radius: 9px;
    padding: 12px;
    margin-bottom: 15px;
}


/* CUSTOMER CARD */

.customer-card {
    background: #fff;
    border: 1px solid #e5e9ef;
    border-radius: 14px;
    margin-bottom: 18px;
    overflow: hidden;
}


/* CUSTOMER HEADER */

.customer-header {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 18px 20px;
    border-bottom: 1px solid #edf0f3;
}

.customer-avatar {
    width: 46px;
    height: 46px;
    border-radius: 50%;
    background: #dcfce7;
    color: #15803d;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
}

.customer-title {
    flex: 1;
}

.customer-title h2 {
    margin: 0;
    color: #172033;
    font-size: 17px;
}

.customer-title p {
    margin: 5px 0 0;
    color: #667085;
    font-size: 11px;
}

.status-badge {
    padding: 7px 11px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 750;
}

.status-pending {
    background: #fff4d6;
    color: #a45a00;
}

.status-done {
    background: #dcfce7;
    color: #087443;
}


/* SECTION TITLE */

.section-title {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    margin: 18px 20px 10px;
    color: #17305f;
    font-size: 14px;
    font-weight: 800;
}


/* EDIT BUTTON */

.edit-button {
    border: 0;
    background: #16a34a;
    color: #fff;
    border-radius: 8px;
    padding: 8px 15px;
    font-size: 12px;
    font-weight: 750;
    cursor: pointer;
}

.edit-button:hover {
    opacity: .9;
}


/* INFORMATION GRID */

.info-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
    padding: 0 20px;
}

.info-box {
    background: #f8fafc;
    border: 1px solid #edf0f3;
    border-radius: 9px;
    padding: 11px;
}

.info-label {
    color: #8893a4;
    font-size: 9px;
    text-transform: uppercase;
    font-weight: 750;
}

.info-value {
    margin-top: 5px;
    color: #263246;
    font-size: 12px;
    font-weight: 650;
    word-break: break-word;
}


/* CUSTOMER EDIT FORM */

.customer-edit {
    display: none;
    margin: 15px 20px 20px;
    padding: 16px;
    border: 1px solid #d8e5dc;
    background: #fbfefc;
    border-radius: 10px;
}

.customer-edit.open {
    display: block;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 11px;
}

.field label {
    display: block;
    margin-bottom: 5px;
    color: #667085;
    font-size: 10px;
    font-weight: 750;
}

.field input {
    width: 100%;
    height: 38px;
    box-sizing: border-box;
    border: 1px solid #d6dce4;
    border-radius: 7px;
    padding: 0 9px;
    background: #fff;
}

.field input:focus {
    outline: none;
    border-color: #16a34a;
}


/* REQUIREMENT */

.requirement-box {
    margin: 0 20px;
    padding: 13px;
    background: #f0fdf4;
    border: 1px solid #ccefdc;
    border-radius: 10px;
    color: #087443;
    font-size: 12px;
}


/* ELIGIBILITY EDIT */

.eligibility-edit {
    display: none;
    margin: 15px 20px 20px;
    padding: 16px;
    background: #fbfefc;
    border: 1px solid #d8e5dc;
    border-radius: 10px;
}

.eligibility-edit.open {
    display: block;
}


/* BUTTONS */

.form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 9px;
    margin-top: 15px;
}

.btn {
    border: 0;
    border-radius: 8px;
    padding: 9px 15px;
    font-size: 12px;
    font-weight: 750;
    cursor: pointer;
}

.btn-primary {
    background: #16a34a;
    color: #fff;
}

.btn-secondary {
    background: #eef2f6;
    color: #344054;
}


/* EMPTY */

.empty {
    background: #fff;
    border: 1px dashed #cbd5e1;
    border-radius: 14px;
    padding: 55px 20px;
    text-align: center;
    color: #667085;
}


/* RESPONSIVE */

@media (max-width: 1100px) {

    .info-grid,
    .form-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 700px) {

    .summary-grid,
    .info-grid,
    .form-grid {
        grid-template-columns: 1fr;
    }

    .customer-header {
        flex-wrap: wrap;
    }
}

</style>


<div class="eligibility-page">


    <!-- =========================================================
         HEADER
         ========================================================= -->

    <div class="page-header">

        <h1>
            Eligibility Check
        </h1>

        <p>
            Review customer information and verify eligibility.
        </p>

    </div>


    <?php if (isset($_GET['saved'])): ?>

        <div class="success-box">
            Eligibility information saved successfully.
        </div>

    <?php endif; ?>


    <?php if (isset($_GET['customer_updated'])): ?>

        <div class="success-box">
            Customer information updated successfully.
        </div>

    <?php endif; ?>


    <?php if ($loadError !== ''): ?>

        <div class="error-box">

            Unable to load customers:

            <?= eligibilityEsc($loadError) ?>

        </div>

    <?php endif; ?>


    <!-- =========================================================
         SUMMARY
         ========================================================= -->

    <div class="summary-grid">

        <div class="summary-card">

            <div class="summary-label">
                Total Assigned Customers
            </div>

            <div class="summary-value">
                <?= $totalCustomers ?>
            </div>

        </div>


        <div class="summary-card submitted">

            <div class="summary-label">
                Eligibility Submitted
            </div>

            <div class="summary-value">
                <?= $submittedCount ?>
            </div>

        </div>


        <div class="summary-card pending">

            <div class="summary-label">
                Eligibility Pending
            </div>

            <div class="summary-value">
                <?= $pendingCount ?>
            </div>

        </div>

    </div>


    <!-- =========================================================
         SEARCH
         ========================================================= -->

    <div class="search-box">

        <input
            type="search"
            id="customerSearch"
            placeholder="Search customer name, ID, mobile or product..."
        >

    </div>


    <!-- =========================================================
         CUSTOMERS
         ========================================================= -->

    <?php if (!$customers): ?>

        <div class="empty">

            <strong>
                No Customers Assigned
            </strong>

            <p>
                Customers assigned by Admin will appear here automatically.
            </p>

        </div>

    <?php else: ?>


        <div id="customerList">


        <?php foreach ($customers as $customer): ?>


            <?php

            $customerId =
                (int)$customer['customer_id'];

            $name =
                trim(
                    (string)(
                        $customer['name']
                        ?? ''
                    )
                );

            $customerCode =
                trim(
                    (string)(
                        $customer['customer_code']
                        ?? ''
                    )
                );

            $saved =
                $eligibility[
                    $customerId
                ] ?? null;

            $isSubmitted =
                $saved
                && (int)(
                    $saved[
                        'eligibility_checked'
                    ] ?? 0
                ) === 1;


            $age =
                $saved
                && $saved['age'] !== null

                ? $saved['age']

                : customerAge(
                    $customer['dob']
                    ?? null
                );


            $employmentType =
                $saved['employment_type']
                ?? (
                    $customer[
                        'employment_type'
                    ] ?? ''
                );


            $employerName =
                $saved['employer_name']
                ?? '';


            $monthlyIncome =
                $saved['monthly_income']
                ?? '';


            $existingEmi =
                $saved['existing_emi']
                ?? '';


            $totalExperience =
                $saved['total_experience']
                ?? '';


            $currentExperience =
                $saved['current_experience']
                ?? '';


            $loanAmount =
                $saved['loan_amount']
                ?? (
                    $customer[
                        'application_amount'
                    ] ?? ''
                );


            $loanTenure =
                $saved['loan_tenure']
                ?? (
                    $customer[
                        'application_tenure'
                    ] ?? ''
                );


            $creditScore =
                $saved['credit_score']
                ?? (
                    $customer[
                        'credit_score'
                    ] ?? ''
                );

            ?>


            <div
                class="customer-card"
                id="customer-<?= $customerId ?>"
                data-search="<?= eligibilityEsc(
                    strtolower(
                        $name
                        . ' '
                        . $customerCode
                        . ' '
                        . ($customer['mobile'] ?? '')
                        . ' '
                        . ($customer['product_name'] ?? '')
                    )
                ) ?>"
            >


                <!-- =================================================
                     CUSTOMER HEADER
                     ================================================= -->

                <div class="customer-header">

                    <div class="customer-avatar">

                        <?= eligibilityEsc(
                            strtoupper(
                                substr(
                                    $name ?: 'C',
                                    0,
                                    1
                                )
                            )
                        ) ?>

                    </div>


                    <div class="customer-title">

                        <h2>

                            <?= eligibilityEsc(
                                $name ?: 'Customer'
                            ) ?>

                        </h2>

                        <p>

                            Customer ID:

                            <?= eligibilityEsc(
                                $customerCode
                                ?: $customerId
                            ) ?>

                            &nbsp; • &nbsp;

                            <?= eligibilityEsc(
                                $customer['mobile']
                                ?? 'No mobile'
                            ) ?>

                        </p>

                    </div>


                    <span
                        class="status-badge
                        <?= $isSubmitted
                            ? 'status-done'
                            : 'status-pending'
                        ?>"
                    >

                        <?= $isSubmitted
                            ? 'Eligibility Submitted'
                            : 'Eligibility Pending'
                        ?>

                    </span>

                </div>


                <!-- =================================================
                     CUSTOMER INFORMATION
                     ================================================= -->

                <div class="section-title">

                    <span>
                        Customer Information
                    </span>


                    <!-- EDIT BUTTON IS HERE -->

                    <button
                        type="button"
                        class="edit-button"
                        onclick="toggleCustomerEdit(<?= $customerId ?>)"
                    >
                        Edit
                    </button>

                </div>


                <div class="info-grid">


                    <div class="info-box">

                        <div class="info-label">
                            Customer Name
                        </div>

                        <div class="info-value">

                            <?= eligibilityEsc(
                                $name ?: 'Not provided'
                            ) ?>

                        </div>

                    </div>


                    <div class="info-box">

                        <div class="info-label">
                            Customer ID
                        </div>

                        <div class="info-value">

                            <?= eligibilityEsc(
                                $customerCode
                                ?: $customerId
                            ) ?>

                        </div>

                    </div>


                    <div class="info-box">

                        <div class="info-label">
                            Mobile
                        </div>

                        <div class="info-value">

                            <?= eligibilityEsc(
                                $customer['mobile']
                                ?? 'Not provided'
                            ) ?>

                        </div>

                    </div>


                    <div class="info-box">

                        <div class="info-label">
                            Email
                        </div>

                        <div class="info-value">

                            <?= eligibilityEsc(
                                $customer['email']
                                ?: (
                                    $customer['user_email']
                                    ?? 'Not provided'
                                )
                            ) ?>

                        </div>

                    </div>


                    <div class="info-box">

                        <div class="info-label">
                            Age
                        </div>

                        <div class="info-value">

                            <?= $age !== null
                                ? eligibilityEsc(
                                    $age
                                ) . ' years'
                                : 'Not provided'
                            ?>

                        </div>

                    </div>


                    <div class="info-box">

                        <div class="info-label">
                            Occupation
                        </div>

                        <div class="info-value">

                            <?= eligibilityEsc(
                                displayValue(
                                    $customer['occupation']
                                    ?? ''
                                )
                            ) ?>

                        </div>

                    </div>


                    <div class="info-box">

                        <div class="info-label">
                            Employment Type
                        </div>

                        <div class="info-value">

                            <?= eligibilityEsc(
                                displayValue(
                                    $employmentType
                                )
                            ) ?>

                        </div>

                    </div>


                    <div class="info-box">

                        <div class="info-label">
                            Income Band
                        </div>

                        <div class="info-value">

                            <?= eligibilityEsc(
                                displayValue(
                                    $customer[
                                        'annual_income_band'
                                    ] ?? ''
                                )
                            ) ?>

                        </div>

                    </div>

                </div>


                <!-- =================================================
                     CUSTOMER EDIT FORM
                     ================================================= -->

                <div
                    class="customer-edit"
                    id="customer-edit-<?= $customerId ?>"
                >

                    <form
                        method="post"
                        autocomplete="off"
                    >

                        <?= csrf_field() ?>


                        <input
                            type="hidden"
                            name="action"
                            value="save_customer"
                        >


                        <input
                            type="hidden"
                            name="customer_id"
                            value="<?= $customerId ?>"
                        >


                        <div class="form-grid">


                            <div class="field">

                                <label>
                                    Customer Name
                                </label>

                                <input
                                    type="text"
                                    name="customer_name"
                                    value="<?= eligibilityEsc(
                                        $customer['name']
                                        ?? ''
                                    ) ?>"
                                    required
                                >

                            </div>


                            <div class="field">

                                <label>
                                    Mobile
                                </label>

                                <input
                                    type="text"
                                    name="customer_mobile"
                                    value="<?= eligibilityEsc(
                                        $customer['mobile']
                                        ?? ''
                                    ) ?>"
                                >

                            </div>


                            <div class="field">

                                <label>
                                    Email
                                </label>

                                <input
                                    type="email"
                                    name="customer_email"
                                    value="<?= eligibilityEsc(
                                        $customer['email']
                                        ?: (
                                            $customer['user_email']
                                            ?? ''
                                        )
                                    ) ?>"
                                >

                            </div>


                            <div class="field">

                                <label>
                                    Requirement
                                </label>

                                <input
                                    type="text"
                                    name="customer_requirement"
                                    value="<?= eligibilityEsc(
                                        $customer['requirement']
                                        ?? ''
                                    ) ?>"
                                >

                            </div>


                        </div>


                        <div class="form-actions">


                            <button
                                type="button"
                                class="btn btn-secondary"
                                onclick="cancelCustomerEdit(<?= $customerId ?>)"
                            >
                                Cancel
                            </button>


                            <button
                                type="submit"
                                class="btn btn-primary"
                            >
                                Save Customer Information
                            </button>


                        </div>

                    </form>

                </div>


                <!-- =================================================
                     REQUIREMENT
                     ================================================= -->

                <div class="section-title">
                    Requirement / Application
                </div>


                <div class="requirement-box">

                    <strong>
                        Requirement:
                    </strong>

                    <?= eligibilityEsc(
                        $customer['requirement']
                        ?: 'No requirement specified'
                    ) ?>


                    <div style="margin-top:7px;color:#667085;">

                        <strong>
                            Application:
                        </strong>

                        <?= eligibilityEsc(
                            $customer[
                                'application_number'
                            ] ?: 'Not created'
                        ) ?>


                        &nbsp; • &nbsp;


                        <strong>
                            Product:
                        </strong>

                        <?= eligibilityEsc(
                            $customer[
                                'product_name'
                            ] ?: 'Not provided'
                        ) ?>


                        &nbsp; • &nbsp;


                        <strong>
                            Status:
                        </strong>

                        <?= eligibilityEsc(
                            $customer[
                                'application_status'
                            ] ?: 'Not available'
                        ) ?>

                    </div>

                </div>


                <!-- =================================================
                     ELIGIBILITY INFORMATION
                     ================================================= -->

                <div class="section-title">

                    <span>
                        Eligibility Information
                    </span>

                    <button
                        type="button"
                        class="edit-button"
                        onclick="toggleEligibilityEdit(<?= $customerId ?>)"
                    >
                        Edit
                    </button>

                </div>


                <div class="info-grid">


                    <div class="info-box">

                        <div class="info-label">
                            Monthly Income
                        </div>

                        <div class="info-value">

                            <?= $monthlyIncome !== ''
                                ? '₹' .
                                  number_format(
                                      (float)$monthlyIncome,
                                      2
                                  )
                                : 'Not entered'
                            ?>

                        </div>

                    </div>


                    <div class="info-box">

                        <div class="info-label">
                            Existing EMI
                        </div>

                        <div class="info-value">

                            <?= $existingEmi !== ''
                                ? '₹' .
                                  number_format(
                                      (float)$existingEmi,
                                      2
                                  )
                                : 'Not entered'
                            ?>

                        </div>

                    </div>


                    <div class="info-box">

                        <div class="info-label">
                            Total Experience
                        </div>

                        <div class="info-value">

                            <?= $totalExperience !== ''
                                ? eligibilityEsc(
                                    $totalExperience
                                ) . ' years'
                                : 'Not entered'
                            ?>

                        </div>

                    </div>


                    <div class="info-box">

                        <div class="info-label">
                            Current Experience
                        </div>

                        <div class="info-value">

                            <?= $currentExperience !== ''
                                ? eligibilityEsc(
                                    $currentExperience
                                ) . ' years'
                                : 'Not entered'
                            ?>

                        </div>

                    </div>


                    <div class="info-box">

                        <div class="info-label">
                            Loan Amount
                        </div>

                        <div class="info-value">

                            <?= $loanAmount !== ''
                                ? '₹' .
                                  number_format(
                                      (float)$loanAmount,
                                      2
                                  )
                                : 'Not entered'
                            ?>

                        </div>

                    </div>


                    <div class="info-box">

                        <div class="info-label">
                            Loan Tenure
                        </div>

                        <div class="info-value">

                            <?= $loanTenure !== ''
                                ? eligibilityEsc(
                                    $loanTenure
                                ) . ' months'
                                : 'Not entered'
                            ?>

                        </div>

                    </div>


                    <div class="info-box">

                        <div class="info-label">
                            Credit Score
                        </div>

                        <div class="info-value">

                            <?= $creditScore !== ''
                                ? eligibilityEsc(
                                    $creditScore
                                )
                                : 'Not entered'
                            ?>

                        </div>

                    </div>


                    <div class="info-box">

                        <div class="info-label">
                            Eligibility Status
                        </div>

                        <div class="info-value">

                            <?php if ($isSubmitted): ?>

                                <?= (int)(
                                    $saved[
                                        'eligibility_passed'
                                    ] ?? 0
                                ) === 1
                                    ? 'Eligible'
                                    : 'Needs Review'
                                ?>

                            <?php else: ?>

                                Pending

                            <?php endif; ?>

                        </div>

                    </div>

                </div>


                <!-- =================================================
                     ELIGIBILITY EDIT FORM
                     ================================================= -->

                <div
                    class="eligibility-edit"
                    id="eligibility-edit-<?= $customerId ?>"
                >

                    <form
                        method="post"
                        autocomplete="off"
                    >

                        <?= csrf_field() ?>


                        <input
                            type="hidden"
                            name="action"
                            value="save_eligibility"
                        >


                        <input
                            type="hidden"
                            name="customer_id"
                            value="<?= $customerId ?>"
                        >


                        <div class="form-grid">


                            <div class="field">

                                <label>
                                    Age
                                </label>

                                <input
                                    type="number"
                                    name="age"
                                    min="0"
                                    max="120"
                                    value="<?= eligibilityEsc(
                                        $age ?? ''
                                    ) ?>"
                                >

                            </div>


                            <div class="field">

                                <label>
                                    Employment Type
                                </label>

                                <input
                                    type="text"
                                    name="employment_type"
                                    value="<?= eligibilityEsc(
                                        $employmentType
                                    ) ?>"
                                >

                            </div>


                            <div class="field">

                                <label>
                                    Employer Name
                                </label>

                                <input
                                    type="text"
                                    name="employer_name"
                                    value="<?= eligibilityEsc(
                                        $employerName
                                    ) ?>"
                                >

                            </div>


                            <div class="field">

                                <label>
                                    Monthly Income
                                </label>

                                <input
                                    type="number"
                                    name="monthly_income"
                                    min="0"
                                    step="0.01"
                                    value="<?= eligibilityEsc(
                                        $monthlyIncome
                                    ) ?>"
                                >

                            </div>


                            <div class="field">

                                <label>
                                    Existing EMI
                                </label>

                                <input
                                    type="number"
                                    name="existing_emi"
                                    min="0"
                                    step="0.01"
                                    value="<?= eligibilityEsc(
                                        $existingEmi
                                    ) ?>"
                                >

                            </div>


                            <div class="field">

                                <label>
                                    Total Experience
                                </label>

                                <input
                                    type="number"
                                    name="total_experience"
                                    min="0"
                                    step="0.1"
                                    value="<?= eligibilityEsc(
                                        $totalExperience
                                    ) ?>"
                                >

                            </div>


                            <div class="field">

                                <label>
                                    Current Experience
                                </label>

                                <input
                                    type="number"
                                    name="current_experience"
                                    min="0"
                                    step="0.1"
                                    value="<?= eligibilityEsc(
                                        $currentExperience
                                    ) ?>"
                                >

                            </div>


                            <div class="field">

                                <label>
                                    Credit Score
                                </label>

                                <input
                                    type="number"
                                    name="credit_score"
                                    min="0"
                                    max="1000"
                                    value="<?= eligibilityEsc(
                                        $creditScore
                                    ) ?>"
                                >

                            </div>


                            <div class="field">

                                <label>
                                    Loan Amount
                                </label>

                                <input
                                    type="number"
                                    name="loan_amount"
                                    min="0"
                                    step="0.01"
                                    value="<?= eligibilityEsc(
                                        $loanAmount
                                    ) ?>"
                                >

                            </div>


                            <div class="field">

                                <label>
                                    Loan Tenure (Months)
                                </label>

                                <input
                                    type="number"
                                    name="loan_tenure"
                                    min="0"
                                    value="<?= eligibilityEsc(
                                        $loanTenure
                                    ) ?>"
                                >

                            </div>


                        </div>


                        <div class="form-actions">


                            <button
                                type="button"
                                class="btn btn-secondary"
                                onclick="cancelEligibilityEdit(<?= $customerId ?>)"
                            >
                                Cancel
                            </button>


                            <button
                                type="submit"
                                class="btn btn-primary"
                            >
                                Save &amp; Verify Eligibility
                            </button>


                        </div>

                    </form>

                </div>


            </div>


        <?php endforeach; ?>


        </div>

    <?php endif; ?>


</div>


<script>

/* =========================================================
   SEARCH
   ========================================================= */

const customerSearch =
    document.getElementById(
        'customerSearch'
    );

if (customerSearch) {

    customerSearch.addEventListener(
        'input',
        function () {

            const query =
                this.value
                    .trim()
                    .toLowerCase();

            document
                .querySelectorAll(
                    '#customerList .customer-card'
                )
                .forEach(
                    function (card) {

                        const text =
                            card.dataset.search
                            || '';

                        card.style.display =
                            (
                                query === ''
                                || text.includes(query)
                            )
                            ? ''
                            : 'none';
                    }
                );
        }
    );
}


/* =========================================================
   CUSTOMER INFORMATION EDIT
   ========================================================= */

function toggleCustomerEdit(customerId)
{
    const form =
        document.getElementById(
            'customer-edit-' + customerId
        );

    if (!form) {
        return;
    }

    form.classList.toggle('open');


    if (form.classList.contains('open')) {

        const firstInput =
            form.querySelector(
                'input:not([type="hidden"])'
            );

        if (firstInput) {
            firstInput.focus();
        }
    }
}


function cancelCustomerEdit(customerId)
{
    const form =
        document.getElementById(
            'customer-edit-' + customerId
        );

    if (!form) {
        return;
    }

    form.classList.remove('open');
}


/* =========================================================
   ELIGIBILITY EDIT
   ========================================================= */

function toggleEligibilityEdit(customerId)
{
    const form =
        document.getElementById(
            'eligibility-edit-' + customerId
        );

    if (!form) {
        return;
    }

    form.classList.toggle('open');


    if (form.classList.contains('open')) {

        const firstInput =
            form.querySelector(
                'input:not([type="hidden"])'
            );

        if (firstInput) {
            firstInput.focus();
        }
    }
}


function cancelEligibilityEdit(customerId)
{
    const form =
        document.getElementById(
            'eligibility-edit-' + customerId
        );

    if (!form) {
        return;
    }

    form.classList.remove('open');
}

</script>


<?php

agent_shell_close();

?>