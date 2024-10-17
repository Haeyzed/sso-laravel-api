<?php

use App\Enums\SocialProviderEnum;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BlockedIpController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FCMController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OAuthClientController;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // User routes
    Route::apiResource('users', UserController::class)->middleware('auth:api');
    Route::controller(UserController::class)->prefix('users')->middleware('auth:api')->group(function () {
        Route::post('/{sqid}/restore', 'restore');
        Route::post('/bulk-delete', 'bulkDelete');
        Route::post('/bulk-restore', 'bulkRestore');
        Route::delete('/{sqid}/force', 'forceDelete');
        Route::post('/import', 'import');
        Route::get('/export', 'export');
        Route::post('/{sqid}/block-ip/{ipAddress}', 'blockIp');
        Route::delete('/{sqid}/unblock-ip/{ipAddress}', 'unblockIp');
    });

    // Upload routes
    Route::apiResource('uploads', UploadController::class)->middleware('auth:api');
    Route::controller(UploadController::class)->prefix('uploads')->middleware('auth:api')->group(function () {
        Route::post('/{sqid}/restore', 'restore');
        Route::post('/bulk-delete', 'bulkDelete');
        Route::post('/bulk-restore', 'bulkRestore');
        Route::delete('/{sqid}/force', 'forceDelete');
        Route::post('/import', 'import');
        Route::get('/export', 'export');
    });

    // Auth routes
    Route::controller(AuthController::class)->prefix('auth')->group(function () {
        Route::post('/login', 'login')->name('login');
        Route::post('/register', 'register')->name('register');
        Route::post('/forgot-password', 'forgotPassword')->name('password.reset');
        Route::post('/reset-password', 'resetPassword');
        Route::post('/issue-passport-token', 'issuePassportToken');
        Route::get('/email/verify/{id}/{hash}', 'verify')
            ->middleware(['signed', 'throttle:6,1'])
            ->name('verification.verify');
        Route::post('/email/resend', 'resendVerification')
            ->middleware(['auth:api', 'throttle:6,1'])
            ->name('verification.send');
        Route::get('/{provider}', 'redirectToProvider')
            ->where('provider', implode('|', SocialProviderEnum::values()));
        Route::get('/{provider}/callback', 'handleProviderCallback')
            ->where('provider', implode('|', SocialProviderEnum::values()));
        Route::middleware('auth:api')->group(function () {
            Route::post('/unlock', 'unlock');
            Route::put('/change-password', 'changePassword');
            Route::get('/profile', 'profile');
            Route::put('/profile', 'updateProfile');
            Route::post('/logout', 'logout')->name('logout');
            Route::post('/refresh', 'refresh');
        });
    });

    // OAuth Client routes
    Route::apiResource('oauth-clients', OAuthClientController::class)->middleware('auth:api');
    Route::controller(OAuthClientController::class)->prefix('oauth-clients')->middleware('auth:api')->group(function () {
        Route::post('/{id}/secret', 'showSecret');
        Route::get('/tokens', 'listTokens');
        Route::delete('/tokens/{tokenId}', 'revokeToken');
        Route::get('/all-tokens', 'listAllTokens');
        Route::delete('/tokens/{id}', 'deleteToken');
    });

    // Blocked IP routes
    Route::apiResource('blocked-ips', BlockedIpController::class)->middleware('auth:api');

    // Dashboard routes
    Route::get('/dashboard/metrics', [DashboardController::class, 'getMetrics'])
        ->middleware('auth:api')
        ->name('dashboard.metrics');

    Route::post('/fcm/send-to-device', [FCMController::class, 'sendToDevice']);
    Route::post('/fcm/send-to-devices', [FCMController::class, 'sendToDevices']);

    Route::controller(NotificationController::class)->prefix('notifications')->middleware('auth:api')->group(function () {
        Route::get('/all', 'index');
        Route::patch('/{id}/read', 'markAsRead');
        Route::post('/mark-all-read', 'markAllAsRead');
        Route::delete('/{id}', 'destroy');
        Route::get('/unread-count', 'unreadCount');
    });
});
