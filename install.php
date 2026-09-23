<?php
/**
 * INDBIN : installer.
 *
 *   http://localhost/indbincrm/install.php
 *
 * Creates the database and runs the schema, without phpMyAdmin. It executes
 * one statement at a time and reports exactly which one fails, which is the
 * thing phpMyAdmin makes hard: it stops at the first error and shows the
 * message without much context.
 *
 * DELETE THIS FILE once the install succeeds. It will drop your database.
 */

declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('max_execution_time', '300');
error_reporting(E_ALL);

const DB_HOST = '127.0.0.1';
const DB_PORT = '3306';
const DB_USER = 'root';
const DB_PASS = '';            // XAMPP default is an empty password
const DB_NAME = 'indbincrm';

$sqlFile = __DIR__ . '/sql/install_from_scratch.sql';

/**
 * Split a script into statements.
 *
 * Scans character by character, tracking whether it is inside a quoted
 * string, so a semicolon in a literal does not split a statement and a "--"
 * in a literal is not mistaken for a comment. An explode(';') would break on
 * both.
 */
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

        // A comment only starts outside a quoted string. Scanning character
        // by character is the only way to tell the two apart: a regex would
        // also strip a "--" that happens to sit inside a string literal.
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

$run      = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['confirm'] ?? '') === 'yes');
$report   = [];
$failedAt = null;
$summary  = null;

