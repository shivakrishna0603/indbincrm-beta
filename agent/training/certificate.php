<?php

require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];

/* =========================================================
   GET USER DETAILS
   ========================================================= */
$stmt = $pdo->prepare("
    SELECT id, full_name
    FROM users
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die('User not found.');
}

$agent_name = $user['full_name'];

/* =========================================================
   GET TRAINING DETAILS
   ========================================================= */
$stmt = $pdo->prepare("
    SELECT certification_score, certification_test
    FROM agent_training
    WHERE user_id = ?
    LIMIT 1
");
$stmt->execute([$user_id]);
$training = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$training || $training['certification_test'] !== 'completed') {
    header('Location: certification.php');
    exit;
}

$score = (int)$training['certification_score'];

/* =========================================================
   CERTIFICATE DETAILS
   ========================================================= */
$certificate_number = 'INDBIN-' . date('Y') . '-' . str_pad($user_id, 6, '0', STR_PAD_LEFT);
$certificate_date = date('d F Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Certificate of Completion - INDBIN</title>

<style>

/* =========================================================
   BASIC RESET
   ========================================================= */

* {
    box-sizing: border-box;
}

html,
body {
    margin: 0;
    padding: 0;
}

body {
    font-family: Arial, Helvetica, sans-serif;
    background: #f3f5f7;
    color: #222;
}


/* =========================================================
   PAGE
   ========================================================= */

.page {
    min-height: 100vh;
    padding: 25px 20px 35px;
}


/* =========================================================
   TOP HEADING
   ========================================================= */

.page-heading {
    text-align: center;
    margin-bottom: 15px;
}

.page-heading h2 {
    margin: 0;
    font-size: 20px;
    font-weight: 700;
    color: #1f2937;
}

.page-heading p {
    margin: 5px 0 0;
    font-size: 13px;
    color: #6b7280;
}


/* =========================================================
   CERTIFICATE WRAPPER
   ========================================================= */

.certificate-wrapper {
    width: 100%;
    display: flex;
    justify-content: center;
    align-items: center;
}


/* =========================================================
   CERTIFICATE
   A4 LANDSCAPE RATIO = 297 / 210
   SCREEN SIZE REDUCED
   ========================================================= */

.certificate {
    width: 900px;
    height: 636px;

    max-width: 90vw;
    max-height: 75vh;

    aspect-ratio: 297 / 210;

    position: relative;

    background: #fffdf8;

    border: 1px solid #d6d0c2;

    box-shadow:
        0 12px 35px rgba(0, 0, 0, 0.12);

    overflow: hidden;
}


/* =========================================================
   INNER BORDER
   ========================================================= */

.certificate::before {
    content: "";
    position: absolute;

    left: 18px;
    right: 18px;
    top: 18px;
    bottom: 18px;

    border: 1px solid #cfc7b5;

    pointer-events: none;
}


/* =========================================================
   GOLD DECORATIVE LINES
   ========================================================= */

.top-line {
    position: absolute;
    left: 70px;
    right: 70px;
    top: 42px;

    height: 2px;

    background: #b99a55;
}

.bottom-line {
    position: absolute;
    left: 70px;
    right: 70px;
    bottom: 42px;

    height: 2px;

    background: #b99a55;
}


/* =========================================================
   SIDE DECORATION
   ========================================================= */

.left-decoration,
.right-decoration {
    position: absolute;
    top: 100px;
    bottom: 100px;

    width: 2px;

    background: #b99a55;
}

.left-decoration {
    left: 43px;
}

.right-decoration {
    right: 43px;
}


/* =========================================================
   TOP BRAND
   ========================================================= */

.brand {
    position: absolute;

    top: 58px;
    left: 0;
    right: 0;

    text-align: center;
}

.brand-name {
    font-size: 25px;
    font-weight: 800;
    letter-spacing: 4px;
    color: #15803d;
}

.brand-subtitle {
    margin-top: 3px;

    font-size: 10px;
    letter-spacing: 2px;
    color: #777;
    text-transform: uppercase;
}


/* =========================================================
   MAIN CONTENT
   ========================================================= */

.certificate-content {
    position: absolute;

    left: 75px;
    right: 75px;

    top: 135px;
    bottom: 110px;

    text-align: center;
}


/* =========================================================
   CERTIFICATE TITLE
   ========================================================= */

.certificate-title {
    margin: 0;

    font-family: Georgia, "Times New Roman", serif;

    font-size: 38px;
    font-weight: 700;

    letter-spacing: 2px;

    color: #222;
}

.title-line {
    width: 180px;
    height: 1px;

    margin: 12px auto 16px;

    background: #b99a55;
}


/* =========================================================
   PRESENTED TO
   ========================================================= */

.presented {
    margin: 0 0 7px;

    font-size: 11px;
    letter-spacing: 2px;

    color: #777;

    text-transform: uppercase;
}


/* =========================================================
   AGENT NAME
   ========================================================= */

.agent-name {
    margin: 0;

    font-family: Georgia, "Times New Roman", serif;

    font-size: 36px;
    font-weight: 700;

    color: #1f2937;

    line-height: 1.15;
}


/* =========================================================
   NAME UNDERLINE
   ========================================================= */

.name-line {
    width: 280px;
    max-width: 80%;

    height: 1px;

    margin: 12px auto 16px;

    background: #c8bfae;
}


/* =========================================================
   DESCRIPTION
   ========================================================= */

.description {
    max-width: 650px;

    margin: 0 auto;

    font-family: Georgia, "Times New Roman", serif;

    font-size: 15px;
    line-height: 1.6;

    color: #4b5563;
}

.description strong {
    color: #222;
}


/* =========================================================
   PROGRAM NAME
   ========================================================= */

.program-name {
    margin-top: 10px;

    font-size: 14px;
    font-weight: 700;

    letter-spacing: 0.5px;

    color: #15803d;
}


/* =========================================================
   SEAL
   ========================================================= */

.seal {
    position: absolute;

    right: 78px;
    bottom: 108px;

    width: 72px;
    height: 72px;

    border: 2px solid #b99a55;
    border-radius: 50%;

    display: flex;
    align-items: center;
    justify-content: center;

    text-align: center;

    color: #8d743d;

    font-size: 8px;
    font-weight: 700;

    letter-spacing: 1px;

    transform: rotate(-8deg);
}

.seal-inner {
    width: 56px;
    height: 56px;

    border: 1px solid #b99a55;
    border-radius: 50%;

    display: flex;
    align-items: center;
    justify-content: center;
}


/* =========================================================
   FOOTER DETAILS
   ========================================================= */

.certificate-footer {
    position: absolute;

    left: 75px;
    right: 75px;
    bottom: 55px;

    display: grid;

    grid-template-columns: 1fr 1fr 1fr;

    align-items: end;

    text-align: center;
}


/* =========================================================
   FOOTER ITEMS
   ========================================================= */

.footer-item {
    min-height: 48px;
}

.footer-label {
    font-size: 9px;

    color: #777;

    text-transform: uppercase;

    letter-spacing: 1px;

    margin-bottom: 5px;
}

.footer-value {
    font-size: 11px;

    color: #333;

    font-weight: 600;
}


/* =========================================================
   SIGNATURE
   ========================================================= */

.signature-line {
    width: 130px;

    height: 1px;

    background: #555;

    margin: 0 auto 5px;
}

.signature-name {
    font-family: Georgia, "Times New Roman", serif;

    font-size: 11px;

    font-weight: 700;

    color: #333;
}


/* =========================================================
   SCORE
   ========================================================= */

.score {
    color: #15803d;
    font-weight: 700;
}


/* =========================================================
   ACTION BUTTONS
   ========================================================= */

.actions {
    display: flex;

    justify-content: center;

    align-items: center;

    gap: 10px;

    margin-top: 20px;

    flex-wrap: wrap;
}

.actions a,
.actions button {
    border: none;

    padding: 10px 18px;

    border-radius: 6px;

    font-size: 13px;

    font-weight: 600;

    text-decoration: none;

    cursor: pointer;

    background: #15803d;

    color: white;

    transition: 0.2s;
}

.actions a:hover,
.actions button:hover {
    background: #166534;
}


/* =========================================================
   RESPONSIVE SCREEN SIZE
   ========================================================= */

@media (max-width: 1000px) {

    .page {
        padding: 18px 12px 25px;
    }

    .certificate {
        width: 90vw;
        height: auto;

        aspect-ratio: 297 / 210;

        max-height: none;
    }

    .certificate-title {
        font-size: clamp(25px, 4vw, 38px);
    }

    .agent-name {
        font-size: clamp(24px, 3.5vw, 36px);
    }

    .description {
        font-size: clamp(10px, 1.5vw, 15px);
    }
}


/* =========================================================
   PRINT / SAVE AS PDF
   ========================================================= */

@media print {

    @page {
        size: A4 landscape;
        margin: 0;
    }

    html,
    body {
        width: 297mm;
        height: 210mm;

        margin: 0;
        padding: 0;

        background: white;
    }

    body {
        overflow: hidden;
    }

    .page {
        width: 297mm;
        height: 210mm;

        min-height: 0;

        padding: 0;

        margin: 0;
    }

    .page-heading,
    .actions {
        display: none !important;
    }

    .certificate-wrapper {
        width: 297mm;
        height: 210mm;

        display: block;

        margin: 0;
        padding: 0;
    }

    .certificate {
        width: 297mm;
        height: 210mm;

        max-width: none;
        max-height: none;

        margin: 0;

        border: none;

        box-shadow: none;

        aspect-ratio: auto;
    }

    .certificate::before {
        left: 5mm;
        right: 5mm;
        top: 5mm;
        bottom: 5mm;
    }

    .top-line {
        left: 18mm;
        right: 18mm;
        top: 12mm;
    }

    .bottom-line {
        left: 18mm;
        right: 18mm;
        bottom: 12mm;
    }

    .left-decoration {
        left: 12mm;
        top: 30mm;
        bottom: 30mm;
    }

    .right-decoration {
        right: 12mm;
        top: 30mm;
        bottom: 30mm;
    }

    .brand {
        top: 17mm;
    }

    .brand-name {
        font-size: 25pt;
    }

    .brand-subtitle {
        font-size: 8pt;
    }

    .certificate-content {
        left: 25mm;
        right: 25mm;

        top: 42mm;
        bottom: 35mm;
    }

    .certificate-title {
        font-size: 30pt;
    }

    .title-line {
        width: 50mm;
        margin: 4mm auto;
    }

    .presented {
        font-size: 8pt;
    }

    .agent-name {
        font-size: 27pt;
    }

    .name-line {
        width: 70mm;
        margin: 3mm auto 4mm;
    }

    .description {
        max-width: 180mm;

        font-size: 11pt;

        line-height: 1.5;
    }

    .program-name {
        font-size: 10pt;
    }

    .seal {
        right: 25mm;
        bottom: 28mm;

        width: 20mm;
        height: 20mm;
    }

    .seal-inner {
        width: 16mm;
        height: 16mm;
    }

    .certificate-footer {
        left: 25mm;
        right: 25mm;

        bottom: 15mm;
    }

    .footer-label {
        font-size: 6.5pt;
    }

    .footer-value {
        font-size: 8pt;
    }

    .signature-line {
        width: 35mm;
    }

    .signature-name {
        font-size: 8pt;
    }
}

</style>
</head>


<body>

<div class="page">

    <!-- =====================================================
         PAGE HEADING
         ===================================================== -->

    <div class="page-heading">
        <h2>Training Certificate</h2>
        <p>Your professional INDBIN training completion certificate</p>
    </div>


    <!-- =====================================================
         CERTIFICATE
         ===================================================== -->

    <div class="certificate-wrapper">

        <div class="certificate">

            <!-- Decorative elements -->
            <div class="top-line"></div>
            <div class="bottom-line"></div>

            <div class="left-decoration"></div>
            <div class="right-decoration"></div>


            <!-- =================================================
                 BRAND
                 ================================================= -->

            <div class="brand">

                <div class="brand-name">
                    INDBIN
                </div>

                <div class="brand-subtitle">
                    Agent Network
                </div>

            </div>


            <!-- =================================================
                 MAIN CONTENT
                 ================================================= -->

            <div class="certificate-content">

                <h1 class="certificate-title">
                    CERTIFICATE OF COMPLETION
                </h1>

                <div class="title-line"></div>


                <p class="presented">
                    Presented to
                </p>


                <h2 class="agent-name">
                    <?= htmlspecialchars($agent_name) ?>
                </h2>


                <div class="name-line"></div>


                <p class="description">
                    This certificate is proudly presented in recognition of
                    successful completion of the
                    <strong>INDBIN Agent Training &amp; Certification Program</strong>,
                    demonstrating the required knowledge of product understanding,
                    customer handling, compliance and professional conduct.
                </p>


                <div class="program-name">
                    AGENT TRAINING &amp; CERTIFICATION PROGRAM
                </div>

            </div>


            <!-- =================================================
                 SEAL
                 ================================================= -->

            <div class="seal">

                <div class="seal-inner">
                    INDBIN<br>
                    CERTIFIED
                </div>

            </div>


            <!-- =================================================
                 FOOTER
                 ================================================= -->

            <div class="certificate-footer">

                <!-- Certificate Number -->

                <div class="footer-item">

                    <div class="footer-label">
                        Certificate No.
                    </div>

                    <div class="footer-value">
                        <?= htmlspecialchars($certificate_number) ?>
                    </div>

                </div>


                <!-- Authorization -->

                <div class="footer-item">

                    <div class="signature-line"></div>

                    <div class="signature-name">
                        Authorized Representative
                    </div>

                    <div class="footer-label" style="margin-top: 4px;">
                        INDBIN
                    </div>

                </div>


                <!-- Date -->

                <div class="footer-item">

                    <div class="footer-label">
                        Date of Completion
                    </div>

                    <div class="footer-value">
                        <?= htmlspecialchars($certificate_date) ?>
                    </div>

                    <div class="footer-label" style="margin-top: 4px;">
                        Score:
                        <span class="score">
                            <?= $score ?>%
                        </span>
                    </div>

                </div>

            </div>

        </div>

    </div>


    <!-- =====================================================
         ACTION BUTTONS
         ===================================================== -->

    <div class="actions">

        <button type="button" onclick="window.print();">
            Print / Save Certificate
        </button>

        <a href="index.php">
            ← Back to Training
        </a>

        <a href="../hierarchy/index.php">
            Continue to Hierarchy &amp; Code Creation →
        </a>

    </div>

</div>

</body>
</html>