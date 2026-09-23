<?php
/**
 * INDBIN : cross-module workflow.
 *
 * This file is the integration. Everything else is three modules sharing a
 * database; these functions are what make them one CRM.
 *
 * The post-activation flow, as the journey chart describes it:
 *
 *     CUSTOMER raises a requirement
 *            |
 *            v
 *     LEAD created, routed to an agent (or a merchant directly)
 *            |
 *     AGENT understands the need, suggests products, runs an eligibility check
 *            |
 *     MERCHANT quotes  <---->  AGENT assists  <---->  CUSTOMER decides
 *            |
 *            v
 *     APPLICATION  (one row, all three parties on it)
 *            |
 *     submitted -> kyc_check -> credit_review -> approved -> disbursed
 *            |                                       |
 *            +-- rejected, everyone notified         +-- COMMISSIONS accrue
 *
 * Two rules hold the whole thing together:
 *
 *   1. There is one applications table. A customer request and a merchant
 *      conversion are the same row, not two unrelated ones.
 *   2. Commission accrues on disbursement, never on approval. Approving
 *      something is not the same as money moving, and paying out on approval
 *      is how a CRM ends up owing commission on a loan that never funded.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------
// Stages
// ---------------------------------------------------------------------

const APP_STAGES = ['submitted', 'kyc_check', 'credit_review', 'approved', 'disbursed'];

/** Stage index, for drawing a progress track. */
function stage_index(string $stage): int
{
    $i = array_search($stage, APP_STAGES, true);
    return $i === false ? 0 : (int)$i;
}

// ---------------------------------------------------------------------
// Leads
// ---------------------------------------------------------------------

/**
 * Create a lead and route it.
 *
 * Routing order, most specific first:
 *   1. the agent who already owns this customer
 *   2. the merchant who referred them
 *   3. unassigned, for the admin queue to hand out
 *
 * Sticky ownership matters: a customer who has spoken to one agent should
 * not be handed to a different one on their second request.
 */
function create_lead(PDO $pdo, array $in): int
{
    $mobile = preg_replace('/\D/', '', (string)($in['mobile'] ?? ''));
    if (!preg_match('/^[6-9]\d{9}$/', $mobile)) {
        throw new InvalidArgumentException('A valid 10-digit mobile is required for a lead.');
    }

    $pdo->beginTransaction();
    try {
        // One CRM record per mobile, whoever enters it.
        $stmt = $pdo->prepare("SELECT * FROM customers WHERE mobile = ?");
        $stmt->execute([$mobile]);
        $customer = $stmt->fetch();

        if (!$customer) {
            $pdo->prepare(
                "INSERT INTO customers (user_id, agent_id, merchant_id, name, mobile, email,
                                        requirement, source, status)
                 VALUES (?,?,?,?,?,?,?,?, 'prospect')"
            )->execute([
                $in['customer_user_id'] ?? null,
                $in['agent_id'] ?? null,
                $in['merchant_id'] ?? null,
                (string)($in['name'] ?? 'Unnamed'),
                $mobile,
                $in['email'] ?? null,
                $in['requirement'] ?? null,
                $in['source'] ?? 'agent_sourced',
            ]);
            $customerId = (int)$pdo->lastInsertId();
            $ownerAgent = $in['agent_id'] ?? null;
        } else {
            $customerId = (int)$customer['id'];
            // Existing owner wins over whoever is entering this lead.
            $ownerAgent = $customer['agent_id'] ?: ($in['agent_id'] ?? null);

            if (!$customer['user_id'] && !empty($in['customer_user_id'])) {
                $pdo->prepare("UPDATE customers SET user_id = ? WHERE id = ?")
                    ->execute([(int)$in['customer_user_id'], $customerId]);
            }
        }

        $merchantId = $in['merchant_id'] ?? ($customer['merchant_id'] ?? null);

        $pdo->prepare(
            "INSERT INTO leads (customer_id, agent_id, merchant_id, name, mobile, email,
                                requirement, product_code, amount, source, status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)"
        )->execute([
            $customerId, $ownerAgent, $merchantId,
            (string)($in['name'] ?? 'Unnamed'), $mobile, $in['email'] ?? null,
            $in['requirement'] ?? null, $in['product_code'] ?? null,
            round((float)($in['amount'] ?? 0), 2),
            $in['source'] ?? 'agent_sourced',
            $ownerAgent ? 'assigned' : 'new',
        ]);
        $leadId = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    audit_log($pdo, 'lead.created', 'leads', (string)$leadId, null,
              ['customer_id' => $customerId, 'agent_id' => $ownerAgent]);

    if ($ownerAgent) {
        notify($pdo, (int)$ownerAgent, 'New lead assigned',
               ($in['name'] ?? 'A customer') . ' needs ' . ($in['requirement'] ?? 'assistance') . '.');
    }
    if ($merchantId) {
        notify($pdo, (int)$merchantId, 'New customer enquiry',
               'A lead has been routed to your store.');
    }

    return $leadId;
}

