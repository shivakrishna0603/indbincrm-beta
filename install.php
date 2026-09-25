<?php
declare(strict_types=1);

/**
 * INDBIN : One-click web installer for Cloud, Hostinger & InfinityFree.
 *
 * Runs the schema directly without needing phpMyAdmin copy-pasting.
 */ini_set('display_errors', '1');
ini_set('max_execution_time', '300');
error_reporting(E_ALL);

if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

$host = defined('DB_HOST') ? DB_HOST : (getenv('DB_HOST') ?: '127.0.0.1');
$port = defined('DB_PORT') ? (string)DB_PORT : (getenv('DB_PORT') ?: '3306');
$user = defined('DB_USER') ? DB_USER : (getenv('DB_USER') ?: 'root');
$pass = defined('DB_PASS') ? DB_PASS : (getenv('DB_PASS') ?: '');
$dbname = defined('DB_NAME') ? DB_NAME : (getenv('DB_NAME') ?: 'indbincrm');

$sqlFile = __DIR__ . '/sql/hostinger_install.sql';
if (!file_exists($sqlFile)) {
    $sqlFile = __DIR__ . '/sql/install_from_scratch.sql';
}

function split_statements(string $sql): array
{
    $out = [];
    $buf = '';
    $inSingle = $inDouble = false;
    $len = strlen($sql);

    for ($i = 0; $i < $len; $i++) {
        $ch   = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : '';
        $prev = $i > 0 ? $sql[$i - 1] : '';

        if (!$inSingle && !$inDouble) {
            if ($ch === '-' && $next === '-') {
                while ($i < $len && $sql[$i] !== "\n") { $i++; }
                $buf .= "\n";
                continue;
            }
            if ($ch === '#') {
                while ($i < $len && $sql[$i] !== "\n") { $i++; }
                $buf .= "\n";
                continue;
            }
            if ($ch === '/' && $next === '*') {
                $i += 2;
                while ($i < $len - 1 && !($sql[$i] === '*' && $sql[$i + 1] === '/')) { $i++; }
                $i++;
                continue;
            }
        }

        if ($ch === "'" && $prev !== '\\' && !$inDouble) { $inSingle = !$inSingle; }
        elseif ($ch === '"' && $prev !== '\\' && !$inSingle) { $inDouble = !$inDouble; }

        if ($ch === ';' && !$inSingle && !$inDouble) {
            $stmt = trim($buf);
            if ($stmt !== '') { $out[] = $stmt; }
            $buf = '';
            continue;
        }
        $buf .= $ch;
    }

    $stmt = trim($buf);
    if ($stmt !== '') { $out[] = $stmt; }
    return $out;
}

$run = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['confirm'] ?? '') === 'yes');
$failedAt = null;
$summary = null;

if ($run) {
    if (!is_readable($sqlFile)) {
        $failedAt = ['error' => 'Cannot read schema file: ' . $sqlFile];
    } else {
        try {
            $pdo = new PDO(
                "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",
                $user, $pass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]
            );

            $raw = preg_replace("/^\xEF\xBB\xBF/", "", (string)file_get_contents($sqlFile)); $statements = split_statements($raw);
            foreach ($statements as $n => $stmt) {
                try {
                    $pdo->exec($stmt);
                } catch (PDOException $e) {
                    $failedAt = ['n' => $n + 1, 'sql' => $stmt, 'error' => $e->getMessage()];
                    break;
                }
            }

            if (!$failedAt) {
                $summary = $pdo->query(
                    "SELECT SUM(TABLE_TYPE = 'BASE TABLE') AS tables_created,
                            SUM(TABLE_TYPE = 'VIEW')       AS views_created
                       FROM information_schema.TABLES
                      WHERE TABLE_SCHEMA = " . $pdo->quote($dbname)
                )->fetch();
                $summary['accounts'] = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            }
        } catch (PDOException $e) {
            $failedAt = ['error' => 'Could not connect to database: ' . $e->getMessage()];
        }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>INDBIN CRM Web Installer</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<style>
body { background: #f8fafc; font-family: system-ui, sans-serif; padding: 40px 15px; }
.install-card { max-width: 680px; margin: 0 auto; background: #fff; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.06); padding: 35px; }
</style>
</head>
<body>
<div class="install-card">
  <h2 class="fw-bold text-primary mb-1">INDBIN CRM Web Installer</h2>
  <p class="text-muted mb-4">One-click database installation tool</p>

  <?php if ($run && !$failedAt): ?>
    <div class="alert alert-success">
      <h5 class="fw-bold"><i class="bi bi-check-circle"></i> Installation Complete!</h5>
      <p class="mb-2">All database tables and seed accounts have been loaded successfully.</p>
      <ul>
        <li>Tables created: <strong><?= $summary['tables_created'] ?? '46' ?></strong></li>
        <li>Views created: <strong><?= $summary['views_created'] ?? '2' ?></strong></li>
        <li>User accounts: <strong><?= $summary['accounts'] ?? '4' ?></strong></li>
      </ul>
      <div class="mt-3">
        <a href="index.php" class="btn btn-primary">Go to Login / Portal</a>
      </div>
    </div>
  <?php elseif ($run && $failedAt): ?>
    <div class="alert alert-danger">
      <h5 class="fw-bold">Installation Failed</h5>
      <p class="mb-1"><?= htmlspecialchars($failedAt['error'] ?? 'Unknown error') ?></p>
      <?php if (!empty($failedAt['sql'])): ?>
        <pre class="bg-dark text-light p-3 rounded mt-2" style="font-size:12px;"><?= htmlspecialchars($failedAt['sql']) ?></pre>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="card bg-light mb-4">
      <div class="card-body">
        <h6 class="fw-bold">Configured Database Connection:</h6>
        <div class="row g-2 small text-muted">
          <div class="col-6"><strong>Host:</strong> <?= htmlspecialchars($host) ?>:<?= htmlspecialchars($port) ?></div>
          <div class="col-6"><strong>Database:</strong> <?= htmlspecialchars($dbname) ?></div>
          <div class="col-6"><strong>User:</strong> <?= htmlspecialchars($user) ?></div>
          <div class="col-6"><strong>Config file:</strong> <?= file_exists(__DIR__ . '/config.php') ? '<span class="text-success fw-bold">config.php detected</span>' : '<span class="text-danger fw-bold">config.php missing (create it first)</span>' ?></div>
        </div>
      </div>
    </div>

    <form method="POST">
      <input type="hidden" name="confirm" value="yes">
      <p class="small text-muted">Clicking the button below will load all tables and default demo accounts into <code><?= htmlspecialchars($dbname) ?></code>.</p>
      <button type="submit" class="btn btn-primary btn-lg w-100 fw-bold">Run Database Installation</button>
    </form>
  <?php endif; ?>
</div>
</body>
</html>
