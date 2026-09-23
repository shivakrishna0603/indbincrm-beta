<?php


require_once __DIR__ . '/../db.php';


// =====================================================
// ADMIN LOGIN CHECK
// =====================================================

if (!isset($_SESSION['admin_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}


// =====================================================
// PROCESS ADMIN ACTION
// =====================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $type    = $_POST['type'] ?? '';
    $user_id = (int)($_POST['user_id'] ?? 0);
    $action  = $_POST['action'] ?? '';

    if ($user_id <= 0) {
        die("Invalid user ID.");
    }


    // =================================================
    // KYC APPROVE / REJECT
    // =================================================

    if ($type === 'kyc') {

        if (!in_array($action, ['approve', 'reject'], true)) {
            die("Invalid KYC action.");
        }

        $status = ($action === 'approve')
            ? 'approved'
            : 'rejected';

        $stmt = $pdo->prepare("
            UPDATE users
            SET kyc_status = ?
            WHERE id = ?
        ");

        $stmt->execute([
            $status,
            $user_id
        ]);
    }


    // =================================================
    // BACKGROUND APPROVE / REJECT
    // =================================================

    if ($type === 'background') {

        if (!in_array($action, ['approve', 'reject'], true)) {
            die("Invalid background action.");
        }

        try {

            $pdo->beginTransaction();

            $backgroundStatus = ($action === 'approve')
                ? 'approved'
                : 'rejected';


            // -----------------------------------------
            // FIND LATEST BACKGROUND RECORD
            // -----------------------------------------

            $stmt = $pdo->prepare("
                SELECT id
                FROM background_verifications
                WHERE user_id = ?
                ORDER BY id DESC
                LIMIT 1
            ");

            $stmt->execute([$user_id]);

            $verification_id = (int)$stmt->fetchColumn();


            // -----------------------------------------
            // UPDATE BACKGROUND VERIFICATION
            // -----------------------------------------

            if ($verification_id > 0) {

                $update = $pdo->prepare("
                    UPDATE background_verifications
                    SET status = ?
                    WHERE id = ?
                ");

                $update->execute([
                    $backgroundStatus,
                    $verification_id
                ]);
            }


            // -----------------------------------------
            // UPDATE USERS BACKGROUND STATUS
            // -----------------------------------------

            $userBackgroundStatus = ($action === 'approve')
                ? 'verified'
                : 'rejected';

            $updateUser = $pdo->prepare("
                UPDATE users
                SET background_status = ?
                WHERE id = ?
            ");

            $updateUser->execute([
                $userBackgroundStatus,
                $user_id
            ]);


            $pdo->commit();

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            die(
                "Background update failed: "
                . htmlspecialchars($e->getMessage())
            );
        }
    }


    // =================================================
    // RETURN TO ADMIN REVIEW
    // =================================================

    header("Location: admin_review.php");
    exit;
}


// =====================================================
// GET AGENT RECORDS
// =====================================================

$stmt = $pdo->query("
    SELECT
        u.id,
        u.email,
        u.kyc_status,
        u.background_status,

        b.status AS background_verification_status,
        b.full_name,
        b.area,
        b.reference_name,
        b.reference_mobile,
        b.reference_relationship,
        b.reference_check,
        b.field_verification,
        b.document_type,
        b.role_assignment,
        b.agent_address

    FROM users u

    LEFT JOIN background_verifications b
        ON b.id = (
            SELECT MAX(b2.id)
            FROM background_verifications b2
            WHERE b2.user_id = u.id
        )

    WHERE u.role = 'agent'

    ORDER BY u.id DESC
");

$agents = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>Admin Review</title>


<style>

/* =====================================================
   RESET
===================================================== */

* {
    box-sizing: border-box;
}


/* =====================================================
   BODY
===================================================== */

body {

    margin: 0;

    padding: 30px;

    background: #f1f5f9;

    font-family: Arial, sans-serif;

    color: #172033;
}


/* =====================================================
   CONTAINER
===================================================== */

.container {

    max-width: 1200px;

    margin: auto;
}


/* =====================================================
   BACK BUTTON
===================================================== */

.back-button {

    display: inline-block;

    padding: 10px 18px;

    margin-bottom: 20px;

    background: #ffffff;

    color: #475569;

    border: 1px solid #cbd5e1;

    border-radius: 7px;

    text-decoration: none;

    font-weight: 700;

    font-size: 13px;

    transition: 0.2s;
}


.back-button:hover {

    background: #f8fafc;

    border-color: #94a3b8;
}


/* =====================================================
   PAGE TITLE
===================================================== */

h1 {

    margin: 0 0 6px 0;

    font-size: 28px;
}


.subtitle {

    color: #64748b;

    margin: 0 0 25px 0;
}


/* =====================================================
   CARD
===================================================== */

.card {

    background: #ffffff;

    border-radius: 14px;

    padding: 22px;

    margin-bottom: 25px;

    box-shadow:
        0 5px 20px rgba(0, 0, 0, 0.07);
}


/* =====================================================
   AGENT HEADER
===================================================== */

.agent-header {

    display: flex;

    justify-content: space-between;

    align-items: center;

    padding-bottom: 15px;

    margin-bottom: 20px;

    border-bottom: 1px solid #e2e8f0;
}


.agent-name {

    font-size: 20px;

    font-weight: 700;
}


.agent-email {

    color: #64748b;

    margin-top: 5px;
}


/* =====================================================
   REVIEW SECTION
===================================================== */

.review-section {

    border: 1px solid #e2e8f0;

    border-radius: 12px;

    padding: 18px;

    margin-top: 18px;
}


.kyc-section {

    border-left: 5px solid #2563eb;
}


.background-section {

    border-left: 5px solid #7c3aed;
}


/* =====================================================
   SECTION TITLE
===================================================== */

.section-title {

    font-size: 18px;

    font-weight: 700;

    margin-bottom: 15px;
}


/* =====================================================
   GRID
===================================================== */

.grid {

    display: grid;

    grid-template-columns: 1fr 1fr;

    gap: 12px;
}


/* =====================================================
   DETAIL
===================================================== */

.detail {

    background: #f8fafc;

    padding: 12px;

    border-radius: 8px;
}


.detail strong {

    display: block;

    font-size: 12px;

    color: #64748b;

    margin-bottom: 5px;
}


.detail span {

    font-size: 14px;
}


/* =====================================================
   STATUS
===================================================== */

.status {

    display: inline-block;

    padding: 6px 14px;

    border-radius: 20px;

    font-size: 12px;

    font-weight: 700;
}


.pending {

    background: #fef3c7;

    color: #92400e;
}


.approved,
.verified {

    background: #dcfce7;

    color: #166534;
}


.rejected {

    background: #fee2e2;

    color: #991b1b;
}


/* =====================================================
   ACTIONS
===================================================== */

.actions {

    display: flex;

    gap: 10px;

    margin-top: 18px;
}


/* =====================================================
   BUTTONS
===================================================== */

button {

    border: none;

    padding: 10px 18px;

    border-radius: 7px;

    color: #ffffff;

    font-weight: 700;

    cursor: pointer;

    font-size: 13px;
}


.approve-btn {

    background: #16a34a;
}


.approve-btn:hover {

    background: #15803d;
}


.reject-btn {

    background: #dc2626;
}


.reject-btn:hover {

    background: #b91c1c;
}


/* =====================================================
   EMPTY
===================================================== */

.empty {

    text-align: center;

    color: #64748b;

    padding: 40px;
}


/* =====================================================
   MOBILE
===================================================== */

@media (max-width: 700px) {

    body {
        padding: 15px;
    }

    .grid {
        grid-template-columns: 1fr;
    }

    .agent-header {
        display: block;
    }

    .actions {
        flex-direction: column;
    }

    .back-button {
        width: 100%;
        text-align: center;
    }

}

</style>

</head>


<body>


<div class="container">


    <!-- =================================================
         BACK TO ADMIN DASHBOARD
    ================================================== -->

    <a
        href="index.php"
        class="back-button"
    >
        ← Back to Admin Dashboard
    </a>


    <!-- =================================================
         PAGE TITLE
    ================================================== -->

    <h1>
        Admin Review
    </h1>

    <p class="subtitle">
        KYC Verification and Background Verification
    </p>


    <!-- =================================================
         NO AGENTS
    ================================================== -->

    <?php if (empty($agents)): ?>

        <div class="card empty">

            No agent records found.

        </div>

    <?php endif; ?>


    <!-- =================================================
         AGENT LIST
    ================================================== -->

    <?php foreach ($agents as $agent): ?>


        <div class="card">


            <!-- =========================================
                 AGENT HEADER
            ========================================== -->

            <div class="agent-header">

                <div>

                    <div class="agent-name">

                        <?= htmlspecialchars(
                            $agent['full_name']
                            ?: 'Agent'
                        ) ?>

                    </div>


                    <div class="agent-email">

                        <?= htmlspecialchars(
                            $agent['email'] ?? ''
                        ) ?>

                    </div>

                </div>


                <div>

                    User ID:

                    <strong>
                        <?= (int)$agent['id'] ?>
                    </strong>

                </div>

            </div>


            <!-- =========================================
                 KYC VERIFICATION
            ========================================== -->

            <div class="review-section kyc-section">


                <div class="section-title">
                    KYC Verification
                </div>


                <div class="grid">


                    <!-- KYC STATUS -->

                    <div class="detail">

                        <strong>
                            KYC Status
                        </strong>


                        <?php

                        $kycStatus = strtolower(
                            trim(
                                $agent['kyc_status']
                                ?? 'pending'
                            )
                        );

                        ?>


                        <span
                            class="status <?= htmlspecialchars(
                                $kycStatus
                            ) ?>"
                        >

                            <?= ucfirst(
                                htmlspecialchars(
                                    $kycStatus
                                )
                            ) ?>

                        </span>

                    </div>


                    <!-- EMAIL -->

                    <div class="detail">

                        <strong>
                            Agent Email
                        </strong>

                        <span>

                            <?= htmlspecialchars(
                                $agent['email'] ?? ''
                            ) ?>

                        </span>

                    </div>


                </div>


                <!-- KYC ACTIONS -->

                <?php if ($kycStatus === 'pending'): ?>

                    <form
                        method="POST"
                        class="actions"
                    >

                        <input
                            type="hidden"
                            name="type"
                            value="kyc"
                        >

                        <input
                            type="hidden"
                            name="user_id"
                            value="<?= (int)$agent['id'] ?>"
                        >


                        <button
                            type="submit"
                            name="action"
                            value="approve"
                            class="approve-btn"
                        >
                            Approve KYC
                        </button>


                        <button
                            type="submit"
                            name="action"
                            value="reject"
                            class="reject-btn"
                        >
                            Reject KYC
                        </button>

                    </form>

                <?php endif; ?>


            </div>


            <!-- =========================================
                 BACKGROUND VERIFICATION
            ========================================== -->

            <div class="review-section background-section">


                <div class="section-title">

                    Background Verification

                </div>


                <?php if (
                    empty(
                        $agent[
                            'background_verification_status'
                        ]
                    )
                ): ?>


                    <p>
                        No background verification
                        submitted yet.
                    </p>


                <?php else: ?>


                    <div class="grid">


                        <!-- BACKGROUND STATUS -->

                        <div class="detail">

                            <strong>
                                Background Status
                            </strong>


                            <?php

                            $backgroundStatus = strtolower(
                                trim(
                                    $agent[
                                        'background_verification_status'
                                    ]
                                    ?: (
                                        $agent[
                                            'background_status'
                                        ] ?? 'pending'
                                    )
                                )
                            );

                            ?>


                            <span
                                class="status <?= htmlspecialchars(
                                    $backgroundStatus
                                ) ?>"
                            >

                                <?= ucfirst(
                                    htmlspecialchars(
                                        $backgroundStatus
                                    )
                                ) ?>

                            </span>

                        </div>


                        <!-- USER BACKGROUND STATUS -->

                        <div class="detail">

                            <strong>
                                Agent Background Status
                            </strong>


                            <?php

                            $agentBackgroundStatus = strtolower(
                                trim(
                                    $agent[
                                        'background_status'
                                    ] ?? 'pending'
                                )
                            );

                            ?>


                            <span
                                class="status <?= htmlspecialchars(
                                    $agentBackgroundStatus
                                ) ?>"
                            >

                                <?= ucfirst(
                                    htmlspecialchars(
                                        $agentBackgroundStatus
                                    )
                                ) ?>

                            </span>

                        </div>


                        <!-- REFERENCE NAME -->

                        <div class="detail">

                            <strong>
                                Reference Name
                            </strong>

                            <span>

                                <?= htmlspecialchars(
                                    $agent[
                                        'reference_name'
                                    ] ?? ''
                                ) ?>

                            </span>

                        </div>


                        <!-- REFERENCE MOBILE -->

                        <div class="detail">

                            <strong>
                                Reference Mobile
                            </strong>

                            <span>

                                <?= htmlspecialchars(
                                    $agent[
                                        'reference_mobile'
                                    ] ?? ''
                                ) ?>

                            </span>

                        </div>


                        <!-- RELATIONSHIP -->

                        <div class="detail">

                            <strong>
                                Reference Relationship
                            </strong>

                            <span>

                                <?= htmlspecialchars(
                                    $agent[
                                        'reference_relationship'
                                    ] ?? ''
                                ) ?>

                            </span>

                        </div>


                        <!-- REFERENCE CHECK -->

                        <div class="detail">

                            <strong>
                                Reference Check
                            </strong>

                            <span>

                                <?= htmlspecialchars(
                                    $agent[
                                        'reference_check'
                                    ] ?? ''
                                ) ?>

                            </span>

                        </div>


                        <!-- FIELD VERIFICATION -->

                        <div class="detail">

                            <strong>
                                Field Verification
                            </strong>

                            <span>

                                <?= htmlspecialchars(
                                    $agent[
                                        'field_verification'
                                    ] ?? ''
                                ) ?>

                            </span>

                        </div>


                        <!-- DOCUMENT TYPE -->

                        <div class="detail">

                            <strong>
                                Document Type
                            </strong>

                            <span>

                                <?= htmlspecialchars(
                                    $agent[
                                        'document_type'
                                    ] ?? ''
                                ) ?>

                            </span>

                        </div>


                        <!-- ROLE -->

                        <div class="detail">

                            <strong>
                                Role Assignment
                            </strong>

                            <span>

                                <?= htmlspecialchars(
                                    $agent[
                                        'role_assignment'
                                    ] ?? ''
                                ) ?>

                            </span>

                        </div>


                        <!-- AREA -->

                        <div class="detail">

                            <strong>
                                Area
                            </strong>

                            <span>

                                <?= htmlspecialchars(
                                    $agent['area'] ?? ''
                                ) ?>

                            </span>

                        </div>


                        <!-- ADDRESS -->

                        <div
                            class="detail"
                            style="grid-column: 1 / -1;"
                        >

                            <strong>
                                Agent Address
                            </strong>

                            <span>

                                <?= nl2br(
                                    htmlspecialchars(
                                        $agent[
                                            'agent_address'
                                        ] ?? ''
                                    )
                                ) ?>

                            </span>

                        </div>


                    </div>


                    <?php

                    $backgroundNeedsReview =
                        (
                            $backgroundStatus === 'pending'
                            ||
                            $agentBackgroundStatus === 'pending'
                        );

                    ?>


                    <!-- BACKGROUND ACTIONS -->

                    <?php if ($backgroundNeedsReview): ?>


                        <form
                            method="POST"
                            class="actions"
                        >

                            <input
                                type="hidden"
                                name="type"
                                value="background"
                            >


                            <input
                                type="hidden"
                                name="user_id"
                                value="<?= (int)$agent['id'] ?>"
                            >


                            <button
                                type="submit"
                                name="action"
                                value="approve"
                                class="approve-btn"
                            >
                                Approve Background
                            </button>


                            <button
                                type="submit"
                                name="action"
                                value="reject"
                                class="reject-btn"
                            >
                                Reject Background
                            </button>

                        </form>


                    <?php endif; ?>


                <?php endif; ?>


            </div>


        </div>


    <?php endforeach; ?>


</div>


</body>

</html>