<?php
/*
    ============================================
    CREDIT & RISK SCORING ENGINE (v1 — rule-based)
    ============================================

    This is a weighted rule-based scorer, not a trained ML model.
    A real ML model needs historical repayment/default data, which
    doesn't exist yet since no merchant has transacted through the
    platform. Once repayment data accumulates, this function's body
    can be swapped for a call to a trained model without changing
    anything that calls it.

    Usage:
        require_once __DIR__ . '/scoring.php';
        $result = calculateRiskScore($pdo, $user_id);
        // $result = ['score' => int, 'category' => 'low'|'medium'|'high',
        //            'credit_limit' => float, 'factors' => array]
*/

function calculateRiskScore(PDO $pdo, int $user_id): ?array
{
    $userStmt = $pdo->prepare("SELECT role, kyc_status, business_verification_status, business_type FROM users WHERE id = ?");
    $userStmt->execute([$user_id]);
    $user = $userStmt->fetch();

    if (!$user || $user['role'] !== 'merchant') {
        return null;
    }

    $bvStmt = $pdo->prepare("SELECT years_in_business, monthly_turnover FROM business_verifications WHERE user_id = ? ORDER BY submitted_at DESC LIMIT 1");
    $bvStmt->execute([$user_id]);
    $bv = $bvStmt->fetch();

    $factors = [];
    $score = 0;

    // Factor 1: eKYC status — weight 20
    $kycPoints = match ($user['kyc_status'] ?? 'not_submitted') {
        'approved' => 20,
        'pending'  => 10,
        default    => 0,
    };
    $score += $kycPoints;
    $factors['ekyc_status'] = ['value' => $user['kyc_status'] ?? 'not_submitted', 'points' => $kycPoints, 'max' => 20];

    // Factor 2: Business verification status — weight 25
    $bvPoints = match ($user['business_verification_status'] ?? 'not_submitted') {
        'approved' => 25,
        'pending'  => 10,
        default    => 0,
    };
    $score += $bvPoints;
    $factors['business_verification_status'] = ['value' => $user['business_verification_status'] ?? 'not_submitted', 'points' => $bvPoints, 'max' => 25];

    // Factor 3: Business type — weight 15
    $lowRiskTypes = ['Grocery / Kirana', 'Pharmacy'];
    $medRiskTypes = ['Retail Store', 'Apparel'];
    $businessType = $user['business_type'] ?? '';
    $typePoints = in_array($businessType, $lowRiskTypes) ? 15
                : (in_array($businessType, $medRiskTypes) ? 10
                : ($businessType ? 6 : 0));
    $score += $typePoints;
    $factors['business_type'] = ['value' => $businessType ?: 'Not set', 'points' => $typePoints, 'max' => 15];

    // Factor 4: Years in business — weight 20
    $years = $bv['years_in_business'] ?? null;
    $yearsPoints = 0;
    if ($years !== null) {
        $yearsPoints = $years >= 5 ? 20 : ($years >= 3 ? 15 : ($years >= 1 ? 10 : 5));
    }
    $score += $yearsPoints;
    $factors['years_in_business'] = ['value' => $years ?? 'Not submitted', 'points' => $yearsPoints, 'max' => 20];

    // Factor 5: Monthly turnover — weight 20
    $turnoverMap = ['below_1L' => 5, '1L_5L' => 10, '5L_25L' => 15, '25L_1Cr' => 18, 'above_1Cr' => 20];
    $turnover = $bv['monthly_turnover'] ?? null;
    $turnoverPoints = $turnoverMap[$turnover] ?? 0;
    $score += $turnoverPoints;
    $factors['monthly_turnover'] = ['value' => $turnover ?? 'Not submitted', 'points' => $turnoverPoints, 'max' => 20];

    // Bands → category + credit limit
    if ($score >= 80) {
        $category = 'low';
        $creditLimit = 500000;
    } elseif ($score >= 50) {
        $category = 'medium';
        $creditLimit = 200000;
    } else {
        $category = 'high';
        $creditLimit = 50000;
    }

    return [
        'score' => $score,
        'category' => $category,
        'credit_limit' => $creditLimit,
        'factors' => $factors,
    ];
}

/**
 * Runs the scoring engine and persists the result: updates users table
 * and logs a row in credit_assessments for audit history.
 */
function runAndSaveRiskAssessment(PDO $pdo, int $user_id): ?array
{
    $result = calculateRiskScore($pdo, $user_id);
    if ($result === null) return null;

    $pdo->prepare("UPDATE users SET risk_score = ?, risk_category = ?, credit_limit = ? WHERE id = ?")
        ->execute([$result['score'], $result['category'], $result['credit_limit'], $user_id]);

    $pdo->prepare(
        "INSERT INTO credit_assessments (user_id, risk_score, risk_category, credit_limit, factors, is_override)
         VALUES (?, ?, ?, ?, ?, 0)"
    )->execute([$user_id, $result['score'], $result['category'], $result['credit_limit'], json_encode($result['factors'])]);

    return $result;
}
