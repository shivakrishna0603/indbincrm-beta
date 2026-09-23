# INDBIN CRM — Beta Testing Build (v2)

Built from the codebase you just uploaded. Verified byte-for-byte: every
file that isn't listed below as removed is untouched from your upload,
including `sql/indbin_full.sql` and the additions in it - checked with a
diff before and after cleanup, not assumed.

## What was removed, same reasoning as last time

- **167 real uploaded KYC documents** - Aadhaar, PAN, live selfies, GST
  certificates, cancelled cheques, income and address proof. Same as
  before: real people's identity and financial documents don't belong in
  something handed to testers. Folder structure and `.htaccess`/`.gitkeep`
  kept, so uploads still work.
- **3 stray `.zip` files** left inside live subfolders.
- **`diagnose.php`** - lists accounts and tests passwords, flagged in its
  own comment as unsafe to leave in a public build.

`export_data.php` is added back in, unchanged from before.

## What's new in this upload, and what I found in it

Your `sql/indbin_full.sql` has real additions since last time - a
`customer_agent_assignments` table, a `training_content` table with ten
genuinely well-written merchant training articles, and a new
`users.assigned_role` column. I checked, and all three are actually wired
into real PHP files, not just sitting in the schema unused - `assigned_role`
in particular is now used across most of the newer agent dashboard pages,
and `agent/onboarding_complete/index.php` treats it as the primary source
for role assignment now, falling back to `agent_verification.role` (what my
own `admin/agent_settings.php` writes to) only if `assigned_role` is empty.
That fallback means my tool still works, just not through the newer, more
direct path - worth reconciling at some point, not urgent, and not
something I touched here given you asked for the data unchanged.

**One thing in that file I want to flag directly rather than quietly leave
alone:** it also contains this statement -

```sql
UPDATE users
SET party_code = CONCAT('INDBIN', CASE role
    WHEN 'admin' THEN 'X' WHEN 'customer' THEN 'C'
    WHEN 'agent' THEN 'A' WHEN 'merchant' THEN 'M' ELSE 'U' END,
    LPAD(id, 7, '0'))
```

I left it in the file exactly as you have it, because you asked me not to
change the data. But if this statement is ever actually run, it will
regenerate every existing party_code from each user's raw `id` - the exact
bug this whole project's `party_code_sequences` system was built to fix
(role-mixed numbering, where the Nth agent doesn't get agent-sequence N).
It would also leave `party_code_sequences`'s own counters unaware of the
new values, risking a collision the next time a real code is issued. It's
present in the file but I found no evidence it's been executed against your
live data - `party_code_sequences` still looked correctly seeded in
everything else I checked. I'd leave this statement out of anything you
actually run.

## What I actually verified

- Every PHP file lints clean - zero syntax errors.
- A fresh install from `sql/install_from_scratch.sql` succeeds (44 tables).
- All four demo roles reach their dashboard with no fatal errors.

## What I still can't vouch for

Same honest limit as last time: the newer agent dashboard pages
(`connections.php`, `documents.php`, `eligibility.php`, `followups.php`,
`requirements.php`, `tracking.php`, and the rest) are code I have no prior
history with. I didn't rewrite or test their internals - this is your code,
cleaned, not mine reasserted over it.

## Demo credentials (fresh install only)

| Role | Email | Password |
|---|---|---|
| Admin | admin@indbin.local | Admin@123 |
| Customer | customer@indbin.local | Test@1234 |
| Agent | agent@indbin.local | Test@1234 |
| Merchant | merchant@indbin.local | Test@1234 |
