#!/usr/bin/env php
<?php

/**
 * 活动列表性能测试脚本
 * 
 * 用法：
 * php test-campaign-list-performance.php
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Campaign;
use Illuminate\Support\Facades\DB;

echo "\n=== 活动列表性能测试 ===\n\n";

// 获取第一个用户的 ID
$userId = DB::table('users')->first()->id ?? 1;

$totalCampaigns = Campaign::where('user_id', $userId)->count();
echo "测试用户 ID: {$userId}\n";
echo "活动数量: {$totalCampaigns}\n\n";

if ($totalCampaigns === 0) {
    echo "⚠️  没有活动数据，无法进行性能测试\n";
    echo "提示：请先创建一些活动，然后再运行此测试\n\n";
    exit(0);
}

// 启用查询日志
DB::enableQueryLog();

// 测试优化后的查询
echo "--- 测试优化后的查询 ---\n";
$start = microtime(true);

$campaigns = Campaign::where('user_id', $userId)
    ->with(['lists:id,name', 'smtpServer:id,name,type'])
    ->select([
        'id', 'user_id', 'list_id', 'smtp_server_id', 'name', 'subject', 
        'status', 'scheduled_at', 'sent_at', 'created_at', 'updated_at',
        'total_recipients', 'total_sent', 'total_delivered', 'total_opened', 
        'total_clicked', 'total_bounced', 'total_complained', 'total_unsubscribed'
    ])
    ->latest()
    ->paginate(15);

// 手动添加 list_ids
$campaigns->getCollection()->transform(function ($campaign) {
    $campaign->list_ids = $campaign->lists->pluck('id')->toArray();
    return $campaign;
});

// 序列化为 JSON（模拟 API 响应）
$json = json_encode([
    'data' => $campaigns->items(),
    'meta' => [
        'current_page' => $campaigns->currentPage(),
        'last_page' => $campaigns->lastPage(),
        'per_page' => $campaigns->perPage(),
        'total' => $campaigns->total(),
    ],
]);

$optimizedTime = (microtime(true) - $start) * 1000;
$optimizedQueries = DB::getQueryLog();
$optimizedQueryCount = count($optimizedQueries);
$optimizedJsonSize = strlen($json);

echo "执行时间: " . round($optimizedTime, 2) . " ms\n";
echo "SQL 查询数: {$optimizedQueryCount}\n";
echo "JSON 大小: " . round($optimizedJsonSize / 1024, 2) . " KB\n";

// 显示查询详情
echo "\nSQL 查询列表:\n";
foreach ($optimizedQueries as $index => $query) {
    $queryTime = round($query['time'], 2);
    $sql = $query['query'];
    // 截断过长的 SQL
    if (strlen($sql) > 100) {
        $sql = substr($sql, 0, 100) . '...';
    }
    echo "  " . ($index + 1) . ". [{$queryTime}ms] {$sql}\n";
}

echo "\n--- 性能摘要 ---\n";
echo "✅ 总执行时间: " . round($optimizedTime, 2) . " ms\n";
echo "✅ SQL 查询数: {$optimizedQueryCount}\n";
echo "✅ 返回数据大小: " . round($optimizedJsonSize / 1024, 2) . " KB\n";
if ($campaigns->count() > 0) {
    echo "✅ 平均每条活动: " . round($optimizedTime / $campaigns->count(), 2) . " ms\n\n";
} else {
    echo "⚠️  没有活动数据，无法计算平均时间\n\n";
}

// 清除查询日志
DB::flushQueryLog();

// 测试未优化的查询（对比）
echo "\n--- 测试未优化的查询（对比） ---\n";
$start = microtime(true);

$campaignsOld = Campaign::where('user_id', $userId)
    ->with(['list', 'lists', 'smtpServer'])
    ->latest()
    ->paginate(15);

// 序列化为 JSON
$jsonOld = json_encode([
    'data' => $campaignsOld->items(),
    'meta' => [
        'current_page' => $campaignsOld->currentPage(),
        'last_page' => $campaignsOld->lastPage(),
        'per_page' => $campaignsOld->perPage(),
        'total' => $campaignsOld->total(),
    ],
]);

$oldTime = (microtime(true) - $start) * 1000;
$oldQueries = DB::getQueryLog();
$oldQueryCount = count($oldQueries);
$oldJsonSize = strlen($jsonOld);

echo "执行时间: " . round($oldTime, 2) . " ms\n";
echo "SQL 查询数: {$oldQueryCount}\n";
echo "JSON 大小: " . round($oldJsonSize / 1024, 2) . " KB\n";

echo "\n--- 性能对比 ---\n";
$timeImprovement = (($oldTime - $optimizedTime) / $oldTime) * 100;
$queryImprovement = (($oldQueryCount - $optimizedQueryCount) / $oldQueryCount) * 100;
$sizeReduction = (($oldJsonSize - $optimizedJsonSize) / $oldJsonSize) * 100;

echo "⚡ 执行时间提升: " . round($timeImprovement, 1) . "%\n";
echo "⚡ SQL 查询减少: " . round($queryImprovement, 1) . "%\n";
echo "⚡ 响应大小减少: " . round($sizeReduction, 1) . "%\n";

if ($timeImprovement > 0) {
    echo "\n✅ 优化效果显著！\n";
} else {
    echo "\n⚠️  优化效果不明显，可能数据量较小。\n";
}

echo "\n=== 测试完成 ===\n\n";

