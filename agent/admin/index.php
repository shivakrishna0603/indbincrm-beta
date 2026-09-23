<?php


require_once __DIR__ . '/../db.php';


if (!isset($_SESSION['admin_id'])) {

    header('Location: ' . BASE_URL . '/index.php');
    exit;

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

    <title>Admin Dashboard | INDBIN</title>

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"
    >

    <style>

        * {
            box-sizing: border-box;
        }

        body {

            margin: 0;

            font-family:
                Arial,
                sans-serif;

            background: #f4f7fb;

            color: #172b4d;

        }

        .header {

            height: 70px;

            background: #ffffff;

            border-bottom:
                1px solid #e2e8f0;

            display: flex;

            align-items: center;

            justify-content: space-between;

            padding:
                0 28px;

        }

        .logo {

            display: flex;

            align-items: center;

            gap: 10px;

        }

        .logo i {

            color: #16a34a;

            font-size: 25px;

        }

        .logo strong {

            font-size: 20px;

        }

        .logo span {

            display: block;

            font-size: 11px;

            color: #64748b;

        }

        /* ADMIN NAME + LOGOUT */

        .admin-actions {

            display: flex;

            align-items: center;

            gap: 15px;

        }
        .logout-btn {

    padding: 8px 15px;

    background: #dc2626;

    color: #ffffff;

    text-decoration: none;

    border-radius: 7px;

    font-size: 13px;

    font-weight: 700;

}

.logout-btn:hover {

    background: #b91c1c;

}

        .admin-name {

            font-weight: 600;

            font-size: 14px;

        }

        .logout-btn {

            padding:
                8px 15px;

            background: #dc2626;

            color: #ffffff;

            text-decoration: none;

            border-radius: 7px;

            font-size: 13px;

            font-weight: 700;

        }

        .logout-btn:hover {

            background: #b91c1c;

        }

        .container {

            max-width: 1000px;

            margin: 50px auto;

            padding: 0 25px;

        }

        .page-title {

            margin-bottom: 30px;

        }

        .page-title h1 {

            margin: 0;

            font-size: 28px;

        }

        .page-title p {

            margin-top: 8px;

            color: #64748b;

        }

        .cards {

            display: grid;

            grid-template-columns:
                repeat(2, 1fr);

            gap: 25px;

        }

        .card {

            background: #ffffff;

            border:
                1px solid #e2e8f0;

            border-radius: 14px;

            padding: 30px;

            box-shadow:
                0 8px 25px
                rgba(15, 23, 42, 0.06);

        }

        .card-icon {

            width: 55px;

            height: 55px;

            border-radius: 12px;

            background: #dcfce7;

            color: #16a34a;

            display: flex;

            align-items: center;

            justify-content: center;

            font-size: 23px;

            margin-bottom: 20px;

        }

        .card h2 {

            margin:
                0 0 10px;

            font-size: 20px;

        }

        .card p {

            color: #64748b;

            font-size: 14px;

            line-height: 1.6;

            min-height: 48px;

        }

        .card a {

            display: inline-block;

            margin-top: 15px;

            padding:
                11px 18px;

            background: #16a34a;

            color: white;

            text-decoration: none;

            border-radius: 7px;

            font-size: 13px;

            font-weight: 700;

        }

        .card a:hover {

            background: #15803d;

        }

        @media (max-width: 700px) {

            .cards {

                grid-template-columns: 1fr;

            }

        }

    </style>

</head>


<body>


<header class="header">

    <div class="logo">

        <i class="fa-solid fa-user-shield"></i>

        <div>

            <strong>INDBIN</strong>

            <span>Admin Portal</span>

        </div>

    </div>


    <div class="admin-actions">

        <span class="admin-name">
            Admin
        </span>

        <a href="<?= BASE_URL ?>/logout.php" class="logout-btn">
            Logout
        </a>

    </div>

</header>



<main class="container">


    <div class="page-title">

        <h1>
            Admin Dashboard
        </h1>

        <p>
            Select a verification module to review agent applications.
        </p>

    </div>



    <div class="cards">


        <!-- KYC REVIEW -->

        <div class="card">

            <div class="card-icon">

                <i class="fa-solid fa-id-card"></i>

            </div>


            <h2>
                KYC Review
            </h2>


            <p>

                Review agent KYC documents,
                verify the submitted information,
                and approve or reject the KYC.

            </p>


            <a href="admin_review.php">

                Open KYC Review →

            </a>

        </div>



        <!-- BACKGROUND VERIFICATION -->

        <div class="card">

            <div class="card-icon">

                <i class="fa-solid fa-user-check"></i>

            </div>


            <h2>
                Background Verification
            </h2>


            <p>

                Review reference and field
                verification details submitted
                by agents.

            </p>


            <a href="background_verification.php">

                Open Background Verification →

            </a>

        </div>


    </div>


</main>


</body>

</html>