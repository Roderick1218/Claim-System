# Claim System — notes for AI assistants

Company claim / expense tracking (Phase 1). Spec: `Claude_Code_Laravel_Claim_System_Spec.txt` (user's Downloads).
Full install / backup / deploy docs: `README.md`.

## Hard rules from the spec (Phase 1)
- **No AI / cloud OCR.** Only local Tesseract (+ optional Poppler for PDFs). Don't add any AI API without explicit user consent.
- **Approval (added 2026-10-12 at the user's request, overriding the original Phase 1 spec):** `draft` → `submitted` (locked for owner) → `approved` (final, nobody edits) / `rejected` (owner edits + resubmits). Only users with `claims.approve` (admin role) approve; never their own claim. Rules in `ClaimPolicy` (`update`, `delete`, `review`). OCR status (`pending/processing/completed/failed`) is separate.
- OCR values (`ReceiptTextHints`) and the category (`CategoryGuesser`: merchant history, then keywords) are pre-filled into the draft form highlighted yellow; nothing is saved until the user clicks Save / Submit, and saved values are never overwritten (except via "↻ Re-fill from OCR").
- Never run `migrate:fresh`, drop tables or truncate without explicit user consent.

## Where things live
- Permission scope: `Claim::scopeVisibleTo()` is the single source of truth for list/dashboard/reports/export. `ClaimPolicy` / `ReceiptPolicy` for single records.
- Permissions are explicit rows (`permissions`, `role_permissions`); roles never grant anything by name. Constants in `App\Models\Permission`.
- Money: `ClaimCalculationService` (integer cents). Amount columns are NOT `$fillable` — only `ClaimService::save()` sets them via `forceFill`.
- Claim number: `ClaimNumberService` (locked `claim_sequences` row per month).
- Files: `ReceiptStorageService` (private disk `storage/app/private/receipts`, UUID names, realpath containment check).
- OCR: `OcrService` (Symfony Process, args array, timeout). Tests replace it with `tests/Support/FakeOcrService.php`.

## Local environment (this VM)
- `C:\xampp\htdocs\claim-system`, PHP 8.3 (`C:\php83`, also used by XAMPP Apache), MariaDB on **port 3307**, DB `claim_system`.
- URL: http://localhost/claim-system/public
- Tesseract 5.4 installed per-user: `C:\Users\whb user\AppData\Local\Programs\Tesseract-OCR` (eng only).
- Poppler 25.07 via winget (path in `.env`).
- `.env` Windows paths must use single quotes (double quotes break on `\U`).
- Tests use in-memory SQLite (`phpunit.xml`, APP_URL forced to http://localhost). `php artisan test`.
- After changing Blade/CSS/JS: `npm run build`.
