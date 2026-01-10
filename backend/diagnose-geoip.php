<?php

/**
 * GeoIP 诊断脚本
 * 用于检查邮件打开记录的国家信息填充情况
 * 
 * 使用方法: php diagnose-geoip.php
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Services\GeoIpService;

echo "=== GeoIP 诊断报告 ===\n\n";

// 1. 检查数据库中的记录情况
echo "【数据库记录统计】\n";

$total = DB::table('email_opens')->count();
$withIp = DB::table('email_opens')->whereNotNull('ip_address')->where('ip_address', '!=', '')->count();
$withCountry = DB::table('email_opens')->whereNotNull('country_code')->where('country_code', '!=', '')->count();
$withoutCountry = DB::table('email_opens')->whereNotNull('ip_address')->where('ip_address', '!=', '')->where(function($q) {
    $q->whereNull('country_code')->orWhere('country_code', '');
})->count();

echo "  - 总记录数: {$total}\n";
echo "  - 有IP地址: {$withIp}\n";
echo "  - 有国家信息: {$withCountry}\n";
echo "  - 有IP但无国家: {$withoutCountry}\n";
echo "\n";

// 2. 检查一些示例 IP
echo "【示例IP检查】\n";
$sampleRecords = DB::table('email_opens')
    ->whereNotNull('ip_address')
    ->where('ip_address', '!=', '')
    ->select('ip_address', 'country_code', 'country_name')
    ->limit(10)
    ->get();

foreach ($sampleRecords as $record) {
    $country = $record->country_code ?: '(空)';
    $name = $record->country_name ?: '(空)';
    echo "  IP: {$record->ip_address} => 国家: {$country} ({$name})\n";
}
echo "\n";

// 3. 测试 GeoIP 服务
echo "【GeoIP 服务测试】\n";
$geoService = app(GeoIpService::class);

// 测试一些公网 IP
$testIps = [
    '8.8.8.8',      // Google DNS (US)
    '114.114.114.114', // 中国 DNS
    '1.1.1.1',      // Cloudflare (US)
];

// 如果有实际的 IP，也测试一下
$realIp = DB::table('email_opens')
    ->whereNotNull('ip_address')
    ->where('ip_address', '!=', '')
    ->where('ip_address', 'not like', '192.168.%')
    ->where('ip_address', 'not like', '10.%')
    ->where('ip_address', 'not like', '127.%')
    ->value('ip_address');

if ($realIp) {
    $testIps[] = $realIp;
}

foreach ($testIps as $ip) {
    $result = $geoService->getCountryByIp($ip);
    $code = $result['country_code'] ?: '(空)';
    $name = $result['country_name'] ?: '(空)';
    echo "  测试 IP {$ip}: {$code} - {$name}\n";
}
echo "\n";

// 4. 检查常见的 IP 类型分布
echo "【IP类型分布】\n";
$privateCount = DB::table('email_opens')
    ->where(function($q) {
        $q->where('ip_address', 'like', '192.168.%')
          ->orWhere('ip_address', 'like', '10.%')
          ->orWhere('ip_address', 'like', '172.16.%')
          ->orWhere('ip_address', 'like', '172.17.%')
          ->orWhere('ip_address', 'like', '172.18.%')
          ->orWhere('ip_address', 'like', '172.19.%')
          ->orWhere('ip_address', 'like', '172.2_.%')
          ->orWhere('ip_address', 'like', '172.30.%')
          ->orWhere('ip_address', 'like', '172.31.%')
          ->orWhere('ip_address', 'like', '127.%')
          ->orWhere('ip_address', '::1');
    })
    ->count();

$publicCount = $withIp - $privateCount;

echo "  - 私有/本地 IP: {$privateCount} (这些无法解析国家)\n";
echo "  - 公网 IP: {$publicCount}\n";
echo "\n";

// 5. 给出建议
echo "【建议】\n";
if ($withoutCountry > 0) {
    echo "  有 {$withoutCountry} 条记录缺少国家信息，可以运行以下命令补充:\n";
    echo "  php artisan email-opens:backfill-countries\n";
}

if ($privateCount > 0) {
    echo "  注意: {$privateCount} 条记录是私有/本地IP，无法解析国家信息\n";
}

echo "\n=== 诊断完成 ===\n";

