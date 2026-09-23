<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/shell.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];

function docEsc($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/* Assigned customers */
$customers = [];
try {
    $q = $pdo->prepare("
        SELECT DISTINCT
            c.id AS customer_id,
            c.user_id AS customer_user_id,
            c.name AS customer_name,
            c.mobile AS customer_mobile,
            c.email AS customer_email,
            u.customer_code
        FROM customers c
        LEFT JOIN users u ON u.id = c.user_id
        LEFT JOIN customer_agent_assignments ca
            ON ca.customer_id = c.id
            AND ca.agent_user_id = ?
            AND ca.status = 'active'
        WHERE ca.id IS NOT NULL
           OR EXISTS (
                SELECT 1
                FROM applications a
                WHERE a.customer_id = c.user_id
                  AND a.agent_id = ?
           )
        ORDER BY c.name ASC
    ");
    $q->execute([$user_id, $user_id]);
    $customers = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $customers = [];
}

/* Actual submitted documents */
$documents = [];

foreach ($customers as $customer) {
    $customerId = (int)$customer['customer_id'];
    $customerUserId = (int)($customer['customer_user_id'] ?? 0);
    if (!$customerUserId) continue;

    /* Customer onboarding documents */
    try {
        $q = $pdo->prepare("
            SELECT id, doc_code, doc_label, stored_name, original_name,
                   status, reviewed_at, rejection_reason, created_at
            FROM customer_documents
            WHERE user_id = ? AND is_current = 1
            ORDER BY id ASC
        ");
        $q->execute([$customerUserId]);

        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $type = trim((string)($r['doc_label'] ?? ''))
                 ?: trim((string)($r['doc_code'] ?? 'Document'));

            $s = strtolower(trim((string)($r['status'] ?? 'pending')));
            $status = in_array($s, ['verified','approved'], true) ? 'Verified'
                    : ($s === 'rejected' ? 'Rejected' : 'Pending Verification');

            $documents[] = [
                'customer_id' => $customerId,
                'customer_name' => $customer['customer_name'] ?? 'Customer',
                'customer_code' => $customer['customer_code'] ?? '',
                'document_id' => $r['id'],
                'document_type' => $type,
                'document_code' => $r['doc_code'] ?? '',
                'file_name' => $r['original_name'] ?: ($r['stored_name'] ?? ''),
                'file_path' => $r['stored_name'] ?? '',
                'status' => $status,
                'status_class' => strtolower(str_replace(' ', '-', $status)),
                'submitted_at' => $r['created_at'] ?? '',
                'rejection_reason' => $r['rejection_reason'] ?? '',
                'source' => 'Customer Onboarding'
            ];
        }
    } catch (Throwable $e) {}

    /* KYC documents not already present above */
    try {
        $q = $pdo->prepare("
            SELECT id, document_type, id_photo_path, status,
                   reviewed_at, submitted_at
            FROM kyc_documents
            WHERE user_id = ?
            ORDER BY id ASC
        ");
        $q->execute([$customerUserId]);

        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $type = trim((string)($r['document_type'] ?? 'KYC Document'));

            $duplicate = false;
            foreach ($documents as $d) {
                if ((int)$d['customer_id'] === $customerId &&
                    strtolower(trim((string)$d['document_type'])) === strtolower($type)) {
                    $duplicate = true;
                    break;
                }
            }
            if ($duplicate) continue;

            $s = strtolower(trim((string)($r['status'] ?? 'pending')));
            $status = in_array($s, ['verified','approved'], true) ? 'Verified'
                    : ($s === 'rejected' ? 'Rejected' : 'Pending Verification');

            $documents[] = [
                'customer_id' => $customerId,
                'customer_name' => $customer['customer_name'] ?? 'Customer',
                'customer_code' => $customer['customer_code'] ?? '',
                'document_id' => $r['id'],
                'document_type' => $type,
                'document_code' => '',
                'file_name' => basename((string)($r['id_photo_path'] ?? '')),
                'file_path' => '',
                'status' => $status,
                'status_class' => strtolower(str_replace(' ', '-', $status)),
                'submitted_at' => $r['submitted_at'] ?? '',
                'rejection_reason' => '',
                'source' => 'Customer KYC'
            ];
        }
    } catch (Throwable $e) {}

    /* Application documents */
    try {
        $q = $pdo->prepare("
            SELECT id, document_type, file_name, file_path, status,
                   uploaded_at, verified_at, rejection_reason
            FROM crm_documents
            WHERE customer_id = ?
            ORDER BY id ASC
        ");
        $q->execute([$customerId]);

        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $s = strtolower(trim((string)($r['status'] ?? 'pending')));
            $status = in_array($s, ['verified','approved'], true) ? 'Verified'
                    : ($s === 'rejected' ? 'Rejected' : 'Pending Verification');

            $documents[] = [
                'customer_id' => $customerId,
                'customer_name' => $customer['customer_name'] ?? 'Customer',
                'customer_code' => $customer['customer_code'] ?? '',
                'document_id' => $r['id'],
                'document_type' => $r['document_type'] ?? 'Application Document',
                'document_code' => '',
                'file_name' => $r['file_name'] ?? '',
                'file_path' => $r['file_path'] ?? '',
                'status' => $status,
                'status_class' => strtolower(str_replace(' ', '-', $status)),
                'submitted_at' => $r['uploaded_at'] ?? '',
                'rejection_reason' => $r['rejection_reason'] ?? '',
                'source' => 'Application'
            ];
        }
    } catch (Throwable $e) {}
}

/* Summary */
$totalSubmitted = count($documents);
$totalVerified = $totalPending = $totalRejected = 0;

foreach ($documents as $d) {
    if ($d['status'] === 'Verified') $totalVerified++;
    elseif ($d['status'] === 'Rejected') $totalRejected++;
    else $totalPending++;
}

agent_shell_open($pdo, $user_id, 'Documents', 'documents.php');
?>

<style>
.documents-page{width:100%}
.documents-header{margin-bottom:20px}
.documents-header h1{margin:0;color:#172033;font-size:27px;font-weight:800}
.documents-header p{margin:7px 0 0;color:#718096;font-size:13px}
.summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px}
.summary-card{padding:17px 18px;background:#fff;border:1px solid #e5e9f0;border-radius:11px}
.summary-card span{display:block;margin-bottom:7px;color:#718096;font-size:12px}
.summary-card strong{color:#172033;font-size:24px;font-weight:800}
.customer-card{margin-bottom:20px;background:#fff;border:1px solid #e5e9f0;border-radius:12px;overflow:hidden}
.customer-header{padding:17px 20px;border-bottom:1px solid #edf0f4}
.customer-header h2{margin:0;color:#172033;font-size:17px;font-weight:800}
.customer-meta{display:flex;gap:16px;margin-top:6px;color:#718096;font-size:12px}
.customer-meta strong{color:#344563}
.section-title{padding:17px 20px 10px;color:#172033;font-size:14px;font-weight:800}
.document-table-wrap{overflow-x:auto}
.document-table{width:100%;border-collapse:collapse}
.document-table th{padding:12px 16px;background:#f8fafc;border-bottom:1px solid #edf0f4;color:#68758b;font-size:11px;font-weight:800;text-align:left;text-transform:uppercase}
.document-table td{padding:14px 16px;border-bottom:1px solid #edf0f4;color:#344563;font-size:13px;vertical-align:middle}
.document-name{display:block;color:#172033;font-weight:700}
.document-code,.document-source{display:block;margin-top:4px;color:#94a3b8;font-size:10px}
.document-source{color:#64748b}
.status{display:inline-flex;padding:5px 10px;border-radius:999px;font-size:11px;font-weight:800;white-space:nowrap}
.status.verified{background:#dcfce7;color:#15803d}
.status.pending-verification{background:#fff7ed;color:#c2410c}
.status.rejected{background:#fee2e2;color:#b91c1c}
.status.submitted{background:#eef4ff;color:#315b9b}
.view-button{display:inline-flex;align-items:center;justify-content:center;padding:7px 12px;border:1px solid #d8dee8;border-radius:7px;background:#fff;color:#344563;text-decoration:none;font-size:12px;font-weight:700}
.view-button:hover{border-color:#16a34a;color:#15803d}
.reason{max-width:230px;margin-top:5px;color:#b91c1c;font-size:11px}
.empty-state{padding:45px 20px;color:#718096;font-size:13px;text-align:center}
.empty-state strong{display:block;margin-bottom:5px;color:#344563;font-size:14px}
@media(max-width:1000px){.summary-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:600px){.summary-grid{grid-template-columns:1fr}.customer-meta{flex-direction:column;gap:5px}}
</style>

<div class="documents-page">

    <div class="documents-header">
        <h1>Documents</h1>
        <p>View documents submitted by your assigned customers and their verification status.</p>
    </div>

    <div class="summary-grid">
        <div class="summary-card"><span>Documents Submitted</span><strong><?= $totalSubmitted ?></strong></div>
        <div class="summary-card"><span>Verified by Admin</span><strong><?= $totalVerified ?></strong></div>
        <div class="summary-card"><span>Pending Verification</span><strong><?= $totalPending ?></strong></div>
        <div class="summary-card"><span>Rejected</span><strong><?= $totalRejected ?></strong></div>
    </div>

    <?php if (!$customers): ?>

        <div class="customer-card">
            <div class="empty-state">
                <strong>No customers connected to this agent</strong>
                Customers assigned by Admin or linked through an application will appear here.
            </div>
        </div>

    <?php else: ?>

        <?php foreach ($customers as $customer): ?>

            <?php
            $customerId = (int)$customer['customer_id'];
            $customerDocs = array_values(array_filter(
                $documents,
                static fn($d) => (int)$d['customer_id'] === $customerId
            ));
            ?>

            <div class="customer-card">

                <div class="customer-header">
                    <h2><?= docEsc($customer['customer_name'] ?? 'Customer') ?></h2>

                    <div class="customer-meta">
                        <span>
                            Customer ID:
                            <strong><?= docEsc($customer['customer_code'] ?: '#'.$customerId) ?></strong>
                        </span>

                        <?php if (!empty($customer['customer_mobile'])): ?>
                            <span>
                                Mobile:
                                <strong><?= docEsc($customer['customer_mobile']) ?></strong>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="section-title">Submitted Documents</div>

                <div class="document-table-wrap">
                    <table class="document-table">
                        <thead>
                            <tr>
                                <th>Document</th>
                                <th>Submission</th>
                                <th>Admin Verification</th>
                                <th>Submitted On</th>
                                <th>Action</th>
                            </tr>
                        </thead>

                        <tbody>

                        <?php if (!$customerDocs): ?>

                            <tr>
                                <td colspan="5">
                                    <div class="empty-state">
                                        <strong>No documents found</strong>
                                        No onboarding, KYC, or application document has been submitted for this customer.
                                    </div>
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($customerDocs as $document): ?>

                                <tr>
                                    <td>
                                        <span class="document-name">
                                            <?= docEsc($document['document_type']) ?>
                                        </span>

                                        <?php if ($document['document_code'] !== ''): ?>
                                            <span class="document-code">
                                                <?= docEsc($document['document_code']) ?>
                                            </span>
                                        <?php endif; ?>

                                        <span class="document-source">
                                            <?= docEsc($document['source']) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <span class="status submitted">Submitted</span>
                                    </td>

                                    <td>
                                        <span class="status <?= docEsc($document['status_class']) ?>">
                                            <?= docEsc($document['status']) ?>
                                        </span>

                                        <?php if (
                                            $document['status'] === 'Rejected' &&
                                            $document['rejection_reason'] !== ''
                                        ): ?>
                                            <div class="reason">
                                                Reason: <?= docEsc($document['rejection_reason']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <td><?= docEsc($document['submitted_at'] ?: '—') ?></td>

                                    <td>
                                        <?php if ($document['file_path'] !== ''): ?>
                                            <a
                                                class="view-button"
                                                href="<?= docEsc($document['file_path']) ?>"
                                                target="_blank"
                                                rel="noopener"
                                            >View</a>
                                        <?php else: ?>
                                            <span style="color:#94a3b8;font-size:12px">Submitted</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                        </tbody>
                    </table>
                </div>

            </div>

        <?php endforeach; ?>

    <?php endif; ?>

</div>

<?php agent_shell_close(); ?>
