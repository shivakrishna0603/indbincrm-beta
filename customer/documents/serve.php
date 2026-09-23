<?php
/**
 * The only route to an uploaded document.
 *
 * A customer may read their own files. An admin may read any. Every read is
 * written to audit_logs, which is what makes "who looked at this Aadhaar
 * scan and when" an answerable question.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$docId = (int)($_GET['id'] ?? 0);
if ($docId <= 0 || empty($_SESSION['user_id'])) {
    http_response_code(404);
    exit('Not found.');
}

$viewerId = (int)$_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$stmt->execute([$viewerId]);
$viewerRole = (string)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT * FROM customer_documents WHERE id = ?");
$stmt->execute([$docId]);
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    exit('Not found.');
}

$isOwner = (int)$doc['user_id'] === $viewerId;
$isStaff = in_array($viewerRole, ['admin', 'agent'], true);

if (!$isOwner && !$isStaff) {
    // Same response as a missing file, so the endpoint does not confirm
    // that a given document ID exists.
    http_response_code(404);
    exit('Not found.');
}

$path = realpath(CUST_ROOT . '/' . $doc['stage'] . '/uploads/' . $doc['stored_name']);
$base = realpath(CUST_ROOT . '/' . $doc['stage'] . '/uploads');

// Guard against a stored_name that escapes its folder.
if ($path === false || $base === false || !str_starts_with($path, $base) || !is_file($path)) {
    error_log('serve.php: missing or out-of-bounds file for document ' . $docId);
    http_response_code(404);
    exit('Not found.');
}

audit_log($pdo, 'document.viewed', 'customer_documents', (string)$docId, null,
          ['viewer_role' => $viewerRole], (int)$doc['user_id']);

header('Content-Type: ' . $doc['mime_type']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . rawurlencode($doc['doc_label']) . '"');
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'; img-src \'self\'; object-src \'none\'');
header('Cache-Control: private, no-store, max-age=0');
readfile($path);
