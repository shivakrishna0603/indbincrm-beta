-- =====================================================================
-- INDBIN CRM : Missing Tables Patch
-- Run this in phpMyAdmin to fix Training & Support errors
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- training_content table (used by merchant/training/topic.php)
CREATE TABLE IF NOT EXISTS training_content (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    topic_key   VARCHAR(60) NOT NULL,
    page_number TINYINT UNSIGNED NOT NULL,
    heading     VARCHAR(150) NOT NULL,
    body        TEXT NOT NULL,
    tip         VARCHAR(255) NULL,
    UNIQUE KEY uq_topic_page (topic_key, page_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO training_content (topic_key, page_number, heading, body, tip) VALUES

('accept_payments', 1, 'How customers pay you',
'Every INDBIN merchant account comes with a UPI-linked QR code. When a customer scans it with any UPI app, the payment goes straight into your linked settlement account.\n\nThere is no card machine to buy and no setup fee. The QR code is generated automatically the moment your account is activated, and it stays the same unless you request a change from Support.',
'Print your QR code and keep it visible at your counter — most repeat customers will look for it instead of asking how to pay.'),

('accept_payments', 2, 'Sharing and tracking your QR code',
'You can find your QR code anytime under Manage Products & Services on your dashboard, where it can be downloaded as an image or reprinted.\n\nEvery payment you receive shows up in Orders & Transactions within a few seconds. If a payment does not appear within a minute, ask the customer to check their UPI app for a failed or pending status before trying again.',
'A payment showing as "pending" for more than 5 minutes should be treated as failed — ask the customer to retry rather than waiting.'),

('credit_repayments', 1, 'Understanding your credit limit',
'Your credit limit is set after your business verification is reviewed, based on your turnover, years in business, and the documents you submitted.\n\nThis limit determines how much value you can extend to customers through INDBIN pay-later options at your shop. It is not a loan to you personally.',
'Your limit is reviewed periodically. Keeping your business documents and turnover figures up to date helps it grow rather than shrink.'),

('credit_repayments', 2, 'How repayments actually work',
'When a customer uses pay-later credit at your shop, INDBIN settles the full amount to you upfront — you are paid immediately. The customer then repays INDBIN directly over their agreed schedule.\n\nYou never need to chase a customer for repayment yourself.',
'You can see which of your sales used pay-later credit under Repayment Tracking.'),

('settlements', 1, 'What a settlement cycle means',
'A settlement is the transfer of money from payments you have received into your actual bank account. You chose your settlement frequency during Account Setup, and you can change it anytime.\n\nMost merchants start with weekly settlements to get a feel for the flow, then switch to daily once their transaction volume grows.',
'Daily settlement has no extra fee on INDBIN — there is no downside to switching to it once you are comfortable.'),

('settlements', 2, 'Tracking your payouts',
'Every settlement is logged under Repayment Tracking with the exact amount transferred, the bank account it went to, and the date.\n\nIf you ever change your bank account details, existing pending settlements will still go to the account that was active at the time the payment was received.',
'Export your settlement history monthly if you reconcile your own books manually — it saves time during tax season.'),

('dashboard', 1, 'Finding your way around',
'Your dashboard sidebar is organized around the actual flow of running your business: Leads come in, you send Quotes, they become Applications, and successful ones become Orders.\n\nThe home screen always shows your most recent activity across all of these.',
'Bookmark the dashboard home page — it is built to answer "what happened since I last checked" faster than any individual sub-page.'),

('dashboard', 2, 'Reports that matter',
'Analytics & Business Reports shows your transaction volume, top products, and customer repeat-rate over whatever time range you pick. These numbers update in real time.\n\nIf a number looks wrong, check the date range filter first. The most common cause is an accidentally narrow date range, not a data error.',
'Compare this week to the same week last month, not just to last week — it filters out normal day-to-day noise and shows real trends.'),

('services', 1, 'What is enabled on your account',
'Manage Products & Services shows every product INDBIN offers and which ones are currently active for your account. Some, like basic UPI acceptance, are on by default.\n\nAn enabled service does not cost anything extra just for having it turned on — costs only apply when it is actually used.',
'Check this page after any major update — INDBIN periodically enables new no-cost services by default.'),

('services', 2, 'Requesting something new',
'If a service you want is not active yet, use the Apply or request button on the Services page. Most approvals happen automatically based on your existing verification.\n\nIf a request is declined, the reason is always shown, and you can usually reapply once the underlying issue is resolved.',
'Services tied to your credit limit may be capped by your current risk category — improving your repayment history is the most reliable way to raise that ceiling.');

-- training_progress table (tracks which topics each merchant completed)
CREATE TABLE IF NOT EXISTS training_progress (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    topic_key  VARCHAR(60) NOT NULL,
    completed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_topic (user_id, topic_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
