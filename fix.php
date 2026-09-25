<?php
/**
 * INDBIN CRM - System Repair & Admin Setup Tool
 */

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

if (!file_exists(__DIR__ . '/config.php')) {
    die("config.php not found in htdocs!");
}
require_once __DIR__ . '/config.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

$msg = [];

// 1. Create missing training_content table & populate
$pdo->exec("CREATE TABLE IF NOT EXISTS training_content (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    topic_key   VARCHAR(60) NOT NULL,
    page_number TINYINT UNSIGNED NOT NULL,
    heading     VARCHAR(150) NOT NULL,
    body        TEXT NOT NULL,
    tip         VARCHAR(255) NULL,
    UNIQUE KEY uq_topic_page (topic_key, page_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS training_progress (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    topic_key  VARCHAR(60) NOT NULL,
    completed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_topic (user_id, topic_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$lessons = [
    ['accept_payments', 1, 'How customers pay you', 'Every INDBIN merchant account comes with a UPI-linked QR code. When a customer scans it with any UPI app, payment goes straight into your linked settlement account. No card machine to buy, no setup fee.', 'Print your QR code and keep it visible at your counter.'],
    ['accept_payments', 2, 'Sharing and tracking your QR code', 'Find your QR code anytime under Manage Products & Services. Every payment shows up in Orders & Transactions within seconds.', 'A payment pending for more than 5 minutes should be treated as failed.'],
    ['credit_repayments', 1, 'Understanding your credit limit', 'Your credit limit is set after business verification based on turnover and documents submitted. It determines credit extended to customers via INDBIN pay-later.', 'Keeping your business documents updated helps your limit grow.'],
    ['credit_repayments', 2, 'How repayments actually work', 'When a customer uses pay-later credit, INDBIN settles the full amount to you upfront immediately. The customer then repays INDBIN directly.', 'Check Repayment Tracking to see which sales used pay-later credit.'],
    ['settlements', 1, 'What a settlement cycle means', 'A settlement transfers payments received into your actual bank account. You can choose daily, weekly, or monthly settlements.', 'Daily settlement has no extra fee on INDBIN.'],
    ['settlements', 2, 'Tracking your payouts', 'Every settlement is logged under Repayment Tracking with the exact amount, destination bank, and date.', 'Export your settlement history monthly for tax filing.'],
    ['dashboard', 1, 'Finding your way around', 'Your dashboard sidebar follows the business flow: Leads -> Quotes -> Applications -> Orders -> Repayments.', 'Bookmark the dashboard home page for quick daily status checks.'],
    ['dashboard', 2, 'Reports that matter', 'Analytics shows your transaction volume, top products, and customer repeat rate over any time range you pick.', 'Compare this week to the same week last month to spot real trends.'],
    ['services', 1, 'What is enabled on your account', 'Manage Products & Services shows every INDBIN product and which are active for your account. Basic UPI acceptance is on by default.', 'Check this page after updates for newly enabled services.'],
    ['services', 2, 'Requesting something new', 'If a service you want is not active yet, use the Apply button on the Services page. Most approvals happen automatically.', 'Improving repayment history is the best way to raise limits.']
];

$stmt = $pdo->prepare("INSERT IGNORE INTO training_content (topic_key, page_number, heading, body, tip) VALUES (?, ?, ?, ?, ?)");
foreach ($lessons as $l) {
    $stmt->execute($l);
}
$msg[] = "Training content & progress tables created successfully.";

// 2. Ensure admins table exists
$pdo->exec("CREATE TABLE IF NOT EXISTS admins (
    user_id             INT UNSIGNED PRIMARY KEY,
    designation         VARCHAR(100) NOT NULL DEFAULT 'Administrator',
    can_approve_kyc     TINYINT(1)   NOT NULL DEFAULT 1,
    can_override_credit TINYINT(1)   NOT NULL DEFAULT 1,
    can_manage_payouts  TINYINT(1)   NOT NULL DEFAULT 1,
    created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// 3. Reset/Create Admin Account: admin@indbin.local / Admin@123
$adminEmail = 'admin@indbin.local';
$adminPass = 'Admin@123';
$adminHash = password_hash($adminPass, PASSWORD_DEFAULT);

$check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$check->execute([$adminEmail]);
$adminId = $check->fetchColumn();

if ($adminId) {
    $pdo->prepare("UPDATE users SET role = 'admin', password_hash = ?, account_status = 'active', kyc_status = 'approved', mobile_verified = 1 WHERE id = ?")
        ->execute([$adminHash, $adminId]);
    $msg[] = "Existing Admin account password reset to: <b>$adminPass</b>";
} else {
    $ins = $pdo->prepare("INSERT INTO users (role, full_name, email, mobile, password_hash, party_code, account_status, kyc_status, mobile_verified) VALUES ('admin', 'System Administrator', ?, '9000000001', ?, 'INDBINX0000001', 'active', 'approved', 1)");
    $ins->execute([$adminEmail, $adminHash]);
    $adminId = $pdo->lastInsertId();
    $msg[] = "New Admin account created: <b>$adminEmail</b> / <b>$adminPass</b>";
}

$pdo->prepare("INSERT IGNORE INTO admins (user_id, designation, can_approve_kyc, can_override_credit, can_manage_payouts) VALUES (?, 'Chief Executive', 1, 1, 1)")
    ->execute([$adminId]);

// 4. One-click instant login handler
if (isset($_GET['login_now'])) {
    session_start();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$adminId;
    $_SESSION['role'] = 'admin';
    $_SESSION['full_name'] = 'System Administrator';
    $_SESSION['last_seen'] = time();
    header("Location: admin/index.php");
    exit;
}

// 5. Query user accounts for display
$users = $pdo->query("SELECT id, role, full_name, email, account_status, kyc_status FROM users ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>INDBIN CRM - System Repair & Admin Setup</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<style>
body { background: #f1f5f9; padding: 40px 15px; font-family: system-ui, sans-serif; }
.card { border-radius: 12px; border: none; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
</style>
</head>
<body>
<div class="container" style="max-width: 760px;">
  <div class="card p-4 mb-4">
    <h3 class="fw-bold text-primary mb-3">🛠️ System Repair & Admin Access</h3>
    
    <div class="alert alert-success">
      <h6 class="fw-bold mb-2">Actions Completed:</h6>
      <ul class="mb-0">
        <?php foreach ($msg as $m): ?>
          <li><?= $m ?></li>
        <?php endforeach; ?>
      </ul>
    </div>

    <div class="card bg-light p-3 mb-4">
      <h5 class="fw-bold text-dark">Admin Credentials</h5>
      <p class="mb-1"><strong>Email:</strong> <code>admin@indbin.local</code></p>
      <p class="mb-1"><strong>Password:</strong> <code>Admin@123</code></p>
      <p class="mb-0"><strong>Account Type (Role):</strong> <code>Admin</code></p>
    </div>

    <div class="d-grid gap-2">
      <a href="fix.php?login_now=1" class="btn btn-primary btn-lg fw-bold">🚀 1-Click Instant Login as Admin</a>
      <a href="merchant/training/setup.php" class="btn btn-outline-success">🎓 Go to Merchant Training & Support</a>
      <a href="index.php" class="btn btn-outline-secondary">Go to Homepage / Login Screen</a>
    </div>
  </div>

  <div class="card p-4">
    <h5 class="fw-bold mb-3">Existing User Accounts (<?= count($users) ?>)</h5>
    <div class="table-responsive">
      <table class="table table-sm table-bordered">
        <thead class="table-light">
          <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Email</th>
            <th>Role</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u): ?>
          <tr>
            <td><?= $u['id'] ?></td>
            <td><?= htmlspecialchars($u['full_name']) ?></td>
            <td><?= htmlspecialchars($u['email']) ?></td>
            <td><span class="badge bg-primary"><?= ucfirst($u['role']) ?></span></td>
            <td><span class="badge bg-success"><?= $u['account_status'] ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
</body>
</html>