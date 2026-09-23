<?php

require_once __DIR__ . '/../db.php';


/* ---------------------------------------------------------
   LOGIN CHECK
--------------------------------------------------------- */

if (!isset($_SESSION['user_id'])) {

    header('Location: ' . BASE_URL . '/index.php');

    exit;

}

$user_id = (int)$_SESSION['user_id'];


/* ---------------------------------------------------------
   TRAINING & CERTIFICATION CHECK
--------------------------------------------------------- */

$trainingStmt = $pdo->prepare("
    SELECT
        product_training,
        compliance_training,
        certification_test
    FROM agent_training
    WHERE user_id = ?
    LIMIT 1
");

$trainingStmt->execute([$user_id]);

$training = $trainingStmt->fetch(PDO::FETCH_ASSOC);

if (
    !$training ||
    $training['product_training'] !== 'completed' ||
    $training['compliance_training'] !== 'completed' ||
    $training['certification_test'] !== 'completed'
) {

    header('Location: ../training/index.php');

    exit;

}


/* ---------------------------------------------------------
   HIERARCHY CHECK
--------------------------------------------------------- */

$agentStmt = $pdo->prepare("
    SELECT
        agent_id,
        referral_code,
        email
    FROM agent_hierarchy
    WHERE user_id = ?
    LIMIT 1
");

$agentStmt->execute([$user_id]);

$agent = $agentStmt->fetch(PDO::FETCH_ASSOC);

if (!$agent) {

    header('Location: ' . BASE_URL . '/agent/hierarchy/index.php');

    exit;

}

$agent_id = trim((string)($agent['agent_id'] ?? ''));

$referral_code = trim(
    (string)($agent['referral_code'] ?? '')
);

$email = (string)($agent['email'] ?? '');


if ($agent_id === '' || $referral_code === '') {

    header('Location: ' . BASE_URL . '/agent/hierarchy/index.php');

    exit;

}


/* ---------------------------------------------------------
   CREATE PAYOUT TABLE IF IT DOES NOT EXIST
--------------------------------------------------------- */

$pdo->exec("
    CREATE TABLE IF NOT EXISTS agent_commission_payout (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL UNIQUE,
        commission_type VARCHAR(50) NOT NULL DEFAULT 'Percentage',
        commission_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        account_holder VARCHAR(150) NOT NULL DEFAULT '',
        account_number VARCHAR(100) NOT NULL DEFAULT '',
        bank_name VARCHAR(150) NOT NULL DEFAULT '',
        ifsc_code VARCHAR(30) NOT NULL DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ON UPDATE CURRENT_TIMESTAMP
    )
");


/* ---------------------------------------------------------
   GET EXISTING PAYOUT DETAILS
--------------------------------------------------------- */

$payoutStmt = $pdo->prepare("
    SELECT *
    FROM agent_commission_payout
    WHERE user_id = ?
    LIMIT 1
");

$payoutStmt->execute([$user_id]);

$payout = $payoutStmt->fetch(PDO::FETCH_ASSOC) ?: [];


/* ---------------------------------------------------------
   DEFAULT VALUES
--------------------------------------------------------- */

$message = '';

$error = '';

$commission_type = 'Percentage';

$commission_rate =
    $payout['commission_rate'] ?? '';

$account_holder =
    $payout['account_holder'] ?? '';

$account_number =
    $payout['account_number'] ?? '';

$bank_name =
    $payout['bank_name'] ?? '';

$ifsc_code =
    $payout['ifsc_code'] ?? '';


/* ---------------------------------------------------------
   SAVE PAYOUT DETAILS
--------------------------------------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /*
     * Commission percentage is controlled by
     * the Admin/Company.
     *
     * The agent only provides payout/bank details.
     */

    $account_holder =
        trim($_POST['account_holder'] ?? '');

    $account_number =
        trim($_POST['account_number'] ?? '');

    $bank_name =
        trim($_POST['bank_name'] ?? '');

    $ifsc_code =
        strtoupper(
            trim($_POST['ifsc_code'] ?? '')
        );


    /* -----------------------------------------------------
       VALIDATION
    ----------------------------------------------------- */

    if (
        $account_holder === '' ||
        $account_number === '' ||
        $bank_name === '' ||
        $ifsc_code === ''
    ) {

        $error =
            'Please complete all bank and payout details.';

    } else {

        /*
        -----------------------------------------------------
        SAVE AGENT PAYOUT DETAILS

        The commission rate is NOT changed here.
        Any existing Admin/Company commission remains intact.
        -----------------------------------------------------
        */

        $save = $pdo->prepare("
            INSERT INTO agent_commission_payout
            (
                user_id,
                commission_type,
                account_holder,
                account_number,
                bank_name,
                ifsc_code
            )
            VALUES
            (?, 'Percentage', ?, ?, ?, ?)

            ON DUPLICATE KEY UPDATE
                account_holder = VALUES(account_holder),
                account_number = VALUES(account_number),
                bank_name      = VALUES(bank_name),
                ifsc_code      = VALUES(ifsc_code)
        ");

        $save->execute([
            $user_id,
            $account_holder,
            $account_number,
            $bank_name,
            $ifsc_code
        ]);


        $message =
            'Payout details saved successfully.';


        /*
        -----------------------------------------------------
        RELOAD PAYOUT DETAILS
        -----------------------------------------------------
        */

        $payoutStmt->execute([$user_id]);

        $payout =
            $payoutStmt->fetch(PDO::FETCH_ASSOC) ?: [];


        $commission_type =
            $payout['commission_type']
            ?? 'Percentage';

        $commission_rate =
            $payout['commission_rate']
            ?? '';

        $account_holder =
            $payout['account_holder']
            ?? '';

        $account_number =
            $payout['account_number']
            ?? '';

        $bank_name =
            $payout['bank_name']
            ?? '';

        $ifsc_code =
            $payout['ifsc_code']
            ?? '';

    }

}


/* ---------------------------------------------------------
   LOAD SHARED AGENT PORTAL SHELL
--------------------------------------------------------- */

require_once __DIR__ . '/../portal/shell.php';


render_shell_open(
    $pdo,
    $user_id,
    'wallet',
    'Wallet & Commission Setup'
);

?>


<style>

/* =========================================================
   WALLET PAGE ALIGNMENT
========================================================= */

.container {
    width: 100%;
    max-width: 680px;
    margin: 0 auto;
    padding: 10px 20px 40px;
}


/* Main heading */

.container > h1 {
    width: 100%;
    text-align: center;
    margin: 0 0 8px;
}


/* Subtitle */

.container > .subtitle {
    width: 100%;
    text-align: center;
    margin: 0 auto 24px;
}


/* Cards */

.container > .card {
    width: 100%;
    margin: 0 auto 16px;
    box-sizing: border-box;
}


/* Success message */

.container > .success {
    width: 100%;
    text-align: center;
    margin: 10px auto 12px;
    font-weight: 700;
}


/* Error message */

.container > .error {
    width: 100%;
    text-align: center;
    margin: 10px auto 12px;
    font-weight: 700;
}


/* Card headings */

.container > .card h2 {
    margin-top: 0;
}


/* Form */

.container > .card form {
    width: 100%;
}


/* Form fields */

.container > .card input,
.container > .card .fixed-field {
    width: 100%;
    box-sizing: border-box;
}


/* Commission information */

.commission-info {
    width: 100%;
    box-sizing: border-box;
    padding: 13px 14px;
    border: 1px solid #d9e2ec;
    border-radius: 8px;
    background: #f7f9fb;
    color: #344054;
    margin-bottom: 18px;
}


/* Small Admin information text */

.commission-note {
    margin: -8px 0 18px;
    font-size: 13px;
    color: #667085;
}


/* Save button
   No custom green color here.
   Uses the existing portal button styling.
*/

.container > .card button {
    width: 100%;
}


/* Next section */

.container > .card.next {
    width: 100%;
    text-align: center;
}


/* Mobile */

@media (max-width: 700px) {

    .container {
        max-width: 100%;
        padding: 10px 15px 30px;
    }

}

</style>


<main class="container">


    <!-- =====================================================
         PAGE TITLE
    ====================================================== -->

    <h1>
        Wallet &amp; Commission
    </h1>

    <p class="subtitle">
        Agent payout and commission details
    </p>


    <!-- =====================================================
         AGENT DETAILS
    ====================================================== -->

    <section class="card">

        <h2>
            Agent Details
        </h2>

        <div class="details">


            <div class="detail">

                <strong>
                    Agent ID
                </strong>

                <span class="value">
                    <?= htmlspecialchars($agent_id) ?>
                </span>

            </div>


            <div class="detail">

                <strong>
                    Referral Code
                </strong>

                <span class="value">
                    <?= htmlspecialchars($referral_code) ?>
                </span>

            </div>


            <div class="detail">

                <strong>
                    Email
                </strong>

                <?= htmlspecialchars($email) ?>

            </div>


        </div>

    </section>


    <!-- =====================================================
         SUCCESS MESSAGE
    ====================================================== -->

    <?php if ($message !== ''): ?>

        <div class="success">

            <?= htmlspecialchars($message) ?>

        </div>

    <?php endif; ?>


    <!-- =====================================================
         ERROR MESSAGE
    ====================================================== -->

    <?php if ($error !== ''): ?>

        <div class="error">

            <?= htmlspecialchars($error) ?>

        </div>

    <?php endif; ?>


    <!-- =====================================================
         COMMISSION & PAYOUT
    ====================================================== -->

    <section class="card">

        <h2>
            Commission &amp; Payout
        </h2>


        <form method="post">


            <!-- =================================================
                 COMMISSION TYPE
            ================================================== -->

            <label>
                Commission Type
            </label>

            <div class="fixed-field">
                Percentage (%)
            </div>


            <!-- =================================================
                 COMMISSION PERCENTAGE
            ================================================== -->

            <label>
                Commission Percentage (%)
            </label>


            <?php if (
                $commission_rate !== '' &&
                is_numeric($commission_rate) &&
                (float)$commission_rate > 0
            ): ?>

                <div class="commission-info">

                    <strong>
                        <?= htmlspecialchars(
                            (string)$commission_rate
                        ) ?>%
                    </strong>

                    &nbsp; — Assigned by Admin/Company

                </div>

            <?php else: ?>

                <div class="commission-info">

                    <strong>
                        Not assigned yet
                    </strong>

                    &nbsp; — Admin/Company will assign the commission rate.

                </div>

            <?php endif; ?>


            <p class="commission-note">

                The commission percentage is set by the
                Admin/Company and cannot be changed here.

            </p>


            <!-- =================================================
                 ACCOUNT HOLDER
            ================================================== -->

            <label for="account_holder">

                Account Holder Name

            </label>

            <input
                id="account_holder"
                type="text"
                name="account_holder"
                value="<?= htmlspecialchars(
                    (string)$account_holder
                ) ?>"
                placeholder="Enter account holder name"
                required
            >


            <!-- =================================================
                 ACCOUNT NUMBER
            ================================================== -->

            <label for="account_number">

                Account Number

            </label>

            <input
                id="account_number"
                type="text"
                name="account_number"
                value="<?= htmlspecialchars(
                    (string)$account_number
                ) ?>"
                placeholder="Enter bank account number"
                required
            >


            <!-- =================================================
                 BANK NAME
            ================================================== -->

            <label for="bank_name">

                Bank Name

            </label>

            <input
                id="bank_name"
                type="text"
                name="bank_name"
                value="<?= htmlspecialchars(
                    (string)$bank_name
                ) ?>"
                placeholder="Enter bank name"
                required
            >


            <!-- =================================================
                 IFSC CODE
            ================================================== -->

            <label for="ifsc_code">

                IFSC Code

            </label>

            <input
                id="ifsc_code"
                type="text"
                name="ifsc_code"
                value="<?= htmlspecialchars(
                    (string)$ifsc_code
                ) ?>"
                maxlength="30"
                placeholder="Enter IFSC code"
                required
            >


            <!-- =================================================
                 SAVE
            ================================================== -->

            <button type="submit">

                Save Payout Details

            </button>


        </form>

    </section>


    <!-- =====================================================
         NEXT STEP
    ====================================================== -->

    <section class="card next">

        <p class="message">

            Once your payout details are saved,
            continue to Tools &amp; Materials.

        </p>


        <a
            href="../tools/index.php"
            class="next-btn"
        >

            Continue to Tools &amp; Materials →

        </a>

    </section>


</main>


<?php

render_shell_close();

?>