<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../portal/shell.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}
$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT role, kyc_status, business_verification_status FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (($user['role'] ?? '') !== 'merchant') {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}
$bvStatus = $user['business_verification_status'] ?? 'not_submitted';
if ($bvStatus === 'pending' || $bvStatus === 'approved') {
    header('Location: status.php');
    exit;
}

// Merchant theme (orange) — same as registration/eKYC
$color = '#f97316';
$lightColor = '#fff1e5';

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $gstin              = strtoupper(trim($_POST['gstin'] ?? ''));
    $business_address   = trim($_POST['business_address'] ?? '');
    $bank_account_number = trim($_POST['bank_account_number'] ?? '');
    $ifsc_code          = strtoupper(trim($_POST['ifsc_code'] ?? ''));
    $years_in_business  = trim($_POST['years_in_business'] ?? '');
    $monthly_turnover   = trim($_POST['monthly_turnover'] ?? '');

    if (!preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/', $gstin)) {
        $errors[] = "Enter a valid 15-character GSTIN.";
    }
    if (!$business_address) $errors[] = "Business address is required.";
    if (!preg_match('/^\d{9,18}$/', $bank_account_number)) {
        $errors[] = "Enter a valid bank account number.";
    }
    if (!preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc_code)) {
        $errors[] = "Enter a valid IFSC code (e.g. HDFC0001234).";
    }
    if ($years_in_business === '' || !is_numeric($years_in_business) || (int)$years_in_business < 0) {
        $errors[] = "Enter years in business.";
    }
    if (!in_array($monthly_turnover, ['below_1L', '1L_5L', '5L_25L', '25L_1Cr', 'above_1Cr'])) {
        $errors[] = "Select your approximate monthly turnover.";
    }
    if (empty($_POST['terms'])) {
        $errors[] = "You must accept the Terms & Conditions to continue.";
    }

    // File uploads
    $fileFields = [
        'gst_certificate'  => ['label' => 'GST certificate', 'path' => null],
        'address_proof'    => ['label' => 'Address proof', 'path' => null],
        'cancelled_cheque' => ['label' => 'Cancelled cheque / passbook photo', 'path' => null],
        'shop_photo'       => ['label' => 'Shop photo', 'path' => null],
    ];

    if (empty($errors)) {
        $uploadDir = __DIR__ . '/uploads/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
        $maxSize = 3 * 1024 * 1024; // 3MB

        foreach ($fileFields as $key => &$info) {
            if (!isset($_FILES[$key]) || $_FILES[$key]['error'] !== UPLOAD_ERR_OK) {
                $errors[] = "Please upload: " . $info['label'];
                continue;
            }
            $file = $_FILES[$key];
            if (!in_array($file['type'], $allowed)) {
                $errors[] = $info['label'] . " must be JPG, PNG, WEBP, or PDF.";
                continue;
            }
            if ($file['size'] > $maxSize) {
                $errors[] = $info['label'] . " must be under 3MB.";
                continue;
            }
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = $key . '_' . $user_id . '_' . time() . '.' . $ext;
            $destination = $uploadDir . $filename;
            if (move_uploaded_file($file['tmp_name'], $destination)) {
                $info['path'] = 'uploads/' . $filename;
            } else {
                $errors[] = "Upload failed for: " . $info['label'];
            }
        }
        unset($info);
    }

    if (empty($errors)) {
        $insert = $pdo->prepare(
            "INSERT INTO business_verifications
             (user_id, gstin, gst_certificate_path, business_address, address_proof_path,
              bank_account_number, ifsc_code, cancelled_cheque_path, shop_photo_path,
              years_in_business, monthly_turnover)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $ok = $insert->execute([
            $user_id,
            $gstin,
            $fileFields['gst_certificate']['path'],
            $business_address,
            $fileFields['address_proof']['path'],
            $bank_account_number,
            $ifsc_code,
            $fileFields['cancelled_cheque']['path'],
            $fileFields['shop_photo']['path'],
            (int)$years_in_business,
            $monthly_turnover,
        ]);

        if ($ok) {
            $pdo->prepare("UPDATE users SET business_verification_status = 'pending' WHERE id = ?")
                ->execute([$user_id]);

            // Scoring runs immediately based on submitted data — it does not
            // wait for admin approval. Admin approval (business/admin_review.php)
            // remains a separate audit/compliance action and can still reject
            // a submission later if needed, but doesn't block the merchant's
            // progress in the meantime.
            require_once __DIR__ . '/../credit/scoring.php';
            runAndSaveRiskAssessment($pdo, $user_id);

            $success = "Your business verification has been submitted, and your credit score is ready.";
        } else {
            $errors[] = "Submission failed. Please try again.";
        }
    }
}
?>


    <?php render_shell_open($pdo, $user_id, 'business', 'Business Verification'); ?>

    <div style="max-width:540px; margin:0 auto;">
        <div class="bv-card">

            <?php if ($success): ?>

                <div class="icon-circle" style="background:#d1fae5; color:#059669;"><i class="fa-solid fa-circle-check"></i></div>
                <h1 style="color:#059669;">Submitted for review</h1>
                <p class="subtitle">You can continue while our team reviews it in the background.</p>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
                <a href="../credit/status.php" class="submit-btn" style="display:block; text-align:center; text-decoration:none; box-sizing:border-box; margin-bottom:10px;">Continue to Credit & Risk Assessment</a>
                <a href="status.php" style="display:block; text-align:center; font-size:13px; color:#64748b; text-decoration:none;">View verification status instead</a>

            <?php else: ?>

                <div class="icon-circle"><i class="fa-solid fa-building"></i></div>
                <h1>Business Verification</h1>
                <p class="subtitle">Final step: verify your business to start accepting credit</p>

                <div class="gradient-divider"></div>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-error">
                        <?php foreach ($errors as $e) echo "<div>" . htmlspecialchars($e) . "</div>"; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data">

                    <div class="section-label">GST details</div>

                    <div class="form-group">
                        <label>GSTIN</label>
                        <input type="text" name="gstin" placeholder="22AAAAA0000A1Z5" maxlength="15"
                               value="<?= htmlspecialchars($_POST['gstin'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label>GST certificate</label>
                        <label class="upload-box" style="display:block;">
                            <i class="fa-solid fa-file-upload"></i>
                            <p>Click to upload (JPG/PNG/PDF, max 3MB)</p>
                            <input type="file" name="gst_certificate" accept="image/*,application/pdf" style="display:none;" required
                                   onchange="this.closest('.upload-box').querySelector('p').innerText = this.files[0]?.name || 'Click to upload'">
                        </label>
                    </div>

                    <div class="section-label">Business address</div>

                    <div class="form-group">
                        <label>Full business address</label>
                        <textarea name="business_address" placeholder="Shop no., street, area, city, state, pincode" required><?= htmlspecialchars($_POST['business_address'] ?? '') ?></textarea>
                    </div>

                    <div class="form-group">
                        <label>Address proof</label>
                        <label class="upload-box" style="display:block;">
                            <i class="fa-solid fa-file-upload"></i>
                            <p>Electricity bill / rent agreement (JPG/PNG/PDF, max 3MB)</p>
                            <input type="file" name="address_proof" accept="image/*,application/pdf" style="display:none;" required
                                   onchange="this.closest('.upload-box').querySelector('p').innerText = this.files[0]?.name || 'Click to upload'">
                        </label>
                    </div>

                    <div class="section-label">Bank details</div>

                    <div class="two-col">
                        <div class="form-group">
                            <label>Bank account number</label>
                            <input type="text" name="bank_account_number" placeholder="123456789012"
                                   value="<?= htmlspecialchars($_POST['bank_account_number'] ?? '') ?>" required>
                        </div>
                        <div class="form-group">
                            <label>IFSC code</label>
                            <input type="text" name="ifsc_code" placeholder="HDFC0001234" maxlength="11"
                                   value="<?= htmlspecialchars($_POST['ifsc_code'] ?? '') ?>" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Cancelled cheque / passbook photo</label>
                        <label class="upload-box" style="display:block;">
                            <i class="fa-solid fa-file-upload"></i>
                            <p>Click to upload (JPG/PNG/PDF, max 3MB)</p>
                            <input type="file" name="cancelled_cheque" accept="image/*,application/pdf" style="display:none;" required
                                   onchange="this.closest('.upload-box').querySelector('p').innerText = this.files[0]?.name || 'Click to upload'">
                        </label>
                    </div>

                    <div class="section-label">Business scale</div>

                    <div class="two-col">
                        <div class="form-group">
                            <label>Years in business</label>
                            <input type="number" name="years_in_business" min="0" max="100" placeholder="e.g. 3"
                                   value="<?= htmlspecialchars($_POST['years_in_business'] ?? '') ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Approx. monthly turnover</label>
                            <select name="monthly_turnover" required>
                                <option value="" disabled <?= empty($_POST['monthly_turnover']) ? 'selected' : '' ?>>Select range</option>
                                <?php
                                $turnoverOptions = [
                                    'below_1L' => 'Below ₹1 lakh',
                                    '1L_5L'    => '₹1 – 5 lakh',
                                    '5L_25L'   => '₹5 – 25 lakh',
                                    '25L_1Cr'  => '₹25 lakh – 1 crore',
                                    'above_1Cr'=> 'Above ₹1 crore',
                                ];
                                foreach ($turnoverOptions as $val => $label) {
                                    $sel = (($_POST['monthly_turnover'] ?? '') === $val) ? 'selected' : '';
                                    echo "<option value=\"$val\" $sel>" . htmlspecialchars($label) . "</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>

                    <div class="section-label">Shop photo</div>

                    <div class="form-group">
                        <label>Photo of your shop / business front</label>
                        <label class="upload-box" style="display:block;">
                            <i class="fa-solid fa-camera"></i>
                            <p>Click to upload (JPG/PNG, max 3MB)</p>
                            <input type="file" name="shop_photo" accept="image/*" style="display:none;" required
                                   onchange="this.closest('.upload-box').querySelector('p').innerText = this.files[0]?.name || 'Click to upload'">
                        </label>
                    </div>

                    <div class="terms-row">
                        <input type="checkbox" name="terms" id="terms" value="1" required>
                        <label for="terms">I confirm the details above are accurate and I agree to the <a href="../terms.php" target="_blank">Terms &amp; Conditions</a>.</label>
                    </div>

                    <button type="submit" class="submit-btn">Submit for review</button>

                </form>

            <?php endif; ?>

        </div>
    </div>

    <?php render_shell_close(); ?>