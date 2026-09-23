<?php
/**
 * Compatibility shim.
 *
 * Every file in this module does require_once 'db.php' and then uses $pdo.
 * Rather than editing all of them, this hands over to the shared core, which
 * means one session, one PDO handle and one set of helpers for the whole
 * application.
 *
 * The module's original db.php opened a second connection with emulated
 * prepares on and printed the DSN to the browser on failure.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';

// $pdo now exists, from core/db.php.
