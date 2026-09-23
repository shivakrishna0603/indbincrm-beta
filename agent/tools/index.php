<?php


require_once __DIR__ . '/../db.php';


/* ---------------------------------------------------------
   LOGIN CHECK
--------------------------------------------------------- */

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];


/* ---------------------------------------------------------
   MARK TOOLS AS COMPLETED

   Was $_SESSION['tools_completed'], which reset on every fresh
   login regardless of whether the agent had actually already
   been through this step - so it could show as "not completed"
   again for someone who genuinely finished it, simply because
   they signed out and back in. Persisted to the database now.
--------------------------------------------------------- */

$pdo->prepare(
    "UPDATE users SET app_walkthrough_completed_at = COALESCE(app_walkthrough_completed_at, NOW()) WHERE id = ?"
)->execute([$user_id]);


/* ---------------------------------------------------------
   LOAD AGENT PORTAL SHELL
   IMPORTANT:
   This comes AFTER setting tools_completed
--------------------------------------------------------- */

require_once __DIR__ . '/../portal/shell.php';

render_shell_open(
    $pdo,
    $user_id,
    'tools',
    'Tools & Materials Access'
);

?>



<div class="container">

    <div class="card">

        <div class="icon">
            ✓
        </div>


        <h1>
            Tools &amp; Materials Access
        </h1>


        <p>
            Your onboarding training and certification
            have been completed successfully.
        </p>


        <div class="item">

            <strong>
                Lead Capture Tools
            </strong>

            Access lead capture and customer onboarding tools.

        </div>


        <div class="item">

            <strong>
                Marketing Kit
            </strong>

            Access marketing materials and promotional resources.

        </div>


        <div class="item">

            <strong>
                Product Brochures
            </strong>

            Access product brochures and information.

        </div>


        <!-- CORRECT PATH:
             tools_materials.php and onboard_complete.php
             are in the same ekyc folder.
        -->

        <a
            href="../onboarding_complete/index.php"
            class="button"
        >
            Continue to Onboarding Complete →
        </a>

    </div>

</div>




<?php

render_shell_close();

?>