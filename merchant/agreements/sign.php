<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../portal/shell.php';

define('AGREEMENT_VERSION', 'v1.0');

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}
$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT role, full_name, business_name, risk_category, credit_limit, agreement_signed_at FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (($user['role'] ?? '') !== 'merchant') {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}
if (!$user['credit_limit']) {
    header('Location: ../business/index.php');
    exit;
}
if ($user['agreement_signed_at']) {
    header('Location: status.php');
    exit;
}

$color = '#f97316';
$lightColor = '#fff1e5';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $signed_name = trim($_POST['signed_name'] ?? '');
    $signature_data = $_POST['signature_data'] ?? '';
    $signature_method = $_POST['signature_method'] ?? 'draw';

    if (!$signed_name) $errors[] = "Please type your full legal name.";
    if (empty($_POST['agree'])) $errors[] = "You must confirm you have read and agree to the terms.";

    $uploadedSignaturePath = null;

    if ($signature_method === 'upload') {
        if (!isset($_FILES['signature_file']) || $_FILES['signature_file']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = "Please upload a signature image file.";
        } else {
            $file = $_FILES['signature_file'];
            $allowed = ['image/jpeg', 'image/png', 'image/webp'];
            $maxSize = 1 * 1024 * 1024; // 1MB

            if (!in_array($file['type'], $allowed)) {
                $errors[] = "Signature file must be JPG, PNG, or WEBP.";
            } elseif ($file['size'] > $maxSize) {
                $errors[] = "Signature file must be under 1MB.";
            } else {
                $uploadDir = __DIR__ . '/signatures/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = 'sig_' . $user_id . '_' . time() . '.' . $ext;
                if (move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
                    $uploadedSignaturePath = 'signatures/' . $filename;
                } else {
                    $errors[] = "Could not save the uploaded signature. Please try again.";
                }
            }
        }
    } else {
        if (!$signature_data || strpos($signature_data, 'data:image/png;base64,') !== 0) {
            $errors[] = "Please draw your signature before submitting.";
        }
        // A blank canvas still produces a valid-looking base64 string, so
        // require a minimum data length as a rough signal that something was drawn.
        if (empty($errors) && strlen($signature_data) < 1500) {
            $errors[] = "Your signature looks empty. Please draw it again.";
        }
    }

    if (empty($errors)) {
        if ($signature_method === 'upload') {
            $finalSignaturePath = $uploadedSignaturePath;
        } else {
            $uploadDir = __DIR__ . '/signatures/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

            $base64 = str_replace('data:image/png;base64,', '', $signature_data);
            $imageBinary = base64_decode($base64);
            $filename = 'sig_' . $user_id . '_' . time() . '.png';
            $filepath = $uploadDir . $filename;

            if (file_put_contents($filepath, $imageBinary) === false) {
                $errors[] = "Could not save your signature. Please try again.";
                $finalSignaturePath = null;
            } else {
                $finalSignaturePath = 'signatures/' . $filename;
            }
        }

        if ($finalSignaturePath) {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

            $insert = $pdo->prepare(
                "INSERT INTO agreement_signatures
                 (user_id, agreement_version, signed_name, signature_image_path, credit_limit_at_signing, ip_address, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $insert->execute([
                $user_id,
                AGREEMENT_VERSION,
                $signed_name,
                $finalSignaturePath,
                $user['credit_limit'],
                $ip,
                $ua,
            ]);

            $pdo->prepare("UPDATE users SET agreement_signed_at = NOW() WHERE id = ?")->execute([$user_id]);

            header('Location: status.php');
            exit;
        }
    }
}
?>


    <?php render_shell_open($pdo, $user_id, 'agreement', 'Sign Your Agreement'); ?>

    <div style="max-width:600px; margin:0 auto;">
        <div class="agreement-card">

            <div class="icon-circle"><i class="fa-solid fa-file-signature"></i></div>
            <h1>Sign Your Merchant Agreement</h1>
            <p class="subtitle">Review the terms below and sign to activate your credit line</p>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <?php foreach ($errors as $e) echo "<div>" . htmlspecialchars($e) . "</div>"; ?>
                </div>
            <?php endif; ?>

            <div class="doc-box">
                <h3>Merchant Credit Agreement (<?= AGREEMENT_VERSION ?>)</h3>
                <p>This agreement is between INDBIN Fintech Services LLP ("INDBIN") and
                   <strong><?= htmlspecialchars($user['business_name'] ?: $user['full_name']) ?></strong> ("Merchant").</p>

                <h3>1. Approved Credit Limit</h3>
                <p>Based on INDBIN's risk assessment, the Merchant is approved for a credit limit of
                   <span class="highlight">₹<?= number_format((float)$user['credit_limit'], 0) ?></span>
                   (Risk category: <?= htmlspecialchars(ucfirst($user['risk_category'] ?? 'medium')) ?>).
                   This limit may be revised by INDBIN based on updated verification or repayment behavior.</p>

                <h3>2. Use of Credit</h3>
                <p>The credit line may be used solely for business transactions conducted through the INDBIN platform.
                   The Merchant agrees to repay all amounts drawn under this facility as per the repayment schedule
                   communicated at the time of each transaction.</p>

                <h3>3. Fees and Charges</h3>
                <p>INDBIN may charge processing fees, late payment fees, or interest as disclosed in the platform's
                   fee schedule, which may be updated from time to time with prior notice.</p>

                <h3>4. Data and Verification</h3>
                <p>The Merchant confirms that all eKYC and business verification details submitted are accurate,
                   and authorizes INDBIN to verify this information with relevant authorities and credit bureaus.</p>

                <h3>5. Termination</h3>
                <p>INDBIN reserves the right to suspend or terminate this credit facility in case of fraud,
                   misrepresentation, or repeated default, in accordance with applicable law.</p>

                <p style="margin-top:14px; font-style:italic; color:#64748b;">
                    <em>This is placeholder legal text — replace with your actual, lawyer-reviewed agreement before going live.</em>
                </p>
            </div>

            <form method="POST" id="agreementForm" enctype="multipart/form-data">

                <div class="form-group">
                    <label>Type your full legal name</label>
                    <input type="text" name="signed_name" placeholder="<?= htmlspecialchars($user['full_name']) ?>" required>
                </div>

                <div class="form-group">
                    <label>Signature method</label>
                    <div class="method-toggle">
                        <label class="method-option active" id="drawTab" onclick="switchMethod('draw')">
                            <i class="fa-solid fa-pen"></i> Draw signature
                        </label>
                        <label class="method-option" id="uploadTab" onclick="switchMethod('upload')">
                            <i class="fa-solid fa-upload"></i> Upload signature image
                        </label>
                    </div>
                </div>

                <input type="hidden" name="signature_method" id="signatureMethod" value="draw">

                <div class="form-group" id="drawSection">
                    <label>Draw your signature below</label>
                    <div class="signature-wrap">
                        <canvas id="signaturePad"></canvas>
                    </div>
                    <div class="sig-actions">
                        <button type="button" onclick="clearSignature()">Clear signature</button>
                        <span style="font-size:11px; color:#94a3b8;">Use your mouse or finger to sign</span>
                    </div>
                </div>

                <div class="form-group" id="uploadSection" style="display:none;">
                    <label>Upload a signature image</label>
                    <label class="upload-box" style="display:block;">
                        <i class="fa-solid fa-file-image"></i>
                        <p id="uploadFileName">Click to upload (JPG/PNG/WEBP, max 1MB)</p>
                        <input type="file" name="signature_file" id="signatureFile" accept="image/*" style="display:none;"
                               onchange="document.getElementById('uploadFileName').innerText = this.files[0]?.name || 'Click to upload'">
                    </label>
                </div>

                <input type="hidden" name="signature_data" id="signatureData">

                <div class="terms-row">
                    <input type="checkbox" name="agree" id="agree" required>
                    <label for="agree">I have read and agree to the Merchant Credit Agreement above, and I understand this digital signature is legally binding.</label>
                </div>

                <button type="submit" class="submit-btn">Sign & Activate Credit Line</button>

            </form>

        </div>
    </div>

    <script>
        const canvas = document.getElementById('signaturePad');
        const ctx = canvas.getContext('2d');

        function resizeCanvas() {
            const rect = canvas.getBoundingClientRect();
            canvas.width = rect.width;
            canvas.height = rect.height;
            ctx.lineWidth = 2.2;
            ctx.lineCap = 'round';
            ctx.strokeStyle = '#0b2545';
        }
        window.addEventListener('resize', resizeCanvas);
        resizeCanvas();

        let drawing = false;
        let lastX = 0, lastY = 0;

        function getPos(e) {
            const rect = canvas.getBoundingClientRect();
            const clientX = e.touches ? e.touches[0].clientX : e.clientX;
            const clientY = e.touches ? e.touches[0].clientY : e.clientY;
            return { x: clientX - rect.left, y: clientY - rect.top };
        }

        function startDraw(e) {
            drawing = true;
            const pos = getPos(e);
            lastX = pos.x; lastY = pos.y;
            e.preventDefault();
        }
        function draw(e) {
            if (!drawing) return;
            const pos = getPos(e);
            ctx.beginPath();
            ctx.moveTo(lastX, lastY);
            ctx.lineTo(pos.x, pos.y);
            ctx.stroke();
            lastX = pos.x; lastY = pos.y;
            e.preventDefault();
        }
        function endDraw() { drawing = false; }

        canvas.addEventListener('mousedown', startDraw);
        canvas.addEventListener('mousemove', draw);
        canvas.addEventListener('mouseup', endDraw);
        canvas.addEventListener('mouseleave', endDraw);
        canvas.addEventListener('touchstart', startDraw);
        canvas.addEventListener('touchmove', draw);
        canvas.addEventListener('touchend', endDraw);

        function clearSignature() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
        }

        function switchMethod(method) {
            document.getElementById('signatureMethod').value = method;
            document.getElementById('drawTab').classList.toggle('active', method === 'draw');
            document.getElementById('uploadTab').classList.toggle('active', method === 'upload');
            document.getElementById('drawSection').style.display = method === 'draw' ? 'block' : 'none';
            document.getElementById('uploadSection').style.display = method === 'upload' ? 'block' : 'none';
        }

        document.getElementById('agreementForm').addEventListener('submit', function (e) {
            if (document.getElementById('signatureMethod').value === 'draw') {
                document.getElementById('signatureData').value = canvas.toDataURL('image/png');
            }
        });
    </script>

    <?php render_shell_close(); ?>