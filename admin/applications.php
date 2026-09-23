<?php

/**
 * Every application, whoever raised it.
 *
 * Admin can assign an agent to an application.
 * When an agent is assigned, the related customer is also
 * automatically assigned to that agent through
 * customer_agent_assignments.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/shell.php';

$admin = require_admin($pdo);


/* =========================================================
   ASSIGN / UNASSIGN AGENT
   ========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_agent') {

    csrf_verify();

    $appId   = post_int('application_id');
    $agentId = post_int('agent_id') ?: null;

    if (!$appId) {
        flash('error', 'Invalid application.');
        redirect(BASE_URL . '/admin/applications.php');
    }


    /* -----------------------------------------------------
       Get application and customer
       ----------------------------------------------------- */

    $stmt = $pdo->prepare("
        SELECT
            id,
            customer_id
        FROM applications
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([$appId]);

    $application = $stmt->fetch();

    if (!$application) {
        flash('error', 'Application not found.');
        redirect(BASE_URL . '/admin/applications.php');
    }


    /*
     * applications.customer_id refers to users.id.
     */
    $customerUserId = (int)$application['customer_id'];


    /* -----------------------------------------------------
       Find the corresponding customers.id
       ----------------------------------------------------- */

    $stmt = $pdo->prepare("
        SELECT
            id,
            user_id,
            name
        FROM customers
        WHERE user_id = ?
        LIMIT 1
    ");

    $stmt->execute([$customerUserId]);

    $customer = $stmt->fetch();

    if (!$customer) {
        flash('error', 'Customer record not found.');
        redirect(BASE_URL . '/admin/applications.php');
    }

    $customerId = (int)$customer['id'];


    /* -----------------------------------------------------
       Validate selected agent
       ----------------------------------------------------- */

    if ($agentId !== null) {

        $stmt = $pdo->prepare("
            SELECT id
            FROM users
            WHERE id = ?
              AND role = 'agent'
              AND account_status = 'active'
            LIMIT 1
        ");

        $stmt->execute([$agentId]);

        if (!$stmt->fetch()) {
            flash('error', 'That is not an active agent account.');
            redirect(
                $_SERVER['HTTP_REFERER']
                ?? (BASE_URL . '/admin/applications.php')
            );
        }
    }


    /* -----------------------------------------------------
       Update application agent
       ----------------------------------------------------- */

    $stmt = $pdo->prepare("
        UPDATE applications
        SET agent_id = ?
        WHERE id = ?
    ");

    $stmt->execute([
        $agentId,
        $appId
    ]);


    /* =====================================================
       AGENT SELECTED
       Automatically assign customer to that agent
       ===================================================== */

    if ($agentId !== null) {

        /*
         * Check whether this customer already has
         * an active assignment.
         */
        $stmt = $pdo->prepare("
            SELECT id
            FROM customer_agent_assignments
            WHERE customer_id = ?
              AND status = 'active'
            LIMIT 1
        ");

        $stmt->execute([$customerId]);

        $existingAssignment = $stmt->fetch();


        /* -------------------------------------------------
           Existing assignment → update it
           ------------------------------------------------- */

        if ($existingAssignment) {

            $stmt = $pdo->prepare("
                UPDATE customer_agent_assignments
                SET
                    agent_user_id = ?,
                    assigned_by = ?,
                    assigned_at = CURRENT_TIMESTAMP,
                    status = 'active'
                WHERE id = ?
            ");

            $stmt->execute([
                $agentId,
                $admin['id'],
                $existingAssignment['id']
            ]);

        }


        /* -------------------------------------------------
           No assignment → create new assignment
           ------------------------------------------------- */

        else {

            $stmt = $pdo->prepare("
                INSERT INTO customer_agent_assignments
                (
                    customer_id,
                    agent_user_id,
                    assigned_by,
                    assigned_at,
                    status
                )
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    CURRENT_TIMESTAMP,
                    'active'
                )
            ");

            $stmt->execute([
                $customerId,
                $agentId,
                $admin['id']
            ]);
        }


        /* -------------------------------------------------
           Audit log
           ------------------------------------------------- */

        audit_log(
            $pdo,
            'customer.agent_assigned',
            'customer_agent_assignments',
            (string)$customerId,
            null,
            [
                'agent_user_id' => $agentId,
                'application_id' => $appId
            ]
        );


        flash(
            'success',
            'Agent assigned to customer successfully.'
        );
    }


    /* =====================================================
       UNASSIGN AGENT
       ===================================================== */

    else {

        /*
         * Remove the active customer-agent assignment
         * by marking it inactive.
         */
        $stmt = $pdo->prepare("
            UPDATE customer_agent_assignments
            SET
                status = 'inactive',
                assigned_at = CURRENT_TIMESTAMP
            WHERE customer_id = ?
              AND status = 'active'
        ");

        $stmt->execute([
            $customerId
        ]);


        /* -------------------------------------------------
           Audit log
           ------------------------------------------------- */

        audit_log(
            $pdo,
            'customer.agent_unassigned',
            'customer_agent_assignments',
            (string)$customerId,
            null,
            [
                'application_id' => $appId
            ]
        );


        flash(
            'success',
            'Agent unassigned.'
        );
    }


    redirect(
        $_SERVER['HTTP_REFERER']
        ?? (BASE_URL . '/admin/applications.php')
    );
}


/* =========================================================
   ACTIVE AGENTS
   ========================================================= */

$agents = $pdo->query(
    "SELECT
        id,
        full_name,
        party_code
     FROM users
     WHERE role = 'agent'
       AND account_status = 'active'
     ORDER BY full_name"
)->fetchAll();


/* =========================================================
   STATUS FILTER
   ========================================================= */

$status = (string)($_GET['status'] ?? '');

$valid = [
    'submitted',
    'under_review',
    'approved',
    'rejected',
    'disbursed',
    'completed'
];


/* =========================================================
   APPLICATION LIST
   ========================================================= */

$sql = "
    SELECT
        a.*,
        c.full_name AS customer_name,
        ag.full_name AS agent_name,
        m.business_name,
        m.full_name AS merchant_name
    FROM applications a

    LEFT JOIN users c
        ON c.id = a.customer_id

    LEFT JOIN users ag
        ON ag.id = a.agent_id

    LEFT JOIN users m
        ON m.id = a.merchant_id
";

$args = [];


if (in_array($status, $valid, true)) {

    $sql .= " WHERE a.status = ?";

    $args[] = $status;
}


$sql .= " ORDER BY a.id DESC LIMIT 200";


$stmt = $pdo->prepare($sql);

$stmt->execute($args);

$rows = $stmt->fetchAll();


/* =========================================================
   STATUS TALLY
   ========================================================= */

$tally = $pdo->query(
    "SELECT
        status,
        COUNT(*) n,
        COALESCE(SUM(amount),0) total
     FROM applications
     GROUP BY status"
)->fetchAll();


/* =========================================================
   ADMIN SHELL
   ========================================================= */

admin_shell_open(
    $pdo,
    $admin,
    'applications',
    'Applications'
);

?>

<h1 class="admin-h1">Applications</h1>

<p class="admin-sub">
    Customer, agent and merchant on one row, from submission to disbursement.
</p>


<div class="kpi-grid">

<?php foreach ($tally as $t): ?>

    <a
        class="kpi"
        style="text-decoration:none;"
        href="?status=<?= e($t['status']) ?>"
    >

        <div>

            <span class="kpi-label">
                <?= e(
                    ucwords(
                        str_replace(
                            '_',
                            ' ',
                            $t['status']
                        )
                    )
                ) ?>
            </span>

            <strong class="kpi-value">
                <?= (int)$t['n'] ?>
            </strong>

            <span class="kpi-delta muted">
                <?= money((float)$t['total']) ?>
            </span>

        </div>

    </a>

<?php endforeach; ?>


<?php if (!$tally): ?>

    <div class="kpi">

        <div>

            <span class="kpi-label">
                Applications
            </span>

            <strong class="kpi-value">
                0
            </strong>

            <span class="kpi-delta muted">
                None raised yet
            </span>

        </div>

    </div>

<?php endif; ?>

</div>


<section class="panel">

    <div class="panel-head">

        <h2>
            <?= $status
                ? e(
                    ucwords(
                        str_replace(
                            '_',
                            ' ',
                            $status
                        )
                    )
                )
                : 'All applications'
            ?>
        </h2>

        <?php if ($status): ?>

            <a href="?">
                Clear filter
            </a>

        <?php endif; ?>

    </div>


    <table class="table">

        <thead>

            <tr>

                <th scope="col">
                    Reference
                </th>

                <th scope="col">
                    Product
                </th>

                <th scope="col">
                    Amount
                </th>

                <th scope="col">
                    Customer
                </th>

                <th scope="col">
                    Agent
                </th>

                <th scope="col">
                    Merchant
                </th>

                <th scope="col">
                    Stage
                </th>

                <th scope="col">
                    Status
                </th>

            </tr>

        </thead>


        <tbody>

        <?php if (!$rows): ?>

            <tr>

                <td
                    colspan="8"
                    style="color:var(--muted);"
                >
                    Nothing matches.
                </td>

            </tr>

        <?php endif; ?>


        <?php foreach ($rows as $r):

            $pill = match ($r['status']) {

                'approved',
                'disbursed',
                'completed'
                    => 'is-ok',

                'rejected',
                'withdrawn'
                    => 'is-bad',

                default
                    => 'is-warn',
            };

        ?>

            <tr>

                <td>

                    <?= e($r['application_number']) ?>

                    <div
                        style="
                            color:var(--faint);
                            font-size:11px;
                        "
                    >

                        <?= e(
                            date(
                                'j M Y',
                                strtotime(
                                    $r['created_at']
                                )
                            )
                        ) ?>

                    </div>

                </td>


                <td>

                    <?= e(
                        $r['product_name']
                    ) ?>

                </td>


                <td>

                    <?= money(
                        (float)$r['amount']
                    ) ?>

                </td>


                <td>

                    <?= e(
                        (string)(
                            $r['customer_name']
                            ?? '—'
                        )
                    ) ?>

                </td>


                <td>

                    <form
                        method="post"
                        style="
                            display:flex;
                            gap:6px;
                            align-items:center;
                        "
                    >

                        <?= csrf_field() ?>


                        <input
                            type="hidden"
                            name="action"
                            value="assign_agent"
                        >


                        <input
                            type="hidden"
                            name="application_id"
                            value="<?= (int)$r['id'] ?>"
                        >


                        <select
                            name="agent_id"
                            onchange="this.form.submit()"
                            style="
                                font-size:12px;
                                padding:4px 6px;
                            "
                        >

                            <option value="">
                                — Unassigned —
                            </option>


                            <?php foreach ($agents as $ag): ?>

                                <option
                                    value="<?= (int)$ag['id'] ?>"
                                    <?= (int)(
                                        $r['agent_id'] ?? 0
                                    ) === (int)$ag['id']
                                        ? 'selected'
                                        : ''
                                    ?>
                                >

                                    <?= e(
                                        $ag['full_name']
                                    ) ?>

                                    <?= $ag['party_code']
                                        ? ' (' .
                                          e(
                                              $ag['party_code']
                                          ) .
                                          ')'
                                        : ''
                                    ?>

                                </option>

                            <?php endforeach; ?>

                        </select>


                        <noscript>

                            <button
                                class="btn secondary"
                                type="submit"
                                style="
                                    min-height:26px;
                                    font-size:11px;
                                    padding:0 8px;
                                "
                            >
                                Save
                            </button>

                        </noscript>

                    </form>

                </td>


                <td>

                    <?= e(
                        (string)(
                            $r['business_name']
                            ?: $r['merchant_name']
                            ?: '—'
                        )
                    ) ?>

                </td>


                <td>

                    <?= e(
                        str_replace(
                            '_',
                            ' ',
                            (string)$r['current_stage']
                        )
                    ) ?>

                </td>


                <td>

                    <span
                        class="pill <?= $pill ?>"
                    >

                        <?= e(
                            str_replace(
                                '_',
                                ' ',
                                $r['status']
                            )
                        ) ?>

                    </span>

                </td>

            </tr>

        <?php endforeach; ?>

        </tbody>

    </table>

</section>


<?php

admin_shell_close();

?>