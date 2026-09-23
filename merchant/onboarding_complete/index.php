<?php

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../portal/shell.php';


/* =========================================================
   LOGIN CHECK
========================================================= */

if (!isset($_SESSION['user_id'])) {

    header('Location: ' . BASE_URL . '/index.php');

    exit;
}


$user_id = (int)$_SESSION['user_id'];


/* =========================================================
   GET MERCHANT DETAILS
========================================================= */

$stmt = $pdo->prepare(
    "SELECT
        role,
        party_code AS merchant_id,
        business_name,
        full_name,
        kyc_status,
        business_verification_status,
        risk_score,
        risk_category,
        credit_limit,
        agreement_signed_at,
        account_setup_completed_at,
        product_enablement_completed_at,
        training_completed_at,
        onboarding_completed_at,
        account_status
     FROM users
     WHERE id = ?"
);

$stmt->execute([$user_id]);

$user = $stmt->fetch();


/* =========================================================
   ROLE CHECK
========================================================= */

if (($user['role'] ?? '') !== 'merchant') {

    header('Location: ' . BASE_URL . '/index.php');

    exit;
}


/* =========================================================
   TRAINING CHECK
========================================================= */

if (!$user['training_completed_at']) {

    header('Location: ../training/index.php');

    exit;
}


/* =========================================================
   VERIFICATION CHECK
========================================================= */

$isVerified =
    $user['kyc_status'] === 'approved'
    &&
    $user['business_verification_status'] === 'approved';


