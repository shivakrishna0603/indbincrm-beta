# INDBIN CRM: Customer module

Built to the customer column of the flow chart, laid out the same way as the
merchant module so the two are navigable by the same map.

---

## 1. Directory structure

```
indbin/
├── index.php                     home + login + register (existing)
├── register.php                  (existing)
├── db.php                        REPLACED, see section 3
├── logout.php                    (existing)
│
└── customer/
    ├── index.php                 entry point: routes to the right step or the dashboard
    ├── README.md
    │
    ├── config/
    │   ├── bootstrap.php         session hardening, PDO, includes. Every file starts here
    │   ├── steps.php             the 7 steps, in one place
    │   ├── documents.php         document catalogue: what is collected, at which step
    │   └── schema.sql            full DDL. Run once, not on every page load
    │
    ├── includes/
    │   ├── helpers.php           escaping, CSRF, uploads, OTP, PII encryption, audit, loyalty
    │   └── guard.php             role check, step gating, customer code issue
    │
    ├── assets/
    │   └── customer.css          the CSS that was pasted into three files
    │
    ├── portal/
    │   ├── shell.php             onboarding shell, 7-step stepper  (merchant: portal/shell.php)
    │   └── customer_shell.php    dashboard shell                   (merchant: merchant_shell.php)
    │
    ├── registration/             STEP 1  Registration + mobile OTP
    │   └── index.php
    │
    ├── ekyc/                     STEP 2  eKYC verification         (merchant: ekyc/)
    │   ├── index.php
    │   ├── upload.php
    │   ├── status.php
    │   ├── admin_review.php
    │   └── uploads/              .htaccess denies direct HTTP access
    │
    ├── profile/                  STEP 3  Profile + payment linking (merchant: business/)
    │   ├── index.php
    │   ├── upload.php
    │   ├── status.php
    │   ├── admin_review.php
    │   └── uploads/
    │
    ├── credit/                   STEP 4  Credit evaluation         (merchant: credit/)
    │   ├── index.php
    │   ├── scoring.php           the rules engine, separated from the page
    │   ├── status.php
    │   ├── admin_override.php
    │   └── uploads/              income proof, bank statements
    │
    ├── products/                 STEP 5  Product activation        (merchant: account_setup/)
    │   ├── index.php
    │   ├── activate.php
    │   ├── status.php
    │   └── admin_review.php
    │
    ├── loyalty/                  STEP 6  Loyalty enrolment         (merchant: training/)
    │   ├── index.php
    │   ├── enroll.php
    │   └── status.php
    │
    ├── onboarding_complete/      STEP 7
    │   └── index.php
    │
    ├── consents/                 cross-cutting: consent ledger and withdrawal
    │   ├── index.php
    │   └── status.php
    │
    ├── documents/                document vault
    │   ├── index.php
    │   ├── upload.php
    │   ├── serve.php             the ONLY route to an uploaded file. Checks + audits
    │   ├── admin_review.php
    │   └── uploads/
    │
    ├── applications/
    │   └── admin_review.php      (merchant: applications/admin_review.php)
    │
    ├── support/
    │   ├── index.php
    │   └── admin_review.php      (merchant: support/admin_review.php)
    │
    └── dashboard/                post-onboarding portal
        ├── index.php             overview
        ├── profile.php
        ├── credit.php
        ├── applications.php
        ├── repayments.php
        ├── statements.php
        ├── documents.php
        ├── mandates.php
        ├── offers.php
        ├── loyalty.php
        ├── referrals.php
        ├── notifications.php
        └── support.php
```

### How the customer steps map to the merchant ones

| # | Merchant | Customer | Why it differs |
|---|---|---|---|
| 1 | Registration | Registration | Customer verifies a mobile number; merchant verifies shop details |
| 2 | eKYC | eKYC | Customer adds liveness and face match; merchant adds GST |
| 3 | Business verification | Profile creation | No business entity. Address, preferences, payment instrument |
| 4 | Credit and risk | Credit evaluation | Conditional for a customer, not always run |
| 5 | Agreement and terms | folded into `consents/` | A customer accepts terms per product, not one master agreement |
| 6 | Account setup | Product activation | Customer picks products; merchant sets settlement and payout |
| 7 | Product enablement | Loyalty enrolment | Customer-side retention, no merchant equivalent |
| 8 | Training and support | `support/` only | A customer needs no product training |
| 9 | Onboarding complete | Onboarding complete | Same |

The merchant flow has nine steps, the customer flow seven, so one directory
per step does not line up one to one. The mapping above is the intended
correspondence.

---

## 2. Documents to add to the customer module

The merchant module collects PAN, Aadhaar, GST certificate, shop photo,
address proof and a cancelled cheque. Customers are a different subject:
there is no business entity, so GST and the shop photo drop out, and identity
matching, personal income evidence and consent artifacts come in.

