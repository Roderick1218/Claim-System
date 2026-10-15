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

// 登录（throttle:login 防暴力破解）
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:login');
});

// 以下都要登录；active 会把已被停用、但 session 还在的账号踢出去
Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
    Route::redirect('/', '/dashboard');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // 修改自己的密码
    Route::get('/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('/password', [PasswordController::class, 'update'])->name('password.update');

    // 我的 claim：这里不挂 permission middleware，每个动作都在 controller 里用 ClaimPolicy 检查
    Route::resource('claims', ClaimController::class);
    Route::post('/claims/{claim}/receipts', [ReceiptController::class, 'store'])->name('claims.receipts.store');

    // 审批：middleware 只挡没有 approve 权限的人，
    // 具体某张能不能批由 ClaimPolicy 的 review 判断（已 submit、不是自己的、看得到）
    Route::middleware('permission:'.Permission::CLAIMS_APPROVE)->group(function () {
        Route::get('/approvals', [ClaimApprovalController::class, 'index'])->name('approvals.index');
        Route::post('/claims/{claim}/approve', [ClaimApprovalController::class, 'approve'])->name('claims.approve');
        Route::post('/claims/{claim}/reject', [ClaimApprovalController::class, 'reject'])->name('claims.reject');
    });

    // 收据：文件放在 private storage，ReceiptPolicy 通过了才由 controller 读出来给浏览器
    Route::get('/receipts/{receipt}/preview', [ReceiptController::class, 'preview'])->name('receipts.preview');
    Route::get('/receipts/{receipt}/download', [ReceiptController::class, 'download'])->name('receipts.download');
    Route::post('/receipts/{receipt}/ocr/retry', [ReceiptController::class, 'retryOcr'])
        ->middleware('throttle:ocr-retry')->name('receipts.ocr.retry');
    Route::delete('/receipts/{receipt}', [ReceiptController::class, 'destroy'])->name('receipts.destroy');

    // 报表和 CSV 导出
    Route::middleware('permission:'.Permission::REPORTS_VIEW)->group(function () {
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/export.csv', [ReportController::class, 'export'])->name('reports.export');
    });

    // 设置：每个模块各自一个 permission。资料只停用不删除，旧 claim 和 audit log 还要引用，所以都没有 destroy
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
