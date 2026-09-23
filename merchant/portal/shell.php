<?php
/**
 * Merchant onboarding shell.
 *
 * The status logic below is the module's own: it reads the real columns and
 * decides which steps are done. Only the rendering changed, and that now
 * comes from core/onboarding_shell.php so the merchant journey looks like
 * the customer one instead of a separate product.
 *
 * The function names and signatures are unchanged, so all 26 pages that
 * already call render_shell_open() pick this up without edits.
 */

declare(strict_types=1);

require_once dirname(dirname(__DIR__)) . '/core/onboarding_shell.php';

function get_step_statuses(PDO $pdo, int $user_id): array
{
    $stmt = $pdo->prepare(
        "SELECT kyc_status, business_verification_status, risk_score,
                agreement_signed_at, account_setup_completed_at, product_enablement_completed_at,
                training_completed_at, onboarding_completed_at
         FROM users WHERE id = ?"
    );
    $stmt->execute([$user_id]);
    $u = $stmt->fetch();

    $statuses = [];
    $statuses['registration']    = 'completed'; // reaching the portal at all implies registration is done
    // pending means submitted and sitting in the reviewer's queue, which is
    // not the same thing as the merchant still having work to do on it.
    $statuses['ekyc']            = match ($u['kyc_status']) {
        'approved' => 'completed',
        'pending'  => 'in_review',
        'rejected' => 'rejected',
        'resubmit' => 'in_progress',
        default    => 'pending',
    };
    $statuses['business']        = match ($u['business_verification_status']) {
        'approved' => 'completed',
        'pending'  => 'in_review',
        'rejected' => 'rejected',
        default    => 'pending',
    };
    $statuses['credit']          = $u['risk_score'] !== null ? 'completed' : 'pending';
    $statuses['agreement']       = $u['agreement_signed_at'] ? 'completed' : 'pending';
    $statuses['account_setup']   = $u['account_setup_completed_at'] ? 'completed' : 'pending';
    $statuses['training']        = $u['training_completed_at'] ? 'completed' : 'pending';
    $statuses['complete']        = $u['onboarding_completed_at'] ? 'completed' : 'pending';

    return $statuses;
}


function render_shell_open(PDO $pdo, int $user_id, string $activeStepKey, string $pageTitle): void
{
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch() ?: ['id' => $user_id, 'full_name' => 'Merchant', 'role' => 'merchant'];

    onboarding_shell_render_open(
        $pdo, $user, 'merchant',
        get_step_statuses($pdo, $user_id),
        $activeStepKey, $pageTitle
    );
}

function render_shell_close(): void
{
    onboarding_shell_render_close();
}

/**
 * Kept because a few pages still call it. The stepper is drawn by the shell
 * now, so this is a no-op rather than a second stepper on the page.
 */
function render_horizontal_stepper(array $statuses, string $activeStepKey): void
{
}
