<?php

/**
 * 实时监控活动处理进度
 * 
 * 使用方法：
 * php monitor-campaign-progress.php <campaign_id> [refresh_seconds]
 * 
 * 示例：
 * php monitor-campaign-progress.php 18 5
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Campaign;
use Illuminate\Support\Facades\DB;

if ($argc < 2) {
    echo "使用方法: php monitor-campaign-progress.php <campaign_id> [refresh_seconds]\n";
    echo "示例: php monitor-campaign-progress.php 18 5\n";
    exit(1);
}

$campaignId = $argv[1];
$refreshSeconds = isset($argv[2]) ? (int)$argv[2] : 5;

$campaign = Campaign::find($campaignId);

if (!$campaign) {
    echo "❌ 活动不存在\n";
    exit(1);
}

$queueName = "campaign_{$campaignId}";
$startTime = time();
$lastJobCount = null;
$lastCheckTime = null;

echo "正在监控活动 #{$campaignId}: {$campaign->name}\n";
echo "按 Ctrl+C 停止监控\n";
echo str_repeat("=", 80) . "\n\n";

while (true) {
    $currentTime = time();
    $elapsed = $currentTime - $startTime;
    
    // 清屏（适用于大多数终端）
    echo "\033[2J\033[H";
    
    // 刷新活动数据
    $campaign->refresh();
    
    // 队列状态
    $pendingJobs = DB::table('jobs')
        ->where('queue', $queueName)
        ->whereNull('reserved_at')
        ->count();
    
    $reservedJobs = DB::table('jobs')
        ->where('queue', $queueName)
        ->whereNotNull('reserved_at')
        ->count();
    
    $totalJobs = $pendingJobs + $reservedJobs;
    
    // Worker 数量
    $workerCount = (int)trim(shell_exec("ps aux | grep 'campaign:process-queue {$campaignId}' | grep -v grep | wc -l"));
    
    // 发送记录
    $sentCount = DB::table('campaign_sends')
        ->where('campaign_id', $campaignId)
        ->where('status', 'sent')
        ->count();
    
    $failedCount = DB::table('campaign_sends')
        ->where('campaign_id', $campaignId)
        ->where('status', 'failed')
        ->count();
    
    $totalProcessed = $sentCount + $failedCount;
    $totalRecipients = $campaign->total_recipients;
    $remaining = $totalRecipients - $totalProcessed;
    
    // 计算速度
    $speed = 0;
    $eta = 'N/A';
    
    if ($lastJobCount !== null && $lastCheckTime !== null) {
        $timeDiff = $currentTime - $lastCheckTime;
        $jobDiff = $lastJobCount - $totalJobs;
        
        if ($timeDiff > 0 && $jobDiff > 0) {
            $speed = round($jobDiff / $timeDiff, 2);
            
            if ($speed > 0) {
                $etaSeconds = $totalJobs / $speed;
                $etaMinutes = floor($etaSeconds / 60);
                $etaSeconds = $etaSeconds % 60;
                $eta = sprintf("%d分%d秒", $etaMinutes, $etaSeconds);
            }
        }
    }
    
    $lastJobCount = $totalJobs;
    $lastCheckTime = $currentTime;
    
    // 计算进度
    $progress = $totalRecipients > 0 ? round($totalProcessed / $totalRecipients * 100, 2) : 0;
    $progressBar = str_repeat('█', floor($progress / 2)) . str_repeat('░', 50 - floor($progress / 2));
    
    // 显示信息
    echo "📊 活动处理监控 - " . date('Y-m-d H:i:s') . "\n";
    echo str_repeat("=", 80) . "\n\n";
    
    echo "📋 活动信息:\n";
    echo "  ID: {$campaign->id}\n";
    echo "  名称: {$campaign->name}\n";
    echo "  状态: {$campaign->status}\n";
    echo "\n";
    
    echo "📈 处理进度:\n";
    echo "  总收件人: {$totalRecipients}\n";
    echo "  已处理: {$totalProcessed} ({$progress}%)\n";
    echo "  - 成功: {$sentCount}\n";
    echo "  - 失败: {$failedCount}\n";
    echo "  剩余: {$remaining}\n";
    echo "  [{$progressBar}] {$progress}%\n";
    echo "\n";
    
    echo "🔄 队列状态:\n";
    echo "  队列名称: {$queueName}\n";
    echo "  待处理: {$pendingJobs}\n";
    echo "  处理中: {$reservedJobs}\n";
    echo "  总任务: {$totalJobs}\n";
    echo "\n";
    
    echo "⚡ 处理速度:\n";
    echo "  Workers: {$workerCount}\n";
    echo "  当前速度: {$speed} 任务/秒\n";
    echo "  预计剩余时间: {$eta}\n";
    echo "\n";
    
    echo "⏱️  运行时间: " . gmdate("H:i:s", $elapsed) . "\n";
    echo "\n";
    
    if ($totalJobs === 0 && $totalProcessed >= $totalRecipients) {
        echo "✅ 活动处理完成！\n";
        break;
    }
    
    if ($workerCount === 0 && $totalJobs > 0) {
        echo "⚠️  警告: 队列有任务但没有 worker 在运行！\n";
        echo "💡 启动 workers: php boost-campaign-workers.php {$campaignId} 10\n";
    }
    
    echo "下次刷新: {$refreshSeconds} 秒后...  (按 Ctrl+C 退出)\n";
    
    sleep($refreshSeconds);
}

