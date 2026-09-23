<?php
/**
 * customer/index.php : the module's single entry point.
 *
 * Sends a signed-in customer to the right place: the step they are on, or
 * the dashboard once the account is active. Root index.php and register.php
 * currently point at three different destinations for the same customer
 * (customer/onboarding.php, ekyc/index.php, ekyc/status.php), so link here
 * from both and this file decides.
 */

declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

$user = require_customer($pdo);

if ($user['account_status'] === 'active' && !empty($user['customer_code'])) {
    redirect(CUST_BASE . '/dashboard/index.php');
}

// A customer who finished every step but is waiting on eKYC lands on step 7.
redirect(step_url(effective_step($pdo, $user)));
