<?php
/*
    INDBIN : standalone registration page.

    Same handler as index.php uses, via auth.php, so the two cannot drift
    apart the way the three earlier copies did.

    auth.php is required first because it owns session_start() and sets the
    session name that the customer module expects.
*/

declare(strict_types=1);
require_once __DIR__ . '/core/bootstrap.php';

// Already signed in? Nothing to register.
if (auth_is_logged_in()) {
    auth_redirect(role_home((string)$_SESSION['role']));
}

$errors  = [];
$success = '';

$role = $_GET['role'] ?? $_POST['role'] ?? '';
if (!in_array($role, SIGNUP_ROLES, true)) {
    $role = 'customer';
}

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

$roleConfig = [
    'customer' => [
        'label'    => 'Customer',
        'subtitle' => 'Create your customer account to get started',
        'icon'     => 'fa-user-group',
        'color'    => '#2563eb',
        'bg'       => '#dbeafe',
    ],
    'agent' => [
        'label'    => 'Agent',
        'subtitle' => 'Create your agent account to get started',
        'icon'     => 'fa-user-doctor',
        'color'    => '#16a34a',
        'bg'       => '#dcfce7',
    ],
    'merchant' => [
        'label'    => 'Merchant',
        'subtitle' => 'Create your merchant account to get started',
        'icon'     => 'fa-store',
        'color'    => '#ea580c',
        'bg'       => '#ffedd5',
    ],
];
$cfg = $roleConfig[$role];

$old_full_name     = htmlspecialchars($_POST['full_name'] ?? '');
$old_email         = htmlspecialchars($_POST['email'] ?? '');
$old_business_name = htmlspecialchars($_POST['business_name'] ?? '');
$old_business_type = htmlspecialchars($_POST['business_type'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register as <?= htmlspecialchars($cfg['label']) ?> | INDBIN</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Arial, sans-serif; }
        body {
            background-color: #eef1f8;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            padding: 60px 20px;
        }

        .back-link {
            position: absolute;
            top: 25px;
            left: 25px;
            text-decoration: none;
            color: #475569;
            font-size: 14px;
            font-weight: 600;
        }
        .back-link i { margin-right: 6px; }

        .register-card {
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 10px 35px rgba(0,0,0,0.08);
            padding: 40px 35px;
            width: 100%;
            max-width: 460px;
            text-align: center;
        }

        .icon-circle {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 0 auto 18px;
            font-size: 26px;
            background: <?= $cfg['bg'] ?>;
            color: <?= $cfg['color'] ?>;
        }

        .register-card h1 {
            font-size: 24px;
            font-weight: 800;
            color: <?= $cfg['color'] ?>;
            margin-bottom: 6px;
        }

        .register-card .subtitle {
            font-size: 14px;
            color: #64748b;
            margin-bottom: 20px;
        }

        hr {
            border: none;
            border-top: 1px solid #e2e8f0;
            margin-bottom: 25px;
        }

        .form-group {
            margin-bottom: 18px;
            text-align: left;
        }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 6px;
            color: #0b2545;
        }
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            color: #1e293b;
            background: #fff;
        }
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: <?= $cfg['color'] ?>;
        }

        .submit-btn {
            width: 100%;
            padding: 13px;
            border: none;
            border-radius: 8px;
            color: #fff;
            font-weight: 700;
            font-size: 15px;
            cursor: pointer;
            margin-top: 5px;
            background: <?= $cfg['color'] ?>;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 18px;
            text-align: left;
        }
        .alert-error { background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; }
        .alert-success { background: #d1fae5; color: #059669; border: 1px solid #6ee7b7; }

        .success-actions { margin-top: 20px; }
        .success-actions a {
            display: inline-block;
            padding: 12px 24px;
            border-radius: 8px;
            background: <?= $cfg['color'] ?>;
            color: #fff;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
        }
    </style>
</head>
<body>

    <a href="index.php" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to Home</a>

    <div class="register-card">

        <?php if (!empty($success)): ?>

            <div class="icon-circle"><i class="fa-solid fa-circle-check"></i></div>
            <h1>You're all set!</h1>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <p class="subtitle" style="margin-bottom:20px;">One last step — verify your identity to activate your account.</p>
            <div class="success-actions">
                <a href="<?= htmlspecialchars(role_home($role)) ?>">Continue to verification</a>
            </div>

        <?php else: ?>

            <div class="icon-circle"><i class="fa-solid <?= $cfg['icon'] ?>"></i></div>
            <h1>Register as <?= htmlspecialchars($cfg['label']) ?></h1>
            <p class="subtitle"><?= htmlspecialchars($cfg['subtitle']) ?></p>
            <hr>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <?php foreach ($errors as $error) echo "<div>" . htmlspecialchars($error) . "</div>"; ?>
                </div>
            <?php endif; ?>

            <form action="register.php?role=<?= htmlspecialchars($role) ?>" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="register">
                <input type="hidden" name="role" value="<?= htmlspecialchars($role) ?>">

                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="full_name" required placeholder="Tom Jacob" value="<?= $old_full_name ?>">
                </div>

                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" required placeholder="tom@gmail.com" value="<?= $old_email ?>">
                </div>

                <?php if ($role === 'merchant'): ?>
                    <div class="form-group">
                        <label>Business Name</label>
                        <input type="text" name="business_name" required placeholder="Tom's General Store" value="<?= $old_business_name ?>">
                    </div>

                    <div class="form-group">
                        <label>Business Type</label>
                        <select name="business_type" required>
                            <option value="" disabled <?= $old_business_type === '' ? 'selected' : '' ?>>Select business type</option>
                            <?php
                            $types = ['Retail Store', 'Grocery / Kirana', 'Restaurant / Food', 'Electronics', 'Pharmacy', 'Apparel', 'Other'];
                            foreach ($types as $type) {
                                $sel = ($old_business_type === $type) ? 'selected' : '';
                                echo "<option value=\"" . htmlspecialchars($type) . "\" $sel>" . htmlspecialchars($type) . "</option>";
                            }
                            ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" required minlength="8"
                           placeholder="At least 8 characters">
                </div>

                <button type="submit" class="submit-btn">Complete</button>
            </form>

        <?php endif; ?>

    </div>

</body>
</html>
