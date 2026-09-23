<?php
/**
 * Help and support, for whichever journey the visitor is in.
 *
 * The top bar carries a Help link in all three portals, so the target has to
 * answer for all three. agent/portal/help.php only ever knew how to be the
 * agent's, and customer and merchant had nothing at this position at all, so
 * the link would have 404'd for two roles out of three.
 *
 * Everything role-specific on this page comes from the same $user row the
 * rest of the app uses. The contact details are deliberately one set: there
 * is one support desk behind all three journeys.
 */

declare(strict_types=1);
require_once __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/customer/portal/topbar.php';

$user = require_login($pdo);
$role = (string)$user['role'];

$portal = in_array($role, ['customer', 'agent', 'merchant'], true) ? $role : 'customer';

$journey = match ($role) {
    'agent'    => 'agent onboarding',
    'merchant' => 'merchant onboarding',
    'admin'    => 'the back office',
    default    => 'customer onboarding',
};

// Questions people actually arrive here with, in the order they arrive with
// them. Kept short: a wall of FAQ is where support pages go to be ignored.
$faqs = [
    [
        'Why is my step still showing In review?',
        'A step marked In review has been submitted and is with a reviewer.'
        . ' Nothing further is needed from you on it. You will get a notification'
        . ' when a decision is recorded, and the step turns green.',
    ],
    [
        'A step was rejected. What now?',
        'Open that step again. The reason the reviewer gave is shown at the top'
        . ' of the form, and you can correct and resubmit from the same page.',
    ],
    [
        'Can I skip ahead to a later step?',
        'No. Each step depends on the one above it, so a later step only opens'
        . ' once everything before it is complete. Steps you have not reached'
        . ' yet are shown greyed out in the sidebar.',
    ],
    [
        'My documents were uploaded but nothing changed.',
        'Uploading stores the file; submitting sends it for review. Check that'
        . ' you pressed Submit at the bottom of the form, then look at the step'
        . ' in the sidebar: it should read In review.',
    ],
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Help and support | INDBIN</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/indbin.css">
</head>
<body data-portal="<?= e($portal) ?>">
<?php render_topbar($pdo, $user, 'onboarding'); ?>

<main class="main">
    <div class="card wide">
        <div class="card-head">
            <div class="card-icon"><i class="fa-regular fa-circle-question"></i></div>
            <h2>Help and support</h2>
            <p>Questions that come up during <?= e($journey) ?>.</p>
        </div>

        <div class="details">
            <?php foreach ($faqs as [$q, $a]): ?>
                <div style="display:block;">
                    <strong style="display:block;color:var(--ink);font-size:14px;margin-bottom:5px;">
                        <?= e($q) ?>
                    </strong>
                    <span style="display:block;color:var(--muted);font-weight:400;text-align:left;font-size:13px;line-height:1.55;">
                        <?= e($a) ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="alert alert-info" style="margin-top:22px;">
            Still stuck? Email <strong>support@indbin.in</strong> or call
            <strong>+91 80 4950 8282</strong>, Monday to Saturday, 9am to 7pm.
            Quote your registered email so the desk can find your account.
        </div>

        <a class="btn" href="<?= e(landing_url($user)) ?>"
           style="display:block;text-align:center;text-decoration:none;">
            Back to <?= e($journey) ?>
        </a>
    </div>
</main>

</body>
</html>
