<?php

require_once __DIR__ . '/../db.php';

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
GET LATEST BACKGROUND VERIFICATION
============================================================
*/

$stmt = $pdo->prepare("
    SELECT
        reference_status,
        field_status,
        background_status,
        admin_remarks
    FROM agent_verification
    WHERE agent_id = ?
    ORDER BY id DESC
    LIMIT 1
");

$stmt->execute([$user_id]);

$verification = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$verification) {

    header('Location: background_verification.php');
    exit;

}

$reference_status =
    $verification['reference_status'] ?? 'pending';

$field_status =
    $verification['field_status'] ?? 'pending';

$background_status =
    $verification['background_status'] ?? 'pending';

/*
============================================================
STATUS TEXT
============================================================
*/

function status_text($status)
{

    if ($status === 'verified') {
        return 'Verified';
    }

    if ($status === 'rejected') {
        return 'Rejected';
    }

    return 'Pending';

}

/*
============================================================
LOAD SHARED AGENT PORTAL SHELL
============================================================
*/

require_once __DIR__ . '/../portal/shell.php';

render_shell_open(
    $pdo,
    $user_id,
    'background',
    'Background & Verification'
);

?>

<!-- =====================================================
     BACKGROUND VERIFICATION PAGE
===================================================== -->

<div class="background-page">

    <div class="background-card">

        <!-- =================================================
             SUCCESS ICON
        ================================================== -->

        <div class="icon-circle">

            <i class="fa-solid fa-circle-check"></i>

        </div>

        <!-- =================================================
             HEADING
        ================================================== -->

        <h1>

            Background &amp; Verification submitted

        </h1>

        <p class="subtitle">

            Your background verification details have been
            submitted successfully.

        </p>

        <!-- =================================================
             SUCCESS MESSAGE
        ================================================== -->

        <div class="success-message">

            ✓ Your details have been submitted and are
            pending Admin verification.

        </div>

        <!-- =================================================
             VERIFICATION STATUS
        ================================================== -->

        <div class="status-section">

            <!-- REFERENCE CHECK -->

            <div class="status-row">

                <span class="status-name">

                    Reference Check

                </span>

                <span
                    class="status <?= htmlspecialchars($reference_status) ?>"
                >

                    <?= status_text($reference_status) ?>

                </span>

            </div>

            <!-- FIELD VERIFICATION -->

            <div class="status-row">

                <span class="status-name">

                    Field Verification

                </span>

                <span
                    class="status <?= htmlspecialchars($field_status) ?>"
                >

                    <?= status_text($field_status) ?>

                </span>

            </div>

            <!-- BACKGROUND STATUS -->

            <div class="status-row">

                <span class="status-name">

                    Background Status

                </span>

                <span
                    class="status <?= htmlspecialchars($background_status) ?>"
                >

                    <?= status_text($background_status) ?>

                </span>

            </div>

        </div>

        <!-- =================================================
             ADMIN REMARKS
        ================================================== -->

        <?php if (!empty($verification['admin_remarks'])): ?>

            <div class="admin-message">

                <strong>Admin Remarks:</strong><br>

                <?= htmlspecialchars($verification['admin_remarks']) ?>

            </div>

        <?php endif; ?>

        <!-- =================================================
             CONTINUE TO TRAINING
             
             IMPORTANT:
             This button is available even when the
             background verification is still pending.
        ================================================== -->

        <a
            href="../training/index.php"
            class="training-button">
        

            Continue to Training &amp; Certification →

        </a>

    </div>

</div>

<?php

render_shell_close();

?>