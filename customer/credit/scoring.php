<?php
/**
 * Step 4: Credit evaluation. Scoring engine.
 *
 * This file holds the decision logic only; credit/index.php renders it.
 * Separated because the flow chart routes scoring to a Python microservice
 * over REST, so this rule set is the PHP fallback and the contract the
 * service has to satisfy.
 *
 * Three problems in the current implementation this replaces:
 *  1. scoring ran as a side effect of a GET request on onboarding.php?step=4,
 *     so a refresh could re-score
 *  2. the score was two hardcoded outcomes: 780/500000 if a bank account
 *     existed at all, 640/150000 otherwise
 *  3. only the latest score was kept, leaving no record of what an earlier
 *     decision was based on
 */

declare(strict_types=1);

const CREDIT_AUTO_APPROVE_CEILING = 200000.00;   // above this, a human decides
const CREDIT_MODEL_VERSION        = 'rules-1.0';

/**
 * Score a customer and persist the result as a new, current evaluation.
 * Idempotent within its validity window: calling it twice in a day returns
 * the existing evaluation rather than writing another.
 */
function run_credit_evaluation(PDO $pdo, int $userId, bool $force = false): array
{
    if (!$force) {
        $stmt = $pdo->prepare("SELECT * FROM credit_evaluations
                                WHERE user_id = ? AND is_current = 1
                                  AND (valid_until IS NULL OR valid_until >= CURDATE())");
        $stmt->execute([$userId]);
        if ($existing = $stmt->fetch()) {
            return $existing;
        }
    }

    $features = collect_credit_features($pdo, $userId);
    [$score, $reasons] = score_from_features($features);

    $risk = match (true) {
        $score >= 730 => 'low',
        $score >= 650 => 'moderate',
        $score >= 580 => 'high',
        default       => 'declined',
    };

    $limit = match ($risk) {
        'low'      => 500000.00,
        'moderate' => 150000.00,
        'high'     => 40000.00,
        default    => 0.00,
    };

    // Income band caps the limit. A score alone should not set one.
    $incomeCap = match ($features['income_band']) {
        'gt_25l'  => 1000000.00,
        '12l_25l' => 500000.00,
        '6l_12l'  => 250000.00,
        '3l_6l'   => 100000.00,
        'lt_3l'   => 40000.00,
        default   => 50000.00,
    };
    $limit = min($limit, $incomeCap);

    $decision = match (true) {
        $risk === 'declined'                  => 'declined',
        $limit > CREDIT_AUTO_APPROVE_CEILING  => 'manual_review',
        !$features['kyc_approved']            => 'manual_review',
        !$features['bank_verified']           => 'manual_review',
        default                               => 'auto_approved',
    };

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE credit_evaluations SET is_current = 0 WHERE user_id = ?")
            ->execute([$userId]);

        $next = $pdo->prepare("SELECT COALESCE(MAX(evaluation_no),0)+1 FROM credit_evaluations WHERE user_id = ?");
        $next->execute([$userId]);
        $evalNo = (int)$next->fetchColumn();

        $pdo->prepare(
            "INSERT INTO credit_evaluations
                (user_id, evaluation_no, is_current, engine, model_version, credit_score,
                 risk_category, max_credit_limit, bnpl_limit, reason_codes, input_snapshot,
                 decision, valid_until)
             VALUES (?,?,1,'rule',?,?,?,?,?,?,?,?, DATE_ADD(CURDATE(), INTERVAL 90 DAY))"
        )->execute([
            $userId, $evalNo, CREDIT_MODEL_VERSION, $score, $risk, $limit,
            round($limit * 0.20, 2),
            json_encode($reasons, JSON_UNESCAPED_UNICODE),
            json_encode($features, JSON_UNESCAPED_UNICODE),
            $decision,
        ]);
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('run_credit_evaluation: ' . $e->getMessage());
        throw $e;
    }

    audit_log($pdo, 'credit.evaluated', 'credit_evaluations', (string)$id, null,
              ['score' => $score, 'risk' => $risk, 'decision' => $decision], $userId);

    $stmt = $pdo->prepare("SELECT * FROM credit_evaluations WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

/** Gather the inputs. Kept explicit so the snapshot is reproducible. */
function collect_credit_features(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        "SELECT u.kyc_status, u.created_at AS joined_at,
                p.employment_type, p.annual_income_band, p.address_same_as_id,
                (SELECT COUNT(*) FROM payment_instruments pi
                  WHERE pi.user_id = u.id AND pi.verification = 'verified') AS verified_instruments,
                (SELECT COUNT(*) FROM customer_documents d
                  WHERE d.user_id = u.id AND d.doc_code = 'PAN_CARD'
                    AND d.is_current = 1 AND d.status = 'verified') AS pan_verified,
                (SELECT COUNT(*) FROM repayments r
                  WHERE r.user_id = u.id AND r.status = 'overdue') AS overdue_count,
                (SELECT COUNT(*) FROM repayments r
                  WHERE r.user_id = u.id AND r.status = 'paid') AS paid_count
           FROM users u
           LEFT JOIN customer_profiles p ON p.user_id = u.id
          WHERE u.id = ?"
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch() ?: [];

    return [
        'kyc_approved'    => ($row['kyc_status'] ?? '') === 'approved',
        'pan_verified'    => (int)($row['pan_verified'] ?? 0) > 0,
        'bank_verified'   => (int)($row['verified_instruments'] ?? 0) > 0,
        'employment'      => $row['employment_type'] ?? null,
        'income_band'     => $row['annual_income_band'] ?? null,
        'address_matches' => (int)($row['address_same_as_id'] ?? 1) === 1,
        'overdue_count'   => (int)($row['overdue_count'] ?? 0),
        'paid_count'      => (int)($row['paid_count'] ?? 0),
        'tenure_days'     => isset($row['joined_at'])
            ? (int)((time() - strtotime($row['joined_at'])) / 86400) : 0,
        'scored_at'       => date('c'),
    ];
}

/**
 * Transparent additive model. Every movement carries a reason code, because
 * a declined applicant is entitled to know which factor drove the decision.
 *
 * @return array{0:int, 1:array<int, array{code:string, effect:int, note:string}>}
 */
function score_from_features(array $f): array
{
    $score   = 600;
    $reasons = [];

    $apply = static function (string $code, int $effect, string $note) use (&$score, &$reasons): void {
        $score += $effect;
        $reasons[] = ['code' => $code, 'effect' => $effect, 'note' => $note];
    };

    if ($f['kyc_approved'])  $apply('KYC_OK',      40, 'Identity verified');
    else                     $apply('KYC_PENDING',-60, 'Identity not yet verified');

    if ($f['pan_verified'])  $apply('PAN_OK',      25, 'PAN on file and verified');
    else                     $apply('PAN_MISSING',-35, 'No verified PAN');

    if ($f['bank_verified']) $apply('BANK_OK',     45, 'Bank account confirmed');
    else                     $apply('BANK_UNVER', -30, 'Bank account not confirmed');

    $apply('INCOME_BAND', match ($f['income_band']) {
        'gt_25l'  => 60, '12l_25l' => 45, '6l_12l' => 30, '3l_6l' => 10, 'lt_3l' => -10, default => -20,
    }, 'Declared income band: ' . ($f['income_band'] ?? 'not stated'));

    $apply('EMPLOYMENT', match ($f['employment']) {
        'salaried' => 30, 'self_employed' => 15, 'retired' => 5, 'student' => -20, default => 0,
    }, 'Employment type: ' . ($f['employment'] ?? 'not stated'));

    if (!$f['address_matches']) $apply('ADDR_MISMATCH', -15, 'Current address differs from ID address');

    if ($f['overdue_count'] > 0) {
        $apply('OVERDUE', -25 * min($f['overdue_count'], 4),
               $f['overdue_count'] . ' overdue instalment(s) on file');
    }
    if ($f['paid_count'] > 0) {
        $apply('REPAY_HISTORY', min(5 * $f['paid_count'], 40),
               $f['paid_count'] . ' instalment(s) repaid on time');
    }
    if ($f['tenure_days'] > 180) $apply('TENURE', 15, 'Account older than six months');

    return [max(300, min(900, $score)), $reasons];
}
