<?php
declare(strict_types=1);

/**
 * Shared eKYC step for merchant and agent.
 *
 * Both modules had their own copy of this screen, each with its own inline
 * CSS and its own idea of the status vocabulary. They are the same step
 * asking the same questions, so there is one implementation here and the
 * four page files are thin wrappers, which is how the customer module is
 * arranged and why it has not drifted.
 *
 * Writes to kyc_documents and sets users.kyc_status, exactly as before, so
 * the admin review queue and the step statuses keep working unchanged.
 *//** Document types offered, keyed by the value stored in the database. */
const EKYC_DOC_TYPES = [
    'AADHAAR'  => 'Aadhaar',
    'PAN'      => 'PAN',
    'PASSPORT' => 'Passport',
    'VOTER_ID' => 'Voter ID',
    'DL'       => 'Driving licence',
];

/** Every spelling of "not yet submitted" that exists across the modules. */
function ekyc_not_started(string $status): bool
{
    return in_array($status, ['not_started', 'not_submitted', '', 'rejected', 'resubmit'], true);
}

/**
 * The upload form, plus its POST handler.
 * $role is 'merchant' or 'agent' and only affects wording and the next step.
 */
function ekyc_upload_page(PDO $pdo, array $user, string $role): void
{
    $uid    = (int)$user['id'];
    $errors = [];
    $sentTo = $_SESSION['ekyc_otp_to'] ?? null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify();
        $action = post_str('action', 20);

        // ---- send the code -------------------------------------------
        if ($action === 'send_otp') {
            $mobile = preg_replace('/\D/', '', post_str('mobile', 15));

            if (!valid_mobile($mobile)) {
                flash('error', 'Enter a 10-digit Indian mobile number.');
            } else {
                $dup = $pdo->prepare("SELECT id FROM users WHERE mobile = ? AND id <> ?");
                $dup->execute([$mobile, $uid]);

                if ($dup->fetch()) {
                    flash('error', 'That mobile number belongs to another account.');
                } elseif (($otp = otp_issue($pdo, $uid, 'ekyc', $mobile)) === null) {
                    flash('error', 'Too many codes requested. Try again in 15 minutes.');
                } else {
                    $_SESSION['ekyc_otp_to'] = $mobile;
                    flash('info', 'Code sent to ' . mask_tail($mobile) . '.');
                    if (APP_ENV === 'local') {
                        flash('warn', 'Local mode: your code is ' . $otp);
                    }
                }
            }
            redirect($_SERVER['REQUEST_URI']);
        }

        // ---- verify the code -----------------------------------------
        if ($action === 'verify_otp') {
            $target = (string)($_SESSION['ekyc_otp_to'] ?? '');
            $code   = preg_replace('/\D/', '', post_str('otp', 6));

            if ($target === '') {
                flash('error', 'Request a code first.');
            } elseif (!otp_verify($pdo, 'ekyc', $target, $code)) {
                flash('error', 'That code is wrong or has expired.');
            } else {
                $_SESSION['ekyc_mobile_ok'] = $target;
                $pdo->prepare("UPDATE users SET mobile = ?, mobile_verified = 1 WHERE id = ?")
                    ->execute([$target, $uid]);
                flash('success', 'Mobile number verified.');
            }
            redirect($_SERVER['REQUEST_URI']);
        }

        // ---- submit the documents ------------------------------------
        if ($action === 'submit') {
            $fullName = post_str('full_name_on_document', 150);
            $docType  = post_str('document_type', 50);
            $docNo    = strtoupper(preg_replace('/\s+/', '', post_str('document_number', 40)));
            $mobile   = (string)($_SESSION['ekyc_mobile_ok'] ?? '');

            if ($mobile === '')                       $errors[] = 'Verify your mobile number first.';
            if ($fullName === '')                     $errors[] = 'Enter your name exactly as it appears on the document.';
            if (!isset(EKYC_DOC_TYPES[$docType]))     $errors[] = 'Choose a document type.';
            if ($docType === 'AADHAAR' && !valid_aadhaar($docNo)) {
                $errors[] = 'That Aadhaar number fails its checksum. Re-enter it.';
            }
            if ($docType === 'PAN' && !valid_pan($docNo)) {
                $errors[] = 'PAN must look like ABCDE1234F.';
            }
            if ($docNo === '')                        $errors[] = 'Enter the document number.';

            // ---- the file
            $storedPath = null;
            $file = $_FILES['id_photo'] ?? null;

            if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                $errors[] = 'Attach a photo or scan of the document.';
            } elseif ($file['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'The upload did not complete. Try again.';
            } elseif ($file['size'] > 5_242_880) {
                $errors[] = 'The file is larger than 5 MB.';
            } else {
                // Trust the bytes, not the browser-supplied content type.
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
                $ext  = match ($mime) {
                    'image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf',
                    default      => null,
                };
                if ($ext === null) {
                    $errors[] = 'Upload a JPG, PNG or PDF.';
                } else {
                    $dir = __DIR__ . '/../' . $role . '/ekyc/uploads';
                    if (!is_dir($dir)) { @mkdir($dir, 0750, true); }

                    // Random name: never reuse what the browser sent.
                    $name = sprintf('kyc_%d_%s.%s', $uid, bin2hex(random_bytes(8)), $ext);
                    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
                        $errors[] = 'Could not save the file.';
                    } else {
                        @chmod($dir . '/' . $name, 0640);
                        $storedPath = 'uploads/' . $name;
                    }
                }
            }

            if (!$errors) {
                $pdo->beginTransaction();
                try {
                    // Merchant/agent eKYC goes to an admin review queue, same
                    // as before. Customer eKYC is a separate implementation
                    // and is unaffected by this either way.
                    $pdo->prepare(
                        "INSERT INTO kyc_documents
                            (user_id, full_name_on_document, mobile_number, document_type,
                             document_number, id_photo_path, status)
                         VALUES (?,?,?,?,?,?, 'pending')"
                    )->execute([$uid, $fullName, $mobile, $docType, $docNo, $storedPath]);

                    $pdo->prepare("UPDATE users SET kyc_status = 'pending' WHERE id = ?")->execute([$uid]);
                    $pdo->commit();
                } catch (Throwable $ex) {
                    if ($pdo->inTransaction()) { $pdo->rollBack(); }
                    error_log('ekyc submit: ' . $ex->getMessage());
                    flash('error', 'Submission failed. Try again.');
                    redirect($_SERVER['REQUEST_URI']);
                }

                unset($_SESSION['ekyc_otp_to'], $_SESSION['ekyc_mobile_ok']);
                audit_log($pdo, 'kyc.submitted', 'kyc_documents', (string)$uid, null, ['type' => $docType]);
                flash('success', 'Documents submitted. Verification usually completes within a working day.');
                redirect('status.php');
            }

            foreach ($errors as $e) { flash('error', $e); }
            redirect($_SERVER['REQUEST_URI']);
        }
    }

    $verified = (string)($_SESSION['ekyc_mobile_ok'] ?? '');
    render_shell_open($pdo, $uid, 'ekyc', 'eKYC verification');
    ?>
    <div class="card">
        <div class="card-icon"><i class="fa-solid fa-shield-halved"></i></div>
        <div class="card-head">
            <h2>Verify your identity</h2>
            <p>We check the details against the document you upload. A reviewer looks at
               every submission before your account is activated.</p>
        </div>

        <?php if ($verified === ''): ?>

            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="send_otp">
                <div class="field">
                    <label for="mobile">Mobile number</label>
                    <div class="inline-field">
                        <input id="mobile" type="tel" name="mobile" inputmode="numeric" maxlength="10"
                               pattern="[6-9][0-9]{9}" required
                               value="<?= e((string)($sentTo ?? $user['mobile'] ?? '')) ?>"
                               placeholder="9876543210">
                        <button class="btn" type="submit"><?= $sentTo ? 'Resend' : 'Send code' ?></button>
                    </div>
                    <p class="hint">Three codes per number every 15 minutes.</p>
                </div>
            </form>

            <?php if ($sentTo): ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="verify_otp">
                    <div class="field">
                        <label for="otp">Enter the code</label>
                        <input id="otp" type="text" name="otp" inputmode="numeric" maxlength="6"
                               pattern="[0-9]{6}" autocomplete="one-time-code" required placeholder="â€¢â€¢â€¢â€¢â€¢â€¢">
                    </div>
                    <button class="btn block" type="submit">Verify number</button>
                </form>
            <?php endif; ?>

        <?php else: ?>

            <div class="notice is-ok">
                <i class="fa-solid fa-circle-check"></i>
                <span>Mobile <?= e(mask_tail($verified)) ?> verified.</span>
            </div>

            <form method="post" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="submit">

                <div class="field">
                    <label for="full_name_on_document">Name on the document</label>
                    <input id="full_name_on_document" type="text" name="full_name_on_document"
                           required value="<?= e((string)$user['full_name']) ?>">
                </div>

                <div class="field">
                    <label for="document_type">Document type</label>
                    <select id="document_type" name="document_type" required>
                        <?php foreach (EKYC_DOC_TYPES as $k => $label): ?>
                            <option value="<?= e($k) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="document_number">Document number</label>
                    <input id="document_number" type="text" name="document_number" required autocomplete="off">
                    <?php if (APP_ENV === 'local'): ?>
                        <p class="hint">Local mode: a valid test Aadhaar is
                            <?= e(generate_valid_aadhaar()) ?>. Real Aadhaar numbers carry a
                            checksum, so most made-up ones fail this same check a real one passes.</p>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label for="id_photo">Photo or scan</label>
                    <input id="id_photo" type="file" name="id_photo" required
                           accept="image/jpeg,image/png,application/pdf">
                    <p class="hint">JPG, PNG or PDF, up to 5 MB. All four corners visible and text legible.</p>
                </div>

                <button class="btn block" type="submit">Submit for verification</button>
            </form>

        <?php endif; ?>
    </div>
    <?php
    render_shell_close();
}