/** Assign or reassign a lead. Used by the admin queue and by agent handover. */
function assign_lead(PDO $pdo, int $leadId, int $agentId, ?string $note = null): void
{
    $stmt = $pdo->prepare("SELECT * FROM leads WHERE id = ?");
    $stmt->execute([$leadId]);
    $lead = $stmt->fetch();
    if (!$lead) {
        throw new RuntimeException('Lead not found.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE leads SET agent_id = ?, status = 'assigned' WHERE id = ?")
            ->execute([$agentId, $leadId]);

        // Ownership follows the lead, so the next request routes the same way.
        if ($lead['customer_id']) {
            $pdo->prepare("UPDATE customers SET agent_id = ? WHERE id = ?")
                ->execute([$agentId, (int)$lead['customer_id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    audit_log($pdo, 'lead.assigned', 'leads', (string)$leadId,
              ['agent_id' => $lead['agent_id']], ['agent_id' => $agentId, 'note' => $note]);
    notify($pdo, $agentId, 'Lead assigned to you', $lead['name'] . ' (' . $lead['mobile'] . ').');
}

// ---------------------------------------------------------------------
// Applications
// ---------------------------------------------------------------------

/**
 * Raise an application. Any of the three parties may call this; whoever is
 * involved goes on the row.
 *
 * Called by:
 *   customer/dashboard/applications.php   customer raises a requirement
 *   agent   dashboard                     agent applies on a customer's behalf
 *   merchant dashboard                    merchant converts a quote
 */
function create_application(PDO $pdo, array $in): int
{
    $customerId = isset($in['customer_id']) ? (int)$in['customer_id'] : null;
    $agentId    = isset($in['agent_id'])    ? (int)$in['agent_id']    : null;
    $merchantId = isset($in['merchant_id']) ? (int)$in['merchant_id'] : null;

    if (!$customerId && !$merchantId) {
        throw new InvalidArgumentException('An application needs a customer or a merchant.');
    }

    $amount = round((float)($in['amount'] ?? 0), 2);
    if ($amount < 0 || $amount > 50_000_000) {
        throw new InvalidArgumentException('Amount is out of range.');
    }

    // If the customer already has an agent, that agent is credited even when
    // the application comes in from somewhere else. Otherwise the agent who
    // nurtured the lead loses the commission to whoever clicked submit.
    if (!$agentId && $customerId) {
        $stmt = $pdo->prepare("SELECT agent_id FROM customers WHERE user_id = ? AND agent_id IS NOT NULL");
        $stmt->execute([$customerId]);
        $agentId = $stmt->fetchColumn() ?: null;
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "INSERT INTO applications
                (application_number, customer_id, agent_id, merchant_id, lead_id, quote_id,
                 product_code, product_name, category, application_type, purpose,
                 amount, tenure_months, notes, current_stage, status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'submitted', 'submitted')"
        )->execute([
            next_application_number($pdo),
            $customerId, $agentId, $merchantId,
            $in['lead_id']  ?? null,
            $in['quote_id'] ?? null,
            $in['product_code'] ?? null,
            (string)($in['product_name'] ?? 'Unspecified'),
            (string)($in['category'] ?? 'loan'),
            (string)($in['application_type'] ?? 'standard'),
            $in['purpose'] ?? null,
            $amount,
            $in['tenure_months'] ?? null,
            $in['notes'] ?? null,
        ]);
        $appId = (int)$pdo->lastInsertId();

        record_stage($pdo, $appId, null, 'submitted', 'Application raised');

        if (!empty($in['lead_id'])) {
            $pdo->prepare("UPDATE leads SET status = 'converted' WHERE id = ?")
                ->execute([(int)$in['lead_id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    audit_log($pdo, 'application.created', 'applications', (string)$appId, null,
              ['customer' => $customerId, 'agent' => $agentId, 'merchant' => $merchantId]);

    notify_parties($pdo, $appId, 'Application submitted',
                   'Application for ' . ($in['product_name'] ?? 'a product') . ' has been raised.');

    return $appId;
}

/** INDBIN-style reference: APP-260911-0007 */
function next_application_number(PDO $pdo): string
{
    $seq = (int)$pdo->query("SELECT COALESCE(MAX(id),0)+1 FROM applications")->fetchColumn();
    return 'APP-' . date('ymd') . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
}

/**
 * Move an application along the track.
 *
 * Forward-only, one stage at a time, except for a rejection which can happen
 * from anywhere. Allowing arbitrary jumps is how an application ends up
 * disbursed without a credit review.
 */
function advance_application(
    PDO $pdo, int $appId, string $toStage, ?int $actorId = null, ?string $note = null
): bool {
    if (!in_array($toStage, APP_STAGES, true)) {
        throw new InvalidArgumentException('Unknown stage: ' . $toStage);
    }

    $stmt = $pdo->prepare("SELECT * FROM applications WHERE id = ? FOR UPDATE");

    $pdo->beginTransaction();
    try {
        $stmt->execute([$appId]);
        $app = $stmt->fetch();
        if (!$app) {
            $pdo->rollBack();
            return false;
        }

        $from = (string)$app['current_stage'];
        if (stage_index($toStage) <= stage_index($from)) {
            $pdo->rollBack();   // already there, or backwards
            return false;
        }
        if (stage_index($toStage) > stage_index($from) + 1) {
            $pdo->rollBack();
            throw new RuntimeException("Cannot skip from {$from} to {$toStage}.");
        }

        $status = match ($toStage) {
            'kyc_check', 'credit_review' => 'under_review',
            'approved'                   => 'approved',
            'disbursed'                  => 'disbursed',
            default                      => $app['status'],
        };

        $pdo->prepare(
            "UPDATE applications
                SET current_stage = ?, status = ?, reviewed_at = NOW(),
                    decision_by = COALESCE(?, decision_by),
                    disbursed_at = IF(? = 'disbursed', NOW(), disbursed_at)
              WHERE id = ?"
        )->execute([$toStage, $status, $actorId, $toStage, $appId]);

        record_stage($pdo, $appId, $from, $toStage, $note);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // Commission accrues here and nowhere else.
    if ($toStage === 'disbursed') {
        accrue_commissions($pdo, $appId);
    }

    audit_log($pdo, 'application.' . $toStage, 'applications', (string)$appId, null, ['note' => $note]);
    notify_parties($pdo, $appId, 'Application ' . str_replace('_', ' ', $toStage),
                   $note ?: 'Your application has moved to ' . str_replace('_', ' ', $toStage) . '.');
    return true;
}

/** Reject from any stage. Requires a reason the customer will actually read. */
function reject_application(PDO $pdo, int $appId, string $reason, ?int $actorId = null): bool
{
    $reason = trim($reason);
    if ($reason === '') {
        throw new InvalidArgumentException('A rejection needs a reason.');
    }

    $done = $pdo->prepare(
        "UPDATE applications
            SET status = 'rejected', rejection_reason = ?, decision_by = ?, reviewed_at = NOW()
          WHERE id = ? AND status NOT IN ('disbursed','completed','withdrawn')"
    );
    $done->execute([$reason, $actorId, $appId]);

    if ($done->rowCount() === 0) {
        return false;
    }

    record_stage($pdo, $appId, null, 'rejected', $reason);
    audit_log($pdo, 'application.rejected', 'applications', (string)$appId, null, ['reason' => $reason]);

    // Everyone hears, not just the customer. An agent chasing a dead
    // application is the most common complaint in a CRM without this.
    notify_parties($pdo, $appId, 'Application not approved', $reason);
    return true;
}

function record_stage(PDO $pdo, int $appId, ?string $from, string $to, ?string $note): void
{
    $pdo->prepare(
        "INSERT INTO application_events (application_id, actor_id, actor_role, from_stage, to_stage, note)
         VALUES (?,?,?,?,?,?)"
    )->execute([
        $appId,
        $_SESSION['user_id'] ?? null,
        $_SESSION['role'] ?? 'system',
        $from, $to, $note,
    ]);
}

/** Notify whichever of the three parties are on the application. */
function notify_parties(PDO $pdo, int $appId, string $title, string $message): void
{
    $stmt = $pdo->prepare("SELECT customer_id, agent_id, merchant_id FROM applications WHERE id = ?");
    $stmt->execute([$appId]);
    $row = $stmt->fetch();
    if (!$row) {
        return;
    }
    foreach (['customer_id', 'agent_id', 'merchant_id'] as $col) {
        if (!empty($row[$col])) {
            notify($pdo, (int)$row[$col], $title, $message);
        }
    }
}

// ---------------------------------------------------------------------
// Commissions
// ---------------------------------------------------------------------

/**
 * Accrue commission for everyone entitled on a disbursed application.
 *
 * Rates come from the product catalogue, overridden by an agent's own
 * negotiated rate where one exists. The unique key on
 * (application_id, earner_id, earner_role) makes this safe to call twice:
 * a retry cannot pay anyone a second time.
 */
function accrue_commissions(PDO $pdo, int $appId): void
{
    $stmt = $pdo->prepare(
        "SELECT a.*, pc.agent_commission_rate, pc.merchant_commission_rate
           FROM applications a
           LEFT JOIN product_catalog pc ON pc.product_code = a.product_code
          WHERE a.id = ?"
    );
    $stmt->execute([$appId]);
    $app = $stmt->fetch();

    if (!$app || $app['status'] !== 'disbursed' || (float)$app['amount'] <= 0) {
        return;
    }

    $basis = (float)$app['amount'];

    $earners = [];

    if (!empty($app['agent_id'])) {
        // A negotiated rate on the agent's payout record beats the catalogue.
        $r = $pdo->prepare("SELECT commission_rate, commission_type
                              FROM agent_commission_payout WHERE user_id = ?");
        $r->execute([(int)$app['agent_id']]);
        $own = $r->fetch();

        $rate = ($own && (float)$own['commission_rate'] > 0)
            ? (float)$own['commission_rate']
            : (float)($app['agent_commission_rate'] ?? 0);
        $type = ($own && strtolower((string)$own['commission_type']) === 'fixed') ? 'fixed' : 'percentage';

        $earners[] = ['id' => (int)$app['agent_id'], 'role' => 'agent', 'rate' => $rate, 'type' => $type];
    }

    if (!empty($app['merchant_id'])) {
        $earners[] = [
            'id'   => (int)$app['merchant_id'],
            'role' => 'merchant',
            'rate' => (float)($app['merchant_commission_rate'] ?? 0),
            'type' => 'percentage',
        ];
    }

    foreach ($earners as $e) {
        if ($e['rate'] <= 0) {
            continue;
        }
        $amount = $e['type'] === 'fixed' ? $e['rate'] : round($basis * $e['rate'] / 100, 2);
        if ($amount <= 0) {
            continue;
        }

        $pdo->prepare(
            "INSERT INTO commissions
                (application_id, earner_id, earner_role, basis_amount, rate, commission_type, amount, status)
             VALUES (?,?,?,?,?,?,?, 'accrued')
             ON DUPLICATE KEY UPDATE amount = VALUES(amount), rate = VALUES(rate)"
        )->execute([$appId, $e['id'], $e['role'], $basis, $e['rate'], $e['type'], $amount]);

        if ($e['role'] === 'agent') {
            $pdo->prepare(
                "INSERT INTO agent_wallet (user_id, balance, lifetime_earned)
                 VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE
                    balance = balance + VALUES(balance),
                    lifetime_earned = lifetime_earned + VALUES(lifetime_earned)"
            )->execute([$e['id'], $amount, $amount]);
        }

        notify($pdo, $e['id'], 'Commission earned',
               money($amount) . ' on ' . $app['application_number'] . '.');
        audit_log($pdo, 'commission.accrued', 'commissions', (string)$appId, null,
                  ['earner' => $e['id'], 'amount' => $amount], $e['id']);
    }
}

// ---------------------------------------------------------------------
// Eligibility, shared by all three dashboards
// ---------------------------------------------------------------------

/**
 * What a customer qualifies for right now.
 *
 * The agent's "Eligibility Check & Guide" step and the customer's own
 * "Eligibility Check" are the same question, so they call the same function
 * and cannot give different answers to the same customer.
 */
function eligibility_for(PDO $pdo, int $customerUserId): array
{
    $stmt = $pdo->prepare(
        "SELECT u.kyc_status, ce.credit_score, ce.risk_category,
                ce.max_credit_limit, ce.bnpl_limit, ce.valid_until
           FROM users u
           LEFT JOIN credit_evaluations ce
                  ON ce.user_id = u.id AND ce.is_current = 1
          WHERE u.id = ?"
    );
    $stmt->execute([$customerUserId]);
    $row = $stmt->fetch() ?: [];

    $score    = isset($row['credit_score']) ? (int)$row['credit_score'] : null;
    $kycOk    = ($row['kyc_status'] ?? '') === 'approved';

    $products = $pdo->query(
        "SELECT product_code, product_name, category, requires_credit, min_score
           FROM product_catalog WHERE is_active = 1 ORDER BY category, product_name"
    )->fetchAll();

    $eligible = $blocked = [];
    foreach ($products as $p) {
        if (!$kycOk) {
            $blocked[] = $p + ['reason' => 'Identity verification is not complete.'];
        } elseif ((int)$p['requires_credit'] === 0) {
            $eligible[] = $p;
        } elseif ($score === null) {
            $blocked[] = $p + ['reason' => 'Needs a credit check first.'];
        } elseif ($score < (int)$p['min_score']) {
            $blocked[] = $p + ['reason' => 'Needs a score of ' . (int)$p['min_score'] . ', currently ' . $score . '.'];
        } else {
            $eligible[] = $p;
        }
    }

    return [
        'kyc_approved' => $kycOk,
        'score'        => $score,
        'risk'         => $row['risk_category'] ?? null,
        'credit_limit' => (float)($row['max_credit_limit'] ?? 0),
        'bnpl_limit'   => (float)($row['bnpl_limit'] ?? 0),
        'valid_until'  => $row['valid_until'] ?? null,
        'eligible'     => $eligible,
        'blocked'      => $blocked,
    ];
}

// ---------------------------------------------------------------------
// Party codes
// ---------------------------------------------------------------------

/** INDBINC / INDBINA / INDBINM / INDBINX, by role. */
function issue_party_code(PDO $pdo, int $userId): ?string
{
    ensure_party_code_sequences($pdo); // DDL must never run inside the transaction below
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT role, party_code, account_status FROM users WHERE id = ? FOR UPDATE");
        $stmt->execute([$userId]);
        $u = $stmt->fetch();

        if (!$u) {
            $pdo->rollBack();
            return null;
        }
        if (!empty($u['party_code'])) {
            $pdo->commit();
            return $u['party_code'];
        }

        $letter = match ($u['role']) {
            'agent'    => 'A',
            'merchant' => 'M',
            'admin'    => 'X',
            default    => 'C',
        };
        $code = 'INDBIN' . $letter . str_pad((string)next_party_number($pdo, $letter), 7, '0', STR_PAD_LEFT);

        $pdo->prepare("UPDATE users SET party_code = ?, account_status = 'active' WHERE id = ?")
            ->execute([$code, $userId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('issue_party_code: ' . $e->getMessage());
        return null;
    }

    audit_log($pdo, 'party.activated', 'users', (string)$userId, null, ['party_code' => $code], $userId);
    notify($pdo, $userId, 'Account active', 'Your INDBIN ID is ' . $code . '.');
    return $code;
}

/**
 * The next number for a role's own sequence, e.g. the 3rd agent ever
 * activated gets 3 here regardless of what user id they happen to have.
 *
 * party_code_sequences holds one row per letter with a row-level lock via
 * FOR UPDATE, so two agents activating at the same moment correctly get
 * consecutive numbers instead of racing to read the same "next" value -
 * while a merchant activating at the same instant is untouched, since it
 * locks a different row.
 *
 * Must be called inside the caller's transaction (issue_party_code and
 * issue_customer_code both already open one) so the row lock is held from
 * the read through to the eventual UPDATE ... SET party_code, and released
 * together with everything else on commit or rollback.
 */
/**
 * Creates party_code_sequences and seeds every role's starting number if
 * either is missing, so a code can be issued on first use rather than
 * depending on a separate migration step having already run.
 */
function ensure_party_code_sequences(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return; // once per request is enough

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS party_code_sequences (
            role_letter  CHAR(1)      NOT NULL PRIMARY KEY,
            next_number  INT UNSIGNED NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    foreach (['C', 'M', 'A', 'X'] as $letter) {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(MAX(CAST(SUBSTRING(party_code, 8) AS UNSIGNED)), 0)
             FROM users WHERE party_code LIKE ?"
        );
        $stmt->execute(["INDBIN{$letter}%"]);
        $maxParty = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT COALESCE(MAX(CAST(SUBSTRING(customer_code, 8) AS UNSIGNED)), 0)
             FROM users WHERE customer_code LIKE ?"
        );
        $stmt->execute(["INDBIN{$letter}%"]);
        $maxCustomer = (int)$stmt->fetchColumn();

        $pdo->prepare("INSERT IGNORE INTO party_code_sequences (role_letter, next_number) VALUES (?, ?)")
            ->execute([$letter, max($maxParty, $maxCustomer) + 1]);
    }

    $checked = true;
}

function next_party_number(PDO $pdo, string $letter): int
{
    $seq = $pdo->prepare("SELECT next_number FROM party_code_sequences WHERE role_letter = ? FOR UPDATE");
    $seq->execute([$letter]);
    $number = $seq->fetchColumn();

    if ($number === false) {
        // No row for this letter yet - shouldn't happen once
        // ensure_party_code_sequences() has seeded all four, but starting
        // at 1 rather than fataling keeps a missing row from blocking
        // activation.
        $number = 1;
        $pdo->prepare("INSERT INTO party_code_sequences (role_letter, next_number) VALUES (?, ?)")
            ->execute([$letter, $number + 1]);
    } else {
        $number = (int)$number;
        $pdo->prepare("UPDATE party_code_sequences SET next_number = next_number + 1 WHERE role_letter = ?")
            ->execute([$letter]);
    }

    return $number;
}
