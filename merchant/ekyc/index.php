<?php
/**
 * merchant eKYC entry: send them to the form or to the status page.
 */

declare(strict_types=1);
require_once __DIR__ . '/../db.php';
require_once APP_ROOT . '/core/ekyc_page.php';

$user = require_merchant($pdo);

redirect(ekyc_not_started((string)$user['kyc_status']) ? 'upload.php' : 'status.php');