`config/documents.php` is the machine-readable version. Summary:

### Required, no merchant equivalent

| Document | Step | Why |
|---|---|---|
| Live selfie | 2 | Proves the person holding the ID is the person applying. The merchant shop photo proves premises, not identity |
| eKYC consent receipt | 2 | Generated, not uploaded. Records what was authorised, under which policy version, from which IP |
| Bureau pull consent | 4 | A credit enquiry without a timestamped consent record is not defensible |
| Key fact statement acknowledgement | 5 | One per credit product: rate, tenure, fees, total cost. Per activation, not once per customer |

### Conditional, no merchant equivalent

| Document | Triggered when |
|---|---|
| Video KYC recording | `kyc_mode = video_kyc`. Stored with agent ID, geotag, timestamp |
| Income proof | Requested limit above the auto-approval ceiling. Salary slips, Form 16 or ITR |
| Six-month bank statement | Customer declines an account aggregator pull |
| Employment proof | Salaried applicant on a BNPL or loan product |
| e-NACH / UPI autopay mandate | Any repayment product activated. Store the signed mandate and its UMRN |
| Nominee declaration | Insurance products |

### Carried over from the merchant set, narrowed

| Document | Change |
|---|---|
| Aadhaar front and back | Same, but skipped entirely when Aadhaar OTP or DigiLocker eKYC succeeds |
| PAN card | Same |
| Address proof | Conditional now, not always. Only when the current address differs from the Aadhaar address |
| Bank proof | Same role as the merchant cancelled cheque. Cheque, passbook page or statement header |

### Dropped

GST certificate and shop photo. Neither has meaning for an individual.

---

## 3. Review of the uploaded files

Ordered by how much damage each one can do.

### Serious

**`ekyc/uploads/` and `business/uploads/` are served over HTTP.** The merchant
tree has 34 ID images and 23 sets of four business documents sitting in
web-reachable folders under predictable names. Anyone who guesses a filename
reads someone else's Aadhaar scan. Fix: the `.htaccess` in each `uploads/`
folder here, plus `documents/serve.php`, which checks the session and writes
an access record before streaming. Copy both into the merchant module.

**No CSRF token on any form.** `register.php`, `onboarding.php` and
`dashboard.php` all accept POSTs with no origin check, so a page on any other
site can submit a KYC change, add a bank mandate or raise an application on
behalf of a signed-in customer. Fix: `csrf_field()` in every form,
`csrf_verify()` at the top of every POST handler.

**`dashboard.php` marks an instalment paid on request.** The `pay_repayment`
branch runs `UPDATE repayments SET status = 'Paid'` on a POSTed ID with no
payment step in between. A crafted POST clears any instalment. Fix: the
version in `dashboard/repayments.php` initiates a gateway handoff and leaves
settlement to the callback.

**The OTP is client side.** `onboarding.php` compares the entered code against
the literal `441704` in JavaScript, and the form submits whether or not the
check passed. The mobile number is never actually verified. Meanwhile
`index.php` logs users in with `WHERE (email = ? OR mobile = ?)` against a
`mobile` column that registration never populates. Fix: the
`otp_verifications` table plus `otp_issue()` and `otp_verify()`, used by
`registration/index.php`.

**`db.php` prints the PDO message to the browser.**
`die("Connection failed: " . $e->getMessage())` leaks the host and database
name on any connection failure. Fix: the replacement `db.php` at the project
root logs and returns a generic 503.

**No role check anywhere in the customer flow.** `onboarding.php` and
`dashboard.php` only test `isset($_SESSION['user_id'])`. A signed-in merchant
or agent can walk the customer flow and write rows into the customer tables.
`register.php` compounds this by setting `$_SESSION['user_id']` without
setting `$_SESSION['role']`, so the auto-redirect in `index.php` never fires.
Fix: `require_customer()`.

**The dashboard is not actually locked.** Step 7 disables the button when
eKYC is pending, but `dashboard.php` has no matching check, so the URL works
directly. Fix: the guard at the top of `dashboard/index.php`.

### Schema

**Three tables are used but never created.** `kyc_details`,
`product_activations` and `bank_mandates.account_holder` are written by
`onboarding.php` and read by `dashboard.php`, but no file in the tree defines
them. Whichever page runs first on a fresh database throws, and the throw is
swallowed.

**Two files disagree about `bank_mandates`.** `onboarding.php` runs
`INSERT ... ON DUPLICATE KEY UPDATE`, which needs a unique key on `user_id`.
`dashboard.php` creates the table without one and inserts several rows per
user. The upsert silently becomes a plain insert.

**DDL runs on every page load.** The `ALTER TABLE ... ADD COLUMN IF NOT EXISTS`
block at the top of `dashboard.php` costs a round trip per request, needs DDL
privileges in the application database user, and sits inside
`catch (PDOException $e) {}`, so every failure is invisible. `ADD COLUMN IF
NOT EXISTS` is also MariaDB syntax that MySQL 8 rejects. Fix: `config/schema.sql`,
run once.

