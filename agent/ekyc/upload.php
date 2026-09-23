<?php
/**
 * agent eKYC: submit documents.
 * The screen itself is core/ekyc_page.php, shared with the other module,
 * because they ask the same questions and used to drift apart.
 */

declare(strict_types=1);
require_once __DIR__ . '/../db.php';
require_once APP_ROOT . '/core/ekyc_page.php';
require_once __DIR__ . '/../portal/shell.php';

$user = require_agent($pdo);

// Already submitted and not bounced back: nothing to do here.
if (!ekyc_not_started((string)$user['kyc_status'])) {
    redirect('status.php');
}

ekyc_upload_page($pdo, $user, 'agent');
