<?php
/**
 * INDBIN : data export.
 *
 *   http://localhost/indbincrm/export_data.php
 *
 * Dumps every table's actual rows - not the schema, just the data you've
 * entered through the app while testing - as a single downloadable .sql
 * file you can keep, inspect, or import somewhere else.
 *
 * TO RESTORE: import this file directly into a database that already has
 * the schema in place and is named indbincrm - not into a freshly-run
 * install_from_scratch.sql. That file has DROP DATABASE IF EXISTS indbincrm
 * and CREATE DATABASE indbincrm hardcoded near the top, so it can only ever
 * target a database with that exact name, and running it again will
 * silently drop and recreate whatever is already there - including data
 * you meant to keep. Run the schema once, then import exports like this
 * one straight into it.
 *
 * Gated behind an admin login, same as every other admin page. Not behind
 * a "delete this file" warning the way install.php and diagnose.php were:
 * this one only ever reads, it can't drop or change anything, so it's safe
 * to leave in place if you want to re-export later. If you'd rather not
 * leave a data-export endpoint sitting on the server at all, delete it
 * after you've got what you need - that's a reasonable call too.
 */

declare(strict_types=1);
require_once __DIR__ . '/core/bootstrap.php';

$admin = require_admin($pdo);

$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

// Views (products, customer_applications) are excluded - they're derived
// from the real tables, dumping them too would just duplicate the data
// under a different name.
$views = $pdo->query(
    "SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE()"
)->fetchAll(PDO::FETCH_COLUMN);
$tables = array_values(array_diff($tables, $views));

if (isset($_GET['download'])) {
    $filename = 'indbin_data_export_' . date('Y-m-d_His') . '.sql';
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    echo "-- INDBIN CRM data export\n";
    echo "-- Generated " . date('c') . " by " . $admin['full_name'] . "\n";
    echo "-- Data only - run this against a database that already has the schema\n";
    echo "-- (sql/install_from_scratch.sql), not against an empty one.\n\n";
    echo "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    foreach ($tables as $table) {
        $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            continue;
        }
        echo "-- --------------------------------------------------\n";
        echo "-- $table (" . count($rows) . " rows)\n";
        echo "-- --------------------------------------------------\n";

        $columns = array_keys($rows[0]);
        $colList = '`' . implode('`, `', $columns) . '`';

        foreach (array_chunk($rows, 200) as $chunk) {
            $valueLines = [];
            foreach ($chunk as $row) {
                $vals = array_map(function ($v) use ($pdo) {
                    if ($v === null) return 'NULL';
                    return $pdo->quote((string)$v);
                }, $row);
                $valueLines[] = '(' . implode(', ', $vals) . ')';
            }
            echo "INSERT INTO `$table` ($colList) VALUES\n" . implode(",\n", $valueLines) . ";\n\n";
        }
    }

    echo "SET FOREIGN_KEY_CHECKS = 1;\n";
    exit;
}

// No ?download - show a plain summary first, so nothing downloads by accident.
$counts = [];
foreach ($tables as $table) {
    $counts[$table] = (int)$pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
}
$totalRows = array_sum($counts);
?>
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title>Export data | INDBIN</title>
<style>
body{font-family:-apple-system,sans-serif;max-width:640px;margin:60px auto;padding:0 20px;color:#0f172a}
table{width:100%;border-collapse:collapse;font-size:13px;margin-top:16px}
td,th{text-align:left;padding:6px 10px;border-bottom:1px solid #e2e8f0}
.btn{display:inline-block;background:#2563eb;color:#fff;padding:10px 18px;border-radius:8px;
     text-decoration:none;font-weight:600;margin-top:20px}
.muted{color:#64748b;font-size:13px}
</style>
</head>
<body>
<h1>Export data</h1>
<p class="muted">Signed in as <?= htmlspecialchars($admin['full_name']) ?>. <?= count($tables) ?> tables,
   <?= number_format($totalRows) ?> rows total.</p>
<a class="btn" href="?download=1">Download as .sql</a>
<table>
<tr><th>Table</th><th>Rows</th></tr>
<?php foreach ($counts as $t => $n): if ($n === 0) continue; ?>
<tr><td><?= htmlspecialchars($t) ?></td><td><?= number_format($n) ?></td></tr>
<?php endforeach; ?>
</table>
</body>
</html>