**No unique index on `users.email`.** Both `auth.php` and `register.php` do
SELECT-then-INSERT to check for a duplicate, which two concurrent
registrations will pass. Fix: `UNIQUE KEY uq_users_email`, and catch the
duplicate-key error rather than pre-checking.

**Documents default to verified.** `customer_documents.status` defaults to
`'Verified'`, and the KYC sync block inserts with that default. A document
cannot be verified at the moment the customer uploads it. Fix: default
`'pending'`, set `'verified'` only from `admin_review.php`.

**Identity numbers stored in plaintext.** `users.pan_number`,
`users.bank_account` and `kyc_details.doc_number` are plain columns. Fix:
`pii_encrypt()` / `pii_hash()`, with only the last four digits kept for
display.

**Single-row history.** `credit_evaluations` is keyed unique on `user_id`, so
each new score overwrites the last, and `users.loyalty_points` is a running
integer with no ledger. Neither a credit decision nor a points balance can be
reconstructed after the fact. Fix: versioned evaluations and `loyalty_ledger`.

### Duplication and structure

**Registration logic exists three times.** `auth.php`, `register.php` and
`index.php` each contain their own copy, and they have already drifted:
`auth.php` has no business fields and does not log the user in, `register.php`
has both, `index.php` has a third variant. `auth.php` is not required by
anything. Delete it, or make it the single handler the other two call.

**Post-registration routing disagrees.** `register.php` links to
`ekyc/index.php`, `index.php` sends customers to `customer/onboarding.php`,
and the login branch redirects to `ekyc/status.php`. Three destinations, three
different paths, at least two of them wrong relative to the folder layout.
Fix: point all three at `customer/index.php` and let it decide.

**`onboarding.php` is one file doing seven jobs, `dashboard.php` is one file
doing thirteen.** Every tab in `dashboard.php` pays for every other tab's
queries because they all run before the `if ($active_tab === ...)` chain.
Split, as in the tree above.

**The stepper is hand-written seven times.** Each sidebar block in
`onboarding.php` repeats the same conditional markup with the number changed,
which is why steps 2 and 3 disagree about what "Profile creation" contains.
Fix: generate it from `config/steps.php`.

**The same CSS is pasted into three files** and has already drifted between
them. Fix: `assets/customer.css`.

**Credit scoring runs on GET.** `onboarding.php` scores the customer as a side
effect of loading `?step=4`, so a refresh can re-score. The score itself is two
hardcoded branches: 780 and a 5,00,000 limit if any bank account exists,
otherwise 640 and 1,50,000. Fix: `credit/scoring.php`, POST only, with reason
codes and an input snapshot on every evaluation.

### Smaller

- `$_FILES` is never read in `onboarding.php` step 2. The "Upload ID photo" input renders, the file is discarded on every submission.
- `strlen($password) < 6` counts bytes, not characters, and six is low. Raise it, and check against a breached-password list.
- No rate limiting on login or OTP.
- `index1.php` and `session_test.php` should not be on a server that holds ID documents.
- `logout.php` destroys the session correctly but does not regenerate the ID first.
- Step gating in `onboarding.php` blocks forward jumps but lets a customer revisit an earlier step and overwrite later data. `advance_step()` uses `GREATEST()` so progress only moves forward.
- No audit trail. The flow chart's central hub says "Secure + Audit Logs"; nothing in the tree writes one. Fix: `audit_logs` plus `audit_log()`.

---

## 4. Installing

```bash
mysql -u root indbin_db < customer/config/schema.sql

mkdir -p storage/logs storage/keys
php -r 'echo bin2hex(random_bytes(32));' > storage/keys/pii.key
chmod 600 storage/keys/pii.key
chmod 750 customer/*/uploads
```

Set `CUST_BASE` in `config/bootstrap.php` to match the deployment path, then
point `index.php` and `register.php` at `customer/index.php` for every
post-registration and post-login customer redirect.

`storage/` must sit outside the web root, or be denied in the server config.
The PII key is not recoverable: losing it makes every encrypted document
number unreadable, so back it up separately from the database.

---

## 5. Still to build

The tree above lists files that this delivery does not include: the remaining
dashboard tabs, `products/activate.php`, `loyalty/enroll.php`, the
`profile/` and `products/` admin review pages, and
`applications/admin_review.php`. Each follows the pattern in
`dashboard/repayments.php` and `ekyc/admin_review.php`: bootstrap, guard, POST
handler with CSRF, its own queries, shell, markup.

Two integration points from the flow chart are stubbed rather than built:
the Python scoring microservice behind `credit/scoring.php`, and the payment
gateway callback that settles a repayment row.
