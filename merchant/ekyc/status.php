<?php
/**
 * merchant eKYC: where the submission stands.
 */

declare(strict_types=1);
require_once __DIR__ . '/../db.php';
require_once APP_ROOT . '/core/ekyc_page.php';
require_once __DIR__ . '/../portal/shell.php';

$user = require_merchant($pdo);

ekyc_status_page($pdo, $user, 'merchant');
