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
   GET USER
   ========================================================= */

$user = [];

try {
    $s = $pdo->prepare("
        SELECT *
        FROM users
        WHERE id = ?
        LIMIT 1
    ");
    $s->execute([$user_id]);
    $user = $s->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $user = [];
}

if (!$user) {
    exit('Agent account not found.');
}

$agent_name = trim((string) ($user['full_name'] ?? $_SESSION['full_name'] ?? 'Agent'));
$agent_role = trim((string) ($user['assigned_role'] ?? '')) ?: 'Agent';
$agent_code = '';

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
   UPDATE PROFILE
   Only updates columns that actually exist in users table.
   ========================================================= */

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {

    $name  = trim((string) ($_POST['full_name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $address = trim((string) ($_POST['address'] ?? ''));

    if ($name === '') {
        $error = 'Please enter your name.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {

        try {
            $fields = [];
            $values = [];

            if (mod_col_exists($pdo, 'users', 'full_name')) {
                $fields[] = 'full_name = ?';
                $values[] = $name;
            }

            if (mod_col_exists($pdo, 'users', 'email')) {
                $fields[] = 'email = ?';
                $values[] = $email !== '' ? $email : null;
            }

            if (mod_col_exists($pdo, 'users', 'phone')) {
                $fields[] = 'phone = ?';
                $values[] = $phone !== '' ? $phone : null;
            } elseif (mod_col_exists($pdo, 'users', 'mobile')) {
                $fields[] = 'mobile = ?';
                $values[] = $phone !== '' ? $phone : null;
            }

            if (mod_col_exists($pdo, 'users', 'address')) {
                $fields[] = 'address = ?';
                $values[] = $address !== '' ? $address : null;
            }

            if (!$fields) {
                $error = 'No editable profile fields were found in the users table.';
            } else {
                $values[] = $user_id;

                $sql = "
                    UPDATE users
                    SET " . implode(', ', $fields) . "
                    WHERE id = ?
                    LIMIT 1
                ";

                $s = $pdo->prepare($sql);
                $s->execute($values);

                $_SESSION['full_name'] = $name;
                if ($email !== '') {
                    $_SESSION['email'] = $email;
                }

                /* Refresh displayed values after saving. */
                $s = $pdo->prepare("
                    SELECT *
                    FROM users
                    WHERE id = ?
                    LIMIT 1
                ");
                $s->execute([$user_id]);
                $user = $s->fetch(PDO::FETCH_ASSOC) ?: $user;

                $agent_name = trim((string) ($user['full_name'] ?? $name));
                $message = 'Profile updated successfully.';
            }
        } catch (Throwable $e) {
            $error = 'Unable to update the profile. Please try again.';
        }
    }
}

/* =========================================================
   SAFE DISPLAY VALUES
   ========================================================= */

$email = trim((string) ($user['email'] ?? ''));
$phone = trim((string) ($user['phone'] ?? $user['mobile'] ?? ''));
$address = trim((string) ($user['address'] ?? ''));
$gender = trim((string) ($user['gender'] ?? ''));
$job_title = trim((string) ($user['job_title'] ?? ''));

$status = trim((string) ($user['status'] ?? 'Active'));
$created_at = trim((string) ($user['created_at'] ?? ''));

$initial = strtoupper(substr($agent_name, 0, 1));

/* =========================================================
   SHELL
   ========================================================= */

require_once __DIR__ . '/shell.php';

$role = $agent_role;
$agent_id = $agent_code;

agent_shell_open($pdo, $user_id, 'Profile', 'profile.php');
?>

<style>
.profile-page{
    max-width:1100px;
}

.profile-head{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:16px;
    margin-bottom:18px;
}

.profile-head h1{
    margin:0;
    font-size:24px;
    color:#172033;
}

.profile-head p{
    margin:6px 0 0;
    color:#667085;
    font-size:12px;
}

.profile-grid{
    display:grid;
    grid-template-columns:300px minmax(0,1fr);
    gap:16px;
}

.card{
    background:#fff;
    border:1px solid #e4e8ed;
    border-radius:12px;
    box-shadow:0 2px 8px rgba(16,24,40,.025);
}

.profile-card{
    padding:22px;
    text-align:center;
}

.avatar{
    width:82px;
    height:82px;
    margin:0 auto 12px;
    border-radius:50%;
    background:#e0f3e9;
    color:#14834b;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:27px;
    font-weight:800;
}

.profile-card h2{
    margin:0;
    color:#172033;
    font-size:17px;
}

.profile-role{
    margin-top:5px;
    color:#158a50;
    font-size:11px;
    font-weight:700;
}

.profile-id{
    margin-top:6px;
    color:#8a93a1;
    font-size:9.5px;
}

.profile-status{
    display:inline-flex;
    align-items:center;
    gap:5px;
    margin-top:13px;
    padding:6px 10px;
    border-radius:999px;
    background:#e8f7ef;
    color:#147847;
    font-size:9px;
    font-weight:800;
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

.detail-list{
    display:flex;
    flex-direction:column;
}

.detail-row{
    display:flex;
    gap:12px;
    align-items:flex-start;
    padding:11px 0;
    border-bottom:1px solid #edf0f3;
}

.detail-row:last-child{
    border-bottom:0;
}

.detail-icon{
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

.detail-text{
    min-width:0;
}

.detail-label{
    color:#8a93a1;
    font-size:9px;
}

.detail-value{
    margin-top:3px;
    color:#344054;
    font-size:10.5px;
    word-break:break-word;
}

.profile-form{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:13px;
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
.form-group textarea{
    width:100%;
    border:1px solid #d9e0e7;
    border-radius:8px;
    padding:9px 10px;
    outline:none;
    font-family:inherit;
    font-size:11px;
    color:#344054;
    background:#fff;
}

.form-group textarea{
    min-height:78px;
    resize:vertical;
}

.form-group input:focus,
.form-group textarea:focus{
    border-color:#6fc99a;
    box-shadow:0 0 0 3px #edf9f2;
}

.readonly{
    background:#f8fafb !important;
    color:#667085 !important;
}

.form-actions{
    grid-column:1 / -1;
    display:flex;
    justify-content:flex-end;
    gap:9px;
    margin-top:3px;
}

.save-btn{
    border:1px solid #16a05a;
    background:#16a05a;
    color:#fff;
    border-radius:8px;
    padding:9px 14px;
    font-size:10px;
    font-weight:800;
    cursor:pointer;
}

.save-btn:hover{
    background:#0f7542;
}

.message{
    margin-bottom:14px;
    padding:10px 12px;
    border-radius:9px;
    font-size:10px;
}

.message.success{
    background:#eef9f3;
    border:1px solid #cfead9;
    color:#147847;
}

.message.error{
    background:#fff5f4;
    border:1px solid #f0d1cd;
    color:#b42318;
}

.info-box{
    margin-top:14px;
    padding:11px 13px;
    background:#f8fafb;
    border:1px solid #edf0f3;
    border-radius:9px;
    color:#667085;
    font-size:9.5px;
    line-height:1.5;
}

@media(max-width:900px){
    .profile-grid{
        grid-template-columns:1fr;
    }
}

@media(max-width:620px){
    .profile-form{
        grid-template-columns:1fr;
    }

    .form-group.full,
    .form-actions{
        grid-column:auto;
    }
}
</style>

<div class="profile-page">

    <div class="profile-head">
        <div>
            <h1>My Profile</h1>
            <p>View and update your personal account details.</p>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="message success">
            <?= mod_esc($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="message error">
            <?= mod_esc($error) ?>
        </div>
    <?php endif; ?>

    <div class="profile-grid">

        <!-- PROFILE SUMMARY -->
        <section class="card">
            <div class="profile-card">

                <div class="avatar">
                    <?= mod_esc($initial) ?>
                </div>

                <h2><?= mod_esc($agent_name) ?></h2>

                <div class="profile-role">
                    <?= mod_esc($agent_role) ?>
                </div>

                <div class="profile-id">
                    Agent ID:
                    <?= mod_esc($agent_code !== '' ? $agent_code : 'Not assigned') ?>
                </div>

                <div class="profile-status">
                    <i class="fa-solid fa-circle"></i>
                    <?= mod_esc($status !== '' ? $status : 'Active') ?>
                </div>

            </div>
        </section>

        <!-- EDIT PROFILE -->
        <section class="card">

            <div class="card-head">
                <h2>Personal Information</h2>
                <p>Change the details that belong to your account.</p>
            </div>

            <div class="card-body">

                <form method="post" class="profile-form">

                    <div class="form-group">
                        <label for="full_name">Full Name</label>
                        <input
                            type="text"
                            id="full_name"
                            name="full_name"
                            value="<?= mod_esc($agent_name) ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="email">Email</label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            value="<?= mod_esc($email) ?>"
                            placeholder="Enter email"
                        >
                    </div>

                    <div class="form-group">
                        <label for="phone">Phone / Mobile</label>
                        <input
                            type="text"
                            id="phone"
                            name="phone"
                            value="<?= mod_esc($phone) ?>"
                            placeholder="Enter phone number"
                        >
                    </div>

                    <div class="form-group">
                        <label>Assigned Role</label>
                        <input
                            type="text"
                            value="<?= mod_esc($agent_role) ?>"
                            class="readonly"
                            readonly
                        >
                    </div>

                    <div class="form-group full">
                        <label for="address">Address</label>
                        <textarea
                            id="address"
                            name="address"
                            placeholder="Enter address"
                        ><?= mod_esc($address) ?></textarea>
                    </div>

                    <div class="form-actions">
                        <button
                            type="submit"
                            name="update_profile"
                            value="1"
                            class="save-btn"
                        >
                            <i class="fa-solid fa-check"></i>
                            Save Changes
                        </button>
                    </div>

                </form>

                <div class="info-box">
                    Your Agent ID and administrator-assigned role are shown here as account information.
                    The role is not changed from this page.
                </div>

            </div>
        </section>

        <!-- ACCOUNT INFORMATION -->
        <section class="card">

            <div class="card-head">
                <h2>Account Information</h2>
                <p>Current agent account details.</p>
            </div>

            <div class="card-body">

                <div class="detail-list">

                    <div class="detail-row">
                        <div class="detail-icon">
                            <i class="fa-solid fa-id-badge"></i>
                        </div>
                        <div class="detail-text">
                            <div class="detail-label">Agent ID</div>
                            <div class="detail-value">
                                <?= mod_esc($agent_code !== '' ? $agent_code : 'Not assigned') ?>
                            </div>
                        </div>
                    </div>

                    <div class="detail-row">
                        <div class="detail-icon">
                            <i class="fa-solid fa-shield-halved"></i>
                        </div>
                        <div class="detail-text">
                            <div class="detail-label">Role</div>
                            <div class="detail-value">
                                <?= mod_esc($agent_role) ?>
                            </div>
                        </div>
                    </div>

                    <?php if ($gender !== ''): ?>
                        <div class="detail-row">
                            <div class="detail-icon">
                                <i class="fa-solid fa-venus-mars"></i>
                            </div>
                            <div class="detail-text">
                                <div class="detail-label">Gender</div>
                                <div class="detail-value">
                                    <?= mod_esc($gender) ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($job_title !== ''): ?>
                        <div class="detail-row">
                            <div class="detail-icon">
                                <i class="fa-solid fa-briefcase"></i>
                            </div>
                            <div class="detail-text">
                                <div class="detail-label">Job Title</div>
                                <div class="detail-value">
                                    <?= mod_esc($job_title) ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($created_at !== ''): ?>
                        <div class="detail-row">
                            <div class="detail-icon">
                                <i class="fa-regular fa-calendar"></i>
                            </div>
                            <div class="detail-text">
                                <div class="detail-label">Account Created</div>
                                <div class="detail-value">
                                    <?= mod_esc($created_at) ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                </div>

            </div>
        </section>

        <!-- CONTACT DETAILS -->
        <section class="card">

            <div class="card-head">
                <h2>Contact Details</h2>
                <p>Information used for your agent account.</p>
            </div>

            <div class="card-body">

                <div class="detail-list">

                    <div class="detail-row">
                        <div class="detail-icon">
                            <i class="fa-regular fa-envelope"></i>
                        </div>
                        <div class="detail-text">
                            <div class="detail-label">Email</div>
                            <div class="detail-value">
                                <?= mod_esc($email !== '' ? $email : 'Not available') ?>
                            </div>
                        </div>
                    </div>

                    <div class="detail-row">
                        <div class="detail-icon">
                            <i class="fa-solid fa-phone"></i>
                        </div>
                        <div class="detail-text">
                            <div class="detail-label">Phone</div>
                            <div class="detail-value">
                                <?= mod_esc($phone !== '' ? $phone : 'Not available') ?>
                            </div>
                        </div>
                    </div>

                    <div class="detail-row">
                        <div class="detail-icon">
                            <i class="fa-solid fa-location-dot"></i>
                        </div>
                        <div class="detail-text">
                            <div class="detail-label">Address</div>
                            <div class="detail-value">
                                <?= nl2br(mod_esc($address !== '' ? $address : 'Not available')) ?>
                            </div>
                        </div>
                    </div>

                </div>

            </div>
        </section>

    </div>
</div>

<?php agent_shell_close(); ?>
