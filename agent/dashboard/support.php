<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];

/* =========================================================
   HELPERS
   ========================================================= */

function mod_table_exists(PDO $pdo, string $table): bool
{
    try {
        $s = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = ?
        ");
        $s->execute([$table]);
        return (int) $s->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function mod_col_exists(PDO $pdo, string $table, string $column): bool
{
    if (!mod_table_exists($pdo, $table)) {
        return false;
    }

    try {
        $s = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND column_name = ?
        ");
        $s->execute([$table, $column]);
        return (int) $s->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function mod_esc($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/* =========================================================
   AGENT
   ========================================================= */

$user = [];
$agent_name = 'Agent';
$agent_role = 'Agent';
$agent_code = '';

try {
    $s = $pdo->prepare("
        SELECT *
        FROM users
        WHERE id = ?
        LIMIT 1
    ");
    $s->execute([$user_id]);
    $user = $s->fetch(PDO::FETCH_ASSOC) ?: [];

    $agent_name = trim((string) (
        $user['full_name']
        ?? $_SESSION['full_name']
        ?? 'Agent'
    ));

    $agent_role = trim((string) (
        $user['assigned_role']
        ?? ''
    )) ?: 'Agent';
} catch (Throwable $e) {
    $agent_name = trim((string)($_SESSION['full_name'] ?? 'Agent'));
    $agent_role = 'Agent';
}

try {
    if (mod_table_exists($pdo, 'agent_hierarchy')) {
        $s = $pdo->prepare("
            SELECT agent_id
            FROM agent_hierarchy
            WHERE user_id = ?
            LIMIT 1
        ");
        $s->execute([$user_id]);
        $v = $s->fetchColumn();

        if ($v !== false) {
            $agent_code = trim((string) $v);
        }
    }
} catch (Throwable $e) {
    $agent_code = '';
}

/* =========================================================
   CUSTOMER / APPLICATION CONTEXT
   Support can be opened with:
   support.php?customer_id=123
   support.php?application_id=456
   ========================================================= */

$customer_id = trim((string)($_GET['customer_id'] ?? $_POST['customer_id'] ?? ''));
$application_id = trim((string)($_GET['application_id'] ?? $_POST['application_id'] ?? ''));

$customer_name = '';
$customer_mobile = '';
$application_status = '';

if ($customer_id !== '' && mod_table_exists($pdo, 'customers')) {
    try {
        $pk = mod_col_exists($pdo, 'customers', 'id')
            ? 'id'
            : (mod_col_exists($pdo, 'customers', 'customer_id') ? 'customer_id' : '');

        if ($pk !== '') {
            $s = $pdo->prepare("
                SELECT *
                FROM customers
                WHERE `$pk` = ?
                LIMIT 1
            ");
            $s->execute([$customer_id]);
            $customer = $s->fetch(PDO::FETCH_ASSOC) ?: [];

            if ($customer) {
                $customer_name = trim((string)($customer['name'] ?? 'Customer'));
                $customer_mobile = trim((string)($customer['mobile'] ?? $customer['phone'] ?? ''));
            }
        }
    } catch (Throwable $e) {
    }
}

if ($application_id !== '' && mod_table_exists($pdo, 'applications')) {
    try {
        $pk = mod_col_exists($pdo, 'applications', 'application_id')
            ? 'application_id'
            : (mod_col_exists($pdo, 'applications', 'id') ? 'id' : '');

        if ($pk !== '') {
            $s = $pdo->prepare("
                SELECT *
                FROM applications
                WHERE `$pk` = ?
                LIMIT 1
            ");
            $s->execute([$application_id]);
            $application = $s->fetch(PDO::FETCH_ASSOC) ?: [];

            if ($application) {
                $application_status = trim((string)($application['status'] ?? 'Pending'));

                if ($customer_id === '' && !empty($application['customer_id'])) {
                    $customer_id = (string) $application['customer_id'];
                }
            }
        }
    } catch (Throwable $e) {
    }
}

/* =========================================================
   SUPPORT TICKETS
   Uses support_tickets only when that table already exists.
   If it does not exist, the page remains usable and shows
   the admin-contact options without changing the database.
   ========================================================= */

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_support'])) {

    $category = trim((string)($_POST['category'] ?? ''));
    $subject = trim((string)($_POST['subject'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));

    if ($category === '' || $subject === '' || $description === '') {
        $error = 'Please complete the support category, subject and description.';
    } elseif (!mod_table_exists($pdo, 'support_tickets')) {
        $error = 'The support ticket table is not available. Please contact the administrator directly.';
    } else {

        try {
            $columns = [];
            $values = [];
            $placeholders = [];

            $possible = [
                'user_id'        => $user_id,
                'agent_id'       => ($agent_code !== '' ? $agent_code : $user_id),
                'customer_id'    => ($customer_id !== '' ? $customer_id : null),
                'application_id' => ($application_id !== '' ? $application_id : null),
                'category'       => $category,
                'subject'        => $subject,
                'description'    => $description,
                'status'         => 'open'
            ];

            foreach ($possible as $column => $value) {
                if (mod_col_exists($pdo, 'support_tickets', $column)) {
                    $columns[] = "`$column`";
                    $placeholders[] = '?';
                    $values[] = $value;
                }
            }

            if (empty($columns)) {
                $error = 'The support ticket table does not contain the expected fields.';
            } else {
                $sql = "
                    INSERT INTO support_tickets
                    (" . implode(', ', $columns) . ")
                    VALUES (" . implode(', ', $placeholders) . ")
                ";

                $s = $pdo->prepare($sql);
                $s->execute($values);

                $message = 'Support request submitted to the administrator.';
            }

        } catch (Throwable $e) {
            $error = 'Unable to submit the support request. Please contact the administrator.';
        }
    }
}

/* Recent tickets */
$tickets = [];

if (mod_table_exists($pdo, 'support_tickets')) {
    try {
        $owner_column = '';

        if (mod_col_exists($pdo, 'support_tickets', 'user_id')) {
            $owner_column = 'user_id';
        } elseif (mod_col_exists($pdo, 'support_tickets', 'agent_id')) {
            $owner_column = 'agent_id';
        }

        if ($owner_column !== '') {
            $owner_value = $owner_column === 'agent_id'
                ? ($agent_code !== '' ? $agent_code : $user_id)
                : $user_id;

            $s = $pdo->prepare("
                SELECT *
                FROM support_tickets
                WHERE `$owner_column` = ?
                ORDER BY 1 DESC
                LIMIT 20
            ");
            $s->execute([$owner_value]);
            $tickets = $s->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        $tickets = [];
    }
}

/* =========================================================
   SHELL
   ========================================================= */

require_once __DIR__ . '/shell.php';

$role = $agent_role;
$agent_id = $agent_code;

agent_shell_open($pdo, $user_id, 'Support & Help', 'support.php');
?>

<style>
.support-page{
    max-width:1100px;
}

.page-head{
    margin-bottom:18px;
}

.page-head h1{
    margin:0;
    font-size:24px;
    color:#172033;
}

.page-head p{
    margin:6px 0 0;
    color:#667085;
    font-size:12px;
}

.support-grid{
    display:grid;
    grid-template-columns:1.15fr .85fr;
    gap:15px;
    margin-bottom:15px;
}

.card{
    background:#fff;
    border:1px solid #e4e8ed;
    border-radius:12px;
    box-shadow:0 2px 8px rgba(16,24,40,.025);
}

.card-head{
    padding:15px 17px;
    border-bottom:1px solid #edf0f3;
}

.card-head h2{
    margin:0;
    font-size:14px;
    color:#172033;
}

.card-head p{
    margin:4px 0 0;
    color:#8a93a1;
    font-size:10px;
}

.card-body{
    padding:17px;
}

.context{
    padding:12px 13px;
    margin-bottom:14px;
    border:1px solid #dcefe4;
    background:#f7fcf9;
    border-radius:9px;
}

.context strong{
    display:block;
    color:#172033;
    font-size:11px;
}

.context span{
    display:block;
    margin-top:4px;
    color:#667085;
    font-size:9px;
}

.form-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:12px;
}

.form-group{
    display:flex;
    flex-direction:column;
    gap:5px;
}

.form-group.full{
    grid-column:1 / -1;
}

.form-group label{
    color:#475467;
    font-size:10px;
    font-weight:700;
}

.form-group input,
.form-group select,
.form-group textarea{
    width:100%;
    border:1px solid #d9e0e7;
    border-radius:8px;
    padding:9px 10px;
    font-family:inherit;
    font-size:11px;
    color:#344054;
    outline:none;
    background:#fff;
}

.form-group textarea{
    min-height:105px;
    resize:vertical;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus{
    border-color:#6fc99a;
    box-shadow:0 0 0 3px #edf9f2;
}

.submit-btn{
    border:1px solid #16a05a;
    background:#16a05a;
    color:#fff;
    border-radius:8px;
    padding:9px 14px;
    font-size:10px;
    font-weight:800;
    cursor:pointer;
}

.submit-btn:hover{
    background:#0f7542;
}

.alert{
    padding:10px 12px;
    border-radius:8px;
    margin-bottom:13px;
    font-size:10px;
}

.alert.success{
    background:#eef9f3;
    border:1px solid #cfead9;
    color:#147847;
}

.alert.error{
    background:#fff5f4;
    border:1px solid #f0d1cd;
    color:#b42318;
}

.help-list{
    display:flex;
    flex-direction:column;
}

.help-row{
    display:flex;
    gap:11px;
    align-items:flex-start;
    padding:12px 0;
    border-bottom:1px solid #edf0f3;
}

.help-row:last-child{
    border-bottom:0;
}

.help-icon{
    width:34px;
    height:34px;
    border-radius:9px;
    background:#eef8f3;
    color:#14834b;
    display:flex;
    align-items:center;
    justify-content:center;
    flex:none;
}

.help-text strong{
    display:block;
    color:#344054;
    font-size:10.5px;
}

.help-text span{
    display:block;
    margin-top:3px;
    color:#8a93a1;
    font-size:9.5px;
    line-height:1.4;
}

.admin-box{
    padding:13px;
    border:1px solid #e4e8ed;
    border-radius:9px;
    background:#fafbfc;
}

.admin-box strong{
    display:block;
    color:#172033;
    font-size:11px;
}

.admin-box span{
    display:block;
    margin-top:4px;
    color:#667085;
    font-size:9.5px;
    line-height:1.5;
}

.ticket-list{
    display:flex;
    flex-direction:column;
}

.ticket{
    display:grid;
    grid-template-columns:minmax(0,1fr) auto;
    gap:15px;
    padding:13px 0;
    border-bottom:1px solid #edf0f3;
}

.ticket:last-child{
    border-bottom:0;
}

.ticket strong{
    color:#344054;
    font-size:10.5px;
}

.ticket span{
    display:block;
    margin-top:4px;
    color:#8a93a1;
    font-size:9px;
}

.ticket-status{
    align-self:start;
    padding:5px 8px;
    border-radius:999px;
    background:#eef8f3;
    color:#147847;
    font-size:8.5px;
    font-weight:800;
}

.empty{
    padding:30px 15px;
    text-align:center;
    color:#8a93a1;
    font-size:10px;
}

@media(max-width:850px){
    .support-grid{
        grid-template-columns:1fr;
    }
}

@media(max-width:600px){
    .form-grid{
        grid-template-columns:1fr;
    }

    .form-group.full{
        grid-column:auto;
    }
}
</style>

<div class="support-page">

    <div class="page-head">
        <div>
            <h1>Support &amp; Help</h1>
            <p>Get help with customers, merchant services, applications and your agent account.</p>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert success"><?= mod_esc($message) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert error"><?= mod_esc($error) ?></div>
    <?php endif; ?>

    <?php if ($customer_name !== '' || $application_id !== ''): ?>
        <div class="context">
            <strong>
                Support Context
            </strong>

            <span>
                <?php if ($customer_name !== ''): ?>
                    Customer: <?= mod_esc($customer_name) ?>
                <?php endif; ?>

                <?php if ($customer_mobile !== ''): ?>
                    · <?= mod_esc($customer_mobile) ?>
                <?php endif; ?>

                <?php if ($application_id !== ''): ?>
                    · Application: <?= mod_esc($application_id) ?>
                <?php endif; ?>

                <?php if ($application_status !== ''): ?>
                    · Status: <?= mod_esc($application_status) ?>
                <?php endif; ?>
            </span>
        </div>
    <?php endif; ?>

    <div class="support-grid">

        <section class="card">

            <div class="card-head">
                <h2>Contact Administrator</h2>
                <p>Submit an issue or request for assistance.</p>
            </div>

            <div class="card-body">

                <form method="post" class="form-grid">

                    <?php if ($customer_id !== ''): ?>
                        <input type="hidden" name="customer_id" value="<?= mod_esc($customer_id) ?>">
                    <?php endif; ?>

                    <?php if ($application_id !== ''): ?>
                        <input type="hidden" name="application_id" value="<?= mod_esc($application_id) ?>">
                    <?php endif; ?>

                    <div class="form-group">
                        <label for="category">Support Category</label>

                        <select id="category" name="category" required>
                            <option value="">Select category</option>
                            <option value="Customer Issue">Customer Issue</option>
                            <option value="Requirement / Eligibility">Requirement / Eligibility</option>
                            <option value="Merchant / Service">Merchant / Service</option>
                            <option value="Application">Application</option>
                            <option value="Document">Document</option>
                            <option value="Account / Role">Account / Role</option>
                            <option value="Technical Issue">Technical Issue</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="subject">Subject</label>

                        <input
                            type="text"
                            id="subject"
                            name="subject"
                            placeholder="Briefly describe the issue"
                            required
                        >
                    </div>

                    <div class="form-group full">
                        <label for="description">Description</label>

                        <textarea
                            id="description"
                            name="description"
                            placeholder="Explain what help you need..."
                            required
                        ></textarea>
                    </div>

                    <div class="form-group full" style="align-items:flex-end;">
                        <button
                            type="submit"
                            name="submit_support"
                            value="1"
                            class="submit-btn"
                        >
                            <i class="fa-solid fa-paper-plane"></i>
                            Submit to Administrator
                        </button>
                    </div>

                </form>

            </div>
        </section>

        <section class="card">

            <div class="card-head">
                <h2>Agent Help</h2>
                <p>Where to go for each stage.</p>
            </div>

            <div class="card-body">

                <div class="help-list">

                    <div class="help-row">
                        <div class="help-icon">
                            <i class="fa-solid fa-users"></i>
                        </div>
                        <div class="help-text">
                            <strong>Customer</strong>
                            <span>
                                View assigned customers and their details.
                            </span>
                        </div>
                    </div>

                    <div class="help-row">
                        <div class="help-icon">
                            <i class="fa-solid fa-clipboard-list"></i>
                        </div>
                        <div class="help-text">
                            <strong>Requirement</strong>
                            <span>
                                Review what the customer needs before choosing a service.
                            </span>
                        </div>
                    </div>

                    <div class="help-row">
                        <div class="help-icon">
                            <i class="fa-solid fa-store"></i>
                        </div>
                        <div class="help-text">
                            <strong>Merchant Service</strong>
                            <span>
                                Use products and services supplied by merchants.
                            </span>
                        </div>
                    </div>

                    <div class="help-row">
                        <div class="help-icon">
                            <i class="fa-solid fa-circle-check"></i>
                        </div>
                        <div class="help-text">
                            <strong>Eligibility</strong>
                            <span>
                                Review the customer's information before continuing to an application.
                            </span>
                        </div>
                    </div>

                    <div class="help-row">
                        <div class="help-icon">
                            <i class="fa-solid fa-file-lines"></i>
                        </div>
                        <div class="help-text">
                            <strong>Application &amp; Tracking</strong>
                            <span>
                                Follow the application while the merchant processes the service.
                            </span>
                        </div>
                    </div>

                </div>

                <div class="admin-box" style="margin-top:14px;">
                    <strong>Need administrator assistance?</strong>
                    <span>
                        Use the support form for customer, merchant, application,
                        document, account or technical issues. The request can also
                        carry the current customer or application reference.
                    </span>
                </div>

            </div>
        </section>

    </div>

    <section class="card">

        <div class="card-head">
            <h2>My Support Requests</h2>
            <p>Requests previously submitted by this agent.</p>
        </div>

        <div class="card-body">

            <?php if (!$tickets): ?>

                <div class="empty">
                    No support requests found.
                </div>

            <?php else: ?>

                <div class="ticket-list">

                    <?php foreach ($tickets as $ticket): ?>

                        <div class="ticket">

                            <div>
                                <strong>
                                    <?= mod_esc(
                                        $ticket['subject']
                                        ?? $ticket['title']
                                        ?? 'Support Request'
                                    ) ?>
                                </strong>

                                <span>
                                    <?= mod_esc(
                                        $ticket['category']
                                        ?? 'General Support'
                                    ) ?>

                                    <?php if (!empty($ticket['customer_id'])): ?>
                                        · Customer:
                                        <?= mod_esc($ticket['customer_id']) ?>
                                    <?php endif; ?>

                                    <?php if (!empty($ticket['application_id'])): ?>
                                        · Application:
                                        <?= mod_esc($ticket['application_id']) ?>
                                    <?php endif; ?>
                                </span>
                            </div>

                            <div class="ticket-status">
                                <?= mod_esc(
                                    ucfirst((string)($ticket['status'] ?? 'Open'))
                                ) ?>
                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

        </div>
    </section>

</div>

<?php agent_shell_close(); ?>
