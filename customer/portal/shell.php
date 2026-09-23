<?php
/**
 * Onboarding shell: the 7-step stepper plus page chrome.
 *
 * Usage inside a step page:
 *   onboarding_shell_open($pdo, $user, 3, 'Profile creation');
 *   ... card markup ...
 *   onboarding_shell_close();
 *
 * The sidebar markup lived in onboarding.php as seven near-identical
 * hand-written blocks. It is generated from CUSTOMER_STEPS here, so the
 * stepper cannot drift out of step with the flow.
 */

declare(strict_types=1);

require_once __DIR__ . '/topbar.php';

function onboarding_shell_open(PDO $pdo, array $user, int $current, string $pageTitle): void
{
    $statuses = step_statuses($pdo, $user);
    $saved    = (int)$user['onboarding_step'];
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> | INDBIN</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/indbin.css">
</head>
<body data-portal="customer">
<?php render_topbar($pdo, $user, 'onboarding'); ?>
<div class="layout">

<aside class="sidebar">
    <h1 class="sidebar-title">Customer Onboarding</h1>

    <nav class="step-list" aria-label="Onboarding progress">
    <?php foreach (CUSTOMER_STEPS as $n => $cfg):
        $status = $statuses[$n];
        $state  = match (true) {
            $status === 'Rejected' || $status === 'Resubmit' => 'is-bad',
            $status === 'In review'                          => 'is-review',
            $status === 'Completed'                          => 'is-done',
            $n === $current                                  => 'is-active',
            default                                          => '',
        };
        $reachable = $n <= $saved && $status !== 'Not required';
        $glyph = match (true) {
            $status === 'Completed'    => '<i class="fa-solid fa-check"></i>',
            $status === 'In review'    => '<i class="fa-solid fa-clock"></i>',
            $status === 'Not required' => '<i class="fa-solid fa-minus"></i>',
            default                    => (string)$n,
        };
        $tag  = $reachable ? 'a' : 'div';
        $href = $reachable ? ' href="' . e(step_url($n)) . '"' : '';
        $lock = $reachable ? '' : ' is-locked';
    ?>
        <<?= $tag . $href ?> class="step-item <?= $state . $lock ?><?= $n === $current ? ' is-current' : '' ?>"<?= $n === $current ? ' aria-current="step"' : '' ?>>
            <div class="step-number"><?= $glyph ?></div>
            <div class="step-info">
                <h4><?= e($cfg['title']) ?></h4>
                <p><?= e($status) ?></p>
            </div>
        </<?= $tag ?>>
    <?php endforeach; ?>
    </nav>

</aside>

<main class="main">
<?php foreach (take_flashes() as $f): ?>
    <div class="notice is-<?= e($f['type'] === 'error' ? 'bad' : ($f['type'] === 'success' ? 'ok' : $f['type'])) ?>"
         style="max-width:480px;width:100%;" role="status">
        <?= e($f['message']) ?>
    </div>
<?php endforeach;
}

function onboarding_shell_close(): void
{
    ?>
</main>
</div>
</body>
</html>
<?php
}
