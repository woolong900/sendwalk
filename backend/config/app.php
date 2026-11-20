<?php

use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;

return [

    'name' => env('APP_NAME', 'SendWalk'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
    // 退订链接专用域名（与主域名隔离，避免租户违规发信导致主域名被投诉/拉黑）
    'unsubscribe_url' => env('UNSUBSCRIBE_URL', env('FRONTEND_URL', 'http://localhost:5173')),
    'timezone' => env('APP_TIMEZONE', 'Asia/Shanghai'),
    'locale' => env('APP_LOCALE', 'zh_CN'),
    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),
    'faker_locale' => env('APP_FAKER_LOCALE', 'zh_CN'),
    'cipher' => 'AES-256-CBC',
    'key' => env('APP_KEY'),
    'previous_keys' => [
        ...array_filter(
            explode(',', env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];

