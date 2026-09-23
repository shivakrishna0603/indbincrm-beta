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


if (!$training) {
    header('Location: product.php');
    exit;
}


/* =========================================================
   COMPLIANCE MUST BE COMPLETED
========================================================= */

if ($training['compliance_training'] !== 'completed') {
    header('Location: compliance.php');
    exit;
}


/* =========================================================
   CERTIFICATION TEST
========================================================= */

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $correct_answers = [

        'q1' => 'b',
        'q2' => 'c',
        'q3' => 'a',
        'q4' => 'b',
        'q5' => 'c'

    ];


    $score = 0;


    foreach ($correct_answers as $question => $correct_answer) {

        if (
            isset($_POST[$question]) &&
            strtolower($_POST[$question]) === $correct_answer
        ) {

            $score++;
        }
    }


    /*
     * 5 questions
     * 4 correct = 80%
     * 3 correct = 60%
     *
     * Therefore 4/5 is required to pass.
     */

    $percentage = (int)(($score / 5) * 100);


    /* =====================================================
       PASS
    ===================================================== */

    if ($score >= 4) {

        $stmt = $pdo->prepare("
            UPDATE agent_training
            SET
                certification_test = 'completed',
                certification_score = ?
            WHERE user_id = ?
        ");

        $stmt->execute([
            $percentage,
            $user_id
        ]);

        header('Location: certificate.php');
        exit;

    }


    /* =====================================================
       FAIL
    ===================================================== */

    else {

        $stmt = $pdo->prepare("
            UPDATE agent_training
            SET
                certification_test = 'pending',
                certification_score = ?
            WHERE user_id = ?
        ");

        $stmt->execute([
            $percentage,
            $user_id
        ]);

        $error =
            "You scored {$percentage}%. You need at least 4 out of 5 correct answers to pass. Please review the training material and try again.";
    }
}


/* =========================================================
   SHELL
========================================================= */

require_once __DIR__ . '/../portal/shell.php';

render_shell_open(
    $pdo,
    $user_id,
    'training',
    'Certification Test'
);

?>

<style>

/* =========================================================
   CERTIFICATION PAGE
========================================================= */

.test-page {
    width: 100%;
    max-width: 980px;

    margin: 0 auto;

    padding: 16px 38px 45px;

    box-sizing: border-box;

    font-family: Arial, Helvetica, sans-serif !important;

    color: #526173;
}


/* =========================================================
   HEADER
========================================================= */

.test-header {
    display: flex !important;

    flex-direction: column !important;

    align-items: center !important;

    justify-content: center !important;

    width: 100% !important;

    margin: 0 auto 24px !important;

    padding: 0 !important;

    text-align: center !important;

    box-sizing: border-box !important;
}


/* =========================================================
   MAIN HEADING
========================================================= */

.test-header h1 {
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

.test-header > p {
    display: block !important;

    width: 100% !important;

    max-width: 780px !important;

    margin: 0 auto 16px !important;

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
   PASS INFORMATION BOX
========================================================= */

.pass-box {
    width: 100%;

    max-width: 760px;

    box-sizing: border-box;

    margin: 0 auto;

    padding: 15px 20px;

    background: #ffffff;

    border: 1px solid #dfe7ef;

    border-radius: 12px;

    color: #526173;

    font-family: Arial, Helvetica, sans-serif !important;

    font-size: 14px;

    font-weight: 400;

    line-height: 1.6;

    text-align: center;

    box-shadow:
        0 2px 8px rgba(15, 23, 42, 0.03);
}


.pass-box strong {
    color: #172b4d;

    font-weight: 700;
}


/* =========================================================
   ERROR BOX
========================================================= */

.error-box {
    width: 100%;

    box-sizing: border-box;

    margin: 0 auto 22px;

    padding: 14px 18px;

    background: #fff7f7;

    border: 1px solid #fecaca;

    border-radius: 10px;

    color: #b91c1c;

    font-family: Arial, Helvetica, sans-serif !important;

    font-size: 14px;

    line-height: 1.6;

    text-align: left;
}


/* =========================================================
   QUESTION CARD
========================================================= */

.question-card {
    position: relative;

    width: 100%;

    box-sizing: border-box;

    margin-bottom: 22px;

    padding: 27px 29px 29px;

    background: #ffffff;

    border: 1px solid #e6ebf0;

    border-radius: 14px;

    box-shadow:
        0 4px 14px rgba(15, 23, 42, 0.04),
        0 1px 3px rgba(15, 23, 42, 0.03);
}


/* =========================================================
   GREEN SIDE ACCENT
========================================================= */

.question-card::before {
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
   QUESTION NUMBER
========================================================= */

.question-number {
    display: inline-flex !important;

    align-items: center !important;

    justify-content: center !important;

    width: auto !important;

    min-width: 0 !important;

    max-width: none !important;

    margin: 0 0 16px !important;

    padding: 4px 8px !important;

    background: #dcfce7;

    border-radius: 6px;

    color: #15803d;

    font-family: Arial, Helvetica, sans-serif !important;

    font-size: 11px !important;

    font-weight: 700 !important;

    line-height: 1.3 !important;

    letter-spacing: 0 !important;

    white-space: nowrap !important;

    text-transform: none !important;

    box-sizing: border-box !important;
}


/* =========================================================
   QUESTION TITLE
========================================================= */

.question-title {
    width: 100%;

    margin: 0 0 16px;

    padding-left: 5px;

    box-sizing: border-box;

    color: #172b4d;

    font-family: Arial, Helvetica, sans-serif !important;

    font-size: 16px;

    font-weight: 600;

    line-height: 1.55;

    text-transform: none !important;
}


/* =========================================================
   OPTIONS
========================================================= */

.option {
    display: flex;

    align-items: center;

    width: 100%;

    min-height: 50px;

    box-sizing: border-box;

    margin-bottom: 10px;

    padding: 12px 16px;

    background: #ffffff;

    border: 1px solid #d7e0e9;

    border-radius: 11px;

    color: #526173;

    font-family: Arial, Helvetica, sans-serif !important;

    font-size: 14px;

    font-weight: 400;

    line-height: 1.5;

    cursor: pointer;

    transition:
        border-color 0.2s ease,
        background 0.2s ease,
        box-shadow 0.2s ease;
}


.option:last-child {
    margin-bottom: 0;
}


.option:hover {
    background: #f8fffa;

    border-color: #86efac;

    box-shadow:
        0 2px 7px rgba(22, 163, 74, 0.06);
}


/* =========================================================
   RADIO BUTTON
========================================================= */

.option input[type="radio"] {
    flex: 0 0 auto;

    width: 16px;

    height: 16px;

    margin: 0 12px 0 0;

    accent-color: #16a34a;

    cursor: pointer;
}


/* =========================================================
   SUBMIT AREA
========================================================= */

.submit-area {
    display: flex;

    justify-content: flex-end;

    align-items: center;

    width: 100%;

    margin-top: 26px;

    padding-bottom: 10px;
}


/* =========================================================
   GREEN BUTTON
========================================================= */

.green-button {
    display: inline-block;

    border: 0;

    border-radius: 9px;

    background: #16a34a;

    color: #ffffff;

    padding: 13px 22px;

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

    .test-page {
        padding: 18px 24px 40px;
    }

    .test-header h1 {
        font-size: 27px !important;
    }

    .test-header > p {
        max-width: 800px !important;
    }
}


@media (max-width: 700px) {

    .test-page {
        padding: 18px 18px 40px;
    }

    .test-header {
        margin-bottom: 25px !important;
    }

    .test-header h1 {
        font-size: 25px !important;
    }

    .test-header > p {
        font-size: 14px !important;

        line-height: 1.6 !important;

        text-align: center !important;
    }

    .pass-box {
        font-size: 14px;

        padding: 14px 16px;
    }

    .question-card {
        padding: 23px 22px 24px;

        border-radius: 12px;
    }

    .question-card::before {
        top: 18px;

        bottom: 18px;
    }

    .question-number {
        font-size: 11px !important;

        padding: 4px 8px !important;

        white-space: nowrap !important;
    }

    .question-title {
        font-size: 15px;
    }

    .option {
        min-height: 48px;

        padding: 11px 13px;

        font-size: 14px;
    }

    .submit-area {
        justify-content: stretch;
    }

    .green-button {
        width: 100%;

        text-align: center;

        box-sizing: border-box;
    }
}

</style>


<div class="test-page">

    <!-- =====================================================
         HEADER
    ====================================================== -->

    <div class="test-header">

        <h1>
            Certification Test
        </h1>

        <p>
            This test checks your understanding of the Product Training
            and Compliance Training courses.
        </p>


        <div class="pass-box">

            There are <strong>5 questions</strong>.
            You need at least <strong>4 correct answers</strong>
            to pass the certification.

        </div>

    </div>


    <!-- =====================================================
         ERROR
    ====================================================== -->

    <?php if ($error): ?>

        <div class="error-box">

            <?= htmlspecialchars($error) ?>

        </div>

    <?php endif; ?>


    <form method="POST">


        <!-- =================================================
             QUESTION 1
        ================================================== -->

        <div class="question-card">

            <div class="question-number">
                Question 1 of 5
            </div>

            <div class="question-title">
                Before explaining a product to a customer,
                what should an agent do?
            </div>


            <label class="option">

                <input
                    type="radio"
                    name="q1"
                    value="a"
                    required
                >

                Guess what the product offers

            </label>


            <label class="option">

                <input
                    type="radio"
                    name="q1"
                    value="b"
                >

                Understand the approved product information

            </label>


            <label class="option">

                <input
                    type="radio"
                    name="q1"
                    value="c"
                >

                Ask another customer about the product

            </label>

        </div>


        <!-- =================================================
             QUESTION 2
        ================================================== -->

        <div class="question-card">

            <div class="question-number">
                Question 2 of 5
            </div>

            <div class="question-title">
                What should an agent do if some product information
                is unclear?
            </div>


            <label class="option">

                <input
                    type="radio"
                    name="q2"
                    value="a"
                    required
                >

                Guess the information

            </label>


            <label class="option">

                <input
                    type="radio"
                    name="q2"
                    value="b"
                >

                Create their own product conditions

            </label>


            <label class="option">

                <input
                    type="radio"
                    name="q2"
                    value="c"
                >

                Ask the appropriate supervisor or support team

            </label>

        </div>


        <!-- =================================================
             QUESTION 3
        ================================================== -->

        <div class="question-card">

            <div class="question-number">
                Question 3 of 5
            </div>

            <div class="question-title">
                How should customer personal information be handled?
            </div>


            <label class="option">

                <input
                    type="radio"
                    name="q3"
                    value="a"
                    required
                >

                Kept confidential and protected

            </label>


            <label class="option">

                <input
                    type="radio"
                    name="q3"
                    value="b"
                >

                Shared with anyone who asks

            </label>


            <label class="option">

                <input
                    type="radio"
                    name="q3"
                    value="c"
                >

                Posted publicly

            </label>

        </div>


        <!-- =================================================
             QUESTION 4
        ================================================== -->

        <div class="question-card">

            <div class="question-number">
                Question 4 of 5
            </div>

            <div class="question-title">
                What should an agent do with customer documents?
            </div>


            <label class="option">

                <input
                    type="radio"
                    name="q4"
                    value="a"
                    required
                >

                Modify them to make an application easier

            </label>


            <label class="option">

                <input
                    type="radio"
                    name="q4"
                    value="b"
                >

                Handle them according to the approved process

            </label>


            <label class="option">

                <input
                    type="radio"
                    name="q4"
                    value="c"
                >

                Share them through any available channel

            </label>

        </div>


        <!-- =================================================
             QUESTION 5
        ================================================== -->

        <div class="question-card">

            <div class="question-number">
                Question 5 of 5
            </div>

            <div class="question-title">
                What should an agent do when suspicious activity
                is identified?
            </div>


            <label class="option">

                <input
                    type="radio"
                    name="q5"
                    value="a"
                    required
                >

                Ignore it

            </label>


            <label class="option">

                <input
                    type="radio"
                    name="q5"
                    value="b"
                >

                Delete the customer information

            </label>


            <label class="option">

                <input
                    type="radio"
                    name="q5"
                    value="c"
                >

                Report it through the appropriate channel

            </label>

        </div>


        <!-- =================================================
             SUBMIT
        ================================================== -->

        <div class="submit-area">

            <button
                type="submit"
                class="green-button"
            >
                Submit Certification Test →
            </button>

        </div>

    </form>

</div>


<?php

render_shell_close();

?>