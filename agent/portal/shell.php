<?php
/**
 * Agent onboarding shell.
 *
 * Same arrangement as the merchant one: the module keeps its own status
 * logic, the rendering comes from core/onboarding_shell.php, and the
 * function signatures are unchanged so the 12 pages that call
 * render_shell_open() need no edits.
 */

declare(strict_types=1);

require_once dirname(dirname(__DIR__)) . '/core/onboarding_shell.php';

function get_step_statuses(PDO $pdo, int $user_id, string $active_step): array
{
    $statuses = [
        'registration' => 'completed',
        'ekyc'         => 'pending',
        'background'   => 'pending',
        'training'     => 'pending',
        'hierarchy'    => 'pending',
        'wallet'       => 'pending',
        'tools'        => 'pending',
        'complete'     => 'pending'
    ];


    /* =====================================================
       eKYC STATUS
    ====================================================== */

    try {

        $stmt = $pdo->prepare("
            SELECT kyc_status
            FROM users
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->execute([$user_id]);

        $kyc = strtolower(
            trim((string) $stmt->fetchColumn())
        );


        if ($kyc === 'approved') {

            $statuses['ekyc'] = 'completed';

        } elseif ($kyc === 'pending') {

            /*
             * Submitted and waiting on a reviewer, not waiting
             * on the agent.
             */

            $statuses['ekyc'] = 'in_review';

        } elseif ($kyc === 'rejected') {

            $statuses['ekyc'] = 'rejected';

        } elseif ($kyc === 'resubmit') {

            $statuses['ekyc'] = 'in_progress';

        } else {

            $statuses['ekyc'] = 'pending';
        }

    } catch (Exception $e) {

        $statuses['ekyc'] = 'pending';
    }


    /* =====================================================
       BACKGROUND & VERIFICATION STATUS
    ====================================================== */

    try {

        $stmt = $pdo->prepare("
            SELECT
                reference_status,
                field_status,
                background_status
            FROM agent_verification
            WHERE agent_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");

        $stmt->execute([$user_id]);

        $verification = $stmt->fetch(PDO::FETCH_ASSOC);


        if ($verification) {

            $reference_status =
                strtolower(
                    trim(
                        $verification['reference_status'] ?? 'pending'
                    )
                );

            $field_status =
                strtolower(
                    trim(
                        $verification['field_status'] ?? 'pending'
                    )
                );

            $background_status =
                strtolower(
                    trim(
                        $verification['background_status'] ?? 'pending'
                    )
                );


            if (
                $reference_status === 'verified' &&
                $field_status === 'verified' &&
                $background_status === 'verified'
            ) {

                $statuses['background'] = 'completed';

            } elseif (
                $reference_status === 'failed' ||
                $field_status === 'failed' ||
                $background_status === 'failed'
            ) {

                $statuses['background'] = 'rejected';

            } else {

                /*
                 * The form is in. Everything left is the
                 * reviewer's, so say so rather than implying
                 * the agent still has work here.
                 */

                $statuses['background'] = 'in_review';
            }

        } elseif ($active_step === 'background') {

            /*
             * Agent has opened Background & Verification.
             * It is therefore in progress even if admin
             * has not started verification yet.
             */

            $statuses['background'] = 'in_progress';
        }

    } catch (Exception $e) {

        if ($active_step === 'background') {
            $statuses['background'] = 'in_progress';
        }
    }


    /* =====================================================
       TRAINING STATUS
    ====================================================== */

    try {

        $stmt = $pdo->prepare("
            SELECT
                product_training,
                compliance_training,
                certification_test
            FROM agent_training
            WHERE user_id = ?
            LIMIT 1
        ");

        $stmt->execute([$user_id]);

        $training = $stmt->fetch(PDO::FETCH_ASSOC);


        if ($training) {

            if (
                strtolower(trim($training['product_training'] ?? '')) === 'completed' &&
                strtolower(trim($training['compliance_training'] ?? '')) === 'completed' &&
                strtolower(trim($training['certification_test'] ?? '')) === 'completed'
            ) {

                $statuses['training'] = 'completed';

            } else {

                $statuses['training'] = 'in_progress';
            }

        } elseif ($active_step === 'training') {

            $statuses['training'] = 'in_progress';
        }

    } catch (Exception $e) {

        if ($active_step === 'training') {
            $statuses['training'] = 'in_progress';
        }
    }


    /* =====================================================
       HIERARCHY STATUS
    ====================================================== */

    try {

        $stmt = $pdo->prepare("
            SELECT id
            FROM agent_hierarchy
            WHERE user_id = ? AND agent_id IS NOT NULL
            LIMIT 1
        ");

        $stmt->execute([$user_id]);

        if ($stmt->fetchColumn()) {

            $statuses['hierarchy'] = 'completed';

        } elseif ($active_step === 'hierarchy') {

            $statuses['hierarchy'] = 'in_progress';
        }

    } catch (Exception $e) {

        if ($active_step === 'hierarchy') {
            $statuses['hierarchy'] = 'in_progress';
        }
    }


    /* =====================================================
       WALLET & COMMISSION STATUS
    ====================================================== */

    try {

        $stmt = $pdo->prepare("
            SELECT id
            FROM agent_commission_payout
            WHERE user_id = ?
            LIMIT 1
        ");

        $stmt->execute([$user_id]);

        if ($stmt->fetchColumn()) {

            $statuses['wallet'] = 'completed';

        } elseif ($active_step === 'wallet') {

            $statuses['wallet'] = 'in_progress';
        }

    } catch (Exception $e) {

        if ($active_step === 'wallet') {
            $statuses['wallet'] = 'in_progress';
        }
    }


    /* =====================================================
       TOOLS & MATERIALS
    ====================================================== */

    try {
        $stmt = $pdo->prepare("
            SELECT app_walkthrough_completed_at
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$user_id]);

        if ($stmt->fetchColumn()) {
            $statuses['tools'] = 'completed';
        } elseif ($active_step === 'tools') {
            $statuses['tools'] = 'in_progress';
        }
    } catch (Exception $e) {
        if ($active_step === 'tools') {
            $statuses['tools'] = 'in_progress';
        }
    }


    /* =====================================================
       ONBOARDING COMPLETE
    ====================================================== */

    /*
     * IMPORTANT:
     * Before the final Onboarding Complete page is opened,
     * this step must remain PENDING.
     *
     * Only the active page is changed to In Progress below.
     */

    $statuses['complete'] = 'pending';


    /*
     * Nothing below an unfinished step may show a tick.
     */

    $statuses = onboarding_enforce_order($statuses, 'agent');


    /*
     * Opening a step starts it, so ONLY the current page that
     * is still pending becomes In Progress.
     *
     * All other pending steps remain Pending.
     */

    if (
        isset($statuses[$active_step]) &&
        $statuses[$active_step] === 'pending'
    ) {

        $statuses[$active_step] = 'in_progress';
    }


    /*
     * If the agent is actually on the final Onboarding Complete
     * page, show In Progress until every required step is completed.
     * If all required steps are completed, show Completed.
     */

    if ($active_step === 'complete') {

        if (
            $statuses['ekyc'] === 'completed' &&
            $statuses['background'] === 'completed' &&
            $statuses['training'] === 'completed' &&
            $statuses['hierarchy'] === 'completed' &&
            $statuses['wallet'] === 'completed' &&
            $statuses['tools'] === 'completed'
        ) {

            $statuses['complete'] = 'completed';

        } else {

            $statuses['complete'] = 'in_progress';
        }
    }


    return $statuses;
}


/* =====================================================
   RENDER SHELL OPEN
===================================================== */

function render_shell_open($pdo, $user_id, $active_step, $page_title = '')
{
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([(int)$user_id]);

    $user = $stmt->fetch() ?: [
        'id' => (int)$user_id,
        'full_name' => 'Agent',
        'role' => 'agent'
    ];

    onboarding_shell_render_open(
        $pdo,
        $user,
        'agent',
        get_step_statuses(
            $pdo,
            (int)$user_id,
            (string)$active_step
        ),
        (string)$active_step,
        $page_title !== ''
            ? (string)$page_title
            : 'Agent onboarding'
    );
}

function render_shell_close()
{
    onboarding_shell_render_close();
}

?>