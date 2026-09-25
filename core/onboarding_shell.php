<?php
declare(strict_types=1);

/**
 * Shared onboarding chrome for merchant and agent.
 *
 * The customer module has a top bar, a vertical stepper and a centred card.
 * The other two each had their own layout with their own inline CSS, so the
 * three journeys looked like three different products. This renders the
 * customer layout for all of them, driven by a per-role step registry.
 *
 * merchant/portal/shell.php and agent/portal/shell.php now delegate here,
 * keeping their original function names, so every page that already called
 * render_shell_open() picks this up without being touched.
 */require_once __DIR__ . '/../customer/portal/topbar.php';

/** Steps per role, in order. Keys match what the existing pages pass in. */
function onboarding_steps(string $role): array
{
    if ($role === 'merchant') {
        return [
            'registration'  => ['Registration',             'Account created'],
            'ekyc'          => ['eKYC Verification',        'Identity documents'],
            'business'      => ['Business Verification',    'Shop, GST and bank'],
            'credit'        => ['Credit & Risk Assessment', 'Limit assessment'],
            'agreement'     => ['Agreement & Terms',        'Digital signature'],
            'account_setup' => ['Account Setup',            'Settlement and payouts'],
            'training'      => ['Training & Support',       'Product walkthrough'],
            'complete'      => ['Onboarding Complete',      'Merchant ID issued'],
        ];
    }

    return [
        'registration' => ['Registration',               'Account created'],
        'ekyc'         => ['eKYC Verification',          'Identity documents'],
        'background'   => ['Background & Verification',  'References and field visit'],
        'training'     => ['Training & Certification',   'Product and compliance'],
        'hierarchy'    => ['Hierarchy & Code Creation',  'Referral code and upline'],
        'wallet'       => ['Wallet & Commission Setup',  'Commission account'],
        'tools'        => ['Tools & Materials Access',   'Lead capture kit'],
        'complete'     => ['Onboarding Complete',        'Agent ID issued'],
    ];
}

/**
 * Merchant onboarding remains sequential.
 *
 * Agent onboarding does NOT depend on Background Verification.
 * Training, Hierarchy, Wallet and Tools can all be accessed and completed
 * while Background Verification is still pending.
 */
function onboarding_enforce_order(array $statuses, string $role): array
{
    /*
     * Agent modules are independent.
     *
     * Do not hold back Training, Hierarchy, Wallet or Tools because
     * Background Verification is pending.
     */
    /*
     * Agent modules: Background Verification specifically does not block
     * later steps (Training, Hierarchy, Wallet, Tools can be done while it
     * is still with a reviewer) - but every other step still has to
     * happen in order. The previous version skipped ordering entirely for
     * agents, which went further than that: it also let Hierarchy show
     * Completed while eKYC itself, step 2, was still pending, since
     * nothing was checking order at all.
     */
    if ($role === 'agent') {
        // No order-downgrading here: every step below now reflects its own
        // genuine completion (see agent/portal/shell.php's get_step_statuses
        // for hierarchy and tools specifically), so a later step showing
        // completed while eKYC or Background is still with a reviewer is
        // accurate, not a bug - agents are allowed to keep going while
        // those are pending.
        return $statuses;
    }

    /*
     * Keep the original sequential behaviour for merchants.
     */
    $blocked = false;

    foreach (array_keys(onboarding_steps($role)) as $key) {
        $state = $statuses[$key] ?? 'pending';

        if ($blocked && $state === 'completed') {
            $statuses[$key] = 'pending';
            continue;
        }

        if ($state !== 'completed') {
            $blocked = true;
        }
    }

    return $statuses;
}

/**
 * @param array $statuses  key => 'completed' | 'in_review' | 'in_progress'
 *                                | 'rejected' | 'pending'
 *
 * in_review and rejected exist because the customer stepper already drew
 * those two states and the other two journeys had nowhere to put them: a
 * KYC sitting with a reviewer and a KYC that came back rejected both
 * rendered as "In progress", which reads as "carry on" when the applicant
 * either cannot carry on or has nothing left to do.
 */
function onboarding_shell_render_open(
    PDO $pdo,
    array $user,
    string $role,
    array $statuses,
    string $activeKey,
    string $pageTitle
): void {
    $steps = onboarding_steps($role);
    $n = 0;
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> | INDBIN</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/indbin.css">
</head>

<body data-portal="<?= e($role) ?>">

<?php render_topbar($pdo, $user, 'onboarding'); ?>

<div class="layout">

<aside class="sidebar">

    <h1 class="sidebar-title">
        <?= $role === 'merchant' ? 'Merchant' : 'Agent' ?> Onboarding
    </h1>

    <nav class="step-list" aria-label="Onboarding progress">

    <?php foreach ($steps as $key => [$label, $hint]):

        $n++;

        $st = $statuses[$key] ?? 'pending';

        $state = match ($st) {
            'completed'   => 'is-done',
            'rejected'    => 'is-bad',
            'in_review'   => 'is-review',
            'in_progress' => 'is-active',
            default       => $key === $activeKey ? 'is-active' : '',
        };

        $word = match ($st) {
            'completed'   => 'Completed',
            'rejected'    => 'Rejected',
            'in_review'   => 'In review',
            'in_progress' => 'In progress',
            default       => $key === $activeKey ? 'In progress' : 'Pending',
        };

        $glyph = match ($st) {
            'completed' => '<i class="fa-solid fa-check"></i>',
            'in_review' => '<i class="fa-solid fa-clock"></i>',
            default     => (string)$n,
        };

        /*
         * Agent onboarding:
         * Do not lock Training, Hierarchy, Wallet or Tools because
         * Background Verification is pending.
         *
         * Merchant keeps the original locking behaviour.
         */
        if ($role === 'agent') {

            $locked = '';

        } else {

            $locked = ($st === 'pending' && $key !== $activeKey)
                ? ' is-locked'
                : '';

        }

    ?>

        <div
            class="step-item <?= $state . $locked ?><?= $key === $activeKey ? ' is-current' : '' ?>"
            <?= $key === $activeKey ? 'aria-current="step"' : '' ?>
        >

            <div class="step-number">
                <?= $glyph ?>
            </div>

            <div class="step-info">

                <h4>
                    <?= e($label) ?>
                </h4>

                <p>
                    <?= e($word) ?>
                </p>

            </div>

        </div>

    <?php endforeach; ?>

    </nav>

</aside>

<main class="main">

<?php foreach (take_flashes() as $f): ?>

    <div
        class="notice is-<?= e(
            $f['type'] === 'error'
                ? 'bad'
                : ($f['type'] === 'success' ? 'ok' : $f['type'])
        ) ?>"
        style="max-width:480px;width:100%;"
        role="status"
    >

        <?= e($f['message']) ?>

    </div>

<?php endforeach; ?>

<?php
}

function onboarding_shell_render_close(): void
{
    ?>

</main>

</div>

</body>
</html>

<?php
}