<?php
/**
 * Merchant training lessons.
 *
 * Single source of truth for the training guide content. Each lesson is
 * rendered to a PDF on the fly by pdf.php and shown inside lesson.php.
 *
 * Structure:
 *   title    page heading
 *   subtitle one-line summary under the title
 *   sections list of blocks. Every block renders whatever keys it has:
 *     body      paragraph text. "\n\n" separates paragraphs.
 *     bullets   bullet list, each item a string
 */

declare(strict_types=1);

function merchant_lessons(): array
{
    return [
        'accept_payments' => [
            'title'    => 'Accepting Payments',
            'subtitle' => 'How to take UPI, card and QR payments and read every transaction.',
            'sections' => [
                [
                    'body' => 'Every activated merchant account can collect payments the moment onboarding finishes. '
                             . 'Customers do not need anything extra - they pay with the UPI app already on their phone.',
                ],
                [
                    'heading' => 'Collecting a payment',
                    'body'    => 'Open your merchant dashboard and use the Collect Payment action. Enter the amount, '
                               . 'the customer scans the QR code or approves the request on their phone, and the '
                               . 'payment is confirmed instantly.',
                ],
                [
                    'heading' => 'The ways customers can pay',
                    'bullets' => [
                        'QR scan: the customer scans your dynamic QR with any UPI app.',
                        'UPI collect request: you send a collect request to the customer\'s UPI ID.',
                        'Card on devices: swipe, tap or insert for customers paying by card.',
                        'Pay Later and instalments: customers with an approved line can split bigger bills.',
                    ],
                ],
                [
                    'heading' => 'After the payment',
                    'body'    => 'You see the transaction in your dashboard immediately, and the customer gets a '
                               . 'receipt. The money lands in your pending settlement balance and is paid out on '
                               . 'the settlement cycle. Fees, if any, are deducted from the amount before payout '
                               . 'and are itemised in Reports.',
                ],
            ],
        ],

        'credit_repayments' => [
            'title'    => 'Credit & Repayments',
            'subtitle' => 'Your credit limit, borrowing as a merchant, and repayment discipline.',
            'sections' => [
                [
                    'body' => 'Your merchant account carries a credit limit set during Credit & Risk Assessment. '
                             . 'Understanding it, and repaying on time, protects the amount of credit you can access.',
                ],
                [
                    'heading' => 'Your credit limit',
                    'body'    => 'During assessment you are given a risk score. The score decides your risk category '
                               . 'and your credit limit - the most you can borrow through merchant products. The '
                               . 'limit is re-assessed as your business data grows, so consistent healthy volume '
                               . 'can raise it over time.',
                ],
                [
                    'heading' => 'Borrowing as a merchant',
                    'body'    => 'Use the Apply for Loan / Insurance screen in your merchant portal. Choose the type, '
                               . 'enter the amount, purpose and repayment tenure, and submit. The underwriting team '
                               . 'reviews it and the status moves from submitted to under review, then to approved '
                               . 'or rejected. An approved loan is disbursed as per the schedule and your repayable '
                               . 'amount is generated in Repayment Tracking.',
                ],
                [
                    'heading' => 'Staying on schedule',
                    'bullets' => [
                        'Instalments are collected from your settlement balance on the due date.',
                        'Check Repayment Tracking for upcoming and settled instalments.',
                        'A missed instalment moves to overdue and hurts your credit standing.',
                        'If you will miss a due date, raise a ticket before it so we can work out options.',
                    ],
                ],
            ],
        ],

        'settlements' => [
            'title'    => 'Settlements & Payouts',
            'subtitle' => 'When and how the money you collect reaches your bank account.',
            'sections' => [
                [
                    'body' => 'Settlements run on a T+1 cycle - payments collected today are transferred to your '
                             . 'registered bank account by the end of the next working day.',
                ],
                [
                    'heading' => 'The settlement flow',
                    'body'    => 'Each transaction first appears as a pending amount, then settles to your bank '
                               . 'account on the next cycle. Fees are deducted before payout, so the amount that '
                               . 'reaches you is the net amount after charges.',
                ],
                [
                    'heading' => 'Where to check',
                    'bullets' => [
                        'Transactions: every payment, refund and failed attempt, with status.',
                        'Reports: daily and monthly settlement summaries with a full fee breakdown.',
                        'Settlement account: the registered account in Account Setup is the only payout destination.',
                    ],
                ],
                [
                    'heading' => 'If a payout is delayed',
                    'body'    => 'First confirm your bank account number and IFSC are correct in Account Setup. If '
                               . 'everything is correct and a settlement is still delayed beyond the cycle, raise a '
                               . 'ticket and quote the period so the team can trace it.',
                ],
            ],
        ],

        'dashboard' => [
            'title'    => 'Merchant Dashboard',
            'subtitle' => 'A tour of the screens you will use every day.',
            'sections' => [
                [
                    'body' => 'The dashboard is your home screen: a live read of how the business is doing today, '
                             . 'with balances, sales and the actions you need most. The menu on the left opens every '
                             . 'section of the portal.',
                ],
                [
                    'heading' => 'Key sections',
                    'bullets' => [
                        'Dashboard: sales today, pending settlements, open tickets, credit limit.',
                        'Collect Payment: take a payment from a customer in seconds.',
                        'Transactions: every payment, refund and failed attempt.',
                        'Repayment Tracking: upcoming and settled instalments.',
                        'Reports: business reports and statements you can download.',
                        'Support: raise and track support tickets and browse help articles.',
                    ],
                ],
                [
                    'heading' => 'Reading your reports',
                    'body'    => 'Reports give daily and period summaries - volume, value, fees and settlements. '
                               . 'Numbers in the portal are a live read from your transactions, so what you see '
                               . 'always matches your settlement statements.',
                ],
            ],
        ],

        'services' => [
            'title'    => 'Enabled Services',
            'subtitle' => 'The products activated with your account and how each one works.',
            'sections' => [
                [
                    'body' => 'Your account is activated with a starter set of services. Manage those, and request '
                             . 'more, from Manage Products / Services in the merchant portal.',
                ],
                [
                    'heading' => 'Your starter services',
                    'bullets' => [
                        'UPI: accept UPI payments by QR and collect requests.',
                        'QR to Cash: let customers scan and pay from any UPI app.',
                        'Insurance: refer and sell cover products to customers.',
                        'Offers: run seasonal offers that drive footfall.',
                        'Loyalty: reward repeat customers with points and referrals.',
                    ],
                ],
                [
                    'heading' => 'Adding and managing services',
                    'body'    => 'Some services are available instantly; others such as loans need a quick approval '
                               . 'before you can use them. Head to Manage Products / Services to see what is active, '
                               . 'what is pending approval, and what you can request next.',
                ],
            ],
        ],
    ];
}

/** @return array|null */
function merchant_lesson(string $key)
{
    $key = (string)$key;
    return merchant_lessons()[$key] ?? null;
}