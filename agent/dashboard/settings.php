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

function mod_esc($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/* =========================================================
   AGENT
   ========================================================= */

$user = [];
$agent_name = trim((string) ($_SESSION['full_name'] ?? 'Agent'));
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
        $value = $s->fetchColumn();

        if ($value !== false) {
            $agent_code = trim((string) $value);
        }
    }
} catch (Throwable $e) {
}

/* =========================================================
   SETTINGS
   Stored in the current session so no new database table
   or database schema change is required.
   ========================================================= */

if (!isset($_SESSION['agent_settings']) || !is_array($_SESSION['agent_settings'])) {
    $_SESSION['agent_settings'] = [
        'notifications' => 'on',
        'followup_alerts' => 'on',
        'application_alerts' => 'on',
        'merchant_updates' => 'on',
        'default_page' => 'index.php',
        'date_format' => 'd M Y',
    ];
}

$settings = $_SESSION['agent_settings'];

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {

    $settings['notifications'] =
        isset($_POST['notifications']) ? 'on' : 'off';

    $settings['followup_alerts'] =
        isset($_POST['followup_alerts']) ? 'on' : 'off';

    $settings['application_alerts'] =
        isset($_POST['application_alerts']) ? 'on' : 'off';

    $settings['merchant_updates'] =
        isset($_POST['merchant_updates']) ? 'on' : 'off';

    $allowed_pages = [
        'index.php',
        'customers.php',
        'requirements.php',
        'products.php',
        'eligibility.php',
        'applications.php',
        'followups.php',
    ];

    $default_page = trim((string) ($_POST['default_page'] ?? 'index.php'));

    if (!in_array($default_page, $allowed_pages, true)) {
        $default_page = 'index.php';
    }

    $settings['default_page'] = $default_page;

    $allowed_dates = [
        'd M Y',
        'd/m/Y',
        'M d, Y',
        'Y-m-d',
    ];

    $date_format = trim((string) ($_POST['date_format'] ?? 'd M Y'));

    if (!in_array($date_format, $allowed_dates, true)) {
        $date_format = 'd M Y';
    }

    $settings['date_format'] = $date_format;

    $_SESSION['agent_settings'] = $settings;

    $message = 'Settings saved successfully.';
}

/* =========================================================
   CURRENT VALUES
   ========================================================= */

$notifications_on =
    ($settings['notifications'] ?? 'on') === 'on';

$followups_on =
    ($settings['followup_alerts'] ?? 'on') === 'on';

$applications_on =
    ($settings['application_alerts'] ?? 'on') === 'on';

$merchant_updates_on =
    ($settings['merchant_updates'] ?? 'on') === 'on';

$default_page =
    (string) ($settings['default_page'] ?? 'index.php');

$date_format =
    (string) ($settings['date_format'] ?? 'd M Y');

require_once __DIR__ . '/shell.php';

$role = $agent_role;
$agent_id = $agent_code;

agent_shell_open($pdo, $user_id, 'Settings', 'settings.php');
?>

