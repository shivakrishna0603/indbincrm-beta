<?php

require_once __DIR__ . '/../../core/bootstrap.php';

/* =========================================================
   LOGIN CHECK
========================================================= */

if (!isset($_SESSION['user_id'])) {

    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];

/* =========================================================
   CHECK AGENT
========================================================= */

$stmt = $pdo->prepare("
    SELECT id, full_name, email
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
   CHECK TRAINING & CERTIFICATION
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

if (!$training) {

    header('Location: ../training/index.php');
    exit;
}

if (
    ($training['product_training'] ?? '') !== 'completed' ||
    ($training['compliance_training'] ?? '') !== 'completed' ||
    ($training['certification_test'] ?? '') !== 'completed'
) {

    header('Location: ../training/index.php');
    exit;
}

/* =========================================================
   GET BACKGROUND DETAILS
========================================================= */

$stmt = $pdo->prepare("
    SELECT
        area,
        role
    FROM agent_verification
    WHERE agent_id = ?
      AND role IS NOT NULL
      AND TRIM(role) <> ''
    ORDER BY id DESC
    LIMIT 1
");

$stmt->execute([$user_id]);

$background = $stmt->fetch(PDO::FETCH_ASSOC);

$area = trim($background['area'] ?? '');

$role = trim($background['role'] ?? '');

if ($role === '') {
    $role = 'Role Not Assigned';
}

/* =========================================================
   GET EXISTING HIERARCHY RECORD
========================================================= */

$stmt = $pdo->prepare("
    SELECT *
    FROM agent_hierarchy
    WHERE user_id = ?
    LIMIT 1
");

$stmt->execute([$user_id]);

$agent = $stmt->fetch(PDO::FETCH_ASSOC);


/* =========================================================
   CREATE / FIX HIERARCHY RECORD
========================================================= */

/*
   IMPORTANT:

   Existing old records such as:

       AGT0007

   will now be detected as invalid.

   Correct Agent ID format:

       INDBINA00000001
       INDBINA00000002
       INDBINA00000003

   Correct Referral Code format:

       AG-XXXXXXXX
*/


/* =========================================================
   THE AGENT ID

   agent_hierarchy used to keep its own count of issued agent
   IDs, separate from users.party_code (the value
   issue_party_code() maintains via party_code_sequences,
   shared with merchant and admin so numbers never collide
   across roles). Two independent counters for the same fact
   meant this page could show AGT0015 - the placeholder
   core/auth.php writes at registration, before an ID has
   really been issued - even once a real INDBINA code existed
   elsewhere. There is one issuer now; this just asks for it.
========================================================= */

$new_agent_id = '';

/*
 * Keep an existing Agent ID if this agent already has one.
 * Otherwise generate the next INDBINAXXXXXXXX value from the
 * existing agent_hierarchy records and save it below.
 */
$stmt = $pdo->prepare("
    SELECT agent_id
    FROM agent_hierarchy
    WHERE user_id = ?
      AND agent_id IS NOT NULL
      AND TRIM(agent_id) <> ''
    LIMIT 1
");
$stmt->execute([$user_id]);

$existingAgentId = trim((string)($stmt->fetchColumn() ?: ''));

if ($existingAgentId !== '') {

    $new_agent_id = $existingAgentId;

} else {

    $stmt = $pdo->query("
        SELECT agent_id
        FROM agent_hierarchy
        WHERE agent_id IS NOT NULL
          AND TRIM(agent_id) <> ''
          AND agent_id REGEXP '^INDBINA[0-9]+$'
    ");

    $maxNumber = 0;

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {

        $value = trim((string)$row['agent_id']);

        if (preg_match('/^INDBINA([0-9]+)$/', $value, $match)) {
            $number = (int)$match[1];

            if ($number > $maxNumber) {
                $maxNumber = $number;
            }
        }
    }

    $nextNumber = $maxNumber + 1;

    $new_agent_id =
        'INDBINA' .
        str_pad(
            (string)$nextNumber,
            8,
            '0',
            STR_PAD_LEFT
        );
}

/* =========================================================
   CHECK EXISTING REFERRAL CODE
========================================================= */

$valid_referral_code = false;

if ($agent && !empty($agent['referral_code'])) {

    $valid_referral_code = preg_match(
        '/^AG-[A-Z0-9]{8}$/',
        (string) $agent['referral_code']
    );
}


if (!$valid_referral_code) {

    do {

        $characters =
            'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        $code_suffix = '';

        for ($i = 0; $i < 8; $i++) {

            $code_suffix .=
                $characters[
                    random_int(
                        0,
                        strlen($characters) - 1
                    )
                ];
        }

        $new_referral_code =
            'AG-' . $code_suffix;

        $check = $pdo->prepare("
            SELECT id
            FROM agent_hierarchy
            WHERE referral_code = ?
            LIMIT 1
        ");

        $check->execute([
            $new_referral_code
        ]);

    } while ($check->fetchColumn());

} else {

    /* Keep the existing correct Referral Code */

    $new_referral_code = $agent['referral_code'];
}


/* =========================================================
   UPDATE EXISTING RECORD
========================================================= */

if ($agent) {

    $stmt = $pdo->prepare("
        UPDATE agent_hierarchy
        SET
            agent_id = ?,
            referral_code = ?,
            registered_name = ?,
            email = ?
        WHERE user_id = ?
    ");

    $stmt->execute([
        $new_agent_id,
        $new_referral_code,
        $user['full_name'],
        $user['email'],
        $user_id
    ]);

} else {

    /* =====================================================
       INSERT NEW HIERARCHY RECORD
    ====================================================== */

    $stmt = $pdo->prepare("
        INSERT INTO agent_hierarchy
        (
            user_id,
            agent_id,
            referral_code,
            upline_id,
            downline_ids,
            registered_name,
            email
        )
        VALUES
        (
            ?,
            ?,
            ?,
            NULL,
            NULL,
            ?,
            ?
        )
    ");

    $stmt->execute([
        $user_id,
        $new_agent_id,
        $new_referral_code,
        $user['full_name'],
        $user['email']
    ]);
}


/* =========================================================
   GET FINAL HIERARCHY RECORD
========================================================= */

$stmt = $pdo->prepare("
    SELECT *
    FROM agent_hierarchy
    WHERE user_id = ?
    LIMIT 1
");

$stmt->execute([$user_id]);

$agent = $stmt->fetch(PDO::FETCH_ASSOC);


/* =========================================================
   FINAL VALUES
========================================================= */

$agent_id =
    $agent['agent_id'] ?? '';

$registered_name =
    $agent['registered_name']
    ?: $user['full_name'];

$email =
    $agent['email']
    ?: $user['email'];

$referral_code =
    $agent['referral_code'] ?? '';

$upline_agent_id =
    $agent['upline_id'] ?? '';

$downline_agent_id =
    $agent['downline_ids'] ?? '';


/* =========================================================
   LOAD SHARED AGENT PORTAL SHELL
========================================================= */

require_once __DIR__ . '/../portal/shell.php';

render_shell_open(
    $pdo,
    $user_id,
    'hierarchy',
    'Hierarchy & Code Creation'
);

?>

<!-- =========================================================
     HIERARCHY CONTENT
========================================================= -->

<div class="hierarchy-page">

    <!-- TITLE -->

    <div class="hierarchy-title">

        <h1>
            Hierarchy &amp; Code Creation
        </h1>

        <p>
            Create and view your Agent ID, referral code and hierarchy.
        </p>

    </div>

    <!-- SUCCESS -->

    <div class="success-message">

        ✓ Training &amp; Certification Completed

    </div>

    <!-- AGENT DETAILS -->

    <div class="hierarchy-card">

        <!-- AGENT ID -->

        <div class="agent-id-box">

            <div class="agent-id-label">

                Agent ID

            </div>

            <div class="agent-id-value">

                <?= htmlspecialchars(
                    $agent_id
                ) ?>

            </div>

        </div>

        <!-- DETAILS -->

        <div class="details-grid">

            <div class="detail-item">

                <span class="detail-label">
                    Registered Name
                </span>

                <span class="detail-value">

                    <?= htmlspecialchars(
                        $registered_name
                    ) ?>

                </span>

            </div>

            <div class="detail-item">

                <span class="detail-label">
                    Email
                </span>

                <span class="detail-value">

                    <?= htmlspecialchars(
                        $email
                    ) ?>

                </span>

            </div>

            <div class="detail-item">

                <span class="detail-label">
                    Area
                </span>

                <span class="detail-value">

                    <?= htmlspecialchars(
                        $area ?: 'Not Available'
                    ) ?>

                </span>

            </div>

            <div class="detail-item">

                <span class="detail-label">
                    Role
                </span>

                <span class="detail-value">

                    <?= htmlspecialchars(
                        $role
                    ) ?>

                </span>

            </div>

        </div>

        <!-- REFERRAL CODE -->

        <div class="referral-box">

            <div class="referral-label">

                REFERRAL CODE

            </div>

            <div class="referral-value">

                <?= htmlspecialchars(
                    $referral_code
                ) ?>

            </div>

        </div>

    </div>


    <!-- =====================================================
         HIERARCHY STRUCTURE
    ====================================================== -->

    <div class="hierarchy-card">

        <div class="section-heading">

            Hierarchy Structure

        </div>

        <div class="hierarchy-grid">

            <!-- UPLINE -->

            <div class="hierarchy-box">

                <div class="hierarchy-box-title">

                    Upline Agent

                </div>

                <div class="hierarchy-box-value">

                    <?php if ($upline_agent_id): ?>

                        <?= htmlspecialchars(
                            $upline_agent_id
                        ) ?>

                    <?php else: ?>

                        Not Assigned

                    <?php endif; ?>

                </div>

            </div>


            <!-- CURRENT AGENT -->

            <div class="hierarchy-box current">

                <div class="hierarchy-box-title">

                    Current Agent

                </div>

                <div class="hierarchy-box-value">

                    <?= htmlspecialchars(
                        $agent_id
                    ) ?>

                </div>

                <div
                    style="
                        margin-top:5px;
                        font-size:11px;
                        font-weight:600;
                    "
                >

                    <?= htmlspecialchars(
                        $registered_name
                    ) ?>

                </div>

            </div>


            <!-- DOWNLINE -->

            <div class="hierarchy-box">

                <div class="hierarchy-box-title">

                    Downline Agent

                </div>

                <div class="hierarchy-box-value">

                    <?php if ($downline_agent_id): ?>

                        <?= htmlspecialchars(
                            $downline_agent_id
                        ) ?>

                    <?php else: ?>

                        Not Assigned

                    <?php endif; ?>

                </div>

            </div>

        </div>

    </div>


    <!-- =====================================================
         NAVIGATION
    ====================================================== -->

    <div class="navigation-buttons">

        <!-- BACK TO TRAINING -->

        <a
            href="../training/index.php"
            class="back-button"
        >
            ← Back to Training &amp; Certification
        </a>


        <!-- CONTINUE TO WALLET -->

        <a
            href="../wallet/index.php"
            class="continue-button"
        >
            Continue to Wallet &amp; Commission →
        </a>

    </div>

</div>

<?php

render_shell_close();

?>