<?php
/*
    ============================================
    INDBIN : home, registration and login
    ============================================

    All authentication now lives in auth.php. This file collects input,
    calls it, and renders. It no longer carries its own copy of the
    registration logic.

    auth.php must be required before anything else, because it owns
    session_start() and configures the session name to match the one the
    customer module uses.
*/

declare(strict_types=1);
require_once __DIR__ . '/core/bootstrap.php';

/* ============================================
   ALREADY SIGNED IN
============================================ */

if (auth_is_logged_in() && !isset($_GET['register'])) {
    auth_redirect(role_home((string)$_SESSION['role']));
}

/* ============================================
   LOGIN
============================================ */

$login_error    = '';
$showLoginModal = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $showLoginModal = true;

    if (!csrf_check()) {
        $login_error = 'Your session expired. Reload the page and try again.';
    } else {
        $result = auth_login(
            $pdo,
            (string)($_POST['identifier'] ?? ''),
            (string)($_POST['password'] ?? ''),
            (string)($_POST['role'] ?? 'customer')
        );

        if ($result['ok']) {
            auth_redirect($result['redirect']);
        }
        $login_error = $result['error'];
    }
}

/* ============================================
   REGISTRATION MODE
============================================ */

$showRegister = isset($_GET['register'])
             || (($_POST['action'] ?? '') === 'register');

$role = $_GET['register'] ?? $_POST['role'] ?? 'customer';
if (!in_array($role, SIGNUP_ROLES, true)) {
    $role = 'customer';
}

/* ============================================
   REGISTRATION SUBMIT
============================================ */

$errors  = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'register') {

    if (!csrf_check()) {
        $errors[] = 'Your session expired. Reload the page and try again.';
    } else {
        $result = auth_register($pdo, [
            'role'          => $role,
            'full_name'     => $_POST['full_name'] ?? '',
            'email'         => $_POST['email'] ?? '',
            'password'      => $_POST['password'] ?? '',
            'business_name' => $_POST['business_name'] ?? '',
            'business_type' => $_POST['business_type'] ?? '',
        ]);

        if ($result['ok']) {
            $success = $result['message'];
        } else {
            $errors = $result['errors'];
        }
    }
}

/* ============================================
   ROLE SETTINGS (REGISTRATION)
============================================ */

