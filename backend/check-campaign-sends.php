<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$campaignId = $argv[1] ?? 20;

echo "=== 检查活动 {$campaignId} 的发送记录数量 ===\n\n";

try {
    $campaign = \App\Models\Campaign::find($campaignId);
    
    if (!$campaign) {
        echo "❌ 活动不存在\n";
        exit(1);
    }
    
    echo "活动信息:\n";
    echo "  ID: {$campaign->id}\n";
    echo "  名称: {$campaign->name}\n";
    echo "  状态: {$campaign->status}\n";
    echo "  总收件人: {$campaign->total_recipients}\n";
    echo "  已发送: {$campaign->total_sent}\n\n";
    
    // 统计发送记录
    $sendsCount = \DB::table('campaign_sends')
        ->where('campaign_id', $campaignId)
        ->count();
    
    echo "campaign_sends 表记录数: {$sendsCount}\n";
    
    if ($sendsCount > 0) {
        // 估算内存占用
        $estimatedMemoryPerRecord = 1024; // 每条记录约 1KB
        $estimatedMemoryMB = ($sendsCount * $estimatedMemoryPerRecord) / 1024 / 1024;
        
        echo "估算加载所有记录需要内存: " . round($estimatedMemoryMB, 2) . " MB\n";
        
        if ($estimatedMemoryMB > 100) {
            echo "⚠️  警告: 内存占用过大！不应直接加载所有 sends 记录\n";
        }
    }
    
    // 检查队列任务
    $queueName = 'campaign_' . $campaignId;
    $queueCount = \DB::table('jobs')
        ->where('queue', $queueName)
        ->count();
    
    echo "\n队列任务数: {$queueCount}\n";
    
} catch (\Exception $e) {
    echo "错误: {$e->getMessage()}\n";
    exit(1);
}

