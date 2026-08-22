<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ListController;
use App\Http\Controllers\Api\SubscriberController;
use App\Http\Controllers\Api\CampaignController;
use App\Http\Controllers\Api\CampaignAnalyticsController;
use App\Http\Controllers\Api\AutomationController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\SmtpServerController;
use App\Http\Controllers\Api\TrackingController;
use App\Http\Controllers\Api\SendMonitorController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\TemplateController;
use App\Http\Controllers\UnsubscribeController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\UserManagementController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Health check (public)
Route::get('health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
    ]);
});

// Public routes
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
});

// Unsubscribe routes (public)
Route::get('unsubscribe', [UnsubscribeController::class, 'show']);
Route::post('unsubscribe', [UnsubscribeController::class, 'unsubscribe']);

// Protected routes
Route::middleware(['auth:sanctum', 'active.user'])->group(function () {
    // Auth
    Route::prefix('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('user', [AuthController::class, 'user']);
    });

    // Dashboard
    Route::get('dashboard/stats', [DashboardController::class, 'stats']);
    Route::get('dashboard/today-domain-stats', [DashboardController::class, 'todayDomainStats']);
    Route::post('dashboard/scheduler/start', [DashboardController::class, 'startScheduler']);
    Route::post('dashboard/scheduler/stop', [DashboardController::class, 'stopScheduler']);
    Route::post('dashboard/queue/clear', [DashboardController::class, 'clearQueue']);

    // User Management
    Route::get('users', [UserManagementController::class, 'index']);
    Route::put('users/{user}', [UserManagementController::class, 'update']);

    // Lists
    Route::apiResource('lists', ListController::class);
    Route::post('lists/{list}/import', [ListController::class, 'import']);
    Route::get('lists/{list}/export', [ListController::class, 'export']);
    Route::post('lists/preview-auto', [ListController::class, 'previewAutoList']);

    // Subscribers
    Route::apiResource('subscribers', SubscriberController::class);
    Route::post('subscribers/bulk-import', [SubscriberController::class, 'bulkImport']);
    Route::get('subscribers/import-progress/{importId}', [SubscriberController::class, 'getImportProgress']);
    Route::delete('subscribers/bulk-delete', [SubscriberController::class, 'bulkDelete']);
    
    // Blacklist
    Route::get('blacklist', [\App\Http\Controllers\Api\BlacklistController::class, 'index']);
    Route::post('blacklist', [\App\Http\Controllers\Api\BlacklistController::class, 'store']);
    Route::post('blacklist/batch-upload', [\App\Http\Controllers\Api\BlacklistController::class, 'batchUpload']);
    Route::get('blacklist/import-progress/{importId}', [\App\Http\Controllers\Api\BlacklistController::class, 'getImportProgress']);
    Route::post('blacklist/check', [\App\Http\Controllers\Api\BlacklistController::class, 'check']);
    Route::delete('blacklist/{blacklist}', [\App\Http\Controllers\Api\BlacklistController::class, 'destroy']);
    Route::post('blacklist/batch-delete', [\App\Http\Controllers\Api\BlacklistController::class, 'batchDestroy']);

    // Domain Blacklist
    Route::get('domain-blacklist', [\App\Http\Controllers\Api\DomainBlacklistController::class, 'index']);
    Route::post('domain-blacklist', [\App\Http\Controllers\Api\DomainBlacklistController::class, 'store']);
    Route::post('domain-blacklist/batch', [\App\Http\Controllers\Api\DomainBlacklistController::class, 'batchStore']);
    Route::post('domain-blacklist/check', [\App\Http\Controllers\Api\DomainBlacklistController::class, 'check']);
    Route::delete('domain-blacklist/{domainBlacklist}', [\App\Http\Controllers\Api\DomainBlacklistController::class, 'destroy']);
    Route::post('domain-blacklist/batch-delete', [\App\Http\Controllers\Api\DomainBlacklistController::class, 'batchDestroy']);

    // Campaigns
    Route::apiResource('campaigns', CampaignController::class);
    Route::post('campaigns/{campaign}/send', [CampaignController::class, 'send']);
    Route::post('campaigns/{campaign}/schedule', [CampaignController::class, 'schedule']);
    Route::post('campaigns/{campaign}/cancel-schedule', [CampaignController::class, 'cancelSchedule']);
    Route::post('campaigns/{campaign}/cancel', [CampaignController::class, 'cancel']);
    Route::post('campaigns/{campaign}/duplicate', [CampaignController::class, 'duplicate']);
    Route::post('campaigns/{campaign}/pause', [CampaignController::class, 'pause']);
    Route::post('campaigns/{campaign}/resume', [CampaignController::class, 'resume']);
    Route::get('campaigns/{campaign}/preview-token', [CampaignController::class, 'getPreviewToken']);
    
    // Campaign Analytics
    Route::get('campaigns/{campaign}/send-logs', [CampaignAnalyticsController::class, 'getSendLogs']);
    Route::get('campaigns/{campaign}/email-opens', [CampaignAnalyticsController::class, 'getEmailOpens']);
    Route::get('campaigns/{campaign}/email-open-details', [CampaignAnalyticsController::class, 'getEmailOpenDetails']);
    Route::get('campaigns/{campaign}/open-stats', [CampaignAnalyticsController::class, 'getOpenStats']);
    Route::get('campaigns/{campaign}/abuse-reports', [CampaignAnalyticsController::class, 'getAbuseReports']);
    Route::get('campaigns/{campaign}/bounces', [CampaignAnalyticsController::class, 'getBounces']);
    Route::get('campaigns/{campaign}/unsubscribes', [CampaignAnalyticsController::class, 'getUnsubscribes']);
    Route::get('campaigns/{campaign}/deliveries', [CampaignAnalyticsController::class, 'getDeliveries']);

    // Automations
    Route::apiResource('automations', AutomationController::class);
    Route::post('automations/{automation}/activate', [AutomationController::class, 'activate']);
    Route::post('automations/{automation}/deactivate', [AutomationController::class, 'deactivate']);

    // Analytics
    Route::get('analytics/overview', [AnalyticsController::class, 'overview']);
    Route::get('analytics/campaigns/{campaign}', [AnalyticsController::class, 'campaign']);

    // SMTP Servers
    Route::apiResource('smtp-servers', SmtpServerController::class);
    Route::post('smtp-servers/{smtpServer}/test', [SmtpServerController::class, 'test']);
    Route::post('smtp-servers/{smtpServer}/duplicate', [SmtpServerController::class, 'duplicate']);
    Route::get('smtp-servers/{smtpServer}/rate-limit-status', [SmtpServerController::class, 'getRateLimitStatus']);

    // 域名预热配置
    Route::get('smtp-servers/{smtpServer}/warmups', [\App\Http\Controllers\Api\DomainWarmupController::class, 'index']);
    Route::put('smtp-servers/{smtpServer}/warmups/{domain}', [\App\Http\Controllers\Api\DomainWarmupController::class, 'update'])
        ->where('domain', '[a-zA-Z0-9.-]+');

    // Tags
    Route::apiResource('tags', TagController::class);
    Route::post('tags/{tag}/test', [TagController::class, 'test']);

    // Templates
    Route::get('templates/categories', [TemplateController::class, 'categories']);
    Route::apiResource('templates', TemplateController::class);
    Route::post('templates/{template}/duplicate', [TemplateController::class, 'duplicate']);
    Route::get('templates/{template}/preview', [TemplateController::class, 'preview']);

    // Send Monitor
    Route::prefix('monitor')->group(function () {
        Route::get('logs', [SendMonitorController::class, 'getLogs']);
        Route::get('logs/paginated', [SendMonitorController::class, 'getPaginatedLogs']);
        Route::get('stats', [SendMonitorController::class, 'getStats']);
        Route::get('queue-status', [SendMonitorController::class, 'getQueueStatus']);
        Route::delete('logs', [SendMonitorController::class, 'clearLogs']);
    });

    // Orders
    Route::prefix('orders')->group(function () {
        Route::get('/', [OrderController::class, 'index']);
        Route::get('/stats', [OrderController::class, 'stats']);
        Route::get('/analytics', [OrderController::class, 'analytics']);
        Route::get('/{order}', [OrderController::class, 'show']);
        Route::post('/sync', [OrderController::class, 'sync']);
    });
});

// Tracking routes (no auth required)
Route::get('track/open/{campaignId}/{subscriberId}', [TrackingController::class, 'trackOpen']);
Route::get('track/click/{campaignId}/{linkId}/{subscriberId}', [TrackingController::class, 'trackClick']);
Route::get('unsubscribe/{campaignId}/{subscriberId}', [TrackingController::class, 'unsubscribe']);

// Abuse and blocking routes (no auth required)
Route::get('abuse/report/{campaignId}/{subscriberId}', [\App\Http\Controllers\Api\AbuseController::class, 'reportAbuse']);
Route::post('abuse/report/{campaignId}/{subscriberId}', [\App\Http\Controllers\Api\AbuseController::class, 'reportAbuse']);
Route::get('abuse/block', [\App\Http\Controllers\Api\AbuseController::class, 'blockAddress']);
Route::post('abuse/block', [\App\Http\Controllers\Api\AbuseController::class, 'blockAddress']);
