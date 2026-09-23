<?php


require_once __DIR__ . '/../db.php';


/* =====================================================
   LOGIN CHECK
===================================================== */

if (!isset($_SESSION['user_id'])) {

    header('Location: ' . BASE_URL . '/index.php');

    exit;
}


$user_id = (int) $_SESSION['user_id'];


/* =====================================================
   GET AGENT NAME
===================================================== */

$full_name = 'Agent';

try {

    $stmt = $pdo->prepare("
        SELECT full_name
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([$user_id]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && !empty($user['full_name'])) {

        $full_name = $user['full_name'];
    }

} catch (Exception $e) {

    $full_name = 'Agent';
}

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Help & Support | INDBIN
    </title>


    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >


    <style>

        * {
            box-sizing: border-box;
        }


        body {

            margin: 0;

            padding: 0;

            font-family:
                Inter,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                Arial,
                sans-serif;

            background: #f4f7fb;

            color: #111827;
        }


        /* =====================================================
           HEADER
        ====================================================== */

        .header {

            height: 64px;

            background: #ffffff;

            border-bottom: 1px solid #e5e7eb;

            display: flex;

            align-items: center;

            justify-content: space-between;

            padding: 0 22px;
        }


        .brand {

            display: flex;

            align-items: center;

            gap: 11px;
        }


        .brand-icon {

            width: 38px;

            height: 38px;

            border-radius: 9px;

            background: #16a34a;

            color: #ffffff;

            display: flex;

            align-items: center;

            justify-content: center;

            font-size: 19px;
        }


        .brand-name {

            font-size: 18px;

            font-weight: 800;

            color: #111827;
        }


        .brand-subtitle {

            margin-top: 3px;

            font-size: 11px;

            color: #6b7280;
        }


        .user {

            display: flex;

            align-items: center;

            gap: 9px;
        }


        .avatar {

            width: 36px;

            height: 36px;

            border-radius: 50%;

            background: #dcfce7;

            color: #16a34a;

            display: flex;

            align-items: center;

            justify-content: center;

            font-size: 13px;

            font-weight: 800;
        }


        .user-name {

            font-size: 13px;

            font-weight: 700;

            color: #111827;
        }


        /* =====================================================
           PAGE
        ====================================================== */

        .page {

            min-height: calc(100vh - 64px);

            padding: 35px 25px;
        }


        .container {

            max-width: 900px;

            margin: 0 auto;
        }


        .back {

            display: inline-block;

            margin-bottom: 18px;

            color: #64748b;

            text-decoration: none;

            font-size: 13px;

            font-weight: 600;
        }


        .back:hover {

            color: #16a34a;
        }


        /* =====================================================
           HELP CARD
        ====================================================== */

        .help-card {

            background: #ffffff;

            border-radius: 12px;

            padding: 30px;

            box-shadow:
                0 6px 20px rgba(0,0,0,0.06);
        }


        .help-title {

            display: flex;

            align-items: center;

            gap: 12px;

            margin-bottom: 8px;
        }


        .help-title-icon {

            width: 42px;

            height: 42px;

            border-radius: 10px;

            background: #eaf8ee;

            color: #16a34a;

            display: flex;

            align-items: center;

            justify-content: center;

            font-size: 20px;
        }


        h1 {

            margin: 0;

            font-size: 24px;

            color: #111827;
        }


        .intro {

            color: #64748b;

            font-size: 14px;

            line-height: 1.6;

            margin: 0 0 25px;
        }


        /* =====================================================
           OPTIONS
        ====================================================== */

        .options {

            display: grid;

            grid-template-columns:
                repeat(3, 1fr);

            gap: 15px;

            margin-bottom: 28px;
        }


        .option {

            border: 1px solid #e5e7eb;

            border-radius: 10px;

            padding: 20px;

            background: #ffffff;
        }


        .option-icon {

            width: 38px;

            height: 38px;

            border-radius: 8px;

            background: #f1f5f9;

            color: #16a34a;

            display: flex;

            align-items: center;

            justify-content: center;

            margin-bottom: 12px;
        }


        .option h3 {

            margin: 0 0 6px;

            font-size: 14px;

            color: #111827;
        }


        .option p {

            margin: 0;

            font-size: 12px;

            color: #64748b;

            line-height: 1.5;
        }


        /* =====================================================
           FAQ
        ====================================================== */

        .section-title {

            font-size: 16px;

            font-weight: 800;

            color: #111827;

            margin-bottom: 14px;
        }


        .faq {

            border: 1px solid #e5e7eb;

            border-radius: 8px;

            margin-bottom: 10px;

            overflow: hidden;
        }


        .faq-question {

            padding: 14px 16px;

            font-size: 13px;

            font-weight: 700;

            color: #111827;

            background: #f8fafc;
        }


        .faq-answer {

            padding: 14px 16px;

            font-size: 12px;

            color: #64748b;

            line-height: 1.6;

            background: #ffffff;
        }


        /* =====================================================
           CONTACT
        ====================================================== */

        .contact {

            margin-top: 25px;

            padding: 20px;

            border-radius: 10px;

            background: #f8fafc;

            border: 1px solid #e5e7eb;
        }


        .contact-title {

            font-size: 14px;

            font-weight: 800;

            margin-bottom: 10px;
        }


        .contact-item {

            font-size: 13px;

            color: #475569;

            margin-bottom: 7px;
        }


        .contact-item i {

            width: 20px;

            color: #16a34a;
        }


        /* =====================================================
           RESPONSIVE
        ====================================================== */

        @media (max-width: 700px) {

            .options {

                grid-template-columns: 1fr;
            }


            .help-card {

                padding: 22px;
            }


            .page {

                padding: 20px 15px;
            }

        }

    </style>

</head>


<body>


<!-- =====================================================
     HEADER
====================================================== -->

<header class="header">


    <div class="brand">

        <div class="brand-icon">

            <i class="fa-solid fa-user-group"></i>

        </div>


        <div>

            <div class="brand-name">
                INDBIN
            </div>


            <div class="brand-subtitle">
                Agent Portal
            </div>

        </div>

    </div>


    <div class="user">

        <div class="avatar">

            <?= strtoupper(
                substr(
                    trim($full_name),
                    0,
                    1
                )
            ) ?>

        </div>


        <div class="user-name">

            <?= htmlspecialchars($full_name) ?>

        </div>

    </div>

</header>


<!-- =====================================================
     HELP PAGE
====================================================== -->

<div class="page">

    <div class="container">


        <a
            href="javascript:history.back()"
            class="back"
        >
            ← Back
        </a>


        <div class="help-card">


            <!-- TITLE -->

            <div class="help-title">

                <div class="help-title-icon">

                    <i class="fa-regular fa-circle-question"></i>

                </div>


                <h1>
                    Help & Support
                </h1>

            </div>


            <p class="intro">

                Need help with your Agent Portal?
                Find answers to common questions or
                contact our support team.

            </p>


            <!-- SUPPORT OPTIONS -->

            <div class="options">


                <div class="option">

                    <div class="option-icon">

                        <i class="fa-solid fa-comments"></i>

                    </div>


                    <h3>
                        Support
                    </h3>


                    <p>

                        Get assistance with your Agent Portal
                        and onboarding process.

                    </p>

                </div>


                <div class="option">

                    <div class="option-icon">

                        <i class="fa-solid fa-phone"></i>

                    </div>


                    <h3>
                        Contact Support
                    </h3>


                    <p>

                        Call our support team for assistance
                        with your account.

                    </p>

                </div>


                <div class="option">

                    <div class="option-icon">

                        <i class="fa-solid fa-circle-question"></i>

                    </div>


                    <h3>
                        Frequently Asked Questions
                    </h3>


                    <p>

                        Find answers to common Agent Portal
                        questions.

                    </p>

                </div>


            </div>


            <!-- FAQ -->

            <div class="section-title">

                Frequently Asked Questions

            </div>


            <div class="faq">

                <div class="faq-question">

                    How do I complete my Agent onboarding?

                </div>


                <div class="faq-answer">

                    Complete each step shown in the
                    Agent Onboarding section. Once a step
                    is completed, continue to the next step.

                </div>

            </div>


            <div class="faq">

                <div class="faq-question">

                    How can I check my verification status?

                </div>


                <div class="faq-answer">

                    Your verification status can be checked
                    from the Background & Verification step.

                </div>

            </div>


            <div class="faq">

                <div class="faq-question">

                    Where can I find my commission information?

                </div>


                <div class="faq-answer">

                    Your commission information is available
                    through the Wallet & Commission section
                    and Agent Dashboard.

                </div>

            </div>


            <div class="faq">

                <div class="faq-question">

                    What should I do if I face a problem?

                </div>


                <div class="faq-answer">

                    If you are unable to continue with the
                    onboarding process, contact Agent Support
                    using the details below.

                </div>

            </div>


            <!-- CONTACT -->

            <div class="contact">

                <div class="contact-title">

                    Contact Agent Support

                </div>


                <div class="contact-item">

                    <i class="fa-solid fa-phone"></i>

                    +91 80 4950 8282

                </div>


                <div class="contact-item">

                    <i class="fa-solid fa-envelope"></i>

                    support@indbin.com

                </div>

            </div>


        </div>

    </div>

</div>


</body>

</html>