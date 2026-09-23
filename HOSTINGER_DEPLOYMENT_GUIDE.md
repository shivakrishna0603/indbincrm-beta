# INDBIN CRM — Hostinger Beta Deployment Guide
**Target Domain:** `indbin.com` (or beta subdomain)  
**Stack:** PHP 8.x + MySQL 8.x (Hostinger Cloud / Shared Web Hosting)

---

## 1. Quick Summary of Architecture

INDBIN CRM unites four distinct user roles into a single application spine:
- **Admin Portal (`/admin/`):** Full oversight of applications, payouts, audits, leads, agent onboarding, and settings.
- **Agent Portal (`/agent/`):** Lead nurture, customer eligibility checks, requirement submission, commissions, and wallet.
- **Merchant Portal (`/merchant/`):** Product management, quotation generation, agreement signing, and orders.
- **Customer Portal (`/customer/`):** Application tracking, profile, loyalty points, and requirements.

---

## 2. Step-by-Step Deployment to Hostinger

### Step 1: Create MySQL Database in Hostinger
1. Log into your **Hostinger hPanel**.
2. Go to **Databases** > **Management** (or **MySQL Databases**).
3. Create a new database:
   - **Database Name:** e.g., `u123456789_indbincrm`
   - **Username:** e.g., `u123456789_indbinuser`
   - **Password:** *Enter a strong password and save it*
4. Click **Create**.

---

### Step 2: Import Schema via phpMyAdmin
1. In the same Databases section in hPanel, locate your new database and click **Enter phpMyAdmin**.
2. Click on the database name in the left sidebar to select it.
3. Go to the **Import** tab at the top.
4. Click **Choose File** and select `sql/hostinger_install.sql` from your project.
5. Click **Go** at the bottom.
   > **Note:** `sql/hostinger_install.sql` is specially formatted for Hostinger: it does not attempt global `DROP/CREATE DATABASE` queries, preventing `#1044 Access Denied` errors.
6. The import will complete with all 43 tables and seed data created.

---

### Step 3: Git Deployment via Hostinger hPanel
If you are deploying via Git (recommended):
1. Push this repository to your private GitHub or GitLab repository:
   ```bash
   git remote add origin https://github.com/your-username/indbincrm-beta.git
   git branch -M main
   git push -u origin main
   ```
2. In Hostinger hPanel, search for **Git** (under the **Advanced** section).
3. Fill in:
   - **Repository:** `https://github.com/your-username/indbincrm-beta.git` (or SSH URL)
   - **Branch:** `main`
   - **Install Directory:** `public_html` (for root domain `indbin.com`) or `public_html/beta`
4. Click **Create**.
5. Once created, click **Deploy** (or **Auto Deployment** using the provided Webhook URL).

*(Alternative: You can also zip the contents of this folder and upload directly to `public_html` via Hostinger File Manager).*

---

### Step 4: Configure Live Database Credentials
1. In Hostinger hPanel, open **File Manager** (or connect via SSH/FTP).
2. Inside `public_html/`, copy `config.sample.php` to `config.php`:
   ```php
   <?php
   declare(strict_types=1);

   define('DB_HOST', 'localhost');
   define('DB_PORT', 3306);
   define('DB_NAME', 'u123456789_indbincrm');    // your Hostinger DB name
   define('DB_USER', 'u123456789_indbinuser');   // your Hostinger DB user
   define('DB_PASS', 'YourHostingerDbPassword');  // your Hostinger DB password
   define('APP_ENV', 'beta');
   ```
3. Save the file. Because `config.php` is listed in `.gitignore`, your production credentials will never leak into version control.

---

### Step 5: File & Folder Permissions
Ensure writable folders have correct permissions (set in File Manager):
- `storage/logs/` -> `0755` (writable for error logs)
- `storage/uploads/` -> `0755` (for KYC / document uploads)
- `storage/keys/` -> `0700`

---

## 3. Demo Login Credentials (Fresh Install)

| Role | Email | Password | Home Page |
|---|---|---|---|
| **Admin** | `admin@indbin.local` | `Admin@123` | `/admin/` |
| **Agent** | `agent@indbin.local` | `Test@1234` | `/agent/dashboard/` |
| **Merchant** | `merchant@indbin.local` | `Test@1234` | `/merchant/dashboard/` |
| **Customer** | `customer@indbin.local` | `Test@1234` | `/customer/portal/shell.php` |

> **Security Note:** Change these default passwords immediately upon logging into your live Beta site!
