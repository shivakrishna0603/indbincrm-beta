<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../portal/shell.php';
require_once __DIR__ . '/../portal/merchant_shell.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}
$user_id = (int)$_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT role, account_status FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (($user['role'] ?? '') !== 'merchant') {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}
$active = (($user['account_status'] ?? '') === 'active');

$q        = trim((string)($_GET['q'] ?? ''));
$category = (string)($_GET['cat'] ?? '');

$where  = "is_published = 1";
$params = [];
if ($q !== '') {
    $where .= " AND (title LIKE ? OR summary LIKE ? OR content LIKE ?)";
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    array_push($params, $like, $like, $like);
}
if ($category !== '') {
    $where .= " AND category = ?";
    $params[] = $category;
}

$catStmt = $pdo->query("SELECT category, COUNT(*) AS cnt FROM support_articles WHERE is_published = 1 GROUP BY category ORDER BY MIN(sort_order), category");
$categories = $catStmt->fetchAll();

$artStmt = $pdo->prepare("SELECT * FROM support_articles WHERE {$where} ORDER BY sort_order, id");
$artStmt->execute($params);
$articles = $artStmt->fetchAll();

$color = '#f97316';
$lightColor = '#fff1e5';

if ($active) {
    render_merchant_shell_open($pdo, $user_id, 'support', 'Knowledge Base');
} else {
    render_shell_open($pdo, $user_id, '', 'Knowledge Base');
}
?>

<style>
    .kb-search { display: flex; gap: 10px; margin-bottom: 14px; }
    .kb-search input[type="search"] { flex: 1; padding: 11px 14px; border: 1px solid var(--field); border-radius: 10px; font-size: 14px; font-family: inherit; }
    .kb-search button { padding: 11px 20px; border: none; border-radius: 10px; background: var(--brand); color: #fff; font-weight: 700; font-size: 14px; cursor: pointer; }

    .cat-chips { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px; }
    .cat-chip { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: 999px; border: 1px solid var(--line); background: var(--surface); color: var(--muted); font-size: 12.5px; font-weight: 700; text-decoration: none; }
    .cat-chip:hover { border-color: var(--brand); color: var(--brand); }
    .cat-chip.active { background: var(--brand); border-color: var(--brand); color: #fff; }
    .cat-chip .ct { min-width: 18px; height: 18px; padding: 0 5px; border-radius: 999px; background: var(--canvas); color: var(--muted); font-size: 11px; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; }
    .cat-chip.active .ct { background: rgba(255,255,255,.25); color: #fff; }

    .kb-card { background: var(--surface); border: 1px solid var(--line); border-radius: 12px; margin-bottom: 10px; overflow: hidden; }
    .kb-card summary { list-style: none; cursor: pointer; padding: 16px 20px; display: flex; gap: 14px; align-items: flex-start; }
    .kb-card summary::-webkit-details-marker { display: none; }
    .kb-card summary:hover { background: var(--canvas); }
    .kb-card .kb-q { flex: 1; min-width: 0; }
    .kb-card .kb-q .t { font-size: 14.5px; font-weight: 800; color: var(--ink); }
    .kb-card .kb-q .s { font-size: 12.5px; color: var(--muted); margin-top: 3px; }
    .kb-card .kb-cat { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .5px; color: var(--brand-deep); background: var(--brand-soft); padding: 4px 10px; border-radius: 999px; white-space: nowrap; }
    .kb-card .kb-chev { color: var(--faint); transition: transform .15s ease; margin-top: 3px; }
    .kb-card[open] .kb-chev { transform: rotate(180deg); }
    .kb-card .kb-body { padding: 4px 22px 18px; }
    .kb-card .kb-body p { font-size: 13.5px; color: var(--body); line-height: 1.65; white-space: pre-line; margin: 0; }

    .kb-empty { background: var(--surface); border: 1px dashed var(--line); border-radius: 12px; padding: 40px 30px; text-align: center; color: var(--muted); }
    .kb-ticket { display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; margin-top: 24px; background: #fff1e5; border: 1px solid #fed7aa; border-radius: 12px; padding: 16px 22px; }
    .kb-ticket .t { font-size: 14px; font-weight: 800; color: var(--ink); }
    .kb-ticket .s { font-size: 12.5px; color: var(--muted); margin-top: 2px; }
    .kb-ticket a { margin-left: auto; }
</style>

<div class="page-header-row">
    <div class="page-header-left">
        <div class="header-icon"><i class="fa-solid fa-book-open"></i></div>
        <div>
            <h1>Knowledge Base</h1>
            <p>Answers to the questions merchants ask most — onboarding, payments, settlements and more.</p>
        </div>
    </div>
</div>

<form class="kb-search" method="GET" action="knowledge_base.php">
    <?php if ($category !== ''): ?><input type="hidden" name="cat" value="<?= htmlspecialchars($category) ?>"><?php endif; ?>
    <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search articles, e.g. settlement, KYC, refund, credit limit">
    <button type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
</form>

<div class="cat-chips">
    <a class="cat-chip <?= $category === '' ? 'active' : '' ?>" href="knowledge_base.php<?= $q !== '' ? '?q=' . urlencode($q) : '' ?>">
        All <span class="ct"><?= array_sum(array_column($categories, 'cnt')) ?></span>
    </a>
    <?php foreach ($categories as $c): $catLabel = ucwords(str_replace('_', ' ', $c['category'])); ?>
        <a class="cat-chip <?= $category === $c['category'] ? 'active' : '' ?>" href="knowledge_base.php?cat=<?= urlencode($c['category']) ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>">
            <?= htmlspecialchars($catLabel) ?> <span class="ct"><?= (int)$c['cnt'] ?></span>
        </a>
    <?php endforeach; ?>
</div>

<?php if (empty($articles)): ?>
    <div class="kb-empty">
        <?= $q !== '' ? 'No articles match "' . htmlspecialchars($q) . '".' : 'No articles here yet.' ?>
        <?= $q !== '' ? ' Try a different search or browse all categories.' : '' ?>
    </div>
<?php else: ?>
    <?php foreach ($articles as $a): ?>
        <details class="kb-card" <?= $q !== '' ? 'open' : '' ?>>
            <summary>
                <span class="kb-cat"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $a['category']))) ?></span>
                <span class="kb-q">
                    <span class="t"><?= htmlspecialchars($a['title']) ?></span>
                    <?php if ($a['summary']): ?><span class="s"><?= htmlspecialchars($a['summary']) ?></span><?php endif; ?>
                </span>
                <i class="fa-solid fa-chevron-down kb-chev"></i>
            </summary>
            <div class="kb-body"><p><?= nl2br(htmlspecialchars($a['content'])) ?></p></div>
        </details>
    <?php endforeach; ?>
<?php endif; ?>

<div class="kb-ticket">
    <div>
        <div class="t">Still stuck? Talk to us.</div>
        <div class="s">Raise a support ticket and our team will get back to you.</div>
    </div>
    <a class="btn-primary-sm" href="index.php"><i class="fa-solid fa-headset"></i> Raise a ticket</a>
</div>

<?php render_shell_close(); ?>