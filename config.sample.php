<?php
declare(strict_types=1);

/**
 * INDBIN CRM - Environment & Database Configuration
 *
 * For Hostinger deployment:
 * 1. Copy this file to `config.php`:
 *    cp config.sample.php config.php
 * 2. Fill in the MySQL credentials created in Hostinger hPanel > Databases.
 * 3. Never commit `config.php` to Git (it is in .gitignore).
 */// Database host: On Hostinger, this is almost always 'localhost'
define('DB_HOST', 'localhost');

// Database port: standard MySQL port
define('DB_PORT', 3306);

// Database name: e.g. 'u123456789_indbincrm'
define('DB_NAME', 'indbincrm');

// Database user: e.g. 'u123456789_indbinuser'
define('DB_USER', 'root');

// Database password: password set when creating DB user in hPanel
define('DB_PASS', '');

// Environment mode: 'local', 'beta', or 'production'
// 'beta' displays helpful error messages if DB is not reachable
define('APP_ENV', 'beta');
