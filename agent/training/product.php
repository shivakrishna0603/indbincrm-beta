<?php

require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];

/* =========================================================
   CREATE TRAINING TABLE
   ========================================================= */

$pdo->exec("
    CREATE TABLE IF NOT EXISTS agent_training (

        id INT AUTO_INCREMENT PRIMARY KEY,

        user_id INT NOT NULL UNIQUE,

        product_training VARCHAR(30)
            NOT NULL DEFAULT 'pending',

        compliance_training VARCHAR(30)
            NOT NULL DEFAULT 'pending',

        certification_test VARCHAR(30)
            NOT NULL DEFAULT 'pending',

        certification_score INT
            NOT NULL DEFAULT 0,

        created_at TIMESTAMP
            DEFAULT CURRENT_TIMESTAMP,

        updated_at TIMESTAMP
            DEFAULT CURRENT_TIMESTAMP
            ON UPDATE CURRENT_TIMESTAMP
    )
");


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
   CREATE RECORD IF NEEDED
   ========================================================= */

if (!$training) {

    $stmt = $pdo->prepare("
        INSERT INTO agent_training (user_id)
        VALUES (?)
    ");

    $stmt->execute([$user_id]);

    $stmt = $pdo->prepare("
        SELECT *
        FROM agent_training
        WHERE user_id = ?
        LIMIT 1
    ");

    $stmt->execute([$user_id]);

    $training = $stmt->fetch(PDO::FETCH_ASSOC);
}


/* =========================================================
   COMPLETE PRODUCT TRAINING
   ========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['complete_product'])) {

        $stmt = $pdo->prepare("
            UPDATE agent_training
            SET product_training = 'completed'
            WHERE user_id = ?
        ");

        $stmt->execute([$user_id]);

        header('Location: compliance.php');
        exit;
    }
}


$completed =
    ($training['product_training'] === 'completed');


/* =========================================================
   SHELL
   ========================================================= */

require_once __DIR__ . '/../portal/shell.php';

render_shell_open(
    $pdo,
    $user_id,
    'training',
    'Product Training'
);

?>

<style>

/* =========================================================
   PRODUCT TRAINING PAGE
========================================================= */

.course-page {
    width: 100%;
    max-width: 980px;

    margin: 0 auto;

    padding: 16px 38px 45px;

    box-sizing: border-box;

    font-family: Arial, Helvetica, sans-serif !important;

    font-size: 14px;

    line-height: 1.6;
}


/* =========================================================
   HEADER
========================================================= */

.course-header {
    display: flex !important;

    flex-direction: column !important;

    align-items: center !important;

    justify-content: center !important;

    width: 100% !important;

    margin: 0 auto 28px !important;

    padding: 0 !important;

    text-align: center !important;

    box-sizing: border-box !important;
}


/* =========================================================
   MAIN HEADING
========================================================= */

.course-header h1 {
    display: block !important;

    width: 100% !important;

    margin: 0 0 7px !important;

    padding: 0 !important;

    color: #0f2742 !important;

    font-family: Arial, Helvetica, sans-serif !important;

    font-size: 30px !important;

    font-weight: 700 !important;

    line-height: 1.25 !important;

    letter-spacing: -0.4px !important;

    text-align: center !important;

    text-transform: none !important;
}


/* =========================================================
   SUBHEADING
========================================================= */

.course-header p {
    display: block !important;

    width: 100% !important;

    max-width: 780px !important;

    margin: 0 auto !important;

    padding: 0 !important;

    color: #64748b !important;

    font-family: Arial, Helvetica, sans-serif !important;

    font-size: 15px !important;

    font-weight: 400 !important;

    line-height: 1.5 !important;

    letter-spacing: 0 !important;

    text-align: center !important;

    text-transform: none !important;
}


/* =========================================================
   COURSE CARDS
========================================================= */

.course-card {
    position: relative;

    width: 100%;

    box-sizing: border-box;

    margin-bottom: 22px;

    padding: 27px 29px;

    background: #ffffff;

    border: 1px solid #e6ebf0;

    border-radius: 14px;

    font-family: Arial, Helvetica, sans-serif !important;

    box-shadow:
        0 4px 14px rgba(15, 23, 42, 0.04),
        0 1px 3px rgba(15, 23, 42, 0.03);
}


/* =========================================================
   GREEN SIDE ACCENT
========================================================= */

.course-card::before {
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
   LESSON HEADING
========================================================= */

.course-card h2 {
    margin: 0 0 13px;

    padding-left: 4px;

    color: #172b4d;

    font-family: Arial, Helvetica, sans-serif !important;

    font-size: 20px;

    font-weight: 600;

    line-height: 1.4;

    letter-spacing: -0.2px;

    text-transform: none !important;
}


/* =========================================================
   LESSON PARAGRAPHS
========================================================= */

.course-card p {
    margin: 0 0 12px;

    color: #526173;

    font-family: Arial, Helvetica, sans-serif !important;

    font-size: 15px;

    font-weight: 400;

    line-height: 1.7;

    text-transform: none !important;
}


/* =========================================================
   LESSON LIST
========================================================= */

.course-card ul {
    margin: 0;

    padding-left: 23px;

    font-family: Arial, Helvetica, sans-serif !important;
}

.course-card li {
    margin-bottom: 10px;

    padding-left: 3px;

    color: #526173;

    font-family: Arial, Helvetica, sans-serif !important;

    font-size: 14px;

    font-weight: 400;

    line-height: 1.65;

    text-transform: none !important;
}

.course-card li:last-child {
    margin-bottom: 0;
}


/* =========================================================
   LEARNING BOX
========================================================= */

.learning-box {
    margin-top: 4px;

    padding: 20px;

    background: #ffffff;

    border: 1px solid #dfe7ef;

    border-radius: 12px;

    font-family: Arial, Helvetica, sans-serif !important;

    font-size: 14px;

    line-height: 1.6;

    box-sizing: border-box;
}


.learning-box strong {
    display: block;

    margin-bottom: 4px;

    color: #172b4d;

    font-family: Arial, Helvetica, sans-serif !important;

    font-size: 14px;

    font-weight: 600;

    text-transform: none !important;
}


.learning-box p {
    margin: 0;

    color: #526173;

    font-family: Arial, Helvetica, sans-serif !important;

    font-size: 14px;

    font-weight: 400;

    line-height: 1.6;

    text-transform: none !important;
}


/* =========================================================
   COMPLETION BOX
========================================================= */

.complete-box {
    position: relative;

    margin-top: 22px;

    padding: 25px 28px;

    background: #ffffff;

    border: 1px solid #e6ebf0;

    border-radius: 14px;

    font-family: Arial, Helvetica, sans-serif !important;

    box-shadow:
        0 4px 14px rgba(15, 23, 42, 0.04),
        0 1px 3px rgba(15, 23, 42, 0.03);
}


/* Green accent */

.complete-box::before {
    content: "";

    position: absolute;

    left: 0;

    top: 20px;

    bottom: 20px;

    width: 4px;

    border-radius: 0 4px 4px 0;

    background: #16a34a;
}


.complete-box h2 {
    margin: 0 0 12px;

    padding-left: 4px;

    color: #172b4d;

    font-family: Arial, Helvetica, sans-serif !important;

    font-size: 20px;

    font-weight: 600;

    line-height: 1.4;

    text-transform: none !important;
}


.complete-box p {
    margin: 0 0 18px;

    color: #526173;

    font-family: Arial, Helvetica, sans-serif !important;

    font-size: 14px;

    font-weight: 400;

    line-height: 1.6;

    text-transform: none !important;
}


/* =========================================================
   BUTTON
========================================================= */

.green-button {
    display: inline-block;

    border: 0;

    border-radius: 9px;

    background: #16a34a;

    color: #ffffff;

    padding: 13px 21px;

    font-family: Arial, Helvetica, sans-serif !important;

    font-size: 14px;

    font-weight: 600;

    line-height: 1.4;

    text-decoration: none;

    cursor: pointer;

    box-shadow:
        0 3px 8px rgba(22, 163, 74, 0.18);

    transition:
        background 0.2s ease,
        box-shadow 0.2s ease,
        transform 0.2s ease;

    text-transform: none !important;
}


.green-button:hover {
    background: #15803d;

    color: #ffffff;

    box-shadow:
        0 5px 14px rgba(22, 163, 74, 0.22);

    transform: translateY(-1px);
}


.green-button:active {
    background: #16a34a;

    color: #ffffff;

    box-shadow: none;

    transform: none;
}


/* =========================================================
   MOBILE
========================================================= */

@media (max-width: 900px) {

    .course-page {
        padding: 18px 24px 40px;
    }

    .course-header {
        display: flex !important;

        flex-direction: column !important;

        align-items: center !important;

        text-align: center !important;
    }

    .course-header h1 {
        font-size: 27px !important;

        text-align: center !important;
    }

    .course-header p {
        max-width: 800px !important;

        text-align: center !important;
    }
}


@media (max-width: 700px) {

    .course-page {
        padding: 18px 18px 40px;
    }

    .course-header {
        margin-bottom: 25px !important;
    }

    .course-header h1 {
        font-size: 25px !important;
    }

    .course-header p {
        font-size: 14px !important;

        line-height: 1.6 !important;

        text-align: center !important;
    }

    .course-card {
        padding: 23px 22px;

        border-radius: 12px;
    }

    .course-card::before {
        top: 18px;

        bottom: 18px;
    }

    .course-card h2 {
        font-size: 18px;
    }

    .course-card p,
    .course-card li {
        font-size: 14px;
    }

    .learning-box {
        font-size: 14px;
    }

    .complete-box {
        padding: 23px 22px;
    }

    .complete-box h2 {
        font-size: 19px;
    }

    .green-button {
        width: 100%;

        text-align: center;

        box-sizing: border-box;
    }
}

</style>


<div class="course-page">

    <!-- HEADER -->

    <div class="course-header">

        <h1>Product Training</h1>

        <p>
            This course helps you understand how to study, understand
            and explain a product correctly before assisting a customer.
        </p>

    </div>


    <!-- LESSON 1 -->

    <div class="course-card">

        <h2>Understanding the Product</h2>

        <p>
            Before explaining any product to a customer, an agent should
            first understand the product clearly. The agent should know
            what the product is designed for, who it is intended for,
            what it offers and how the customer can use it.
        </p>

        <p>
            An agent should never recommend a product simply because
            the customer asks for it. First understand the customer's
            requirement and then explain the relevant product information.
        </p>

        <div class="learning-box">

            <strong>Remember:</strong>

            <p>
                Understand the product yourself before asking the customer
                to understand or choose it.
            </p>

        </div>

    </div>


    <!-- LESSON 2 -->

    <div class="course-card">

        <h2>How to Study a Product</h2>

        <p>
            When a new product is introduced, the agent should carefully
            read the approved product information and understand each
            important part before assisting customers.
        </p>

        <p>
            Pay attention to the product's purpose, important features,
            benefits, eligibility requirements, applicable conditions,
            required documents and application process.
        </p>

        <p>
            If there is any information that is unclear, the agent should
            ask the appropriate supervisor or support team instead of
            guessing.
        </p>

    </div>


    <!-- LESSON 3 -->

    <div class="course-card">

        <h2>Explaining a Product to a Customer</h2>

        <p>
            Product information should be explained in simple and clear
            language. The customer should be able to understand what the
            product does and what they need to do.
        </p>

        <p>
            An agent should explain only information that is approved and
            available from the official product material.
        </p>

        <p>
            Do not create your own terms, conditions, benefits or promises.
            If something is not mentioned in the approved information,
            confirm it before explaining it to the customer.
        </p>

    </div>


    <!-- LESSON 4 -->

    <div class="course-card">

        <h2>Understanding Customer Requirements</h2>

        <p>
            Every customer may have a different requirement. Therefore,
            an agent should listen carefully before suggesting or explaining
            a product.
        </p>

        <p>
            Understand what the customer needs, explain the relevant
            information and allow the customer to make an informed decision.
        </p>

        <p>
            The agent must not pressure the customer into selecting a
            product.
        </p>

    </div>


    <!-- LESSON 5 -->

    <div class="course-card">

        <h2>Product Information and Documents</h2>

        <p>
            Some products require specific customer information or
            supporting documents. The agent should clearly explain what
            is required and why the information is needed.
        </p>

        <p>
            Documents must be collected and handled according to the
            approved process. The agent should check that the information
            submitted is accurate and belongs to the correct customer.
        </p>

    </div>


    <!-- LESSON 6 -->

    <div class="course-card">

        <h2>What an Agent Must Avoid</h2>

        <ul>

            <li>
                Do not provide false or misleading product information.
            </li>

            <li>
                Do not make promises that are not part of the approved
                product information.
            </li>

            <li>
                Do not hide important conditions from the customer.
            </li>

            <li>
                Do not guess when you are unsure about a product.
            </li>

            <li>
                Do not change customer information to make an application
                appear eligible.
            </li>

        </ul>

    </div>


    <!-- COMPLETION -->

    <div class="complete-box">

        <?php if ($completed): ?>

            <h2>✓ Product Training Completed</h2>

            <p>
                You have completed the Product Training course.
            </p>

            <a href="../training/compliance.php" class="green-button">
                Continue to Compliance Training →
            </a>

        <?php else: ?>

            <h2>Complete Your Product Training</h2>

            <p>
                Read and understand the training material above before
                marking this course as completed.
            </p>

            <form method="POST">

                <button
                    type="submit"
                    name="complete_product"
                    class="green-button"
                >
                    Complete Product Training →
                </button>

            </form>

        <?php endif; ?>

    </div>

</div>


<?php

render_shell_close();

?>