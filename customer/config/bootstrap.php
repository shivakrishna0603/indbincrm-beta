<?php
/**
 * Customer module bootstrap.
 *
 * Since the three modules were merged this only adds what is specific to the
 * customer flow. The session, the PDO handle, the shared helpers and the
 * role guards all come from core, so a customer signing in on the main site
 * is the same session the moment they walk into this module.
 */

declare(strict_types=1);

require_once dirname(dirname(__DIR__)) . '/core/bootstrap.php';

define('CUST_ROOT', dirname(__DIR__));
define('CUST_BASE', BASE_URL . '/customer');

require_once CUST_ROOT . '/includes/helpers.php';   // document handling only
require_once CUST_ROOT . '/includes/guard.php';     // the seven-step gate
require_once CUST_ROOT . '/config/steps.php';
require_once CUST_ROOT . '/config/documents.php';
