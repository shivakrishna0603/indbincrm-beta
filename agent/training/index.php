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
   CREATE TRAINING RECORD IF MISSING
   ========================================================= */

if (!$training) {

    $stmt = $pdo->prepare("
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
   PROGRESS
   ========================================================= */

$progress = 0;

if ($training['product_training'] === 'completed') {
    $progress += 33;
}

if ($training['compliance_training'] === 'completed') {
    $progress += 33;
}

if ($training['certification_test'] === 'completed') {
    $progress = 100;
}

/* =========================================================
   SHELL
   ========================================================= */

require_once __DIR__ . '/../portal/shell.php';

render_shell_open(
    $pdo,
    $user_id,
    'training',
    'Training & Certification'
);

?>

<div class="training-page">

    <!-- =====================================================
         TITLE
    ====================================================== -->

    <div class="training-title">

        <h1>
            Training &amp; Certification
        </h1>

        <p>
            Complete the required training and pass the certification test.
        </p>

    </div>

    <!-- =====================================================
         PROGRESS
    ====================================================== -->

    <div class="progress-card">

        <div class="progress-top">

            <span class="progress-label">
                Training Progress
            </span>

            <span class="progress-value">
                <?= $progress ?>%
            </span>

        </div>

        <div class="progress-track">

            <div
                class="progress-fill"
                style="width: <?= $progress ?>%;"
            ></div>

        </div>

    </div>

    <!-- =====================================================
         PRODUCT TRAINING
    ====================================================== -->

    <div class="training-card">

        <div class="training-card-header">

            <div class="training-card-title">

                <div class="training-number">
                    1
                </div>

                <h2>
                    Product Training
                </h2>

            </div>

            <?php if ($training['product_training'] === 'completed'): ?>

                <span class="training-status completed">
                    Completed
                </span>

            <?php else: ?>

                <span class="training-status progress">
                    In Progress
                </span>

            <?php endif; ?>

        </div>

        <p class="training-description">
            Learn about products, features, benefits, plans and
            correct customer handling procedures.
        </p>

        <div class="info-box">

            <h3>
                Product Training
            </h3>

            <ul>

                <li>
                    Understand the products and their main features.
                </li>

                <li>
                    Understand the benefits and available plans or options.
                </li>

                <li>
                    Provide customers with accurate product information.
                </li>

                <li>
                    Understand customer requirements before recommending a product.
                </li>

                <li>
                    Never make false promises or provide incorrect information.
                </li>

            </ul>

        </div>

        <a href="product.php" class="green-button">
            <?php
            echo ($training['product_training'] === 'completed')
                ? 'View Product Training →'
                : 'Start Product Training →';
            ?>
        </a>

    </div>

    <!-- =====================================================
         COMPLIANCE TRAINING
    ====================================================== -->

    <div class="training-card">

        <div class="training-card-header">

            <div class="training-card-title">

                <div class="training-number">
                    2
                </div>

                <h2>
                    Compliance Training
                </h2>

            </div>

            <?php if ($training['compliance_training'] === 'completed'): ?>

                <span class="training-status completed">
                    Completed
                </span>

            <?php elseif ($training['product_training'] === 'completed'): ?>

                <span class="training-status progress">
                    In Progress
                </span>

            <?php else: ?>

                <span class="training-status pending">
                    Pending
                </span>

            <?php endif; ?>

        </div>

        <p class="training-description">
            Learn the required KYC, privacy, customer protection
            and responsible conduct procedures.
        </p>

        <?php if ($training['product_training'] === 'completed'): ?>

            <div class="info-box">

                <h3>
                    Compliance Training
                </h3>

                <ul>

                    <li>
                        Verify customer information according to the required KYC process.
                    </li>

                    <li>
                        Protect customer personal information and maintain confidentiality.
                    </li>

                    <li>
                        Follow the approved process when handling customer information.
                    </li>

                    <li>
                        Do not make false promises or misrepresent products.
                    </li>

                    <li>
                        Escalate issues when you are unsure about the correct procedure.
                    </li>

                </ul>

            </div>

            <a href="compliance.php" class="green-button">

                <?php
                echo ($training['compliance_training'] === 'completed')
                    ? 'View Compliance Training →'
                    : 'Start Compliance Training →';
                ?>

            </a>

        <?php else: ?>

            <span class="training-status pending">
                Complete Product Training First
            </span>

        <?php endif; ?>

    </div>

    <!-- =====================================================
         CERTIFICATION TEST
    ====================================================== -->

    <div class="training-card">

        <div class="training-card-header">

            <div class="training-card-title">

                <div class="training-number">
                    3
                </div>

                <h2>
                    Certification Test
                </h2>

            </div>

            <?php if ($training['certification_test'] === 'completed'): ?>

                <span class="training-status completed">
                    Passed
                </span>

            <?php elseif ($training['compliance_training'] === 'completed'): ?>

                <span class="training-status progress">
                    Ready
                </span>

            <?php else: ?>

                <span class="training-status pending">
                    Pending
                </span>

            <?php endif; ?>

        </div>

        <p class="training-description">
            Complete the certification test. A minimum score of
            70% is required to pass.
        </p>

        <?php if ($training['compliance_training'] === 'completed'): ?>

            <?php if ($training['certification_test'] === 'completed'): ?>

                <div class="completion-box">

                    <strong>
                        Certification Successfully Completed
                    </strong>

                    Your certification test has been passed successfully.

                    <br><br>

                    <strong>
                        Score:
                        <?= (int)$training['certification_score'] ?>%
                    </strong>

                </div>

                <div style="margin-top: 16px;">

                    <a
                        href="certificate.php"
                        class="certificate-button"
                    >
                        View &amp; Download Certificate →
                    </a>

                    <a
                        href="../hierarchy/index.php"
                        class="next-button"
                    >
                        Continue to Hierarchy &amp; Code Creation →
                    </a>

                </div>

            <?php else: ?>

                <div class="info-box">

                    <h3>
                        Certification Test
                    </h3>

                    <ul>

                        <li>
                            Answer all five questions.
                        </li>

                        <li>
                            Minimum passing score is 70%.
                        </li>

                        <li>
                            You can retry the test if you do not pass.
                        </li>

                    </ul>

                </div>

                <a
                    href="certification.php"
                    class="green-button"
                >
                    Start Certification Test →
                </a>

            <?php endif; ?>

        <?php else: ?>

            <span class="training-status pending">
                Complete Compliance Training First
            </span>

        <?php endif; ?>

    </div>

</div>

<?php

render_shell_close();

?>