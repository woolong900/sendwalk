<?php

/**
 * 修复活动状态（进度100%但状态仍是sending）
 * 
 * 使用方法：
 * php fix-campaign-status.php <campaign_id>
 * php fix-campaign-status.php --all  # 检查并修复所有卡住的活动
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Campaign;
use Illuminate\Support\Facades\DB;

echo "========================================\n";
echo "  修复活动状态\n";
echo "========================================\n\n";

if ($argc < 2) {
    echo "使用方法:\n";
    echo "  php fix-campaign-status.php <campaign_id>  # 修复指定活动\n";
    echo "  php fix-campaign-status.php --all          # 检查并修复所有卡住的活动\n";
    exit(1);
}

$target = $argv[1];

if ($target === '--all') {
    // 查找所有状态为 sending 的活动
    $campaigns = Campaign::where('status', 'sending')->get();
    
    if ($campaigns->isEmpty()) {
        echo "✅ 没有状态为 'sending' 的活动\n";
        exit(0);
    }
    
    echo "找到 {$campaigns->count()} 个 'sending' 状态的活动\n\n";
    
    foreach ($campaigns as $campaign) {
        checkAndFixCampaign($campaign);
        echo str_repeat("-", 60) . "\n";
    }
} else {
    $campaign = Campaign::find($target);
    
    if (!$campaign) {
        echo "❌ 活动 #{$target} 不存在\n";
        exit(1);
    }
    
    checkAndFixCampaign($campaign);
}

function checkAndFixCampaign(Campaign $campaign): void
{
    echo "📋 活动 #{$campaign->id}: {$campaign->name}\n";
    echo "   状态: {$campaign->status}\n";
    
    $queueName = "campaign_{$campaign->id}";
    
    // 检查队列任务
    $pendingJobs = DB::table('jobs')
        ->where('queue', $queueName)
        ->whereNull('reserved_at')
        ->count();
    
    $reservedJobs = DB::table('jobs')
        ->where('queue', $queueName)
        ->whereNotNull('reserved_at')
        ->count();
    
    $totalJobs = $pendingJobs + $reservedJobs;
    
    echo "   队列任务: {$totalJobs} (待处理: {$pendingJobs}, 处理中: {$reservedJobs})\n";
    
    // 检查 campaign_sends 状态
    $sendStats = DB::table('campaign_sends')
        ->where('campaign_id', $campaign->id)
        ->select('status', DB::raw('COUNT(*) as count'))
        ->groupBy('status')
        ->pluck('count', 'status')
        ->toArray();
    
    $sentCount = $sendStats['sent'] ?? 0;
    $failedCount = $sendStats['failed'] ?? 0;
    $pendingCount = $sendStats['pending'] ?? 0;
    $totalProcessed = $sentCount + $failedCount;
    
    echo "   发送记录: sent={$sentCount}, failed={$failedCount}, pending={$pendingCount}\n";
    echo "   总收件人: {$campaign->total_recipients}\n";
    echo "   已处理数: {$totalProcessed}\n";
    
    // 计算进度
    if ($campaign->total_recipients > 0) {
        $progress = round($totalProcessed / $campaign->total_recipients * 100, 2);
        echo "   进度: {$progress}%\n";
    }
    
    // 判断是否需要修复
    $needsFix = false;
    $reason = '';
    
    if ($campaign->status === 'sending') {
        // 情况1：队列为空且所有记录都已处理
        if ($totalJobs === 0 && $pendingCount === 0 && $totalProcessed >= $campaign->total_recipients) {
            $needsFix = true;
            $reason = '队列为空且所有邮件已处理完成';
        }
        // 情况2：队列为空，有pending记录但数量与total_recipients匹配
        elseif ($totalJobs === 0 && $totalProcessed >= $campaign->total_recipients) {
            $needsFix = true;
            $reason = '队列为空，已处理数达到总收件人数';
        }
        // 情况3：stuck reserved jobs - 超过1小时的reserved任务
        elseif ($reservedJobs > 0 && $pendingJobs === 0) {
            $stuckJobs = DB::table('jobs')
                ->where('queue', $queueName)
                ->whereNotNull('reserved_at')
                ->where('reserved_at', '<', time() - 3600) // 超过1小时
                ->count();
            
            if ($stuckJobs > 0) {
                echo "   ⚠️  发现 {$stuckJobs} 个卡住的任务（reserved超过1小时）\n";
                // 释放卡住的任务
                DB::table('jobs')
                    ->where('queue', $queueName)
                    ->whereNotNull('reserved_at')
                    ->where('reserved_at', '<', time() - 3600)
                    ->update(['reserved_at' => null]);
                echo "   ✅ 已释放卡住的任务\n";
            }
        }
    }
    
    if ($needsFix) {
        echo "\n   🔧 需要修复: {$reason}\n";
        
        // 更新状态为 sent
        $campaign->update([
            'status' => 'sent',
            'sent_at' => now(),
        ]);
        
        // 同步 total_sent 统计
        if ($campaign->total_sent != $totalProcessed) {
            $campaign->update(['total_sent' => $totalProcessed]);
        }
        
        // 同步 total_delivered 统计
        if ($campaign->total_delivered != $sentCount) {
            $campaign->update(['total_delivered' => $sentCount]);
        }
        
        echo "   ✅ 活动状态已更新为 'sent'\n";
        echo "   ✅ 统计数据已同步 (total_sent={$totalProcessed}, total_delivered={$sentCount})\n";
    } elseif ($campaign->status === 'sending' && $totalJobs > 0) {
        echo "\n   ℹ️  活动正在正常处理中，无需修复\n";
    } elseif ($campaign->status === 'sending' && $totalJobs === 0 && $pendingCount > 0) {
        echo "\n   ⚠️  队列为空但有 pending 记录，可能需要重建任务\n";
        echo "   💡 建议运行: php fix-stuck-campaign.php {$campaign->id}\n";
    } elseif ($campaign->status !== 'sending') {
        echo "\n   ℹ️  活动状态为 '{$campaign->status}'，无需修复\n";
    }
}

echo "\n========================================\n";
echo "  完成\n";
echo "========================================\n";