if ($run) {
    if (!is_readable($sqlFile)) {
        $failedAt = ['n' => 0, 'sql' => '', 'error' => 'Cannot read ' . $sqlFile
                     . '. Extract the full zip, keeping the sql folder.'];
    } else {
        try {
            // Connect with no database selected: the script creates it.
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4',
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                 PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );

            $statements = split_statements((string)file_get_contents($sqlFile));

            foreach ($statements as $n => $stmt) {
                try {
                    $pdo->exec($stmt);
                    $report[] = ['n' => $n + 1, 'ok' => true, 'sql' => $stmt];
                } catch (PDOException $e) {
                    $failedAt = ['n' => $n + 1, 'sql' => $stmt, 'error' => $e->getMessage()];
                    break;
                }
            }

            if (!$failedAt) {
                $pdo->exec('USE `' . DB_NAME . '`');
                $summary = $pdo->query(
                    "SELECT SUM(TABLE_TYPE = 'BASE TABLE') AS tables_created,
                            SUM(TABLE_TYPE = 'VIEW')       AS views_created
                       FROM information_schema.TABLES
                      WHERE TABLE_SCHEMA = " . $pdo->quote(DB_NAME)
                )->fetch();
                $summary['accounts'] = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            }
        } catch (PDOException $e) {
            $failedAt = ['n' => 0, 'sql' => '', 'error' => 'Could not connect to MySQL: '
                         . $e->getMessage() . ' — start MySQL in the XAMPP control panel.'];
        }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>INDBIN installer</title>
<style>
 :root{--ok:#16a34a;--bad:#dc2626;--warn:#d97706;--ink:#0b2545;--muted:#64748b;--line:#e2e8f0}
 *{box-sizing:border-box;margin:0;padding:0}
 body{font:15px/1.6 -apple-system,"Segoe UI",Roboto,sans-serif;background:#f8fafc;color:#334155;padding:40px 20px}
 main{max-width:780px;margin:0 auto}
 h1{font-size:26px;font-weight:800;color:var(--ink)}
 .sub{color:var(--muted);font-size:14px;margin:6px 0 24px}
 .card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:24px;margin-bottom:18px}
 .banner{padding:16px 20px;border-radius:10px;font-weight:700;margin-bottom:20px}
 .banner.ok{background:#dcfce7;color:var(--ok)}
 .banner.bad{background:#fef2f2;color:var(--bad)}
 .banner.warn{background:#fef3c7;color:#92400e}
 button{padding:13px 26px;border:0;border-radius:9px;background:#dc2626;color:#fff;font:inherit;font-weight:700;cursor:pointer}
 pre{background:#0b2545;color:#e2e8f0;padding:14px;border-radius:8px;overflow:auto;font-size:12px;line-height:1.5;margin-top:12px}
 code{background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:13px}
 ul{margin:10px 0 0 20px}li{margin-bottom:6px;font-size:14px}
 .stat{display:flex;gap:26px;margin-top:14px}
 .stat div{font-size:13px;color:var(--muted)}
 .stat strong{display:block;font-size:26px;color:var(--ink)}
 a{color:#2563eb}
</style>
</head>
<body>
<main>
 <h1>INDBIN installer</h1>
 <p class="sub">Creates <code><?= DB_NAME ?></code> and loads the schema. Delete this file afterwards.</p>

<?php if (!$run): ?>

 <div class="banner warn">
   This drops the <code><?= DB_NAME ?></code> database if it exists. Everything in it is destroyed.
 </div>

 <div class="card">
   <h2 style="font-size:17px;font-weight:700;color:var(--ink);margin-bottom:10px;">Before you press it</h2>
   <ul>
     <li>MySQL must be running in the XAMPP control panel.</li>
     <li>The file <code>sql/install_from_scratch.sql</code> must exist:
         <?= is_readable($sqlFile)
             ? '<span style="color:var(--ok);font-weight:700">found</span>'
             : '<span style="color:var(--bad);font-weight:700">MISSING, extract the full zip</span>' ?></li>
     <li>Credentials used: <code><?= DB_USER ?></code> at <code><?= DB_HOST ?>:<?= DB_PORT ?></code>
         with <?= DB_PASS === '' ? 'an empty password' : 'the password set in this file' ?>.
         Edit the constants at the top of install.php if yours differ.</li>
   </ul>

   <form method="post" style="margin-top:20px;">
     <input type="hidden" name="confirm" value="yes">
     <button type="submit">Drop and rebuild <?= DB_NAME ?></button>
   </form>
 </div>

<?php elseif ($failedAt): ?>

 <div class="banner bad">
   Stopped at statement <?= (int)$failedAt['n'] ?>. Nothing after it ran.
 </div>

 <div class="card">
   <h2 style="font-size:17px;font-weight:700;color:var(--ink);">What MySQL said</h2>
   <pre><?= htmlspecialchars($failedAt['error'], ENT_QUOTES) ?></pre>

   <?php if ($failedAt['sql'] !== ''): ?>
     <h2 style="font-size:17px;font-weight:700;color:var(--ink);margin-top:20px;">The statement it rejected</h2>
     <pre><?= htmlspecialchars(substr($failedAt['sql'], 0, 3000), ENT_QUOTES) ?></pre>
   <?php endif; ?>

   <p style="margin-top:16px;font-size:14px;color:var(--muted);">
     Send me both boxes above and I can fix it precisely.
     <?= count($report) ?> statement(s) ran before this one.
   </p>
 </div>

<?php else: ?>

 <div class="banner ok">Installed. <?= count($report) ?> statements ran without error.</div>

 <div class="card">
   <div class="stat">
     <div>Tables<strong><?= (int)($summary['tables_created'] ?? 0) ?></strong></div>
     <div>Views<strong><?= (int)($summary['views_created'] ?? 0) ?></strong></div>
     <div>Accounts<strong><?= (int)($summary['accounts'] ?? 0) ?></strong></div>
   </div>
   <p style="margin-top:16px;font-size:14px;color:var(--muted);">
     Expected: 43 tables, 2 views, 4 accounts.
   </p>

   <h2 style="font-size:17px;font-weight:700;color:var(--ink);margin-top:24px;">Next</h2>
   <ul>
     <li>Delete <code>install.php</code> and <code>diagnose.php</code>.</li>
     <li>Sign in at <a href="index.php">the main site</a> as
         <code>admin@indbin.local</code> / <code>Admin@123</code>, role Admin.</li>
     <li>Change all four seeded passwords.</li>
   </ul>
 </div>

<?php endif; ?>
</main>
</body>
</html>
