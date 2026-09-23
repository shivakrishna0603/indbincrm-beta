<?php

require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];

/* =========================================================
   GET TRAINING RECORD
========================================================= */

$stmt = $pdo->prepare("
    SELECT *
    FROM agent_training
    WHERE user_id = ?
    LIMIT 1
");

$stmt->execute([$user_id]);

$training = $stmt->fetch(PDO::FETCH_ASSOC);

/* =========================================================
   IF NO TRAINING RECORD
========================================================= */

if (!$training) {

    $insert = $pdo->prepare("
        INSERT INTO agent_training
        (
            user_id,
            product_training,
            compliance_training,
            certification_test,
            certification_score
        )
        VALUES
        (?, 'pending', 'pending', 'pending', 0)
    ");

    $insert->execute([$user_id]);

    header('Location: product.php');
    exit;
}

/* =========================================================
   PRODUCT TRAINING CHECK
========================================================= */

if (
    ($training['product_training'] ?? '') !== 'completed'
) {
    header('Location: product.php');
    exit;
}

/* =========================================================
   COMPLETE COMPLIANCE TRAINING
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $stmt = $pdo->prepare("
        UPDATE agent_training
        SET compliance_training = 'completed'
        WHERE user_id = ?
    ");

    $stmt->execute([$user_id]);

    header('Location: certification.php');
    exit;
}

/* =========================================================
   SHELL
========================================================= */

require_once __DIR__ . '/../portal/shell.php';

render_shell_open(
    $pdo,
    $user_id,
    'training',
    'Compliance Training'
);

?>

<style>

/* =========================================================
   PAGE
========================================================= */

.compliance-page {
    width: 100%;
    max-width: 980px;

    margin: 0 auto;

    /* Reduced top space */
    padding: 16px 38px 45px;

    font-family: Arial, Helvetica, sans-serif;
    box-sizing: border-box;
}


/* =========================================================
   HEADER
========================================================= */

.compliance-header {
    width: 100%;

    margin: 0 auto 28px;

    text-align: center;
}


/* =========================================================
   MAIN HEADING
========================================================= */

.compliance-header h1 {
    margin: 0 0 7px;

    color: #0f2742;

    font-size: 30px;
    font-weight: 700;

    line-height: 1.25;
    letter-spacing: -0.4px;

    text-align: center;
    text-transform: none;
}


/* =========================================================
   SUBHEADING
========================================================= */

.compliance-header p {
    width: 100%;
    max-width: none;

    margin: 0 auto;

    color: #64748b;

    font-size: 15px;
    font-weight: 400;

    line-height: 1.5;
    letter-spacing: 0;

    text-align: center;
    text-transform: none;

    /* Keeps the sentence properly aligned on one line */
    white-space: nowrap;
}


/* =========================================================
   TRAINING CARD
========================================================= */

.training-card {
    position: relative;

    width: 100%;
    box-sizing: border-box;

    background: #ffffff;

    border: 1px solid #e6ebf0;
    border-radius: 14px;

    padding: 27px 29px;

    margin-bottom: 22px;

    box-shadow:
        0 4px 14px rgba(15, 23, 42, 0.04),
        0 1px 3px rgba(15, 23, 42, 0.03);
}


/* =========================================================
   GREEN CARD ACCENT
========================================================= */

.training-card::before {
    content: "";

    position: absolute;

    left: 0;
    top: 22px;
    bottom: 22px;

    width: 4px;

    border-radius: 0 4px 4px 0;

    background: #16a34a;
}


/* =========================================================
   CARD HEADING
========================================================= */

.training-card h2 {
    margin: 0 0 13px;

    padding-left: 4px;

    color: #172b4d;

    font-size: 19px;
    font-weight: 700;

    line-height: 1.4;
    letter-spacing: -0.2px;

    text-transform: none;
}


/* =========================================================
   CARD TEXT
========================================================= */

.training-card p {
    margin: 0 0 17px;

    color: #526173;

    font-size: 15px;
    font-weight: 400;

    line-height: 1.7;

    text-transform: none;
}


/* =========================================================
   LIST
========================================================= */

.training-card ul {
    margin: 0;

    padding-left: 23px;
}

.training-card li {
    margin-bottom: 10px;

    padding-left: 3px;

    color: #526173;

    font-size: 14px;
    font-weight: 400;

    line-height: 1.65;

    text-transform: none;
}

.training-card li:last-child {
    margin-bottom: 0;
}


/* =========================================================
   CONTINUE AREA
========================================================= */

.continue-area {
    display: flex;

    justify-content: flex-end;
    align-items: center;

    margin-top: 28px;
}


/* =========================================================
   BUTTON
========================================================= */

.green-button {
    border: 0;
    border-radius: 9px;

    background: #16a34a;
    color: #ffffff;

    padding: 13px 21px;

    font-family: Arial, Helvetica, sans-serif;

    font-size: 14px;
    font-weight: 600;

    line-height: 1.4;

    cursor: pointer;

    box-shadow:
        0 3px 8px rgba(22, 163, 74, 0.18);

    transition:
        background 0.2s ease,
        box-shadow 0.2s ease,
        transform 0.2s ease;

    text-transform: none;
}

.green-button:hover {
    background: #15803d;

    box-shadow:
        0 5px 14px rgba(22, 163, 74, 0.22);

    transform: translateY(-1px);
}

.green-button:active {
    background: #16a34a;

    box-shadow: none;

    transform: none;
}


/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 900px) {

    .compliance-page {
        padding: 18px 24px 40px;
    }

    .compliance-header h1 {
        font-size: 27px;
    }

    .compliance-header p {
        white-space: normal;
        max-width: 800px;
    }
}


@media (max-width: 768px) {

    .compliance-page {
        padding: 18px 18px 40px;
    }

    .compliance-header {
        margin-bottom: 25px;
    }

    .compliance-header h1 {
        font-size: 25px;
    }

    .compliance-header p {
        font-size: 14px;
        line-height: 1.6;
        white-space: normal;
    }

    .training-card {
        padding: 23px 22px;
        border-radius: 12px;
    }

    .training-card::before {
        top: 18px;
        bottom: 18px;
    }

    .training-card h2 {
        font-size: 18px;
    }

    .training-card p,
    .training-card li {
        font-size: 14px;
    }

    .continue-area {
        justify-content: stretch;
    }

    .green-button {
        width: 100%;
    }
}

</style>


<div class="compliance-page">

    <div class="compliance-header">

        <h1>
            Compliance Training
        </h1>

        <p>
            Complete the required compliance and policy training
            before taking the certification test.
        </p>

    </div>


    <div class="training-card">

        <h2>
            Compliance &amp; Policy Training
        </h2>

        <p>
            As an agent, you must follow the approved policies
            and procedures when dealing with customers,
            products and applications.
        </p>

        <ul>

            <li>
                Protect customer personal information.
            </li>

            <li>
                Keep customer documents confidential.
            </li>

            <li>
                Follow approved product and application procedures.
            </li>

            <li>
                Do not guess or create your own product conditions.
            </li>

            <li>
                Ask your supervisor or support team when information
                is unclear.
            </li>

            <li>
                Report suspicious activity through the appropriate channel.
            </li>

        </ul>

    </div>


    <div class="training-card">

        <h2>
            Important Compliance Responsibilities
        </h2>

        <p>
            Agents are responsible for handling customer information,
            documents and applications according to the approved
            process. Customer information must remain confidential
            and protected.
        </p>

    </div>


    <div class="continue-area">

        <form method="POST">

            <button
                type="submit"
                class="green-button"
            >
                Complete Compliance Training →
            </button>

        </form>

    </div>

</div>


<?php

render_shell_close();

?>