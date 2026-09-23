<?php
/**
 * Renamed to agent_settings.php once role assignment moved here too.
 * Kept as a redirect so an old bookmark or link still lands somewhere.
 */
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
redirect(BASE_URL . '/admin/agent_settings.php');
