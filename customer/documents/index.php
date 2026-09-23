<?php
/**
 * Document vault. Every document the customer has given us, its version,
 * its review state, and what is still outstanding.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
require_once CUST_ROOT . '/portal/customer_shell.php';

$user = require_customer($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $code = post_str('doc_code', 50);
    $spec = CUSTOMER_DOCUMENTS[$code] ?? null;

    if (!$spec) {
        flash('error', 'Unknown document type.');
    } else {
        $res = store_document($pdo, (int)$user['id'], $code, $_FILES['file'] ?? [], $spec['stage']);
        flash($res['ok'] ? 'success' : 'error',
              $res['ok'] ? $spec['label'] . ' uploaded and queued for review.' : $res['error']);
    }
    redirect(CUST_BASE . '/documents/index.php');
}

$stmt = $pdo->prepare("SELECT * FROM customer_documents WHERE user_id = ? AND is_current = 1
                        ORDER BY FIELD(stage,'ekyc','profile','credit','products','support','other'), doc_label");
$stmt->execute([(int)$user['id']]);
$docs = $stmt->fetchAll();

$held = array_column($docs, 'doc_code');
$outstanding = [];
foreach (CUSTOMER_DOCUMENTS as $code => $spec) {
    if (!empty($spec['required']) && !in_array($code, $held, true)) {
        $outstanding[$code] = $spec;
    }
}

portal_shell_open($pdo, $user, 'documents', 'Documents');
?>
<?php if ($outstanding): ?>
<div class="card wide" style="margin-bottom:20px;">
    <h2 style="font-size:16px;font-weight:800;color:var(--ink);margin-bottom:12px;">Still needed</h2>
    <?php foreach ($outstanding as $code => $spec): ?>
        <form method="post" enctype="multipart/form-data"
              style="display:flex;gap:10px;align-items:center;margin-bottom:12px;flex-wrap:wrap;">
            <?= csrf_field() ?>
            <input type="hidden" name="doc_code" value="<?= e($code) ?>">
            <span style="min-width:200px;font-weight:600;font-size:14px;"><?= e($spec['label']) ?></span>
            <input type="file" name="file" required accept="<?= e(implode(',', $spec['mime'])) ?>"
                   style="max-width:280px;">
            <button class="btn" style="min-height:40px;font-size:13px;">Upload</button>
        </form>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card wide">
    <h2 style="font-size:16px;font-weight:800;color:var(--ink);margin-bottom:12px;">On file</h2>
    <table class="table">
        <thead><tr>
            <th scope="col">Document</th><th scope="col">Stage</th><th scope="col">Version</th>
            <th scope="col">Uploaded</th><th scope="col">Status</th>
        </tr></thead>
        <tbody>
        <?php if (!$docs): ?>
            <tr><td colspan="5" style="color:var(--muted);">Nothing uploaded yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($docs as $d):
            $pill = match ($d['status']) {
                'verified' => 'is-ok', 'rejected', 'expired' => 'is-bad', default => 'is-warn',
            }; ?>
            <tr>
                <td>
                    <a href="<?= CUST_BASE ?>/documents/serve.php?id=<?= (int)$d['id'] ?>" target="_blank" rel="noopener">
                        <?= e($d['doc_label']) ?>
                    </a>
                    <?php if (!empty($d['rejection_reason'])): ?>
                        <div style="color:var(--bad);font-size:12px;"><?= e($d['rejection_reason']) ?></div>
                    <?php endif; ?>
                </td>
                <td><?= e($d['stage']) ?></td>
                <td>v<?= (int)$d['version'] ?></td>
                <td><?= e(date('j M Y', strtotime($d['created_at']))) ?></td>
                <td><span class="pill <?= $pill ?>"><?= e($d['status']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php portal_shell_close(); ?>
