<?php
/**
 * Root compatibility shim.
 * index.php and register.php require this; it hands over to the shared core.
 */

declare(strict_types=1);
require_once __DIR__ . '/core/bootstrap.php';
