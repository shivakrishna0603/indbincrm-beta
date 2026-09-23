# INDBIN CRM: three modules, one application

## What changed

The three modules were three sites sharing a MySQL database. They had
separate sessions, separate registration logic, and separate ideas of what an
application is. This build gives them one spine.

Four things made the difference:

**One session.** Each module called a bare `session_start()` with PHP's
default cookie name while the customer module used `INDBINSESS`. Signing in
on one module and walking into another looked like signing out. `core/bootstrap.php`
now owns the session for every page in every module.

**One `applications` table.** Merchant and agent shared `applications`; the
customer module had its own `customer_applications`. A customer raising a
requirement and a merchant converting a lead produced two unrelated rows, so
no agent could see the customer's request and no commission could be traced
to the person who earned it. There is now one table carrying nullable
`customer_id`, `agent_id` and `merchant_id`. Whoever is involved is on the
row. A view named `customer_applications` keeps the older queries working.

**One identity, four roles.** `core/auth.php` handles registration and login
for customer, agent, merchant and admin. The three modules each had their own
copy of the registration logic and they had drifted: only one of them set
`$_SESSION['role']`, which is why no role check downstream could work.

**One audit trail.** Every module writes to `audit_logs` through the same
function. Nothing in the original three tree wrote a single audit record,
despite the flow chart's central hub promising them.

---

## The post-activation flow

Your two reference charts differ in one place: the first routes eligibility
through the agent, the second lets the customer check it directly. Both are
right, and the build supports both, because eligibility is one function
(`eligibility_for()`) that the customer dashboard and the agent dashboard
both call. They cannot give different answers about the same customer.

```
  CUSTOMER                    AGENT                      MERCHANT
     |                          |                            |
  Raise requirement             |                     Manage products
     |                          |                            |
     +--------> LEAD created, routed ------------------------+
     |          (sticky: the agent who                       |
     |           owns this customer keeps them)              |
     |                          |                            |
     |                  Understand need                      |
     |                  Suggest products                     |
     |                          |                            |
     |<---- Eligibility check --+--- same function --------->|
     |                          |                            |
  Interested? ---- no ---> Nurture, follow up later          |
     |                          |                            |
    yes                         |                    Provide quote
     |                          |                            |
     +------> APPLICATION  (one row, all three parties) <----+
                        |
     submitted -> kyc_check -> credit_review -> approved -> disbursed
                        |                                       |
                 rejected at any stage                   COMMISSION accrues
                 all three notified                      agent + merchant
                                                         from product rates
                        |
     POST OUTCOME: transactions, support, loyalty, renewals, cross-sell
```

### Two rules that hold it together

**Commission accrues on disbursement, never on approval.** Approving
something is not the same as money moving. Paying out on approval is how a
CRM ends up owing commission on a loan that never funded. `accrue_commissions()`
is called from exactly one place: the transition into `disbursed`.

**Lead ownership is sticky.** If a customer already has an agent, that agent
is credited even when the application arrives from somewhere else. Without
this the agent who spent three weeks nurturing a lead loses the commission to
whoever happened to click submit.

### Stage transitions are forward-only

`advance_application()` refuses to skip stages. An application cannot reach
`disbursed` without passing `credit_review`. Rejection is the exception and
can happen from anywhere, but it requires a written reason, and all three
parties are notified — an agent chasing an application that died two weeks ago
is the most common complaint in a CRM without that.

---

## Shared entry points, and where registration goes

There is one of each of these for the whole application. The merchant and
agent modules had their own copies of all five; those are deleted, because a
second registration handler meant a second place for the session role to go
unset, which is why role checks silently failed.

| File | Does |
|---|---|
| `index.php` | Home, the login modal for all four roles, and registration |
| `register.php` | Standalone signup page, same handler |
| `auth.php` | Shim to `core/bootstrap.php`, kept so old links still resolve |
| `logout.php` | One sign-out for every role |
| `admin/index.php` | Back-office console |

Registration and login both end in `landing_url()` in `core/auth.php`, which
reads the account row and returns the page that account actually belongs on.
No module decides this for itself any more.

| Role and state | Lands on |
|---|---|
| Customer, any state | `customer/index.php`, which resolves the seven-step position |
| Merchant, new | `merchant/ekyc/upload.php` |
| Merchant, KYC submitted | `merchant/ekyc/status.php` |
| Merchant, KYC approved | `merchant/business/upload.php` |
| Merchant, business submitted | `merchant/business/status.php` |
| Merchant, both approved | `merchant/credit/status.php` |
| Merchant, active | `merchant/dashboard/index.php` |
| Agent, new | `agent/ekyc/upload.php` |
| Agent, KYC submitted | `agent/ekyc/status.php` |
| Agent, KYC approved | `agent/background/index.php` |
| Agent, active | `agent/dashboard/index.php` |
| Admin | `admin/index.php` |

