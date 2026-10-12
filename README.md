<div align="center">

**English** | [简体中文](README.zh-CN.md)

<img src="public/images/logo.png" alt="Wai Hong Brothers" width="320">

# ClaimFlow — Company Expense Claim System

**Less Paper. More Progress.**

Snap a receipt → the system reads the merchant, date, items and amounts → staff check and submit → Admin approves or rejects

Laravel 13 · PHP 8.3 · MySQL / MariaDB · Blade + Tailwind CSS 4 · Local OCR (Tesseract) · **No AI, no cloud services**

</div>

![Dashboard](docs/screenshots/02-dashboard.png)

---

## Contents

1. [Features](#1-features)
2. [How to use (staff)](#2-how-to-use-staff)
3. [How to use (Admin approval)](#3-how-to-use-admin-approval)
4. [Roles & permissions](#4-roles--permissions)
5. [Installation (Windows + XAMPP)](#5-installation-windows--xampp)
6. [Local OCR setup](#6-local-ocr-setup)
7. [Tests](#7-tests)
8. [Backup & restore](#8-backup--restore)
9. [Going live](#9-going-live)
10. [Known limits](#10-known-limits)

---

## 1. Features

| Feature | Description |
|---|---|
| 📷 **Photo / upload receipts** | Opens the camera directly on phones; also accepts JPG, PNG and PDF. One claim can hold several receipts |
| 🔍 **Local OCR auto-fill** | Reads the merchant name, address, date, time, every item (qty × unit price), add-ons, service charge, SST, rounding and total. Auto-filled fields are highlighted in **yellow** so they are easy to check |
| 🏷️ **Category auto-select** | Uses the category this merchant had before; otherwise matches keywords (e.g. KFC → Food & Beverage). Keywords are editable in Settings |
| ✅ **Approval workflow** | Draft → Submitted (locked) → Approved / Rejected (a reason is required; staff can fix and resubmit) |
| 🧮 **Automatic amounts** | The server recalculates every line and the total, and shows a warning if it differs from the total printed on the receipt |
| 📊 **Dashboard** | This month's figures vs last month, monthly line chart, by category / project, recent claims |
| 🔔 **Notifications** | The bell shows claims waiting for approval, rejected claims and unsent drafts |
| 📑 **Reports / CSV export** | Filter by date, category, employee, department, project, amount and status, then export to CSV |
| 🔐 **Permissions & audit** | Role permissions are ticked in the admin settings; every important action is recorded in the Audit Log |
| 📱 **Mobile friendly** | Bottom navigation on phones; every page fits a phone screen |

---

## 2. How to use (staff)

### Step 1: Sign in

Open the system URL and sign in with your work email and password.

![Sign in](docs/screenshots/01-login.png)

### Step 2: New claim — add the receipt

Click **New Claim** in the left menu (phone: the **＋ New Claim** button on the home page).

- On a phone tap **Take Photo**; on a computer click **Choose File** or drag files into the box.
- You can add several receipts at once. No receipt? Click **Enter manually**.
- Click **Continue** — the system reads the receipt (a few seconds each).

> 💡 Photo tip: lay the receipt flat in good light and let it fill the whole photo for the best results.

![New claim](docs/screenshots/04-new-claim.png)

### Step 3: Check the auto-filled details

- The receipt photo is on the left, the auto-filled details on the right.
- **Yellow fields** were filled by OCR — please compare them with the photo.
- Fix any item, quantity or price directly, or click **Add item**.
- The calculated total is shown at the bottom, with the difference from the receipt total (if any).
- Click **Save Draft** to keep it for later, or **Submit for approval**.

> ⚠️ Once submitted, the claim is **locked** and cannot be changed while it is being reviewed.

![Check the OCR details](docs/screenshots/05-check-ocr.png)

### Step 4: Track progress

**My Claims** lists all your claims, with status tabs (All / Draft / Pending / Approved / Rejected), search and filters.

![My claims](docs/screenshots/03-claims.png)

Open a claim to see the receipt, the details and its progress (Draft → Submitted → Approved).

![Claim details](docs/screenshots/06-claim-details.png)

### What if my claim is rejected?

The dashboard and the bell show a red notice with the reason the Admin gave. Open the claim, fix it, and click **Resubmit for approval**.

---

## 3. How to use (Admin approval)

1. Open **Approvals** in the left menu (red number = claims waiting).
2. The **Pending** tab is ordered by submission time, oldest first; the **Approved / Rejected** tabs show claims already handled.

![Approvals](docs/screenshots/07-approvals.png)

3. Click **Review** and compare the receipt with the amounts:
   - **Approve** — a remark is optional;
   - **Reject** — a reason is required, and the staff member will see it.
4. After each decision the next pending claim opens automatically.

> 🔒 Nobody — Admin included — can **approve their own claim**. Approved claims can no longer be changed.

![Review](docs/screenshots/08-review.png)

### Reports

**Reports**: filter and export to CSV (only data you are allowed to see).

![Reports](docs/screenshots/09-reports.png)

### Mobile

<p>
<img src="docs/screenshots/11-mobile-dashboard.png" width="260" alt="Mobile dashboard">
&nbsp;&nbsp;
<img src="docs/screenshots/12-mobile-claims.png" width="260" alt="Mobile claims list">
</p>

---

## 4. Roles & permissions

Company rule: **everyone sees only their own claims; only Admin sees everyone's.**

| Role | Whose claims they see | Reports | Approve | Settings |
|---|---|---|---|---|
| Staff | Their own only | ✗ | ✗ | ✗ |
| Manager | Their own only | ✗ | ✗ | ✗ |
| Owner | Their own only | ✗ | ✗ | ✗ |
| **Admin** | **Everyone's** | ✓ | ✓ | ✓ |

- Access is decided by permission rows, never by role name. An Admin can grant a role more in **Settings → Roles & Permissions** — e.g. let Owner see everything (`claims.view_all`) or Manager see their department (`claims.view_department`) — without changing code.
- Checks run on the server: changing a claim or receipt ID in the URL is still refused (403).

![Roles & permissions](docs/screenshots/10-roles.png)

### Amount rules

- `line total = qty × unit price − discount + tax`. If the receipt only shows a total, leave unit price empty and enter **Amount**.
- Claim total = sum of all lines, calculated in cents to avoid rounding errors.
- Three numbers are kept apart: the OCR-detected total (a hint only), the **receipt total confirmed by staff**, and the **calculated claim total**. If they differ, the difference is shown — nothing is overwritten.
- Categories marked *Receipt required* cannot be submitted without a receipt (drafts are allowed).

---

## 5. Installation (Windows + XAMPP)

**Requirements:** PHP ≥ 8.3 (extensions: pdo_mysql, mbstring, fileinfo, gd, openssl, zip; pdo_sqlite for tests), Composer, Node.js ≥ 20, MySQL / MariaDB.

```powershell
cd C:\xampp\htdocs
git clone https://github.com/Roderick1218/Claim-System.git claim-system
cd claim-system
composer install
copy .env.example .env
php artisan key:generate
# edit .env: DB_*, APP_URL, TESSERACT_PATH, PDFTOTEXT_PATH / PDFTOPPM_PATH
```

Create the database (phpMyAdmin, or):

```sql
CREATE DATABASE claim_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```powershell
php artisan migrate
php artisan db:seed --class=RolePermissionSeeder
php artisan db:seed --class=ClaimCategorySeeder
# Local testing only (creates demo accounts, password: password123):
php artisan db:seed --class=DemoDataSeeder
npm install
npm run build
```

Open `http://localhost/claim-system/public`, or run `php artisan serve` and open `http://127.0.0.1:8000`.

### Demo accounts (DemoDataSeeder — delete or deactivate before going live)

| Email | Role | Department |
|---|---|---|
| admin@claim.local | admin | Accounts |
| owner@claim.local | owner | Management |
| manager@claim.local | manager | Sales |
| staff@claim.local | staff | Sales |
| staff2@claim.local | staff | Operations |

Password for all: `password123`

### First Admin on a real server (no demo data)

```powershell
php artisan tinker
```

```php
$u = App\Models\User::create(['name' => 'Admin', 'email' => 'admin@company.com', 'password' => 'CHANGE-ME-123']);
$u->roles()->attach(App\Models\Role::where('name', 'admin')->first());
```

### Changing the logo

Save the new logo as `public/images/logo.png` (a transparent PNG works best). Change the system name with `APP_NAME` in `.env`.

---

## 6. Local OCR setup

### Tesseract (required)

```powershell
winget install --id UB-Mannheim.TesseractOCR -e
tesseract --version
tesseract --list-langs
```

Put the **full path** in `.env`, in **single quotes**:

```
TESSERACT_PATH='C:\Program Files\Tesseract-OCR\tesseract.exe'
TESSERACT_LANG=eng
TESSERACT_PSM=4,6
```

- `TESSERACT_PSM=4,6`: each receipt is read with two page-layout modes and the best result is kept (readings whose items add up to the total win).
- Malay / Chinese receipts: download `msa.traineddata` / `chi_sim.traineddata` from https://github.com/tesseract-ocr/tessdata into the `tessdata` folder, then set e.g. `TESSERACT_LANG=eng+msa`. Test with real receipts — more languages can make English results slightly worse.

> If Apache runs as a Windows service under another account, a per-user install path may not be readable — install Tesseract to `C:\Program Files` instead.

### Poppler (PDF receipts, optional)

```powershell
winget install --id oschwartz10612.Poppler -e
```

Set `PDFTOTEXT_PATH` and `PDFTOPPM_PATH` in `.env` (the exe files in `...\poppler-xx\Library\bin\`). Without Poppler, PDFs still upload and preview; they just aren't read automatically.

---

## 7. Tests

```powershell
php artisan test
```

Tests use in-memory SQLite and a fake OCR engine — they never touch the real database or call Tesseract. Covered: sign-in / deactivated users, permission scope (changing IDs in the URL), approval workflow, amount calculation, upload checks, OCR failure and retry, OCR text parsing (many real receipt layouts), report filters and safe CSV export.

---

## 8. Backup & restore

Back up **both** the database and the receipt files — one without the other is useless.

| What | Where |
|---|---|
| Database | `claim_system` |
| Receipt files | `storage/app/private/receipts/` |
| Settings | `.env` (contains passwords — keep it safe, never put it in Git) |

```powershell
# Backup (XAMPP MariaDB — use your own port)
$d = Get-Date -Format yyyyMMdd-HHmm
New-Item -ItemType Directory -Force D:\backup\claim\$d
cmd /c "C:\xampp\mysql\bin\mysqldump.exe --user=root --port=3306 --host=127.0.0.1 --single-transaction claim_system > D:\backup\claim\$d\claim_system.sql"
Compress-Archive C:\xampp\htdocs\claim-system\storage\app\private\receipts D:\backup\claim\$d\receipts.zip
```

```powershell
# Restore
cmd /c "C:\xampp\mysql\bin\mysql.exe --user=root --port=3306 --host=127.0.0.1 claim_system < D:\backup\claim\<date>\claim_system.sql"
Expand-Archive D:\backup\claim\<date>\receipts.zip C:\xampp\htdocs\claim-system\storage\app\private\ -Force
php artisan migrate
```

Schedule the backup daily with Windows Task Scheduler and copy it somewhere else (NAS / cloud drive).

---

## 9. Going live

- `.env`: `APP_ENV=production`, `APP_DEBUG=false`, the real `APP_URL`, a strong database password.
- Point the web server's document root at `public/`; never expose the whole project folder.
- Receipts are stored in `storage/app/private` (not under `public/`) and are only served after a permission check. Do **not** run `php artisan storage:link` for receipts.
- `storage/` and `bootstrap/cache/` must be writable.
- After each deploy: `php artisan config:cache && php artisan route:cache && php artisan view:cache`.
- Use HTTPS outside the office network (phone camera uploads also need HTTPS).
- Deactivate staff who leave instead of deleting them, so their old claims are kept.
- PHP `upload_max_filesize` / `post_max_size` must be larger than the receipt size limit × receipts per upload.

---

## 10. Known limits

- OCR accuracy depends on photo quality; Tesseract cannot read handwritten receipts — type those in manually.
- One approval level only (Admin); no email notifications (the bell and dashboard show notices in the system).
- OCR runs during the upload (a few seconds per photo); for high volumes it could move to a background queue.