<style>
.settings-page{
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

.settings-grid{
    display:grid;
    grid-template-columns:1.1fr .9fr;
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

.setting-row{
    display:flex;
    align-items:center;
    gap:12px;
    padding:13px 0;
    border-bottom:1px solid #edf0f3;
}

.setting-row:last-child{
    border-bottom:0;
}

.setting-icon{
    width:36px;
    height:36px;
    border-radius:9px;
    background:#eef8f3;
    color:#14834b;
    display:flex;
    align-items:center;
    justify-content:center;
    flex:none;
}

.setting-info{
    flex:1;
    min-width:0;
}

.setting-info strong{
    display:block;
    color:#344054;
    font-size:11px;
}

.setting-info span{
    display:block;
    margin-top:3px;
    color:#8a93a1;
    font-size:9.5px;
    line-height:1.4;
}

/* Toggle */
.switch{
    position:relative;
    width:40px;
    height:22px;
    flex:none;
}

.switch input{
    opacity:0;
    width:0;
    height:0;
}

.slider{
    position:absolute;
    inset:0;
    cursor:pointer;
    background:#d0d5dd;
    border-radius:999px;
    transition:.2s;
}

.slider:before{
    content:"";
    position:absolute;
    width:16px;
    height:16px;
    left:3px;
    top:3px;
    background:#fff;
    border-radius:50%;
    box-shadow:0 1px 3px rgba(0,0,0,.18);
    transition:.2s;
}

.switch input:checked + .slider{
    background:#16a05a;
}

.switch input:checked + .slider:before{
    transform:translateX(18px);
}

.form-group{
    display:flex;
    flex-direction:column;
    gap:5px;
    margin-bottom:13px;
}

.form-group:last-child{
    margin-bottom:0;
}

.form-group label{
    color:#475467;
    font-size:10px;
    font-weight:700;
}

.form-group select{
    width:100%;
    border:1px solid #d9e0e7;
    border-radius:8px;
    padding:9px 10px;
    background:#fff;
    color:#344054;
    font-family:inherit;
    font-size:11px;
    outline:none;
}

.form-group select:focus{
    border-color:#6fc99a;
    box-shadow:0 0 0 3px #edf9f2;
}

.save-area{
    display:flex;
    justify-content:flex-end;
    margin-top:15px;
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

.alert{
    padding:10px 12px;
    border-radius:8px;
    margin-bottom:14px;
    font-size:10px;
}

.alert.success{
    background:#eef9f3;
    border:1px solid #cfead9;
    color:#147847;
}

.account-list{
    display:flex;
    flex-direction:column;
}

.account-row{
    display:flex;
    gap:11px;
    align-items:flex-start;
    padding:11px 0;
    border-bottom:1px solid #edf0f3;
}

.account-row:last-child{
    border-bottom:0;
}

.account-icon{
    width:34px;
    height:34px;
    border-radius:9px;
    background:#f3f5f7;
    color:#475467;
    display:flex;
    align-items:center;
    justify-content:center;
    flex:none;
}

.account-text strong{
    display:block;
    color:#344054;
    font-size:10.5px;
}

.account-text span{
    display:block;
    margin-top:3px;
    color:#8a93a1;
    font-size:9.5px;
}

.info-box{
    margin-top:14px;
    padding:11px 13px;
    background:#f7fcf9;
    border:1px solid #dcefe4;
    border-radius:9px;
    color:#667085;
    font-size:9.5px;
    line-height:1.5;
}

.quick-links{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:10px;
}

.quick-link{
    display:flex;
    align-items:center;
    gap:9px;
    padding:10px 11px;
    border:1px solid #e4e8ed;
    border-radius:8px;
    text-decoration:none;
    color:#344054;
    font-size:10px;
    font-weight:700;
}

.quick-link:hover{
    background:#f2faf5;
    color:#147847;
    border-color:#cfe8d9;
}

.quick-link i{
    color:#14834b;
}

@media(max-width:850px){
    .settings-grid{
        grid-template-columns:1fr;
    }
}

@media(max-width:560px){
    .quick-links{
        grid-template-columns:1fr;
    }
}
</style>

<div class="settings-page">

    <div class="page-head">
        <div>
            <h1>Settings</h1>
            <p>Manage your Agent Portal preferences and work notifications.</p>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert success">
            <?= mod_esc($message) ?>
        </div>
    <?php endif; ?>

    <div class="settings-grid">

        <!-- NOTIFICATIONS -->
        <section class="card">

            <div class="card-head">
                <h2>Notification Preferences</h2>
                <p>Choose the customer and application updates you want to receive.</p>
            </div>

            <div class="card-body">

                <form method="post">

                    <div class="setting-row">
                        <div class="setting-icon">
                            <i class="fa-regular fa-bell"></i>
                        </div>

                        <div class="setting-info">
                            <strong>Notifications</strong>
                            <span>Enable notifications in the Agent Portal.</span>
                        </div>

                        <label class="switch">
                            <input
                                type="checkbox"
                                name="notifications"
                                <?= $notifications_on ? 'checked' : '' ?>
                            >
                            <span class="slider"></span>
                        </label>
                    </div>

                    <div class="setting-row">
                        <div class="setting-icon">
                            <i class="fa-solid fa-clock"></i>
                        </div>

                        <div class="setting-info">
                            <strong>Follow-up Alerts</strong>
                            <span>Receive attention alerts for customer follow-ups.</span>
                        </div>

                        <label class="switch">
                            <input
                                type="checkbox"
                                name="followup_alerts"
                                <?= $followups_on ? 'checked' : '' ?>
                            >
                            <span class="slider"></span>
                        </label>
                    </div>

                    <div class="setting-row">
                        <div class="setting-icon">
                            <i class="fa-solid fa-file-lines"></i>
                        </div>

                        <div class="setting-info">
                            <strong>Application Alerts</strong>
                            <span>Receive alerts when application work needs attention.</span>
                        </div>

                        <label class="switch">
                            <input
                                type="checkbox"
                                name="application_alerts"
                                <?= $applications_on ? 'checked' : '' ?>
                            >
                            <span class="slider"></span>
                        </label>
                    </div>

                    <div class="setting-row">
                        <div class="setting-icon">
                            <i class="fa-solid fa-store"></i>
                        </div>

                        <div class="setting-info">
                            <strong>Merchant Updates</strong>
                            <span>Show updates related to merchant-provided products and services.</span>
                        </div>

                        <label class="switch">
                            <input
                                type="checkbox"
                                name="merchant_updates"
                                <?= $merchant_updates_on ? 'checked' : '' ?>
                            >
                            <span class="slider"></span>
                        </label>
                    </div>

                    <div class="save-area">
                        <button
                            type="submit"
                            name="save_settings"
                            value="1"
                            class="save-btn"
                        >
                            <i class="fa-solid fa-check"></i>
                            Save Settings
                        </button>
                    </div>

                    <input type="hidden" name="default_page" value="<?= mod_esc($default_page) ?>">
                    <input type="hidden" name="date_format" value="<?= mod_esc($date_format) ?>">

                </form>

            </div>
        </section>

        <!-- WORK PREFERENCES -->
        <section class="card">

            <div class="card-head">
                <h2>Work Preferences</h2>
                <p>Choose how you normally work in the Agent Portal.</p>
            </div>

            <div class="card-body">

                <form method="post">

                    <input
                        type="hidden"
                        name="save_settings"
                        value="1"
                    >

                    <input
                        type="hidden"
                        name="notifications"
                        value="<?= $notifications_on ? '1' : '' ?>"
                    >

                    <input
                        type="hidden"
                        name="followup_alerts"
                        value="<?= $followups_on ? '1' : '' ?>"
                    >

                    <input
                        type="hidden"
                        name="application_alerts"
                        value="<?= $applications_on ? '1' : '' ?>"
                    >

                    <input
                        type="hidden"
                        name="merchant_updates"
                        value="<?= $merchant_updates_on ? '1' : '' ?>"
                    >

                    <div class="form-group">
                        <label for="default_page">Default Work Page</label>

                        <select id="default_page" name="default_page">

                            <option value="index.php" <?= $default_page === 'index.php' ? 'selected' : '' ?>>
                                Dashboard
                            </option>

                            <option value="customers.php" <?= $default_page === 'customers.php' ? 'selected' : '' ?>>
                                Customers
                            </option>

                            <option value="requirements.php" <?= $default_page === 'requirements.php' ? 'selected' : '' ?>>
                                Requirements
                            </option>

                            <option value="products.php" <?= $default_page === 'products.php' ? 'selected' : '' ?>>
                                Products &amp; Services
                            </option>

                            <option value="eligibility.php" <?= $default_page === 'eligibility.php' ? 'selected' : '' ?>>
                                Eligibility
                            </option>

                            <option value="applications.php" <?= $default_page === 'applications.php' ? 'selected' : '' ?>>
                                Applications
                            </option>

                            <option value="followups.php" <?= $default_page === 'followups.php' ? 'selected' : '' ?>>
                                Follow-ups
                            </option>

                        </select>
                    </div>

                    <div class="form-group">
                        <label for="date_format">Date Format</label>

                        <select id="date_format" name="date_format">

                            <option value="d M Y" <?= $date_format === 'd M Y' ? 'selected' : '' ?>>
                                17 Sep 2026
                            </option>

                            <option value="d/m/Y" <?= $date_format === 'd/m/Y' ? 'selected' : '' ?>>
                                17/09/2026
                            </option>

                            <option value="M d, Y" <?= $date_format === 'M d, Y' ? 'selected' : '' ?>>
                                Sep 17, 2026
                            </option>

                            <option value="Y-m-d" <?= $date_format === 'Y-m-d' ? 'selected' : '' ?>>
                                2026-09-17
                            </option>

                        </select>
                    </div>

                    <div class="info-box">
                        These preferences are kept with your current Agent Portal session,
                        so no new database table is required.
                    </div>

                    <div class="save-area">
                        <button
                            type="submit"
                            class="save-btn"
                        >
                            <i class="fa-solid fa-sliders"></i>
                            Save Preferences
                        </button>
                    </div>

                </form>

            </div>
        </section>

    </div>

    <!-- ACCOUNT -->
    <section class="card" style="margin-bottom:15px;">

        <div class="card-head">
            <h2>Account &amp; Access</h2>
            <p>Your account information is controlled by the CRM administrator.</p>
        </div>

        <div class="card-body">

            <div class="account-list">

                <div class="account-row">
                    <div class="account-icon">
                        <i class="fa-solid fa-user"></i>
                    </div>

                    <div class="account-text">
                        <strong>Agent Name</strong>
                        <span><?= mod_esc($agent_name) ?></span>
                    </div>
                </div>

                <div class="account-row">
                    <div class="account-icon">
                        <i class="fa-solid fa-id-badge"></i>
                    </div>

                    <div class="account-text">
                        <strong>Agent ID</strong>
                        <span><?= mod_esc($agent_code !== '' ? $agent_code : 'Not assigned') ?></span>
                    </div>
                </div>

                <div class="account-row">
                    <div class="account-icon">
                        <i class="fa-solid fa-shield-halved"></i>
                    </div>

                    <div class="account-text">
                        <strong>Assigned Role</strong>
                        <span><?= mod_esc($agent_role) ?></span>
                    </div>
                </div>

                <div class="account-row">
                    <div class="account-icon">
                        <i class="fa-solid fa-lock"></i>
                    </div>

                    <div class="account-text">
                        <strong>Role &amp; Access</strong>
                        <span>Role assignment and access permissions are managed by the administrator.</span>
                    </div>
                </div>

            </div>

        </div>
    </section>

    <!-- QUICK LINKS -->
    <section class="card">

        <div class="card-head">
            <h2>Quick Settings</h2>
            <p>Common account and workflow pages.</p>
        </div>

        <div class="card-body">

            <div class="quick-links">

                <a class="quick-link" href="profile.php">
                    <i class="fa-regular fa-user"></i>
                    <span>Edit Profile</span>
                </a>

                <a class="quick-link" href="notifications.php">
                    <i class="fa-regular fa-bell"></i>
                    <span>View Notifications</span>
                </a>

                <a class="quick-link" href="support.php">
                    <i class="fa-solid fa-headset"></i>
                    <span>Contact Support</span>
                </a>

                <a class="quick-link" href="customers.php">
                    <i class="fa-solid fa-users"></i>
                    <span>Open Customers</span>
                </a>

            </div>

        </div>
    </section>

</div>

<?php agent_shell_close(); ?>
