<div align="center">

**English** | [简体中文](README.zh-CN.md)

<img src="public/images/logo.png" alt="Wai Hong Brothers" width="320">

# ClaimFlow

Expense claim system for our company. Staff take a photo of the receipt, the system fills in most of the form, and Admin approves it.

</div>

![Dashboard](docs/screenshots/02-dashboard.png)

## About

Instead of collecting paper receipts and typing them into Excel, each staff member uploads their own receipts. The photo is stored with the claim, so nothing gets lost, and accounts can export everything to Excel at month end.

The receipt reading runs on our own PC/server with Tesseract OCR. No AI service or cloud API is used, so it costs nothing to run and the receipts never leave our machine.

Built with Laravel 13, PHP 8.3, MariaDB/MySQL, Blade and Tailwind CSS 4.

## What it does

- Take a photo on the phone, or upload a JPG / PNG / PDF. One claim can have more than one receipt.
- OCR fills in the merchant, address, date, time, items, SST / service charge and the total. Anything it filled in is marked yellow so you know what to double check.
- The category is picked for you: the same as last time for this merchant, otherwise by keyword (KFC → Food & Beverage). The keywords can be edited in Settings.
- Approval flow: Draft → Submitted → Approved or Rejected. A rejected claim comes back with Admin's reason so staff can fix it and submit again.
- The server recalculates every amount itself. If our total doesn't match the receipt, it shows the difference.
- The dashboard compares this month with last month, with a monthly chart and breakdowns by category and project.
- Reports can be filtered and exported to CSV (opens in Excel). Rows are numbered and the last row is the Grand Total.
- An audit log records who created, edited, approved or exported what.
- Works on a phone. There is a bottom menu, and every page fits a phone screen.

## Using it (staff)

**1. Sign in** with your work email and password.

![Sign in](docs/screenshots/01-login.png)

**2. New Claim.** On a phone, tap **Take Photo**. On a computer, click **Choose File** or drag the receipt into the box. You can add a few receipts at once. If you have no receipt, use **Enter manually**. Then click **Continue** and wait a few seconds per receipt.

For the best result, put the receipt flat, use good light, and let the receipt fill the photo.

![New claim](docs/screenshots/04-new-claim.png)

**3. Check the details.** The receipt is on the left, the form on the right. Check the yellow fields against the photo. You can change any item, quantity or price, or add a line. Then click **Save Draft** to finish it later, or **Submit for approval**.

After you submit, the claim is locked until Admin has reviewed it.

![Check the OCR details](docs/screenshots/05-check-ocr.png)

**4. Follow up** in **My Claims**. The tabs (All / Draft / Pending / Approved / Rejected), search and filters help you find a claim quickly.

![My claims](docs/screenshots/03-claims.png)

Click a claim to see the receipt, the items and where it is in the approval flow.

![Claim details](docs/screenshots/06-claim-details.png)

**Rejected?** A red notice on the dashboard and the bell shows Admin's reason. Open the claim, fix it and click **Resubmit for approval**.

## Using it (Admin)

Go to **Approvals**. The red number is how many claims are waiting. The Pending tab shows the oldest first.

![Approvals](docs/screenshots/07-approvals.png)

Open a claim and compare the receipt with the amounts. Then click **Approve** (the remark is optional) or **Reject** (a reason is required, because the staff member will see it). After that, the next pending claim opens by itself.

Nobody can approve their own claim, not even Admin. Once a claim is approved it can't be edited any more.

![Review](docs/screenshots/08-review.png)

**Reports** has the filters and the CSV export. The export only contains data you are allowed to see.

![Reports](docs/screenshots/09-reports.png)

On the phone:

<p>
<img src="docs/screenshots/11-mobile-dashboard.png" width="260" alt="Mobile dashboard">
&nbsp;&nbsp;
<img src="docs/screenshots/12-mobile-claims.png" width="260" alt="Mobile claims list">
</p>

## Who sees what

Our rule: everyone sees only their own claims. Only Admin sees everybody's.

| Role | Sees | Reports | Approve | Settings |
|---|---|---|---|---|
| Staff | own claims | - | - | - |
| Manager | own claims | - | - | - |
| Owner | own claims | - | - | - |
| Admin | all claims | yes | yes | yes |

The code checks permissions, not role names. So if one day Owner should see everything, Admin can tick `claims.view_all` for Owner in **Settings → Roles & Permissions**, with no code change. There is also `claims.view_department` for department heads.

The checks run on the server. Changing the claim ID in the URL just gives a 403.

![Roles & permissions](docs/screenshots/10-roles.png)

### How amounts are calculated

- Line amount = qty × unit price − discount + tax. If the receipt only shows a total, leave the unit price empty and type the amount.
- Claim total = all lines added up. It is calculated in cents, so there are no rounding errors.
- We keep three numbers separate: what OCR read (only a hint), the receipt total the staff member confirmed, and our calculated total. If they don't match, we show the difference and don't overwrite anything.
- Categories marked *receipt required* can't be submitted without a receipt. Saving a draft is still fine.

## Installing (Windows + XAMPP)

You need PHP 8.3 or newer (with pdo_mysql, mbstring, fileinfo, gd, openssl, zip, and pdo_sqlite for the tests), Composer, Node.js 20 or newer, and MySQL or MariaDB.

