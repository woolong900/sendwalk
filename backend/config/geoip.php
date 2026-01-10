<?php

return [
    /*
    |--------------------------------------------------------------------------
    | MaxMind GeoLite2 配置
    |--------------------------------------------------------------------------
    |
    | GeoLite2 是 MaxMind 提供的免费 IP 地理位置数据库
    | 需要注册 MaxMind 账号获取 License Key: https://www.maxmind.com/en/geolite2/signup
    |
    */

    // MaxMind License Key（用于下载数据库）
    'license_key' => env('MAXMIND_LICENSE_KEY', ''),

    // 数据库文件存储路径
    'database_path' => storage_path('app/geoip/GeoLite2-Country.mmdb'),

    // 是否启用本地数据库（如果禁用或数据库不存在，会回退到在线 API）
    'use_local_database' => env('GEOIP_USE_LOCAL', true),

    // 在线 API 配置（当本地数据库不可用时使用）
    'fallback_api' => [
        'enabled' => true,
        'url' => 'http://ip-api.com/json/',
    ],

    // 缓存配置
    'cache' => [
        'enabled' => true,
        'ttl' => 60 * 60 * 24 * 7, // 7 天
        'prefix' => 'geo_ip:',
    ],
];

