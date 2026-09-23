<?php
/**
 * The 7 customer onboarding steps from the CRM flow chart.
 *
 * This is the single source of truth. The stepper, the guards, the
 * progress bar and the admin queue all read from here, so adding or
 * reordering a step is one edit rather than seven.
 *
 * In the current onboarding.php the step numbers are hardcoded in the
 * gate check, the $steps_status array, the sidebar markup and every
 * form action, which is why steps 2 and 3 disagree about what "Profile
 * Creation" contains.
 */

declare(strict_types=1);

const CUSTOMER_STEPS = [
    1 => [
        'key'      => 'registration',
        'dir'      => 'registration',
        'title'    => 'Registration',
        'subtitle' => 'Account and mobile verification',
        'icon'     => 'fa-user-plus',
        'optional' => false,
    ],
    2 => [
        'key'      => 'ekyc',
        'dir'      => 'ekyc',
        'title'    => 'eKYC Verification',
        'subtitle' => 'Aadhaar, PAN and liveness check',
        'icon'     => 'fa-shield-halved',
        'optional' => false,
    ],
    3 => [
        'key'      => 'profile',
        'dir'      => 'profile',
        'title'    => 'Profile Creation',
        'subtitle' => 'Address, preferences and payment method',
        'icon'     => 'fa-address-card',
        'optional' => false,
    ],
    4 => [
        'key'      => 'credit',
        'dir'      => 'credit',
        'title'    => 'Credit Evaluation',
        'subtitle' => 'Runs only for credit-linked products',
        'icon'     => 'fa-chart-line',
        'optional' => true,   // the flow chart marks this "(if required)"
    ],
    5 => [
        'key'      => 'products',
        'dir'      => 'products',
        'title'    => 'Product Activation',
        'subtitle' => 'BNPL, pay later, loans, insurance, rewards',
        'icon'     => 'fa-sliders',
        'optional' => false,
    ],
    6 => [
        'key'      => 'loyalty',
        'dir'      => 'loyalty',
        'title'    => 'Loyalty Enrolment',
        'subtitle' => 'Join the programme, set contact preferences',
        'icon'     => 'fa-gift',
        'optional' => false,
    ],
    7 => [
        'key'      => 'complete',
        'dir'      => 'onboarding_complete',
        'title'    => 'Onboarding Complete',
        'subtitle' => 'Customer ID issued, account activated',
        'icon'     => 'fa-circle-check',
        'optional' => false,
    ],
];

const CUSTOMER_FINAL_STEP = 7;

/** URL of a step's landing page. */
function step_url(int $step): string
{
    $cfg = CUSTOMER_STEPS[$step] ?? CUSTOMER_STEPS[1];
    return CUST_BASE . '/' . $cfg['dir'] . '/index.php';
}

/**
 * Has the customer asked for anything credit-linked?
 *
 * This is NOT the same question as "should step 4 run", which is what it
 * used to gate. Products are chosen at step 5, so at step 4 the answer is
 * always no, which skipped the credit check for everyone. Step 5 then
 * greyed out every credit product because no score existed. The two steps
 * each waited on the other.
 *
 * Step 4 is now a step the customer answers for themselves: run the check,
 * or say no thanks. This function is only used to decide whether a product
 * selection needs to send them back for a score.
 */
function has_credit_products(PDO $pdo, int $userId): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
           FROM product_activations pa
           JOIN product_catalog pc ON pc.product_code = pa.product_code
          WHERE pa.user_id = ? AND pc.requires_credit = 1
            AND pa.status IN ('requested','active')"
    );
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn() > 0;
}

/** Does a usable credit assessment exist? */
function current_evaluation(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT * FROM credit_evaluations
          WHERE user_id = ? AND is_current = 1
            AND (valid_until IS NULL OR valid_until >= CURDATE())"
    );
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}
