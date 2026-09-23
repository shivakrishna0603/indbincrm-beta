<?php
/**
 * Step 2: eKYC verification.
 *
 * Collects identity details and the documents in the 'ekyc' stage of
 * config/documents.php, then hands the case to ekyc/admin_review.php.
 *
 * Differences from the current onboarding.php step 2:
 *  - the uploaded ID photo is actually read and stored ($_FILES was ignored)
 *  - Aadhaar numbers get a Verhoeff checksum test, PAN gets a format test
 *  - the document number is encrypted; only the last four digits are shown
 *  - a live selfie is required so the face can be matched to the ID
 *  - an eKYC consent record is written before any document is accepted
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
require_once CUST_ROOT . '/portal/shell.php';

$user = require_customer($pdo);
require_step($pdo, $user, 2);

$stmt = $pdo->prepare("SELECT * FROM kyc_details WHERE user_id = ?");
$stmt->execute([(int)$user['id']]);
$kyc = $stmt->fetch() ?: null;

// Already submitted and not bounced back: show status instead of the form.
if ($kyc && in_array($user['kyc_status'], ['pending', 'approved'], true)) {
    redirect(CUST_BASE . '/ekyc/status.php');
}

$docs = documents_for_stage('ekyc');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $errors   = [];
    $fullName = post_str('full_name', 120);
    $dob      = post_str('dob', 10);
    $gender   = post_str('gender', 12);
    $kycMode  = post_str('kyc_mode', 20);
    $docType  = post_str('doc_type', 50);
    $docNo    = strtoupper(preg_replace('/\s+/', '', post_str('doc_number', 30)));
    $consent  = isset($_POST['ekyc_consent']);

    if ($fullName === '')                          $errors[] = 'Enter your name exactly as it appears on the document.';
    if (!$dob || strtotime($dob) === false)        $errors[] = 'Enter your date of birth.';
    if (!in_array($kycMode, ['aadhaar_otp','digilocker','video_kyc','offline_xml','manual'], true)) {
        $errors[] = 'Choose a verification method.';
    }
    if ($docType === '')                           $errors[] = 'Choose a document type.';
    if (!$consent)                                 $errors[] = 'eKYC cannot proceed without your consent.';

    // Age gate. The platform does not onboard minors.
    if ($dob && strtotime($dob) !== false) {
        $age = (new DateTime())->diff(new DateTime($dob))->y;
        if ($age < 18) {
            $errors[] = 'You must be 18 or older to open an account.';
        }
    }

    if ($docType === 'AADHAAR' && !valid_aadhaar($docNo)) {
        $errors[] = 'That Aadhaar number fails its checksum. Re-enter it.';
    }
    if ($docType === 'PAN' && !valid_pan($docNo)) {
        $errors[] = 'PAN must look like ABCDE1234F.';
    }

    // Refuse a document number already verified against another account.
    if (!$errors && $docNo !== '') {
        $hash = pii_hash($docNo);
        $dup  = $pdo->prepare("SELECT user_id FROM kyc_details
                                WHERE doc_number_hash = ? AND user_id <> ? AND status = 'approved'");
        $dup->execute([$hash, (int)$user['id']]);
        if ($dup->fetch()) {
            $errors[] = 'This document is already linked to a verified account.';
        }
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            record_consent($pdo, (int)$user['id'], 'aadhaar_ekyc', true, '1.0', $kycMode);

            $pdo->prepare(
                "INSERT INTO kyc_details
                    (user_id, full_name, mobile, dob, gender, kyc_mode, doc_type,
                     doc_number_last4, doc_number_enc, doc_number_hash, status)
                 VALUES (?,?,?,?,?,?,?,?,?,?, 'pending')
                 ON DUPLICATE KEY UPDATE
                    full_name = VALUES(full_name), dob = VALUES(dob), gender = VALUES(gender),
                    kyc_mode = VALUES(kyc_mode), doc_type = VALUES(doc_type),
                    doc_number_last4 = VALUES(doc_number_last4),
                    doc_number_enc = VALUES(doc_number_enc),
                    doc_number_hash = VALUES(doc_number_hash),
                    status = 'pending', rejection_reason = NULL"
            )->execute([
                (int)$user['id'], $fullName, (string)$user['mobile'], $dob, $gender ?: null,
                $kycMode, $docType, substr($docNo, -4) ?: null,
                pii_encrypt($docNo), $docNo !== '' ? pii_hash($docNo) : null,
            ]);

            // Store every file that came with the form.
            foreach ($docs as $code => $spec) {
                if (!isset($_FILES[$code]) || ($_FILES[$code]['error'] ?? 4) === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $res = store_document($pdo, (int)$user['id'], $code, $_FILES[$code], 'ekyc',
                                      $code === 'PAN_CARD' ? substr($docNo, -4) : null);
                if (!$res['ok']) {
                    throw new RuntimeException($spec['label'] . ': ' . $res['error']);
                }
            }

            $missing = missing_documents($pdo, (int)$user['id'], 'ekyc');
            // The consent receipt is generated, not uploaded, so it never blocks.
            $missing = array_diff($missing, ['EKYC_CONSENT_RECEIPT']);
            if ($missing) {
                $labels = array_map(static fn($c) => CUSTOMER_DOCUMENTS[$c]['label'], $missing);
                throw new RuntimeException('Still needed: ' . implode(', ', $labels));
            }

            $pdo->prepare("UPDATE users SET kyc_status = 'pending' WHERE id = ?")
                ->execute([(int)$user['id']]);
            $pdo->commit();

            audit_log($pdo, 'kyc.submitted', 'kyc_details', (string)$user['id'], null, ['mode' => $kycMode]);
            advance_step($pdo, (int)$user['id'], 3);

            flash('success', 'Documents submitted. Verification usually completes within a working day.');
            redirect(CUST_BASE . '/ekyc/status.php');

        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $ex->getMessage();
        }
    }

    foreach ($errors as $msg) {
        flash('error', $msg);
    }
}

onboarding_shell_open($pdo, $user, 2, 'eKYC verification');
?>
<div class="card">
    <div class="card-icon"><i class="fa-solid fa-shield-halved"></i></div>
    <div class="card-head">
        <h2>Verify your identity</h2>
        <p>Your details are checked against the document you upload. Nothing is shared outside INDBIN and its regulated partners.</p>
    </div>

    <form method="post" enctype="multipart/form-data" action="<?= CUST_BASE ?>/ekyc/index.php">
        <?= csrf_field() ?>

        <div class="field">
            <label for="kyc_mode">Verification method</label>
            <select id="kyc_mode" name="kyc_mode" required>
                <option value="aadhaar_otp">Aadhaar OTP</option>
                <option value="digilocker">DigiLocker</option>
                <option value="video_kyc">Video KYC with an agent</option>
                <option value="offline_xml">Offline Aadhaar XML</option>
                <option value="manual">Upload documents for manual review</option>
            </select>
        </div>

        <div class="field">
            <label for="full_name">Name on the document</label>
            <input id="full_name" type="text" name="full_name" required
                   value="<?= e($kyc['full_name'] ?? $user['full_name']) ?>">
        </div>

        <div class="field">
            <label for="dob">Date of birth</label>
            <input id="dob" type="date" name="dob" required max="<?= date('Y-m-d', strtotime('-18 years')) ?>"
                   value="<?= e($kyc['dob'] ?? '') ?>">
        </div>

        <div class="field">
            <label for="gender">Gender</label>
            <select id="gender" name="gender">
                <option value="">Prefer not to say</option>
                <option value="female">Female</option>
                <option value="male">Male</option>
                <option value="other">Other</option>
            </select>
        </div>

        <div class="field">
            <label for="doc_type">Primary document</label>
            <select id="doc_type" name="doc_type" required>
                <option value="AADHAAR">Aadhaar</option>
                <option value="PAN">PAN</option>
                <option value="PASSPORT">Passport</option>
                <option value="VOTER_ID">Voter ID</option>
                <option value="DL">Driving licence</option>
            </select>
        </div>

        <div class="field">
            <label for="doc_number">Document number</label>
            <input id="doc_number" type="text" name="doc_number" required autocomplete="off">
            <p class="hint">Stored encrypted. Only the last four digits are ever displayed back to you.</p>
            <?php if (APP_ENV === 'local'): ?>
                <p class="hint">Local mode: a valid test Aadhaar is
                    <?= e(generate_valid_aadhaar()) ?>. Real Aadhaar numbers carry a
                    checksum, so most made-up ones fail this same check a real one passes.</p>
            <?php endif; ?>
        </div>

        <?php foreach ($docs as $code => $spec):
            if ($code === 'EKYC_CONSENT_RECEIPT') continue;   // generated server side
            if ($code === 'VIDEO_KYC_RECORDING')  continue;   // captured in the video session
        ?>
        <div class="field">
            <label for="f_<?= e($code) ?>">
                <?= e($spec['label']) ?><?= empty($spec['required']) ? ' (optional)' : '' ?>
            </label>
            <input id="f_<?= e($code) ?>" type="file" name="<?= e($code) ?>"
                   accept="<?= e(implode(',', $spec['mime'])) ?>"
                   <?= !empty($spec['required']) ? 'required' : '' ?>>
        </div>
        <?php endforeach; ?>

        <div class="field">
            <label style="display:flex;gap:10px;align-items:flex-start;font-weight:600;">
                <input type="checkbox" name="ekyc_consent" value="1" required
                       style="width:auto;min-height:auto;margin-top:3px;">
                <span>I authorise INDBIN to verify these documents with the issuing authority and to
                      store them for as long as my account is open.</span>
            </label>
        </div>

        <button type="submit" class="btn block">Submit for verification</button>
    </form>
</div>
<?php onboarding_shell_close(); ?>
