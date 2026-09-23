<?php


require_once __DIR__ . '/../db.php';


/*
============================================================
CHECK ADMIN LOGIN
============================================================
*/

if (!isset($_SESSION['admin_id'])) {

    header('Location: ' . BASE_URL . '/index.php');
    exit;

}


$success = '';
$error = '';


/*
============================================================
SAVE ADMIN VERIFICATION
============================================================
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['save_verification'])
) {

    $verification_id =
        (int) ($_POST['verification_id'] ?? 0);

    $reference_status =
        $_POST['reference_status'] ?? 'pending';

    $field_status =
        $_POST['field_status'] ?? 'pending';

    $remarks =
        trim($_POST['admin_remarks'] ?? '');


    /*
    --------------------------------------------------------
    OVERALL BACKGROUND STATUS
    --------------------------------------------------------
    */

    if (
        $reference_status === 'verified'
        &&
        $field_status === 'verified'
    ) {

        $background_status = 'verified';

    } else {

        $background_status = 'pending';

    }


    /*
    --------------------------------------------------------
    UPDATE VERIFICATION RECORD
    --------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        UPDATE agent_verification

        SET
            reference_status = ?,
            field_status = ?,
            background_status = ?,
            admin_remarks = ?

        WHERE id = ?
    ");


    $stmt->execute([

        $reference_status,

        $field_status,

        $background_status,

        $remarks,

        $verification_id

    ]);


    $success =
        'Verification updated successfully.';

}


/*
============================================================
GET SUBMITTED BACKGROUND VERIFICATIONS
============================================================
*/

