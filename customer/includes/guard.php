<?php
/**
 * Customer module: the seven-step onboarding gate.
 *
 * require_customer() and require_admin() now live in core/guard.php, shared
 * by all four roles. What stays here is the step sequencing, which only the
 * customer flow has.
 */

declare(strict_types=1);

/**
 * The step a customer is really on.
 *
 * onboarding_step is the raw counter. It is not the same thing as the step
 * to show, because step 4 is optional: a customer who does not want a
 * credit product is parked on 4 with nothing to do there.
 *
 * Without this, steps 4 and 5 bounced off each other forever. Step 4 sent
 * you to 5 because credit did not apply, and step 5 sent you back to 4
 * because the saved counter had not reached 5 yet. Chrome gave up with
 * ERR_TOO_MANY_REDIRECTS.
 */
function effective_step(PDO $pdo, array $user): int
{
    return max(1, min(CUSTOMER_FINAL_STEP, (int)$user['onboarding_step']));
}

function require_step(PDO $pdo, array $user, int $step): int
{
    $reachable = effective_step($pdo, $user);

    // Step 4 no longer redirects anywhere on its own. It asks the customer
    // whether they want a credit check and they answer, which is what the
    // flow chart's "(if required)" actually means. The old auto-skip fired
    // for every customer and took the risk assessment with it.
    if ($step > $reachable) {
        // No flash when the gap is a single step. Landing one step early is
        // ordinary navigation, not a mistake worth telling them about, and
        // during the loop above it filled the session with the same notice.
        if ($step > $reachable + 1) {
            flash('info', 'Finish the earlier steps first.');
        }
        redirect(step_url($reachable));
    }

    return $step;
}

/**
 * Advance the saved step. Never moves backwards, so a customer revisiting
 * step 3 cannot reset their progress.
 */
function advance_step(PDO $pdo, int $userId, int $to): void
{
    $to = max(1, min(CUSTOMER_FINAL_STEP, $to));
    $pdo->prepare("UPDATE users
                      SET onboarding_step = GREATEST(onboarding_step, ?),
                          account_status  = IF(? >= " . CUSTOMER_FINAL_STEP . ", account_status, 'onboarding')
                    WHERE id = ?")
        ->execute([$to, $to, $userId]);
}

/**
 * Issue the customer code. Only once every step is done and eKYC is approved,
 * and only inside a transaction so two parallel requests cannot mint two codes.
 */
function issue_customer_code(PDO $pdo, int $userId): ?string
{
    ensure_party_code_sequences($pdo); // DDL must never run inside the transaction below
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT customer_code, onboarding_step, kyc_status,
                                       full_name, mobile, email
                                 FROM users WHERE id = ? FOR UPDATE");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if (!$row || $row['kyc_status'] !== 'approved'
            || (int)$row['onboarding_step'] < CUSTOMER_FINAL_STEP) {
            $pdo->rollBack();
            return null;
        }
        if (!empty($row['customer_code'])) {
            $pdo->commit();
            return $row['customer_code'];
        }

        // Shares its counter with issue_party_code()'s own 'C' case in
        // core/workflow.php: both mint a customer code, so both draw from
        // the same next_party_number('C') sequence rather than each having
        // a private notion of "the next customer".
        $code = 'INDBINC' . str_pad((string)next_party_number($pdo, 'C'), 7, '0', STR_PAD_LEFT);
        $pdo->prepare("UPDATE users SET customer_code = ?, account_status = 'active' WHERE id = ?")
            ->execute([$code, $userId]);

        // customers is a directory of everyone who has ever been a
        // customer, agent-sourced or not - previously only
        // agent/dashboard/leads.php and customers.php ever inserted into
        // it, converting an agent's own lead, so a self-registered
        // customer (the ordinary signup path) never appeared there at
        // all. ON DUPLICATE KEY UPDATE covers the case where an agent
        // already logged this person as a prospect, by this same mobile
        // number, before they signed up and activated themselves.
        $pdo->prepare(
            "INSERT INTO customers (user_id, name, mobile, email, source, status)
             VALUES (?, ?, ?, ?, 'self', 'active')
             ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id), status = 'active'"
        )->execute([$userId, $row['full_name'], $row['mobile'], $row['email']]);

        $pdo->commit();

        audit_log($pdo, 'customer.activated', 'users', (string)$userId, null, ['customer_code' => $code], $userId);
        notify($pdo, $userId, 'Account active', "Your customer ID is {$code}.");
        return $code;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('issue_customer_code: ' . $e->getMessage());
        return null;
    }
}

/** Per-step status labels for the stepper. */
function step_statuses(PDO $pdo, array $user): array
{
    $saved      = (int)$user['onboarding_step'];
    $kyc        = $user['kyc_status'];
    $eval       = current_evaluation($pdo, (int)$user['id']);

    $out = [];
    foreach (CUSTOMER_STEPS as $n => $cfg) {
        if ($n === 4) {
            $out[$n] = match (true) {
                $eval !== null      => 'Completed',
                $saved > 4          => 'Not required',
                $saved === 4        => 'In progress',
                default             => 'Pending',
            };
            continue;
        }
        if ($n === 2) {
            $out[$n] = match ($kyc) {
                'approved' => 'Completed',
                'pending'  => 'In review',
                'rejected' => 'Rejected',
                'resubmit' => 'Resubmit',
                default    => $saved >= 2 ? 'In progress' : 'Pending',
            };
            continue;
        }
        if ($n === CUSTOMER_FINAL_STEP) {
            // A customer who has not reached the last step yet is simply
            // Pending on it, the same as any other step they have not got
            // to. "Awaiting verification" only applies once they are there
            // and the only thing left is the KYC decision.
            $out[$n] = match (true) {
                $saved >= CUSTOMER_FINAL_STEP && $kyc === 'approved' => 'Completed',
                $saved >= CUSTOMER_FINAL_STEP                        => 'In review',
                default                                              => 'Pending',
            };
            continue;
        }
        $out[$n] = $saved > $n ? 'Completed' : ($saved === $n ? 'In progress' : 'Pending');
    }
    return $out;
}
