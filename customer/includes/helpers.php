<?php
/**
 * Customer module: document handling.
 *
 * Everything general moved to core/helpers.php when the three modules were
 * merged: escaping, CSRF, OTP, PII encryption, audit, loyalty. Defining any
 * of them here as well would be a fatal redeclare, so this file keeps only
 * what is specific to the customer document flow.
 */

declare(strict_types=1);

/**
 * Validate and store one uploaded file, then record it in customer_documents.
 *
 * onboarding.php step 2 renders an "Upload ID photo" input but never reads
 * $_FILES, so the file is discarded on every submission. This is the
 * replacement path.
 *
 * @return array{ok:bool, error?:string, id?:int}
 */
function store_document(
    PDO $pdo,
    int $userId,
    string $docCode,
    array $file,
    string $stage,
    ?string $numberLast4 = null,
    int $maxBytes = 5_242_880
): array {
    if (!isset(CUSTOMER_DOCUMENTS[$docCode])) {
        return ['ok' => false, 'error' => 'Unknown document type.'];
    }
    $spec = CUSTOMER_DOCUMENTS[$docCode];

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload did not complete. Try again.'];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'error' => 'Invalid upload.'];
    }
    if ($file['size'] > $maxBytes) {
        return ['ok' => false, 'error' => 'File is larger than ' . (int)($maxBytes / 1048576) . ' MB.'];
    }

    // Trust the file's own bytes, not the browser-supplied Content-Type.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string)$finfo->file($file['tmp_name']);
    if (!in_array($mime, $spec['mime'], true)) {
        return ['ok' => false, 'error' => 'This file type is not accepted for ' . $spec['label'] . '.'];
    }

    $ext = match ($mime) {
        'image/jpeg'       => 'jpg',
        'image/png'        => 'png',
        'application/pdf'  => 'pdf',
        'video/mp4'        => 'mp4',
        'video/webm'       => 'webm',
        'application/json' => 'json',
        default            => 'bin',
    };

    $dir = CUST_ROOT . '/' . $stage . '/uploads/' . date('Y/m');
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        error_log('store_document: cannot create ' . $dir);
        return ['ok' => false, 'error' => 'Storage unavailable.'];
    }

    // Random name. Never reuse the client filename on disk.
    $stored = sprintf('%d_%s_%s.%s', $userId, $docCode, bin2hex(random_bytes(8)), $ext);
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) {
        return ['ok' => false, 'error' => 'Could not save the file.'];
    }
    chmod($dir . '/' . $stored, 0640);

    $sha  = hash_file('sha256', $dir . '/' . $stored);
    $rel  = date('Y/m') . '/' . $stored;

    // Supersede any earlier version of the same document.
    $pdo->prepare("UPDATE customer_documents SET is_current = 0
                    WHERE user_id = ? AND doc_code = ? AND is_current = 1")
        ->execute([$userId, $docCode]);

    $version = (int)$pdo->query("SELECT COALESCE(MAX(version),0)+1 FROM customer_documents
                                  WHERE user_id = " . (int)$userId . "
                                    AND doc_code = " . $pdo->quote($docCode))->fetchColumn();

    $pdo->prepare(
        "INSERT INTO customer_documents
            (user_id, stage, doc_code, doc_label, doc_number_last4, stored_name,
             original_name, mime_type, size_bytes, sha256, version, is_current, status)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,1,'pending')"
    )->execute([
        $userId, $stage, $docCode, $spec['label'], $numberLast4, $rel,
        mb_substr((string)$file['name'], 0, 180), $mime, (int)$file['size'], $sha, $version,
    ]);

    $id = (int)$pdo->lastInsertId();
    audit_log($pdo, 'document.upload', 'customer_documents', (string)$id, null,
              ['doc_code' => $docCode, 'sha256' => $sha], $userId);

    return ['ok' => true, 'id' => $id];
}

/** Which required docs for a stage are still missing or rejected. */
function missing_documents(PDO $pdo, int $userId, string $stage): array
{
    $required = required_docs_for_stage($stage);
    if (!$required) {
        return [];
    }
    $in   = implode(',', array_fill(0, count($required), '?'));
    $stmt = $pdo->prepare(
        "SELECT doc_code FROM customer_documents
          WHERE user_id = ? AND is_current = 1 AND status IN ('pending','verified')
            AND doc_code IN ($in)"
    );
    $stmt->execute(array_merge([$userId], $required));
    $have = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_diff($required, $have));
}