```powershell
cd C:\xampp\htdocs
git clone https://github.com/Roderick1218/Claim-System.git claim-system
cd claim-system
composer install
copy .env.example .env
php artisan key:generate
```

Edit `.env`: the database settings, `APP_URL`, `TESSERACT_PATH`, and the Poppler paths if you want PDF reading. Then create the database:

```sql
CREATE DATABASE claim_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```powershell
php artisan migrate
php artisan db:seed --class=RolePermissionSeeder
php artisan db:seed --class=ClaimCategorySeeder
php artisan db:seed --class=DemoDataSeeder   # demo accounts, for local testing only
npm install
npm run build
```

Open `http://localhost/claim-system/public`, or run `php artisan serve` and go to `http://127.0.0.1:8000`.

### Demo accounts

All passwords are `password123`. Remove these before going live.

| Email | Role | Department |
|---|---|---|
| admin@claim.local | admin | Accounts |
| owner@claim.local | owner | Management |
| manager@claim.local | manager | Sales |
| staff@claim.local | staff | Sales |
| staff2@claim.local | staff | Operations |

On a real server, skip the demo seeder and create the first Admin in `php artisan tinker`:

```php
$u = App\Models\User::create(['name' => 'Admin', 'email' => 'admin@company.com', 'password' => 'CHANGE-ME-123']);
$u->roles()->attach(App\Models\Role::where('name', 'admin')->first());
```

To change the logo, replace `public/images/logo.png` (a transparent PNG looks best). The name comes from `APP_NAME` in `.env`.

## OCR setup

Tesseract is required:

```powershell
winget install --id UB-Mannheim.TesseractOCR -e
tesseract --version
```

Put the full path in `.env`. Use single quotes, because double quotes break on Windows paths:

```
TESSERACT_PATH='C:\Program Files\Tesseract-OCR\tesseract.exe'
TESSERACT_LANG=eng
TESSERACT_PSM=4,6
```

`4,6` means every receipt is read in two layout modes and we keep the better result. Mode 4 suits normal till receipts. Mode 6 suits A4 invoices with a table.

For Malay or Chinese receipts, download `msa.traineddata` or `chi_sim.traineddata` from https://github.com/tesseract-ocr/tessdata into Tesseract's `tessdata` folder and set, for example, `TESSERACT_LANG=eng+msa`. Try it on real receipts first: extra languages sometimes make English worse.

If Apache runs as a Windows service, install Tesseract under `C:\Program Files`. A per-user install may not be readable by the service account.

Poppler is optional and only needed for PDF receipts:

```powershell
winget install --id oschwartz10612.Poppler -e
```

Set `PDFTOTEXT_PATH` and `PDFTOPPM_PATH` to the exe files in `poppler-xx\Library\bin\`. Without it, PDFs still upload and preview, but they aren't read.

## Tests

```powershell
php artisan test
```

The tests use an in-memory SQLite database and a fake OCR engine, so they don't touch your real data and don't need Tesseract. They cover login, permissions (including URL ID tampering), the approval flow, amount calculation, uploads, OCR retry, receipt text parsing based on real receipts we tested, reports and the CSV export.

## Backup

Back up the database and the receipt files together. One without the other is not much use.

| What | Where |
|---|---|
| Database | `claim_system` |
| Receipts | `storage/app/private/receipts/` |
| Settings | `.env` (has passwords, keep it out of Git) |

```powershell
# Backup (change the port to your MySQL port)
$d = Get-Date -Format yyyyMMdd-HHmm
New-Item -ItemType Directory -Force D:\backup\claim\$d
cmd /c "C:\xampp\mysql\bin\mysqldump.exe --user=root --port=3306 --host=127.0.0.1 --single-transaction claim_system > D:\backup\claim\$d\claim_system.sql"
Compress-Archive C:\xampp\htdocs\claim-system\storage\app\private\receipts D:\backup\claim\$d\receipts.zip

# Restore
cmd /c "C:\xampp\mysql\bin\mysql.exe --user=root --port=3306 --host=127.0.0.1 claim_system < D:\backup\claim\<date>\claim_system.sql"
Expand-Archive D:\backup\claim\<date>\receipts.zip C:\xampp\htdocs\claim-system\storage\app\private\ -Force
php artisan migrate
```

Run it daily with Task Scheduler and keep a copy on the NAS or a cloud drive.

## Before going live

- In `.env`, set `APP_ENV=production`, `APP_DEBUG=false`, the real `APP_URL` and a proper database password.
- The web root must point to `public/`, not the project folder.
- Receipts are kept in `storage/app/private` and are only served after a permission check. Don't run `storage:link` for them.
- `storage/` and `bootstrap/cache/` need to be writable.
- After each deploy, run `php artisan config:cache`, `route:cache` and `view:cache`.
- Use HTTPS if people will use it outside the office.
- When someone leaves, deactivate the account instead of deleting it, so their old claims stay.
- PHP `upload_max_filesize` and `post_max_size` must be bigger than the receipt size limit × the number of receipts per upload.

## Known limits

- OCR is only as good as the photo. Handwritten bills can't be read, so type those in.
- There is one approval level (Admin) and no email. Notices only appear in the system: the bell and the dashboard.
- OCR runs while you wait, a few seconds per photo. If volume grows, it could move to a background queue.