It accepts both `not_started` and `not_submitted` for the same state, because
the three modules spell it differently and a mismatch there previously left
new merchants on a status page with nothing to click.

## What each module shares

Every page in every module reaches the same four things:

- **One session.** `merchant/db.php` and `agent/db.php` are shims to
  `core/bootstrap.php`, which owns `session_name('INDBINSESS')`. Each module
  used to call a bare `session_start()`, so signing in on one and walking into
  another looked like signing out.
- **One PDO handle**, from `core/db.php`.
- **One set of guards**: `require_customer()`, `require_agent()`,
  `require_merchant()`, `require_admin()`. They reload the user row each
  request, so a suspended account stops immediately rather than at next login.
- **One stylesheet**, `assets/indbin.css`, and one onboarding shell, so the
  three journeys look like one product.

---

## Directory layout

```
indbincrm/
├── index.php  register.php  logout.php  auth.php     root pages and shims
│
├── core/                         THE SPINE
│   ├── bootstrap.php             session, paths, PII key; include this first
│   ├── db.php                    the one PDO handle
│   ├── helpers.php               escaping, CSRF, OTP, PII, audit, loyalty
│   ├── auth.php                  register and login, all four roles
│   ├── guard.php                 require_customer / agent / merchant / admin
│   └── workflow.php              THE INTEGRATION: leads, applications,
│                                 stages, commissions, eligibility
│
├── admin/                        INDBIN admin console
│   ├── index.php                 dashboard: counts, onboarding, trend, split
│   ├── onboarding.php            all three roles, same columns
│   ├── leads.php                 assign and reassign
│   ├── applications.php          every application, all parties visible
│   ├── approvals.php             one queue for all four verification types
│   ├── payouts.php               commission approval
│   ├── transactions.php
│   ├── reports.php               funnel, top agents, top merchants
│   ├── audit.php
│   └── shell.php
│
├── customer/                     seven-step onboarding + portal (complete)
├── merchant/                     db.php and auth.php are shims to core
├── agent/                        db.php and auth.php are shims to core
├── assets/indbin.css             one stylesheet
├── sql/indbin_full.sql           the whole database
└── storage/                      logs and the PII key; keep outside web root
```

---

## Installing

```
1. Copy the folder into C:\xampp\htdocs\indbincrm
2. Start Apache and MySQL
3. CREATE DATABASE indbin_db CHARACTER SET utf8mb4;
4. mysql -u root indbin_db < sql\indbin_full.sql
5. http://localhost/indbincrm/
```

The last statement in the SQL returns a count. Expect 43 tables and 2 views.
If it returns fewer, phpMyAdmin stopped at a failing statement further up.

### Starter logins

| Role | Email | Password |
|---|---|---|
| Admin | `admin@indbin.local` | `Admin@123` |
| Customer | `customer@indbin.local` | `Test@1234` |
| Agent | `agent@indbin.local` | `Test@1234` |
| Merchant | `merchant@indbin.local` | `Test@1234` |

Change all four. Those hashes are in a file anyone can read.

### Requirements

PHP 8.1 or newer (`never` return types, `str_starts_with`). Extensions
`pdo_mysql`, `fileinfo`, `openssl`, `mbstring`.

---

## What is done, and what is not

I want to be straight with you about this, because "error free" is a claim
worth being precise about.

### Verified

Every PHP file parses with balanced braces and parentheses. No function or
constant is declared twice along any load path — this mattered, because the
customer module defined 27 functions that also exist in `core/`, and loading
both would have been a fatal error on every page. Every table and column
referenced by the new code exists in the schema. Every admin nav link
resolves to a file. Every CSS class used has a rule.

### Built and working

The customer module end to end: seven steps, portal, document vault, credit
scoring with reason codes. The admin console, all ten pages, every figure a
real query. The workflow layer. The unified schema.

### Not yet done

**The merchant and agent screens still carry their own markup.** Their
`db.php` and `auth.php` are shims, so they share the session, the connection
and the guards — that is what makes login work across modules. But their 87
page files still contain their own inline CSS, their own duplicated
registration forms, and direct table writes that bypass `core/workflow.php`.
They will run; they will not yet look like one product, and a merchant
converting a lead still writes `applications` directly rather than calling
`create_application()`, so no commission accrues from that path.

**Nothing has been executed against a live MySQL.** I have no database in
this environment. The schema is validated structurally — statement order,
foreign key targets, balanced parentheses, no table referenced before
creation — but "parses correctly" is not "ran successfully". Run it and send
me whatever it says.

**Untested at runtime.** No page in this build has been loaded in a browser.
Static checks catch redeclares and missing columns; they do not catch logic
errors.

### The next step I would take

Point the merchant lead-conversion and the agent application submission at
`create_application()`. That is roughly four files and it is what turns the
commission engine on for the other two journeys. Everything else is
cosmetics.
