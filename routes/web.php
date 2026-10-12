<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\ClaimApprovalController;
use App\Http\Controllers\ClaimController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\Settings;
use App\Models\Permission;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:login');
});

Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
    Route::redirect('/', '/dashboard');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('/password', [PasswordController::class, 'update'])->name('password.update');

    // Claims — every action is checked by ClaimPolicy inside the controller.
    Route::resource('claims', ClaimController::class);
    Route::post('/claims/{claim}/receipts', [ReceiptController::class, 'store'])->name('claims.receipts.store');

    // Approval — the ClaimPolicy "review" ability is checked per claim (submitted, not own, can view).
    Route::middleware('permission:'.Permission::CLAIMS_APPROVE)->group(function () {
        Route::get('/approvals', [ClaimApprovalController::class, 'index'])->name('approvals.index');
        Route::post('/claims/{claim}/approve', [ClaimApprovalController::class, 'approve'])->name('claims.approve');
        Route::post('/claims/{claim}/reject', [ClaimApprovalController::class, 'reject'])->name('claims.reject');
    });

    // Receipts — files are streamed from private storage only after ReceiptPolicy allows it.
    Route::get('/receipts/{receipt}/preview', [ReceiptController::class, 'preview'])->name('receipts.preview');
    Route::get('/receipts/{receipt}/download', [ReceiptController::class, 'download'])->name('receipts.download');
    Route::post('/receipts/{receipt}/ocr/retry', [ReceiptController::class, 'retryOcr'])
        ->middleware('throttle:ocr-retry')->name('receipts.ocr.retry');
    Route::delete('/receipts/{receipt}', [ReceiptController::class, 'destroy'])->name('receipts.destroy');

    Route::middleware('permission:'.Permission::REPORTS_VIEW)->group(function () {
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/export.csv', [ReportController::class, 'export'])->name('reports.export');
    });

    Route::prefix('settings')->name('settings.')->group(function () {
        Route::middleware('permission:'.Permission::SETTINGS_USERS)->group(function () {
            Route::resource('users', Settings\UserController::class)->except(['show', 'destroy']);
        });
        Route::middleware('permission:'.Permission::SETTINGS_DEPARTMENTS)->group(function () {
            Route::resource('departments', Settings\DepartmentController::class)->except(['show', 'destroy']);
        });
        Route::middleware('permission:'.Permission::SETTINGS_ROLES)->group(function () {
            Route::resource('roles', Settings\RoleController::class)->except(['show', 'destroy']);
        });
        Route::middleware('permission:'.Permission::SETTINGS_CATEGORIES)->group(function () {
            Route::resource('categories', Settings\CategoryController::class)->except(['show', 'destroy']);
        });
        Route::middleware('permission:'.Permission::SETTINGS_PROJECTS)->group(function () {
            Route::resource('projects', Settings\ProjectController::class)->except(['show', 'destroy']);
        });
        Route::get('audit-logs', [Settings\AuditLogController::class, 'index'])
            ->middleware('permission:'.Permission::AUDIT_VIEW)->name('audit-logs.index');
    });
});
