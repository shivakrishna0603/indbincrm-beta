<?php

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../portal/shell.php';

/*
============================================================
CHECK AGENT LOGIN
============================================================
*/

if (!isset($_SESSION['user_id'])) {

    header('Location: ' . BASE_URL . '/index.php');

    exit;

}

$user_id = (int) $_SESSION['user_id'];

/*
============================================================
GET AGENT DETAILS
============================================================
*/

$stmt = $pdo->prepare("
    SELECT full_name
    FROM users
    WHERE id = ?
");

$stmt->execute([$user_id]);

$agent = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$agent) {

    exit('Agent not found.');

}

/*
============================================================
SUBMIT BACKGROUND VERIFICATION
============================================================
*/

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $area =
        trim($_POST['area'] ?? '');

    $reference_name =
        trim($_POST['reference_name'] ?? '');

    $reference_mobile =
        trim($_POST['reference_mobile'] ?? '');

    $reference_relationship =
        trim($_POST['reference_relationship'] ?? '');

    $verification_address =
        trim($_POST['verification_address'] ?? '');

    $agreement =
        isset($_POST['agreement']);

    /*
    --------------------------------------------------------
    VALIDATION
    --------------------------------------------------------
    */

    if (
        $area === '' ||
        $reference_name === '' ||
        $reference_mobile === '' ||
        $reference_relationship === '' ||
        $verification_address === '' ||
        !$agreement
    ) {

        $error =
            'Please complete all required fields.';

    }

    else {

        /*
        ----------------------------------------------------
        STORE BACKGROUND INFORMATION
        ----------------------------------------------------
        */

        /*
        ----------------------------------------------------
        CREATE BACKGROUND VERIFICATION RECORD
        ----------------------------------------------------
        */

        $stmt = $pdo->prepare("
            INSERT INTO agent_verification
            (
                agent_id,
                area,
                reference_name,
                reference_mobile,
                reference_relationship,
                verification_address,
                reference_status,
                field_status,
                background_status,
                admin_remarks
            )
            VALUES
            (?, ?, ?, ?, ?, ?, 'pending', 'pending', 'pending', NULL)

            ON DUPLICATE KEY UPDATE
                area                   = VALUES(area),
                reference_name         = VALUES(reference_name),
                reference_mobile       = VALUES(reference_mobile),
                reference_relationship = VALUES(reference_relationship),
                verification_address   = VALUES(verification_address),
                reference_status       = 'pending',
                field_status           = 'pending',
                background_status      = 'pending',
                admin_remarks          = NULL,
                reviewed_at            = NULL,
                created_at             = CURRENT_TIMESTAMP
        ");

        $stmt->execute([
            $user_id,
            $area,
            $reference_name,
            $reference_mobile,
            $reference_relationship,
            $verification_address
        ]);

        /*
        ----------------------------------------------------
        AFTER SUBMISSION
        ----------------------------------------------------
        */

        header(
            'Location:../background/status.php'
        );

        exit;

    }

}

/*
============================================================
OPEN AGENT SHELL
============================================================
*/

render_shell_open(

    $pdo,

    $user_id,

    'background',

    'Background & Verification'

);

?>

<!-- ========================================================
     BACKGROUND & VERIFICATION CARD
     ======================================================== -->

<div class="background-card">

    <h1>
        Background & Verification
    </h1>

    <p class="subtitle">

        Provide the required information for
        background verification.

    </p>

    <?php if (!empty($error)): ?>

        <div class="error-message">

            <?= htmlspecialchars($error) ?>

        </div>

    <?php endif; ?>

    <form method="POST">

        <!-- =================================================
             FULL NAME
             ================================================= -->

        <label>
            Full Name
        </label>

        <input
            type="text"
            value="<?= htmlspecialchars(
                $agent['full_name']
            ) ?>"
            readonly
        >

        <!-- =================================================
             AREA
             ================================================= -->

        <label>
            Area
        </label>

        <input
            type="text"
            name="area"
            placeholder="Enter your area"
            required
        >

        <!-- =================================================
             REFERENCE NAME
             ================================================= -->

        <label>
            Reference Name
        </label>

        <input
            type="text"
            name="reference_name"
            placeholder="Enter reference name"
            required
        >

        <!-- =================================================
             REFERENCE MOBILE
             ================================================= -->

        <label>
            Reference Mobile Number
        </label>

        <input
            type="text"
            name="reference_mobile"
            placeholder="Enter 10-digit mobile number"
            maxlength="10"
            required
        >

        <!-- =================================================
             REFERENCE RELATIONSHIP
             ================================================= -->

        <label>
            Reference Relationship
        </label>

        <select
            name="reference_relationship"
            required
        >

            <option value="">
                Select Relationship
            </option>

            <option value="Friend">
                Friend
            </option>

            <option value="Colleague">
                Colleague
            </option>

            <option value="Relative">
                Relative
            </option>

            <option value="Manager">
                Manager
            </option>

            <option value="Other">
                Other
            </option>

        </select>

        <!-- =================================================
             ADDRESS
             ================================================= -->

        <label>
            Agent Address for Field Verification
        </label>

        <textarea
            name="verification_address"
            rows="4"
            placeholder="Enter your address"
            required
        ></textarea>

        <!-- =================================================
             ROLE ASSIGNMENT

             Used to be a dropdown here (Field Agent / Sales Agent /
             Senior Agent / Service Agent) that the agent picked for
             themselves. Now set by the admin, after onboarding
             completes, from admin/agent_settings.php - agent_verification.role
             stays NULL until then, which the admin page treats as
             "not assigned yet", exactly like commission_rate already does.
             ================================================= -->

        <!-- =================================================
             AGREEMENT
             ================================================= -->

        <label class="agreement">

            <input
                type="checkbox"
                name="agreement"
                required
            >

            <span>

                I agree to the terms and conditions
                of the Agent onboarding and background
                verification process.

            </span>

        </label>

        <!-- =================================================
             SUBMIT
             ================================================= -->

        <button
            type="submit"
            class="submit-button"
        >

            Submit Verification

        </button>

    </form>

</div>

<?php

/*
============================================================
CLOSE AGENT SHELL
============================================================
*/

render_shell_close();

?>