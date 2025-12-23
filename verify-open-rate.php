#!/usr/bin/env php
<?php

/**
 * 验证仪表盘打开率计算是否正确
 * 
 * 使用方法：
 * cd backend
 * php ../verify-open-rate.php
 */

require __DIR__ . '/backend/vendor/autoload.php';

$app = require_once __DIR__ . '/backend/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Campaign;
use App\Models\User;

echo "\n";
echo "========================================\n";
echo "仪表盘打开率计算验证\n";
echo "========================================\n";
echo "\n";

// 获取所有用户
$users = User::all();

if ($users->isEmpty()) {
    echo "❌ 未找到任何用户\n";
    exit(1);
}

echo "找到 {$users->count()} 个用户\n\n";

foreach ($users as $user) {
    echo "用户: {$user->name} (ID: {$user->id})\n";
    echo "----------------------------------------\n";
    
    $campaigns = Campaign::where('user_id', $user->id)->get();
    
    if ($campaigns->isEmpty()) {
        echo "  该用户暂无活动\n\n";
        continue;
    }
    
    echo "  活动总数: {$campaigns->count()}\n\n";
    
    // 方法 1: 错误的算法（旧方法）
    $campaignsWithDelivery = $campaigns->where('total_delivered', '>', 0);
    $wrongAvgOpenRate = $campaignsWithDelivery->count() > 0 
        ? $campaignsWithDelivery->avg('open_rate') 
        : 0;
    
    // 方法 2: 正确的算法（新方法）
    $totalDelivered = Campaign::where('user_id', $user->id)->sum('total_delivered');
    $totalOpened = Campaign::where('user_id', $user->id)->sum('total_opened');
    $correctAvgOpenRate = $totalDelivered > 0 
        ? ($totalOpened / $totalDelivered) * 100 
        : 0;
    
    // 显示详细数据
    echo "  详细统计:\n";
    echo "  ├─ 总送达数: " . number_format($totalDelivered) . "\n";
    echo "  ├─ 总打开数: " . number_format($totalOpened) . "\n";
    echo "  └─ 总发送数: " . number_format(Campaign::where('user_id', $user->id)->sum('total_sent')) . "\n";
    echo "\n";
    
    // 显示活动明细
    echo "  活动明细:\n";
    foreach ($campaigns as $campaign) {
        if ($campaign->total_delivered > 0) {
            echo "  ├─ {$campaign->name}\n";
            echo "  │  ├─ 送达: " . number_format($campaign->total_delivered) . "\n";
            echo "  │  ├─ 打开: " . number_format($campaign->total_opened) . "\n";
            echo "  │  └─ 打开率: {$campaign->open_rate}%\n";
        }
    }
    echo "\n";
    
    // 对比结果
    echo "  计算结果对比:\n";
    echo "  ├─ ❌ 错误算法（旧）: " . round($wrongAvgOpenRate, 2) . "%\n";
    echo "  └─ ✅ 正确算法（新）: " . round($correctAvgOpenRate, 2) . "%\n";
    
    // 计算差异
    $difference = abs($wrongAvgOpenRate - $correctAvgOpenRate);
    if ($difference > 0.01) {
        $percentage = $wrongAvgOpenRate > 0 ? ($difference / $wrongAvgOpenRate * 100) : 0;
        echo "\n";
        echo "  ⚠️  差异: " . round($difference, 2) . "% (相差 " . round($percentage, 1) . "%)\n";
        
        if ($wrongAvgOpenRate > $correctAvgOpenRate) {
            echo "  旧算法高估了打开率\n";
        } else {
            echo "  旧算法低估了打开率\n";
        }
    } else {
        echo "\n  ✅ 两种算法结果一致（数据特殊情况）\n";
    }
    
    echo "\n";
}

echo "========================================\n";
echo "验证完成\n";
echo "========================================\n";
echo "\n";

echo "说明:\n";
echo "- 错误算法: 计算每个活动打开率的平均值\n";
echo "- 正确算法: 总打开数 / 总送达数\n";
echo "- 如果有差异，说明修复是必要的\n";
echo "\n";

