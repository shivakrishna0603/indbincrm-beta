<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
require_once CUST_ROOT . '/portal/customer_shell.php';

$user = require_customer($pdo);
if ($user['kyc_status'] !== 'approved' || empty($user['customer_code'])) {
    flash('info', 'Your dashboard opens once identity verification clears.');
    redirect(step_url(effective_step($pdo, $user)));
}
$uid = (int)$user['id'];

// The vault itself lives in customer/documents/ so the onboarding steps and
// the dashboard share one implementation.
redirect(CUST_BASE . '/documents/index.php');