/** The status screen shown once something has been submitted. */
function ekyc_status_page(PDO $pdo, array $user, string $role): void
{
    $uid    = (int)$user['id'];
    $status = (string)($user['kyc_status'] ?? 'not_started');

    $stmt = $pdo->prepare("SELECT * FROM kyc_documents WHERE user_id = ?
                            ORDER BY id DESC LIMIT 1");
    $stmt->execute([$uid]);
    $doc = $stmt->fetch() ?: null;

    [$icon, $tone, $label, $blurb] = match ($status) {
        'approved' => ['fa-circle-check', 'is-ok', 'Verified',
                       'Your documents cleared verification.'],
        'pending'  => ['fa-clock', 'is-warn', 'In review',
                       'A reviewer is checking your documents. This usually takes one working day.'],
        'rejected' => ['fa-circle-xmark', 'is-warn', 'Not accepted',
                       'Read the reason below, then submit again.'],
        'resubmit' => ['fa-rotate', 'is-warn', 'Resubmission needed',
                       'Replace the document flagged below.'],
        default    => ['fa-circle-exclamation', 'is-warn', 'Not submitted',
                       'You have not sent us anything to check yet.'],
    };

    $nextHref  = $role === 'merchant' ? '../business/upload.php' : '../background/index.php';
    $nextLabel = $role === 'merchant' ? 'Continue to business verification'
                                      : 'Continue to background check';

    render_shell_open($pdo, $uid, 'ekyc', 'eKYC status');
    ?>
    <div class="card">
        <div class="card-icon <?= $tone ?>"><i class="fa-solid <?= $icon ?>"></i></div>
        <div class="card-head">
            <h2>KYC status</h2>
            <p><?= e($blurb) ?></p>
        </div>

        <p class="status-label" style="text-align:center;margin-bottom:18px;"><?= e($label) ?></p>

        <?php if ($doc && !empty($doc['rejection_reason'])): ?>
            <div class="notice is-bad"><?= e((string)$doc['rejection_reason']) ?></div>
        <?php endif; ?>

        <?php if ($doc): ?>
            <div class="detail-block">
                <div class="detail-row"><span>Name on document</span>
                    <strong><?= e((string)$doc['full_name_on_document']) ?></strong></div>
                <div class="detail-row"><span>Document</span>
                    <strong><?= e((string)$doc['document_type']) ?></strong></div>
                <div class="detail-row"><span>Mobile</span>
                    <strong><?= e(mask_tail((string)$doc['mobile_number'])) ?></strong></div>
                <div class="detail-row"><span>Submitted</span>
                    <strong><?= e(date('j M Y, H:i', strtotime((string)$doc['created_at']))) ?></strong></div>
            </div>
        <?php endif; ?>

        <?php if (ekyc_not_started($status)): ?>
            <a class="btn block" href="upload.php">
                <?= $doc ? 'Submit again' : 'Start KYC verification' ?>
            </a>
        <?php elseif (!in_array($status, ['rejected', 'resubmit'], true)): ?>
            <a class="btn block" href="<?= e($nextHref) ?>"><?= e($nextLabel) ?></a>
        <?php else: ?>
            <button class="btn block" disabled>
                <i class="fa-solid fa-clock"></i> Waiting on the reviewer
            </button>
        <?php endif; ?>
    </div>
    <?php
    render_shell_close();
}