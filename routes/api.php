<?php

use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AdminRoleController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardWidgetController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SettingsController;
use Illuminate\Support\Facades\Route;

Route::prefix('tenant')->group(function (): void {
    Route::post('/internal/licenses/sync', [SettingsController::class, 'internalSyncLicenses'])->middleware('internal.sync.token');

    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/mfa/verify', [AuthController::class, 'verifyMfa']);
    Route::post('/auth/refresh', [AuthController::class, 'refresh']);

    Route::middleware('auth.token')->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/context', [SettingsController::class, 'context']);
        Route::get('/dashboard/widgets', [DashboardWidgetController::class, 'index']);
        Route::put('/dashboard/widgets', [DashboardWidgetController::class, 'upsert']);
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::get('/notifications/recent', [NotificationController::class, 'recent']);
        Route::get('/notifications/stream', [NotificationController::class, 'stream']);
        Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllRead']);
        Route::patch('/notifications/{notification}', [NotificationController::class, 'update']);
        Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy']);

        Route::get('/profile', [ProfileController::class, 'show']);
        Route::patch('/profile', [ProfileController::class, 'update']);
        Route::patch('/profile/password', [ProfileController::class, 'updatePassword']);
        Route::patch('/profile/mfa', [ProfileController::class, 'updateMfa']);
        Route::post('/profile/mfa/app/setup', [ProfileController::class, 'setupAppMfa']);
        Route::post('/profile/mfa/app/activate', [ProfileController::class, 'activateAppMfa']);
        Route::get('/profile/trusted-devices', [ProfileController::class, 'trustedDevices']);
        Route::delete('/profile/trusted-devices', [ProfileController::class, 'revokeAllTrustedDevices']);
        Route::delete('/profile/trusted-devices/{device}', [ProfileController::class, 'revokeTrustedDevice']);

        Route::get('/admin/users', [AdminUserController::class, 'index'])->middleware('permission:users.manage');
        Route::get('/admin/module-seats', [AdminUserController::class, 'moduleSeatOverview'])->middleware('permission:users.manage');
        Route::post('/admin/users', [AdminUserController::class, 'store'])->middleware('permission:users.manage');
        Route::patch('/admin/users/{user}', [AdminUserController::class, 'update'])->middleware('permission:users.manage');
        Route::delete('/admin/users/{user}', [AdminUserController::class, 'destroy'])->middleware('permission:users.manage');
        Route::get('/admin/users/{user}/trusted-devices', [AdminUserController::class, 'listTrustedDevices'])->middleware('permission:users.manage');
        Route::post('/admin/users/{user}/trusted-devices/revoke', [AdminUserController::class, 'revokeTrustedDevices'])->middleware('permission:users.manage');
        Route::delete('/admin/users/{user}/trusted-devices/{deviceUuid}', [AdminUserController::class, 'revokeTrustedDevice'])->middleware('permission:users.manage');
        Route::post('/admin/users/{user}/mfa/app/setup', [AdminUserController::class, 'setupAppMfa'])->middleware('permission:users.manage');
        Route::post('/admin/users/{user}/mfa/app/activate', [AdminUserController::class, 'activateAppMfa'])->middleware('permission:users.manage');
        Route::get('/admin/roles', [AdminRoleController::class, 'index'])->middleware('permission:users.manage');
        Route::get('/admin/permissions', [AdminRoleController::class, 'permissions'])->middleware('permission:users.manage');
        Route::post('/admin/roles', [AdminRoleController::class, 'store'])->middleware('permission:users.manage');
        Route::patch('/admin/roles/{role}', [AdminRoleController::class, 'update'])->middleware('permission:users.manage');
        Route::delete('/admin/roles/{role}', [AdminRoleController::class, 'destroy'])->middleware('permission:users.manage');

        Route::get('/settings/general', [SettingsController::class, 'getGeneral'])->middleware('permission:settings.manage');
        Route::patch('/settings/general', [SettingsController::class, 'updateGeneral'])->middleware('permission:settings.manage');
        Route::post('/settings/mail/test', [SettingsController::class, 'sendTestMail'])->middleware('permission:settings.manage');
        Route::post('/settings/auth/ldap/test', [SettingsController::class, 'testLdap'])->middleware('permission:settings.manage');
        Route::post('/settings/auth/ldap/sync', [SettingsController::class, 'syncLdap'])->middleware('permission:settings.manage');
        Route::get('/settings/auth/ldap/users', [SettingsController::class, 'ldapUsers'])->middleware('permission:settings.manage');
        Route::post('/settings/auth/ldap/deactivate', [SettingsController::class, 'deactivateLdap'])->middleware('permission:settings.manage');
        Route::get('/settings/auth/ldap/deactivate/{operationId}', [SettingsController::class, 'ldapDeactivationStatus'])->middleware('permission:settings.manage');
        Route::get('/settings/licenses', [SettingsController::class, 'getLicenses'])->middleware('permission:settings.licenses.read');
        Route::post('/settings/licenses/sync', [SettingsController::class, 'syncLicenses'])->middleware('permission:settings.licenses.sync');
        Route::post('/settings/licenses/activate', [SettingsController::class, 'activateLicense'])->middleware('permission:settings.licenses.sync');
        Route::get('/settings/licenses/core', [SettingsController::class, 'getCoreLicenseInventory'])->middleware('permission:settings.licenses.sync');
    });
});
