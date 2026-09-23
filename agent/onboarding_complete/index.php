<?php

require_once __DIR__ . '/../db.php';


/* =========================================================
   LOGIN CHECK
========================================================= */

if (!isset($_SESSION['user_id'])) {

    header('Location: ' . BASE_URL . '/index.php');

    exit;
}

$user_id = (int) $_SESSION['user_id'];


/* =========================================================
   USER DETAILS
========================================================= */

$stmt = $pdo->prepare("
    SELECT
        id,
        full_name,
        email,
        kyc_status,
        assigned_role
    FROM users
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$user_id]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {

    session_destroy();

    header('Location: ' . BASE_URL . '/index.php');

    exit;
}


/* =========================================================
   eKYC STATUS
========================================================= */

$kyc_status = strtolower(
    trim((string)($user['kyc_status'] ?? ''))
);

$kyc_approved = ($kyc_status === 'approved');


/* =========================================================
   ROLE ASSIGNMENT
========================================================= */

/*
   Role is assigned by ADMIN.

   Agent does NOT select the role from
   Background & Verification.

   The admin-assigned role is stored in:
   users.assigned_role
*/

$assigned_role = trim(
    (string)($user['assigned_role'] ?? '')
);

$role_assigned = ($assigned_role !== '');


/* =========================================================
   BACKGROUND & VERIFICATION
========================================================= */