/* =========================================================
   ACTIVATE MERCHANT
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    ($_POST['action'] ?? '') === 'activate'
) {

    /*
     * Merchant can only be activated after:
     *
     * eKYC = approved
     * Business Verification = approved
     */

    if ($isVerified) {

        $pdo->prepare("
            UPDATE users
            SET
                onboarding_completed_at = NOW(),
                account_status = 'active'
            WHERE id = ?
        ")->execute([$user_id]);


        /* -------------------------------------------------
           AUTO-ENABLE FREE SERVICES
        ------------------------------------------------- */

        $freeServices = [
            'upi',
            'qr_to_cash',
            'insurance',
            'offers',
            'loyalty'
        ];


        $insertSvc = $pdo->prepare("
            INSERT IGNORE INTO merchant_services
            (
                user_id,
                service_key,
                status
            )
            VALUES
            (?, ?, 'approved')
        ");


        foreach ($freeServices as $svcKey) {

            $insertSvc->execute([
                $user_id,
                $svcKey
            ]);

        }


        header('Location: ../dashboard/index.php');

        exit;
    }

}


/* =========================================================
   ACCOUNT ACTIVE STATUS
========================================================= */

$isActive =
    $user['account_status'] === 'active'
    &&
    $isVerified;


$canActivate = $isVerified;


/* =========================================================
   SETTLEMENT ACCOUNT
========================================================= */

$accStmt = $pdo->prepare("
    SELECT account_number
    FROM settlement_accounts
    WHERE user_id = ?
      AND is_primary = 1
    LIMIT 1
");

$accStmt->execute([$user_id]);

$acc = $accStmt->fetch();


/* =========================================================
   ENABLED SERVICES
========================================================= */

$svcStmt = $pdo->prepare("
    SELECT
        service_key,
        status
    FROM merchant_services
    WHERE user_id = ?
");

$svcStmt->execute([$user_id]);


$serviceLabels = [

    'upi'            => 'UPI',
    'qr_to_cash'     => 'QR to Cash',
    'aeps'           => 'AEPS',
    'money_transfer' => 'Money Transfer',
    'loans'          => 'Credit & Business Loan',
    'insurance'      => 'Insurance',
    'offers'         => 'Offers',
    'loyalty'        => 'Loyalty'

];


$enabledServices = [];


foreach ($svcStmt->fetchAll() as $s) {

    $enabledServices[] =
        $serviceLabels[$s['service_key']]
        ?? $s['service_key'];

}


/* =========================================================
   STATUS TEXT
========================================================= */

$kycStatus =
    ucfirst(
        $user['kyc_status'] ?? 'not submitted'
    );


$businessStatus =
    ucfirst(
        $user['business_verification_status']
        ?? 'not submitted'
    );


/* =========================================================
   OPEN SHELL
========================================================= */

render_shell_open(
    $pdo,
    $user_id,
    'complete',
    'Onboarding Complete'
);

?>


<style>

/* =========================================================
   FINAL ONBOARDING PAGE
========================================================= */

.final-wrap {

    width: 100%;
    max-width: 680px;

    margin: 0 auto;

    padding: 10px 20px 40px;

    box-sizing: border-box;
}


/* =========================================================
   HERO CARD
========================================================= */

.hero-card {

    text-align: center;

    margin-bottom: 16px;
}


.hero-icon {

    width: 54px;
    height: 54px;

    margin: 0 auto 14px;

    border-radius: 50%;

    display: flex;
    align-items: center;
    justify-content: center;

    background: #fff1e5;

    color: #f97316;

    font-size: 22px;
}


.hero-card h1 {

    margin: 0 0 8px;

    font-size: 28px;

    color: #102a43;
}


.hero-card p {

    margin: 0;

    color: #52606d;

    font-size: 15px;

    line-height: 1.6;
}


/* =========================================================
   MAIN SUMMARY CARD
========================================================= */

.summary-card {

    background: #ffffff;

    border: 1px solid #e5e7eb;

    border-radius: 16px;

    padding: 24px;

    box-sizing: border-box;

    box-shadow:
        0 4px 14px rgba(15, 23, 42, 0.05);

}


.summary-card h4 {

    margin: 0 0 18px;

    font-size: 18px;

    color: #102a43;
}


/* =========================================================
   SUMMARY ROWS
========================================================= */

.sum-row {

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 20px;

    padding: 13px 0;

    border-bottom: 1px dashed #e1e7ef;

    font-size: 14px;
}


.sum-row:last-of-type {

    border-bottom: none;
}


.sum-row > span:first-child {

    color: #52606d;

}


.sum-row > span:last-child {

    color: #243b53;

    text-align: right;

    font-weight: 500;
}


/* =========================================================
   STATUS BADGES
========================================================= */

.status-badge {

    display: inline-flex;

    align-items: center;

    gap: 6px;

    padding: 5px 10px;

    border-radius: 999px;

    font-size: 12px;

    font-weight: 700;

    line-height: 1;
}


.status-pending {

    background: #fff7ed;

    color: #c2410c;
}


.status-approved {

    background: #ecfdf3;

    color: #15803d;
}


/* =========================================================
   VERIFICATION SECTION
========================================================= */

.verification-section {

    margin-top: 20px;

    padding-top: 20px;

    border-top: 1px solid #e5e7eb;
}


.verification-title {

    margin: 0 0 6px;

    font-size: 15px;

    font-weight: 700;

    color: #243b53;
}


.verification-description {

    margin: 0 0 14px;

    font-size: 13px;

    line-height: 1.5;

    color: #667085;
}


/* =========================================================
   WAITING MESSAGE
========================================================= */

.verification-alert {

    display: flex;

    align-items: flex-start;

    gap: 12px;

    padding: 14px 16px;

    border-radius: 10px;

    background: #fff7ed;

    border: 1px solid #fed7aa;

    color: #9a3412;

    font-size: 13px;

    line-height: 1.5;
}


.verification-alert i {

    margin-top: 2px;

    font-size: 16px;
}


/* =========================================================
   READY MESSAGE
========================================================= */

.ready-message {

    display: flex;

    align-items: flex-start;

    gap: 12px;

    padding: 14px 16px;

    border-radius: 10px;

    background: #ecfdf3;

    border: 1px solid #bbf7d0;

    color: #166534;

    font-size: 13px;

    line-height: 1.5;
}


.ready-message i {

    margin-top: 2px;

    font-size: 16px;
}


/* =========================================================
   ACTIVE BADGE
========================================================= */

.active-badge {

    display: inline-flex;

    align-items: center;

    gap: 6px;

    margin-top: 12px;

    padding: 7px 12px;

    border-radius: 999px;

    background: #ecfdf3;

    color: #15803d;

    font-size: 13px;

    font-weight: 700;
}


/* =========================================================
   BUTTON
========================================================= */

.activate-btn {

    width: 100%;

    box-sizing: border-box;

    margin-top: 16px;

    padding: 13px 18px;

    border: none;

    border-radius: 9px;

    background: #f97316;

    color: #ffffff;

    font-size: 15px;

    font-weight: 700;

    cursor: pointer;

    transition: opacity 0.2s ease;
}


.activate-btn:hover {

    opacity: 0.92;
}


/* =========================================================
   MOBILE
========================================================= */

@media (max-width: 700px) {

    .final-wrap {

        max-width: 100%;

        padding: 10px 15px 30px;
    }


    .summary-card {

        padding: 18px;

        border-radius: 13px;
    }


    .sum-row {

        gap: 12px;

        font-size: 13px;
    }


    .hero-card h1 {

        font-size: 24px;
    }

}

</style>


<div class="final-wrap">


    <!-- =====================================================
         HERO
    ====================================================== -->

    <div class="hero-card">

        <div class="hero-icon">

            <i class="fa-solid
                <?= $isActive
                    ? 'fa-circle-check'
                    : 'fa-flag-checkered'
                ?>">
            </i>

        </div>


        <h1>

            <?= $isActive
                ? 'You\'re an Active Merchant'
                : 'Almost done!'
            ?>

        </h1>


        <p>

            <?= $isActive
                ? 'Your merchant account is fully onboarded and ready to transact.'
                : 'Your account is waiting for the required verification approvals.'
            ?>

        </p>


        <?php if ($isActive): ?>

            <div class="active-badge">

                <i class="fa-solid fa-circle-check"></i>

                Account Active

            </div>

        <?php endif; ?>

    </div>


    <!-- =====================================================
         MERCHANT SUMMARY + VERIFICATION
    ====================================================== -->

    <div class="summary-card">


        <h4>
            Merchant Summary
        </h4>


        <!-- Merchant ID -->

        <div class="sum-row">

            <span>
                Merchant ID
            </span>

            <span>
                <?= htmlspecialchars(
                    $user['merchant_id'] ?? '—'
                ) ?>
            </span>

        </div>


        <!-- Business Name -->

        <div class="sum-row">

            <span>
                Business Name
            </span>

            <span>
                <?= htmlspecialchars(
                    $user['business_name'] ?: '—'
                ) ?>
            </span>

        </div>


        <!-- eKYC -->

        <div class="sum-row">

            <span>
                eKYC Status
            </span>

            <span>

                <?php if ($user['kyc_status'] === 'approved'): ?>

                    <span class="status-badge status-approved">

                        <i class="fa-solid fa-circle-check"></i>

                        Approved

                    </span>

                <?php else: ?>

                    <span class="status-badge status-pending">

                        <i class="fa-solid fa-clock"></i>

                        <?= htmlspecialchars($kycStatus) ?>

                    </span>

                <?php endif; ?>

            </span>

        </div>


        <!-- Business Verification -->

        <div class="sum-row">

            <span>
                Business Verification
            </span>

            <span>

                <?php if (
                    $user['business_verification_status']
                    === 'approved'
                ): ?>

                    <span class="status-badge status-approved">

                        <i class="fa-solid fa-circle-check"></i>

                        Approved

                    </span>

                <?php else: ?>

                    <span class="status-badge status-pending">

                        <i class="fa-solid fa-clock"></i>

                        <?= htmlspecialchars($businessStatus) ?>

                    </span>

                <?php endif; ?>

            </span>

        </div>


        <!-- Credit Limit -->

        <div class="sum-row">

            <span>
                Credit Limit
            </span>

            <span>

                <?= $user['credit_limit']
                    ? '₹' .
                      number_format(
                          (float)$user['credit_limit'],
                          0
                      )
                      .
                      ' (' .
                      ucfirst(
                          $user['risk_category']
                      )
                      .
                      ' risk)'
                    : 'Not assigned'
                ?>

            </span>

        </div>


        <!-- Agreement -->

        <div class="sum-row">

            <span>
                Agreement Signed
            </span>

            <span>

                <?= $user['agreement_signed_at']
                    ? htmlspecialchars(
                        date(
                            'd M Y',
                            strtotime(
                                $user['agreement_signed_at']
                            )
                        )
                    )
                    : 'Not signed'
                ?>

            </span>

        </div>


        <!-- Settlement -->

        <div class="sum-row">

            <span>
                Settlement Account
            </span>

            <span>

                <?= $acc
                    ? '•••' .
                      htmlspecialchars(
                          substr(
                              $acc['account_number'],
                              -4
                          )
                      )
                    : 'Not set'
                ?>

            </span>

        </div>


        <!-- Services -->

        <div class="sum-row">

            <span>
                Enabled Services
            </span>

            <span>

                <?= $enabledServices
                    ? htmlspecialchars(
                        implode(
                            ', ',
                            $enabledServices
                        )
                    )
                    : 'None selected'
                ?>

            </span>

        </div>


        <!-- =================================================
             VERIFICATION / ACTIVATION
        ================================================== -->

        <div class="verification-section">


            <?php if ($isActive): ?>


                <div class="ready-message">

                    <i class="fa-solid fa-circle-check"></i>

                    <div>

                        <strong>
                            Account Active
                        </strong>

                        <br>

                        Your eKYC and Business Verification
                        have been approved. Your merchant
                        account is ready to transact.

                    </div>

                </div>


            <?php elseif (!$canActivate): ?>


                <div class="verification-alert">

                    <i class="fa-solid fa-clock"></i>

                    <div>

                        <strong>
                            Verification pending
                        </strong>

                        <br>

                        Your account will be activated after
                        eKYC and Business Verification are
                        approved by our team.

                    </div>

                </div>


            <?php else: ?>


                <div class="ready-message">

                    <i class="fa-solid fa-circle-check"></i>

                    <div>

                        <strong>
                            Ready to activate
                        </strong>

                        <br>

                        Your eKYC and Business Verification
                        have been approved. You can now
                        activate your merchant account.

                    </div>

                </div>


            <?php endif; ?>


        </div>


        <!-- =================================================
             ACTION
        ================================================== -->


        <?php if ($isActive): ?>


            <a
                href="../dashboard/index.php"
                class="activate-btn"
                style="
                    display:block;
                    text-align:center;
                    text-decoration:none;
                "
            >

                Go to Dashboard

            </a>


        <?php elseif ($canActivate): ?>


            <form method="POST">

                <input
                    type="hidden"
                    name="action"
                    value="activate"
                >


                <button
                    type="submit"
                    class="activate-btn"
                >

                    Activate My Account

                </button>

            </form>


        <?php endif; ?>


    </div>


</div>


<?php

render_shell_close();

?>