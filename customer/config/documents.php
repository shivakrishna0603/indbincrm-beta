<?php
/**
 * Document catalogue for the customer module.
 *
 * The merchant module collects PAN, Aadhaar, GST certificate, shop photo,
 * address proof and a cancelled cheque. A customer is a different subject:
 * there is no business entity, so GST and the shop photo drop out, and
 * face-to-document matching, income evidence and consent artifacts come in.
 *
 * 'required' is the baseline. 'conditional' documents are pulled in by the
 * rule in the 'when' field, which credit/scoring.php and products/activate.php
 * evaluate at runtime.
 */

declare(strict_types=1);

const CUSTOMER_DOCUMENTS = [

    // ---------------- Step 2: eKYC ----------------
    'AADHAAR_FRONT' => [
        'label' => 'Aadhaar front',
        'stage' => 'ekyc', 'required' => true,
        'mime'  => ['image/jpeg', 'image/png', 'application/pdf'],
        'note'  => 'Skipped when the customer completes Aadhaar OTP or DigiLocker eKYC.',
    ],
    'AADHAAR_BACK' => [
        'label' => 'Aadhaar back',
        'stage' => 'ekyc', 'required' => true,
        'mime'  => ['image/jpeg', 'image/png', 'application/pdf'],
        'note'  => 'Carries the address used for the address-match check.',
    ],
    'PAN_CARD' => [
        'label' => 'PAN card',
        'stage' => 'ekyc', 'required' => true,
        'mime'  => ['image/jpeg', 'image/png', 'application/pdf'],
        'note'  => 'Needed for any credit-linked product and for CKYC upload.',
    ],
    'LIVE_SELFIE' => [
        'label' => 'Live selfie',
        'stage' => 'ekyc', 'required' => true,
        'mime'  => ['image/jpeg', 'image/png'],
        'note'  => 'NEW vs merchant flow. Captured in-session, matched against the ID photo. '
                 . 'The merchant equivalent is a shop photo, which proves premises, not identity.',
    ],
    'VIDEO_KYC_RECORDING' => [
        'label' => 'Video KYC recording',
        'stage' => 'ekyc', 'required' => false, 'conditional' => true,
        'when'  => 'kyc_mode = video_kyc',
        'mime'  => ['video/mp4', 'video/webm'],
        'note'  => 'NEW. Stored with agent ID, geotag and timestamp.',
    ],
    'EKYC_CONSENT_RECEIPT' => [
        'label' => 'eKYC consent receipt',
        'stage' => 'ekyc', 'required' => true,
        'mime'  => ['application/pdf', 'application/json'],
        'note'  => 'NEW. Generated, not uploaded. Records what the customer authorised, '
                 . 'under which policy version, from which IP.',
    ],

    // ---------------- Step 3: Profile ----------------
    'ADDRESS_PROOF' => [
        'label' => 'Current address proof',
        'stage' => 'profile', 'required' => false, 'conditional' => true,
        'when'  => 'address_same_as_id = 0',
        'mime'  => ['image/jpeg', 'image/png', 'application/pdf'],
        'note'  => 'Utility bill, rent agreement or passport. Only when the current '
                 . 'address differs from the Aadhaar address.',
    ],
    'BANK_PROOF' => [
        'label' => 'Bank proof',
        'stage' => 'profile', 'required' => true,
        'mime'  => ['image/jpeg', 'image/png', 'application/pdf'],
        'note'  => 'Cancelled cheque, passbook first page or a bank statement header. '
                 . 'Same role as the merchant cancelled cheque.',
    ],
    'ENACH_MANDATE' => [
        'label' => 'e-NACH / UPI autopay mandate',
        'stage' => 'profile', 'required' => false, 'conditional' => true,
        'when'  => 'any repayment product is activated',
        'mime'  => ['application/pdf'],
        'note'  => 'NEW. The signed mandate plus its UMRN. Merchants sign a commercial '
                 . 'agreement; customers sign a debit authority.',
    ],

    // ---------------- Step 4: Credit ----------------
    'INCOME_PROOF' => [
        'label' => 'Income proof',
        'stage' => 'credit', 'required' => false, 'conditional' => true,
        'when'  => 'requested limit > auto-approval threshold',
        'mime'  => ['application/pdf', 'image/jpeg', 'image/png'],
        'note'  => 'NEW. Three salary slips, Form 16, or the last ITR. Merchants prove '
                 . 'turnover instead; customers prove personal income.',
    ],
    'BANK_STATEMENT_6M' => [
        'label' => 'Six-month bank statement',
        'stage' => 'credit', 'required' => false, 'conditional' => true,
        'when'  => 'account aggregator pull not available',
        'mime'  => ['application/pdf'],
        'note'  => 'NEW. Fallback when the customer does not consent to an AA data pull.',
    ],
    'EMPLOYMENT_PROOF' => [
        'label' => 'Employment proof',
        'stage' => 'credit', 'required' => false, 'conditional' => true,
        'when'  => 'employment_type = salaried AND product = BNPL/loan',
        'mime'  => ['application/pdf', 'image/jpeg', 'image/png'],
        'note'  => 'NEW. Offer letter or employee ID.',
    ],
    'BUREAU_CONSENT' => [
        'label' => 'Credit bureau pull consent',
        'stage' => 'credit', 'required' => true,
        'mime'  => ['application/pdf', 'application/json'],
        'note'  => 'NEW and non-optional. A bureau enquiry without a recorded, '
                 . 'timestamped consent is not defensible.',
    ],

    // ---------------- Step 5: Products ----------------
    'PRODUCT_KFS' => [
        'label' => 'Key fact statement acknowledgement',
        'stage' => 'products', 'required' => true,
        'mime'  => ['application/pdf'],
        'note'  => 'NEW. One per credit product: rate, tenure, fees, total cost. '
                 . 'Stored per activation, not once per customer.',
    ],
    'NOMINEE_DECLARATION' => [
        'label' => 'Nominee declaration',
        'stage' => 'products', 'required' => false, 'conditional' => true,
        'when'  => 'category = insurance',
        'mime'  => ['application/pdf'],
        'note'  => 'NEW. Has no merchant equivalent.',
    ],
    'GUARDIAN_CONSENT' => [
        'label' => 'Guardian consent',
        'stage' => 'products', 'required' => false, 'conditional' => true,
        'when'  => 'age < 18',
        'mime'  => ['application/pdf'],
        'note'  => 'NEW. Only relevant if minors are ever admitted to the platform; '
                 . 'otherwise keep the age gate at registration and drop this.',
    ],
];

/** Documents for one stage, in display order. */
function documents_for_stage(string $stage): array
{
    return array_filter(
        CUSTOMER_DOCUMENTS,
        static fn(array $d): bool => $d['stage'] === $stage
    );
}

/** Mandatory doc codes for a stage, used by the step completion check. */
function required_docs_for_stage(string $stage): array
{
    $out = [];
    foreach (CUSTOMER_DOCUMENTS as $code => $d) {
        if ($d['stage'] === $stage && !empty($d['required'])) {
            $out[] = $code;
        }
    }
    return $out;
}