$stmt = $pdo->query("
    SELECT
        av.id,
        av.agent_id,
        av.area,
        av.reference_name,
        av.reference_mobile,
        av.reference_relationship,
        av.document_type,
        av.verification_address,
        av.role,
        av.reference_status,
        av.field_status,
        av.background_status,
        av.admin_remarks,
        av.created_at,
        u.full_name

    FROM agent_verification av

    LEFT JOIN users u
        ON u.id = av.agent_id

    ORDER BY av.id DESC
");


$verifications =
    $stmt->fetchAll(PDO::FETCH_ASSOC);

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
        Background Verification | Admin
    </title>


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


        /*
        ====================================================
        HEADER
        ====================================================
        */

        .header {

            height: 70px;

            background: #ffffff;

            border-bottom:
                1px solid #e2e8f0;

            display: flex;

            align-items: center;

            justify-content: space-between;

            padding: 0 28px;

        }


        .logo {

            display: flex;

            align-items: center;

            gap: 10px;

        }


        .logo strong {

            font-size: 20px;

        }


        .logo span {

            display: block;

            font-size: 11px;

            color: #64748b;

        }


        .admin-actions {

            display: flex;

            align-items: center;

            gap: 15px;

        }


        .admin-name {

            font-size: 14px;

            font-weight: 600;

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


        /*
        ====================================================
        CONTAINER
        ====================================================
        */

        .container {

            max-width: 1050px;

            margin: 40px auto;

            padding: 0 25px;

        }


        h1 {

            margin: 0 0 8px;

            font-size: 27px;

        }


        .subtitle {

            margin: 0 0 25px;

            color: #64748b;

            font-size: 14px;

        }


        /*
        ====================================================
        SUCCESS
        ====================================================
        */

        .success {

            background: #dcfce7;

            color: #15803d;

            border:
                1px solid #86efac;

            padding: 11px 14px;

            border-radius: 8px;

            margin-bottom: 20px;

            font-size: 13px;

            font-weight: 600;

        }


        /*
        ====================================================
        VERIFICATION CARD
        ====================================================
        */

        .verification-card {

            background: #ffffff;

            border:
                1px solid #e2e8f0;

            border-radius: 14px;

            padding: 25px;

            margin-bottom: 25px;

            box-shadow:
                0 6px 20px
                rgba(15, 23, 42, 0.06);

        }


        .agent-header {

            display: flex;

            justify-content: space-between;

            align-items: center;

            margin-bottom: 20px;

            padding-bottom: 15px;

            border-bottom:
                1px solid #e2e8f0;

        }


        .agent-header h2 {

            margin: 0;

            font-size: 19px;

        }


        .agent-id {

            color: #64748b;

            font-size: 12px;

        }


        /*
        ====================================================
        DETAILS
        ====================================================
        */

        .details {

            display: grid;

            grid-template-columns:
                repeat(2, 1fr);

            gap: 15px 25px;

            margin-bottom: 25px;

        }


        .detail {

            font-size: 13px;

        }


        .detail strong {

            display: block;

            color: #64748b;

            font-size: 11px;

            margin-bottom: 4px;

            text-transform: uppercase;

        }


        .address {

            grid-column: 1 / -1;

        }


        /*
        ====================================================
        ADMIN FORM
        ====================================================
        */

        .verification-form {

            border-top:
                1px solid #e2e8f0;

            padding-top: 20px;

        }


        .form-row {

            display: grid;

            grid-template-columns:
                repeat(2, 1fr);

            gap: 20px;

        }


        label {

            display: block;

            font-size: 12px;

            font-weight: 700;

            margin-bottom: 6px;

            color: #172b4d;

        }


        select,
        textarea {

            width: 100%;

            padding: 10px;

            border:
                1px solid #cbd5e1;

            border-radius: 7px;

            font-size: 13px;

            background: #ffffff;

        }


        textarea {

            min-height: 75px;

            resize: vertical;

            margin-top: 15px;

        }


        .save-btn {

            margin-top: 15px;

            padding: 10px 18px;

            border: none;

            border-radius: 7px;

            background: #16a34a;

            color: #ffffff;

            font-size: 13px;

            font-weight: 700;

            cursor: pointer;

        }


        .save-btn:hover {

            background: #15803d;

        }


        .empty {

            background: #ffffff;

            border:
                1px solid #e2e8f0;

            border-radius: 12px;

            padding: 30px;

            text-align: center;

            color: #64748b;

        }


        @media (max-width: 700px) {

            .details,
            .form-row {

                grid-template-columns: 1fr;

            }

            .address {

                grid-column: auto;

            }

        }

    </style>

</head>


<body>


<header class="header">


    <div class="logo">

        <div>

            <strong>INDBIN</strong>

            <span>
                Admin Portal
            </span>

        </div>

    </div>


    <div class="admin-actions">

        <span class="admin-name">
            Admin
        </span>

        <a
            href="<?= BASE_URL ?>/logout.php"
            class="logout-btn"
        >
            Logout
        </a>

    </div>


</header>



<main class="container">


    <h1>
        Background Verification
    </h1>


    <p class="subtitle">
        Review agent reference and field verification details.
    </p>


    <?php if ($success): ?>

        <div class="success">

            <?= htmlspecialchars($success) ?>

        </div>

    <?php endif; ?>



    <?php if (empty($verifications)): ?>


        <div class="empty">

            No background verification submissions found.

        </div>


    <?php else: ?>


        <?php foreach ($verifications as $verification): ?>


            <div class="verification-card">


                <div class="agent-header">

                    <div>

                        <h2>

                            <?= htmlspecialchars(
                                $verification['full_name']
                                ?? 'Unknown Agent'
                            ) ?>

                        </h2>

                    </div>


                    <div class="agent-id">

                        Agent ID:
                        <?= (int) $verification['agent_id'] ?>

                    </div>

                </div>



                <div class="details">


                    <div class="detail">

                        <strong>
                            Area
                        </strong>

                        <?= htmlspecialchars(
                            $verification['area'] ?? '—'
                        ) ?>

                    </div>


                    <div class="detail">

                        <strong>
                            Role
                        </strong>

                        <?= htmlspecialchars(
                            $verification['role'] ?? 'Not assigned yet'
                        ) ?>

                    </div>


                    <div class="detail">

                        <strong>
                            Reference Name
                        </strong>

                        <?= htmlspecialchars(
                            $verification['reference_name']
                        ) ?>

                    </div>


                    <div class="detail">

                        <strong>
                            Reference Mobile
                        </strong>

                        <?= htmlspecialchars(
                            $verification['reference_mobile']
                        ) ?>

                    </div>


                    <div class="detail">

                        <strong>
                            Reference Relationship
                        </strong>

                        <?= htmlspecialchars(
                            $verification['reference_relationship']
                        ) ?>

                    </div>


                    <div class="detail">

                        <strong>
                            Document Type
                        </strong>

                        <?= htmlspecialchars(
                            $verification['document_type']
                        ) ?>

                    </div>


                    <div class="detail address">

                        <strong>
                            Field Verification Address
                        </strong>

                        <?= nl2br(
                            htmlspecialchars(
                                $verification['verification_address']
                            )
                        ) ?>

                    </div>


                </div>



                <form
                    method="POST"
                    class="verification-form"
                >


                    <input
                        type="hidden"
                        name="verification_id"
                        value="<?= (int) $verification['id'] ?>"
                    >


                    <div class="form-row">


                        <div>

                            <label>
                                Reference Check
                            </label>

                            <select
                                name="reference_status"
                            >

                                <option
                                    value="pending"
                                    <?= $verification['reference_status'] === 'pending'
                                        ? 'selected'
                                        : '' ?>
                                >
                                    Pending
                                </option>

                                <option
                                    value="verified"
                                    <?= $verification['reference_status'] === 'verified'
                                        ? 'selected'
                                        : '' ?>
                                >
                                    Verified
                                </option>

                            </select>

                        </div>



                        <div>

                            <label>
                                Field Verification
                            </label>

                            <select
                                name="field_status"
                            >

                                <option
                                    value="pending"
                                    <?= $verification['field_status'] === 'pending'
                                        ? 'selected'
                                        : '' ?>
                                >
                                    Pending
                                </option>

                                <option
                                    value="verified"
                                    <?= $verification['field_status'] === 'verified'
                                        ? 'selected'
                                        : '' ?>
                                >
                                    Verified
                                </option>

                            </select>

                        </div>


                    </div>



                    <label>
                        Admin Remarks
                    </label>


                    <textarea
                        name="admin_remarks"
                        placeholder="Enter admin remarks"
                    ><?= htmlspecialchars(
                        $verification['admin_remarks'] ?? ''
                    ) ?></textarea>



                    <button
                        type="submit"
                        name="save_verification"
                        class="save-btn"
                    >
                        Save Verification
                    </button>


                </form>


            </div>


        <?php endforeach; ?>


    <?php endif; ?>


</main>


</body>

</html>