$stmt = $pdo->prepare("
    SELECT
        background_status,
        admin_remarks,
        role
    FROM agent_verification
    WHERE agent_id = ?
    ORDER BY id DESC
    LIMIT 1
");

$stmt->execute([$user_id]);

$background = $stmt->fetch(PDO::FETCH_ASSOC);

$background_status = strtolower(
    trim((string)($background['background_status'] ?? ''))
);

$background_approved =
    $background_status === 'verified' ||
    $background_status === 'approved';

$admin_remarks = trim(
    (string)($background['admin_remarks'] ?? '')
);


/* =========================================================
   FALLBACK ROLE
========================================================= */

/*
   If the role is available in agent_verification but
   users.assigned_role has not been populated yet,
   use it as a fallback for displaying the role.
*/

if (
    $assigned_role === '' &&
    !empty($background['role'])
) {

    $assigned_role = trim(
        (string)$background['role']
    );

}

$role_assigned = ($assigned_role !== '');


/* =========================================================
   AGENT ID
========================================================= */

$stmt = $pdo->prepare("
    SELECT
        agent_id,
        referral_code
    FROM agent_hierarchy
    WHERE user_id = ?
    LIMIT 1
");

$stmt->execute([$user_id]);

$hierarchy = $stmt->fetch(PDO::FETCH_ASSOC);

$agent_id = $hierarchy['agent_id'] ?? '';

$agent_id_created = !empty($agent_id);


/* =========================================================
   TRAINING
========================================================= */

$stmt = $pdo->prepare("
    SELECT
        product_training,
        compliance_training,
        certification_test
    FROM agent_training
    WHERE user_id = ?
    LIMIT 1
");

$stmt->execute([$user_id]);

$training = $stmt->fetch(PDO::FETCH_ASSOC);

$training_completed =
    $training &&
    ($training['product_training'] ?? '') === 'completed' &&
    ($training['compliance_training'] ?? '') === 'completed' &&
    ($training['certification_test'] ?? '') === 'completed';


/* =========================================================
   WALLET
========================================================= */

$stmt = $pdo->prepare("
    SELECT
        account_holder,
        account_number,
        bank_name,
        ifsc_code
    FROM agent_commission_payout
    WHERE user_id = ?
    LIMIT 1
");

$stmt->execute([$user_id]);

$wallet = $stmt->fetch(PDO::FETCH_ASSOC);

$wallet_completed =
    $wallet &&
    !empty($wallet['account_holder']) &&
    !empty($wallet['account_number']) &&
    !empty($wallet['bank_name']) &&
    !empty($wallet['ifsc_code']);


/* =========================================================
   TOOLS & MATERIALS
========================================================= */

/*
   Use the same source of truth as the Agent sidebar:
   users.app_walkthrough_completed_at.

   If this field contains a completion timestamp, the
   Tools & Materials step is completed. Otherwise it is
   pending/in progress. No hardcoded completion is used.
*/

$tools_completed = false;

try {
    $stmt = $pdo->prepare("
        SELECT app_walkthrough_completed_at
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([$user_id]);

    $tools_completed = (bool) $stmt->fetchColumn();
} catch (Exception $e) {
    $tools_completed = false;
}


/* =========================================================
   AGENT ONBOARDING COMPLETION
========================================================= */

$agent_steps_completed =
    $training_completed &&
    $agent_id_created &&
    $wallet_completed &&
    $tools_completed;


/* =========================================================
   FINAL ACTIVATION
========================================================= */

/*
   Agent becomes active only when:

   1. eKYC approved
   2. Background verification approved
   3. Training completed
   4. Agent ID created
   5. Wallet completed
   6. Tools completed
   7. Admin assigned a role
*/

$can_activate =
    $kyc_approved &&
    $background_approved &&
    $agent_steps_completed &&
    $role_assigned;


/* =========================================================
   PAGE MESSAGE
========================================================= */

if ($can_activate) {

    $page_title = "You're All Set!";

    $page_description =
        "Your account is now active. You can access your Agent Dashboard and start using INDBIN services.";

} else {

    $page_title = "Almost Done!";

    $page_description =
        "Your agent onboarding is almost complete. Your account will become active after the required verifications and role assignment are completed.";

}


/* =========================================================
   PORTAL SHELL
========================================================= */

require_once __DIR__ . '/../portal/shell.php';

render_shell_open(
    $pdo,
    $user_id,
    'complete',
    $can_activate ? 'Active Account' : 'Onboarding Progress'
);

?>


<style>

/* =========================================================
   COMPLETE PAGE
========================================================= */

.complete-page {
    width: 100%;
    display: flex;
    justify-content: center;
    padding: 10px 20px 40px;
}


.complete-card {
    width: 100%;
    max-width: 760px;
    margin: 0 auto;

    background: #ffffff;

    border: 1px solid #dfe8e2;

    border-radius: 14px;

    box-shadow:
        0 5px 18px rgba(20, 60, 35, .05);

    padding: 28px;
}


/* =========================================================
   HEADER
========================================================= */

.complete-header {
    width: 100%;

    display: flex !important;

    flex-direction: column !important;

    align-items: center !important;

    justify-content: center !important;

    text-align: center !important;

    gap: 10px;

    padding: 8px 10px 20px;
}


/* =========================================================
   ICON
========================================================= */

.complete-icon {
    width: 56px;

    height: 56px;

    display: flex;

    align-items: center;

    justify-content: center;

    margin: 0 auto;

    border-radius: 50%;

    background: #dff8e8;

    color: #159447;

    font-size: 30px;

    font-weight: 700;
}


/* =========================================================
   TITLE
========================================================= */

.complete-title {
    width: 100%;

    display: block !important;

    margin: 0 !important;

    text-align: center !important;

    color: #123b28;

    font-size: 28px;

    font-weight: 800;

    line-height: 1.2;
}


/* =========================================================
   DESCRIPTION
========================================================= */

.complete-description {
    width: 100%;

    max-width: 600px;

    margin: 0 auto !important;

    text-align: center !important;

    color: #68776f;

    font-size: 13px;

    line-height: 1.6;
}


/* =========================================================
   SUMMARY
========================================================= */

.summary {
    width: 100%;
}


.summary-title {
    margin-bottom: 18px;

    font-size: 18px;

    font-weight: 700;

    color: #123b28;
}


.summary-row {
    display: flex;

    justify-content: space-between;

    align-items: center;

    gap: 15px;

    padding: 16px 0;

    border-bottom: 1px dashed #dfe8e2;
}


.summary-label {
    color: #60756a;

    font-size: 13px;
}


.summary-value {
    font-size: 13px;

    font-weight: 600;

    text-align: right;
}


/* =========================================================
   AGENT ID
========================================================= */

.agent-id {
    background: #dff8e8;

    color: #123b28;

    padding: 10px 16px;

    border-radius: 14px;

    font-weight: 700;
}


/* =========================================================
   ROLE
========================================================= */

.role-value {
    background: #e8f7ee;

    color: #15803d;

    padding: 8px 14px;

    border-radius: 20px;

    font-weight: 700;

    display: inline-block;
}


/* =========================================================
   STATUS BADGES
========================================================= */

.status-badge {
    display: inline-block;

    padding: 6px 12px;

    border-radius: 20px;

    font-size: 12px;

    font-weight: 700;
}


.status-badge.approved {
    background: #dcfce7;

    color: #15803d;
}


.status-badge.pending {
    background: #fef3c7;

    color: #b45309;
}


.status-badge.rejected {
    background: #fee2e2;

    color: #b91c1c;
}


/* =========================================================
   ACCESS SECTION
========================================================= */

.access-section {
    width: 100%;

    margin-top: 28px;
}


.access-title {
    margin-bottom: 14px;

    color: #123b28;

    font-size: 18px;
}


.access-item {
    padding: 14px;

    margin-bottom: 10px;

    border: 1px solid #e2e8e5;

    border-radius: 10px;

    background: #fafcfb;

    color: #52675b;

    text-align: center;

    font-size: 13px;
}


.access-item.active {
    background: #e8f7ee;

    border-color: #c9efd7;

    color: #15803d;

    font-weight: 600;
}


/* =========================================================
   APPROVAL WARNING
========================================================= */

.approval-warning {
    margin-top: 24px;

    padding: 18px;

    border-radius: 10px;

    background: #fff8e6;

    border: 1px solid #f3df9d;
}


.approval-warning h3 {
    margin: 0 0 12px;

    color: #8a5a00;

    font-size: 15px;
}


.approval-warning p {
    margin: 7px 0;

    color: #795b19;

    font-size: 13px;

    line-height: 1.5;
}


.admin-remark {
    margin-top: 14px;

    padding: 12px;

    background: #ffffff;

    border-radius: 8px;

    color: #6b7280;

    font-size: 12px;
}


/* =========================================================
   ACTIVE ACCOUNT MESSAGE
========================================================= */

.active-message {
    margin-top: 24px;

    padding: 18px;

    background: #e8f7ee;

    border: 1px solid #c9efd7;

    border-radius: 10px;

    color: #166534;

    text-align: center;
}


.active-message strong {
    display: block;

    margin-bottom: 5px;

    font-size: 15px;
}


/* =========================================================
   BUTTON
========================================================= */

.activate-button {
    display: block;

    width: 100%;

    margin-top: 20px;

    padding: 14px;

    border-radius: 10px;

    background: #16a34a;

    color: #ffffff;

    text-decoration: none;

    text-align: center;

    font-size: 14px;

    font-weight: 700;
}


.activate-button:hover {
    background: #15803d;
}


.activate-button.disabled {
    background: #cbd5e1;

    color: #64748b;

    cursor: not-allowed;

    pointer-events: none;
}


/* =========================================================
   MOBILE
========================================================= */

@media (max-width: 700px) {

    .complete-page {
        padding: 10px 12px 30px;
    }

    .complete-card {
        padding: 20px;
    }

    .complete-title {
        font-size: 24px;
    }

    .complete-description {
        font-size: 12px;
    }

    .summary-row {
        align-items: flex-start;
    }

}

</style>


<div class="complete-page">


    <div class="complete-card">


        <!-- =================================================
             HEADER
        ================================================== -->

        <div class="complete-header">


            <div class="complete-icon">

                <?= $can_activate ? '✓' : '✓' ?>

            </div>


            <h1 class="complete-title">

                <?= htmlspecialchars($page_title) ?>

            </h1>


            <p class="complete-description">

                <?= htmlspecialchars($page_description) ?>

            </p>


        </div>


        <!-- =================================================
             AGENT SUMMARY
        ================================================== -->

        <div class="summary">


            <div class="summary-title">

                Agent Summary

            </div>


            <!-- =================================================
                 AGENT ID
            ================================================== -->

            <div class="summary-row">

                <span class="summary-label">

                    Agent ID

                </span>


                <span class="summary-value agent-id">

                    <?= htmlspecialchars(
                        $agent_id ?: 'Not Available'
                    ) ?>

                </span>

            </div>


            <!-- =================================================
                 eKYC
            ================================================== -->

            <div class="summary-row">

                <span class="summary-label">

                    eKYC Verification

                </span>


                <span class="summary-value">

                    <?php if ($kyc_approved): ?>

                        <span class="status-badge approved">

                            Approved

                        </span>

                    <?php elseif ($kyc_status === 'rejected'): ?>

                        <span class="status-badge rejected">

                            Rejected

                        </span>

                    <?php else: ?>

                        <span class="status-badge pending">

                            Pending

                        </span>

                    <?php endif; ?>

                </span>

            </div>


            <!-- =================================================
                 BACKGROUND
            ================================================== -->

            <div class="summary-row">

                <span class="summary-label">

                    Background &amp; Verification

                </span>


                <span class="summary-value">

                    <?php if ($background_approved): ?>

                        <span class="status-badge approved">

                            Approved

                        </span>

                    <?php elseif ($background_status === 'rejected'): ?>

                        <span class="status-badge rejected">

                            Rejected

                        </span>

                    <?php else: ?>

                        <span class="status-badge pending">

                            Pending

                        </span>

                    <?php endif; ?>

                </span>

            </div>


            <!-- =================================================
                 ROLE ASSIGNMENT
            ================================================== -->

            <div class="summary-row">

                <span class="summary-label">

                    Role Assignment

                </span>


                <span class="summary-value">

                    <?php if ($role_assigned): ?>

                        <span class="role-value">

                            <?= htmlspecialchars(
                                $assigned_role
                            ) ?>

                        </span>

                    <?php else: ?>

                        <span class="status-badge pending">

                            Waiting for Admin

                        </span>

                    <?php endif; ?>

                </span>

            </div>


        </div>


        <!-- =================================================
             AGENT ACCESS
        ================================================== -->

        <div class="access-section">


            <h2 class="access-title">

                Agent Access

            </h2>


            <div class="access-item <?= $agent_id_created ? 'active' : '' ?>">

                <?= $agent_id_created
                    ? '✓ Agent ID Created'
                    : 'Agent ID Pending'
                ?>

            </div>


            <div class="access-item <?= $can_activate ? 'active' : '' ?>">

                <?= $can_activate
                    ? '✓ Access to Lead Management'
                    : 'Access to Lead Management'
                ?>

            </div>


            <div class="access-item <?= $can_activate ? 'active' : '' ?>">

                <?= $can_activate
                    ? '✓ Customer Management'
                    : 'Customer Management'
                ?>

            </div>


            <div class="access-item <?= $can_activate ? 'active' : '' ?>">

                <?= $can_activate
                    ? '✓ Commission & Reports'
                    : 'Commission & Reports'
                ?>

            </div>


        </div>


        <!-- =================================================
             NOT ACTIVE YET
        ================================================== -->

        <?php if (!$can_activate): ?>


            <div class="approval-warning">


                <h3>

                    Your account is almost ready

                </h3>


                <?php if (!$kyc_approved): ?>

                    <p>

                        • eKYC Verification is currently:

                        <strong>

                            <?= htmlspecialchars(
                                ucfirst(
                                    $kyc_status ?: 'Pending'
                                )
                            ) ?>

                        </strong>

                    </p>

                <?php endif; ?>


                <?php if (!$background_approved): ?>

                    <p>

                        • Background &amp; Verification is currently:

                        <strong>

                            <?= htmlspecialchars(
                                ucfirst(
                                    $background_status ?: 'Pending'
                                )
                            ) ?>

                        </strong>

                    </p>

                <?php endif; ?>


                <?php if (!$role_assigned): ?>

                    <p>

                        • Role Assignment is currently:

                        <strong>
                            Waiting for Admin
                        </strong>

                    </p>

                <?php endif; ?>


                <?php if (!$training_completed): ?>

                    <p>

                        • Training &amp; Certification is not yet completed.

                    </p>

                <?php endif; ?>


                <?php if (!$agent_id_created): ?>

                    <p>

                        • Agent ID has not been created yet.

                    </p>

                <?php endif; ?>


                <?php if (!$wallet_completed): ?>

                    <p>

                        • Wallet &amp; Commission setup is not yet completed.

                    </p>

                <?php endif; ?>


                <?php if (!$tools_completed): ?>

                    <p>

                        • Tools &amp; Materials access is not yet completed.

                    </p>

                <?php endif; ?>


                <?php if ($admin_remarks !== ''): ?>

                    <div class="admin-remark">

                        <strong>
                            Admin Remarks:
                        </strong>

                        <br>

                        <?= nl2br(
                            htmlspecialchars($admin_remarks)
                        ) ?>

                    </div>

                <?php endif; ?>


            </div>


            <a
                href="#"
                class="activate-button disabled"
            >

                Almost Done — Waiting for Approval

            </a>


        <?php else: ?>


            <!-- =================================================
                 ACTIVE ACCOUNT
            ================================================== -->

            <div class="active-message">

                <strong>

                    You're All Set! 🎉

                </strong>

                Your account is now active.
                You can access your Agent Dashboard
                and start using INDBIN services.

                <?php if ($assigned_role !== ''): ?>

                    <br><br>

                    Your assigned role is:

                    <strong>

                        <?= htmlspecialchars(
                            $assigned_role
                        ) ?>

                    </strong>

                <?php endif; ?>

            </div>


            <a
                href="../dashboard/index.php"
                class="activate-button"
            >

                Go to Agent Dashboard →

            </a>


        <?php endif; ?>


    </div>

</div>


<?php

/* =========================================================
   CLOSE AGENT SHELL
========================================================= */

render_shell_close();

?>