if ($role === 'customer') {
    $title = "Register as Customer";
    $subtitle = "Create your customer account to get started";
    $color = "#2563eb";
    $lightColor = "#eaf2ff";
    $icon = '<i class="fa-solid fa-user-group"></i>';
} elseif ($role === 'agent') {
    $title = "Register as Agent";
    $subtitle = "Create your agent account to get started";
    $color = "#16a34a";
    $lightColor = "#eaf8ee";
    $icon = '<i class="fa-solid fa-user-tie"></i>';
} else {
    $title = "Register as Merchant";
    $subtitle = "Create your merchant account to get started";
    $color = "#f97316";
    $lightColor = "#fff1e5";
    $icon = '<i class="fa-solid fa-store"></i>';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $showRegister ? $title : "INDBIN | Grow Retail on Credit Digitally"; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Arial, sans-serif; }
        body { background-color: #f4f7fb; color: #333; min-height: 100vh; }

        .navbar { background: #ffffff; display: flex; justify-content: space-between; align-items: center; padding: 15px 5%; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .logo { font-size: 24px; font-weight: 800; color: #0b2545; display: flex; align-items: center; text-decoration: none; }
        .logo span { color: #2563eb; }
        .nav-links { display: flex; gap: 25px; list-style: none; align-items: center; }
        .nav-links a { text-decoration: none; color: #334155; font-size: 14px; font-weight: 500; }
        .nav-right { display: flex; align-items: center; gap: 20px; }
        .phone { font-size: 14px; font-weight: 600; color: #0f172a; }

        .btn-login { background: #2563eb; color: #ffffff; border: none; padding: 9px 20px; border-radius: 6px; font-weight: 600; cursor: pointer; text-decoration: none; display: flex; align-items: center; gap: 8px; font-size: 14px; }
        .btn-login:hover { background: #1d4ed8; }

        .hero { text-align: center; padding: 50px 20px 30px; }
        .hero h1 { font-size: 42px; color: #0b2545; margin-bottom: 12px; font-weight: 800; }
        .hero h1 span { color: #2563eb; }
        .hero h3 { font-size: 20px; color: #1e293b; font-weight: 700; margin-bottom: 6px; }
        .hero p { color: #64748b; font-size: 15px; }

        .cards-container { display: flex; justify-content: center; gap: 25px; max-width: 1150px; margin: 0 auto 60px; padding: 0 20px; flex-wrap: wrap; }
        .card { background: #ffffff; border-radius: 14px; padding: 35px 25px; width: 330px; text-align: center; box-shadow: 0 4px 20px rgba(0,0,0,0.03); border: 1px solid #e2e8f0; display: flex; flex-direction: column; align-items: center; transition: transform 0.2s; }
        .card:hover { transform: translateY(-5px); }
        .card-customer { background: #f8fafc; }
        .card-agent { background: #f0fdf4; }
        .card-merchant { background: #fff7ed; }

        .icon-wrapper { width: 70px; height: 70px; border-radius: 50%; display: flex; justify-content: center; align-items: center; margin-bottom: 20px; font-size: 28px; }
        .card-customer .icon-wrapper { background: #dbeafe; color: #2563eb; }
        .card-agent .icon-wrapper { background: #dcfce7; color: #16a34a; }
        .card-merchant .icon-wrapper { background: #ffedd5; color: #ea580c; }

        .card h2 { font-size: 22px; margin-bottom: 12px; }
        .card-customer h2 { color: #1d4ed8; }
        .card-agent h2 { color: #15803d; }
        .card-merchant h2 { color: #c2410c; }
        .card p { font-size: 14px; color: #475569; margin-bottom: 25px; min-height: 40px; line-height: 1.4; }

        .btn-card { width: 100%; padding: 12px; border: none; border-radius: 8px; font-size: 15px; font-weight: 600; color: #ffffff; cursor: pointer; display: flex; justify-content: center; align-items: center; gap: 10px; text-decoration: none; }
        .card-customer .btn-card { background: #2563eb; }
        .card-agent .btn-card { background: #16a34a; }
        .card-merchant .btn-card { background: #ea580c; }

        /* Registration UI Styles */
        .registration-page { min-height: calc(100vh - 150px); display: flex; justify-content: center; align-items: center; padding: 30px 20px; }
        .registration-box { width: 500px; max-width: 100%; background: #ffffff; padding: 30px 35px; border-radius: 15px; box-shadow: 0 6px 20px rgba(0,0,0,0.08); }
        .registration-icon { width: 70px; height: 70px; margin: 0 auto 18px; border-radius: 50%; background: <?= $lightColor; ?>; display: flex; align-items: center; justify-content: center; font-size: 30px; color: <?= $color; ?>; }
        .registration-box h1 { text-align: center; color: <?= $color; ?>; font-size: 27px; margin-bottom: 8px; }
        .subtitle { text-align: center; color: #64748b; font-size: 15px; margin-bottom: 22px; }
        .line { width: 100%; height: 1px; background: <?= $lightColor; ?>; margin-bottom: 22px; }

        .form-group { margin-bottom: 18px; text-align: left; }
        .form-group label { display: block; color: #172b4d; font-size: 14px; font-weight: bold; margin-bottom: 7px; }
        .form-group input, .form-group select { width: 100%; height: 46px; padding: 0 13px; border: 1px solid #d5dce5; border-radius: 7px; font-size: 14px; color: #1e293b; background: #ffffff; }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: <?= $color; ?>; box-shadow: 0 0 0 3px <?= $lightColor; ?>; }
        .form-group input::placeholder { color: #8a98ad; }

        .complete-button { width: 100%; height: 48px; border: none; border-radius: 7px; background: <?= $color; ?>; color: #ffffff; font-size: 16px; font-weight: bold; cursor: pointer; margin-top: 3px; transition: 0.2s; display: flex; align-items: center; justify-content: center; text-decoration: none; }
        .complete-button:hover { opacity: 0.9; transform: translateY(-1px); }
        .back-button { display: block; text-align: center; margin-top: 15px; text-decoration: none; color: #64748b; font-size: 14px; }
        .back-button:hover { color: #2563eb; }

        .alert { padding: 12px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 18px; text-align: left; }
        .alert-error { background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; }
        .alert-success { background: #d1fae5; color: #059669; border: 1px solid #6ee7b7; }

        /* Login Modal Component */
        .modal-backdrop { display: <?= $showLoginModal ? 'flex' : 'none' ?>; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(11, 37, 69, 0.6); justify-content: center; align-items: center; z-index: 1000; backdrop-filter: blur(3px); }
        .modal-card { background: #ffffff; width: 100%; max-width: 440px; padding: 32px; border-radius: 16px; position: relative; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
        .modal-close { position: absolute; top: 16px; right: 20px; font-size: 22px; cursor: pointer; color: #94a3b8; }
        .modal-close:hover { color: #0f172a; }

        footer { background: #061a33; color: #94a3b8; padding: 40px 5% 20px; margin-top: auto; }
        .footer-grid { display: flex; justify-content: space-between; max-width: 1200px; margin: 0 auto 30px; flex-wrap: wrap; gap: 30px; }
        .footer-col h4 { color: #ffffff; margin-bottom: 15px; font-size: 14px; }
        .footer-col p, .footer-col a { font-size: 13px; color: #94a3b8; text-decoration: none; display: block; margin-bottom: 8px; }
        .footer-bottom { border-top: 1px solid #1e293b; padding-top: 20px; display: flex; justify-content: space-between; align-items: center; max-width: 1200px; margin: 0 auto; font-size: 12px; }
        .socials a { color: #ffffff; margin-left: 15px; font-size: 16px; }

        @media (max-width: 800px) { .nav-links, .phone { display: none; } }
        @media (max-width: 600px) { .navbar { padding: 15px 20px; } .hero { padding: 35px 20px 25px; } .hero h1 { font-size: 30px; } .registration-box { padding: 25px 20px; } .footer-bottom { flex-direction: column; gap: 15px; text-align: center; } }
    </style>
</head>
<body>

<nav class="navbar">
    <a href="index.php" class="logo">IND<span>BIN</span></a>

    <ul class="nav-links">
        <li><a href="index.php">Home</a></li>
        <li><a href="#">About Us ▾</a></li>
        <li><a href="#">Solutions</a></li>
        <li><a href="#">Products ▾</a></li>
        <li><a href="#">Resources ▾</a></li>
        <li><a href="#">Contact Us</a></li>
    </ul>

    <div class="nav-right">
        <span class="phone"><i class="fa-solid fa-phone"></i> +91 80 4950 8282</span>
        <button class="btn-login" onclick="openLoginModal('customer')"><i class="fa-solid fa-user"></i> Login</button>
    </div>
</nav>

<?php if (!$showRegister): ?>

<section class="hero">
    <h1>Welcome to IND<span>BIN</span></h1>
    <h3>Grow Retail on Credit Digitally</h3>
    <p>One Platform. Multiple Opportunities.</p>
</section>

<section class="cards-container">
    <div class="card card-customer">
        <div class="icon-wrapper"><i class="fa-solid fa-user-group"></i></div>
        <h2>I am a Customer</h2>
        <p>Shop from your favorite stores and pay on credit easily and securely.</p>
        <div style="display:flex; flex-direction:column; gap:10px; width:100%;">
            <a href="index.php?register=customer" class="btn-card">Continue as Customer <i class="fa-solid fa-arrow-right"></i></a>
            <button onclick="openLoginModal('customer')" style="background:transparent; border:none; color:#2563eb; font-size:13px; font-weight:600; cursor:pointer;">Already registered? Login</button>
        </div>
    </div>

    <div class="card card-agent">
        <div class="icon-wrapper"><i class="fa-solid fa-user-tie"></i></div>
        <h2>I am an Agent</h2>
        <p>Join our network as an agent and earn attractive commissions.</p>
        <div style="display:flex; flex-direction:column; gap:10px; width:100%;">
            <a href="index.php?register=agent" class="btn-card">Continue as Agent <i class="fa-solid fa-arrow-right"></i></a>
            <button onclick="openLoginModal('agent')" style="background:transparent; border:none; color:#16a34a; font-size:13px; font-weight:600; cursor:pointer;">Already registered? Login</button>
        </div>
    </div>

    <div class="card card-merchant">
        <div class="icon-wrapper"><i class="fa-solid fa-store"></i></div>
        <h2>I am a Merchant</h2>
        <p>Grow your business by offering credit to your customers.</p>
        <div style="display:flex; flex-direction:column; gap:10px; width:100%;">
            <a href="index.php?register=merchant" class="btn-card">Continue as Merchant <i class="fa-solid fa-arrow-right"></i></a>
            <button onclick="openLoginModal('merchant')" style="background:transparent; border:none; color:#ea580c; font-size:13px; font-weight:600; cursor:pointer;">Already registered? Login</button>
        </div>
    </div>
</section>

<?php else: ?>

<div class="registration-page">
    <div class="registration-box">
        <?php if ($success): ?>
            <div class="registration-icon" style="background:#d1fae5; color:#059669;">
                <i class="fa-solid fa-circle-check"></i>
            </div>

            <h1 style="color:#059669;">You're all set!</h1>
            <p class="subtitle">One last step — verify your identity to activate your account.</p>

            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>

            <a href="<?= htmlspecialchars(role_home($role)) ?>" class="complete-button">
                Continue to Onboarding Process
            </a>
        <?php else: ?>
            <div class="registration-icon"><?= $icon; ?></div>

            <h1><?= $title; ?></h1>
            <p class="subtitle"><?= $subtitle; ?></p>
            <div class="line"></div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <?php foreach ($errors as $err) echo "<div>" . htmlspecialchars($err) . "</div>"; ?>
                </div>
            <?php endif; ?>

            <form action="index.php?register=<?= htmlspecialchars($role); ?>" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="register">
                <input type="hidden" name="role" value="<?= htmlspecialchars($role); ?>">

                <div class="form-group">
                    <label for="full_name">Full Name</label>
                    <input type="text" id="full_name" name="full_name" placeholder="Enter your full name" value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>" required>
                </div>

                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" placeholder="Enter your email address" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                </div>

                <?php if ($role === 'merchant'): ?>
                    <div class="form-group">
                        <label for="business_name">Business Name</label>
                        <input type="text" id="business_name" name="business_name" placeholder="Enter your business name" value="<?= htmlspecialchars($_POST['business_name'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="business_type">Business Type</label>
                        <select id="business_type" name="business_type" required>
                            <option value="" disabled <?= empty($_POST['business_type']) ? 'selected' : '' ?>>Select business type</option>
                            <?php
                            $types = ['Retail Store', 'Grocery / Kirana', 'Restaurant / Food', 'Electronics', 'Pharmacy', 'Apparel', 'Other'];
                            foreach ($types as $type) {
                                $sel = (($_POST['business_type'] ?? '') === $type) ? 'selected' : '';
                                echo "<option value=\"" . htmlspecialchars($type) . "\" $sel>" . htmlspecialchars($type) . "</option>";
                            }
                            ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="Enter your password" required>
                </div>

                <button type="submit" class="complete-button">Complete</button>
            </form>

            <a href="index.php" class="back-button">← Back to role selection</a>
        <?php endif; ?>
    </div>
</div>

<?php endif; ?>

<!-- UNIFIED LOGIN MODAL -->
<div class="modal-backdrop" id="loginModal">
    <div class="modal-card">
        <span class="modal-close" onclick="closeLoginModal()">&times;</span>
        <h2 id="modalTitle" style="color:#0b2545; font-size:22px; margin-bottom:6px;">Login</h2>
        <p style="color:#64748b; font-size:13px; margin-bottom:20px;">Access your INDBIN dashboard</p>

        <?php if ($login_error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($login_error) ?></div>
        <?php endif; ?>

        <form method="POST" action="index.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="login">

            <div class="form-group">
                <label>Account Role</label>
                <select name="role" id="loginRoleSelect">
                    <option value="customer">Customer</option>
                    <option value="agent">Agent</option>
                    <option value="merchant">Merchant</option>
                    <option value="admin">Admin</option>
                </select>
            </div>

            <div class="form-group">
                <label>Email or Mobile</label>
                <input type="text" name="identifier" placeholder="Enter Email or Mobile" required>
            </div>

            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" placeholder="Enter Password" required>
            </div>

            <button type="submit" class="complete-button" style="background:#2563eb; margin-top:10px;">Login <i class="fa-solid fa-right-to-bracket"></i></button>
        </form>
    </div>
</div>

<footer>
    <div class="footer-grid">
        <div class="footer-col">
            <h3 style="color: #fff; margin-bottom: 10px;">INDBIN</h3>
            <p style="max-width: 250px;">INDBIN Fintech Services LLP is building technology-driven solutions to empower retail and small businesses with digital credit.</p>
        </div>

        <div class="footer-col">
            <h4>Quick Links</h4>
            <a href="#">About Us</a>
            <a href="#">Products</a>
            <a href="#">Solutions</a>
            <a href="#">Careers</a>
        </div>

        <div class="footer-col">
            <h4>Products</h4>
            <a href="#">GROCD</a>
            <a href="#">PalmPay</a>
            <a href="#">AEPS</a>
            <a href="#">Ruflow</a>
        </div>

        <div class="footer-col">
            <h4>Company</h4>
            <a href="#">Privacy Policy</a>
            <a href="#">Terms & Conditions</a>
            <a href="#">Refund Policy</a>
        </div>

        <div class="footer-col">
            <h4>Contact Us</h4>
            <p><i class="fa-solid fa-location-dot"></i> Bengaluru, Karnataka, India</p>
            <p><i class="fa-solid fa-phone"></i> +91 80 4950 8282</p>
            <p><i class="fa-solid fa-envelope"></i> support@indbin.com</p>
        </div>
    </div>

    <div class="footer-bottom">
        <p>© 2026 INDBIN Fintech Services LLP. All Rights Reserved.</p>

        <div class="socials">
            <a href="#"><i class="fa-brands fa-facebook"></i></a>
            <a href="#"><i class="fa-brands fa-linkedin"></i></a>
            <a href="#"><i class="fa-brands fa-twitter"></i></a>
            <a href="#"><i class="fa-brands fa-instagram"></i></a>
        </div>
    </div>
</footer>

<script>
<?php if ($showLoginModal): ?>
    window.addEventListener('DOMContentLoaded', function () {
        document.getElementById('loginModal').style.display = 'flex';
    });
<?php endif; ?>

    function openLoginModal(role) {
        if (role) {
            document.getElementById('loginRoleSelect').value = role;
        }
        document.getElementById('loginModal').style.display = 'flex';
        document.querySelector('#loginModal input[name="identifier"]').focus();
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { closeLoginModal(); }
    });

    function closeLoginModal() {
        document.getElementById('loginModal').style.display = 'none';
    }

    window.onclick = function(event) {
        let modal = document.getElementById('loginModal');
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    }
</script>

</body>
</html>