<?php
/**
 * Shared merchant support-ticket logic.
 *
 * Used by the post-activation dashboard page (merchant/dashboard/support.php)
 * and the onboarding support page (merchant/support/index.php), so a
 * merchant can raise and track tickets both before and after activation.
 * Only the business logic lives here; each caller renders its own shell.
 */

declare(strict_types=1);

function tkt_handle_submit(PDO $pdo, int $user_id): array
{
    $errors = [];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return ['errors' => $errors, 'success' => ''];
    }

    $subject     = trim($_POST['subject'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category    = strtolower(trim($_POST['category'] ?? 'general'));
    $priority    = strtolower(trim($_POST['priority'] ?? 'normal'));

    $validCategories = ['general', 'payments', 'applications', 'kyc', 'technical', 'other'];
    $validPriorities = ['low', 'normal', 'high', 'urgent'];

    if (!$subject) $errors[] = 'Enter a subject.';
    if (!$description) $errors[] = 'Describe your issue.';
    if (!in_array($category, $validCategories, true)) $category = 'general';
    if (!in_array($priority, $validPriorities, true)) $priority = 'normal';

    if (empty($errors)) {
        // support_tickets.ticket_code is NOT NULL with no default and user_id
        // is the ticket owner; the merchant_id column is the merchant context.
        $ticketCode = 'TKT' . date('ymd') . strtoupper(bin2hex(random_bytes(3)));
        $pdo->prepare(
            "INSERT INTO support_tickets
                (ticket_code, user_id, merchant_id, category, subject, description, priority)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([$ticketCode, $user_id, $user_id, $category, $subject, $description, $priority]);
        return ['errors' => $errors, 'success' => "Ticket {$ticketCode} submitted. Our support team will respond shortly."];
    }

    return ['errors' => $errors, 'success' => ''];
}

function merchant_ticket_tabs(): array
{
    return ['all' => 'All', 'open' => 'Open', 'in_progress' => 'In Progress',
            'waiting_customer' => 'Waiting on You', 'resolved' => 'Resolved', 'closed' => 'Closed'];
}

function merchant_ticket_counts(PDO $pdo, int $user_id): array
{
    $counts    = ['all' => 0];
    $stmt = $pdo->prepare("SELECT status, COUNT(*) AS cnt FROM support_tickets WHERE merchant_id = ? GROUP BY status");
    $stmt->execute([$user_id]);
    foreach ($stmt->fetchAll() as $row) {
        $counts[$row['status']] = (int)$row['cnt'];
        $counts['all'] += (int)$row['cnt'];
    }
    return $counts;
}

function merchant_ticket_list(PDO $pdo, int $user_id, string $activeTab): array
{
    $sql = "SELECT * FROM support_tickets WHERE merchant_id = ?" . ($activeTab === 'all' ? '' : " AND status = ?") . "
            ORDER BY created_at DESC";
    $params = [$user_id];
    if ($activeTab !== 'all') $params[] = $activeTab;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function merchant_ticket_displays(): array
{
    return [
        'status'   => ['open' => '#2563eb', 'in_progress' => '#b45309', 'waiting_customer' => '#d97706',
                       'resolved' => '#059669', 'closed' => '#64748b'],
        'priority' => ['low' => '#64748b', 'normal' => '#b45309', 'high' => '#dc2626', 'urgent' => '#7c3aed'],
        'category' => ['general' => 'General', 'payments' => 'Payments & Settlements',
                       'applications' => 'Applications & Disbursal', 'kyc' => 'KYC & Documents',
                       'technical' => 'Technical', 'other' => 'Other'],
    ];
}