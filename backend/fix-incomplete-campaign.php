#!/usr/bin/env php
<?php

/**
 * 修复未完全发送的活动
 * 
 * 问题描述：
 * 由于 ProcessScheduledCampaigns 中 total_recipients 设置错误，
 * 导致活动在只发送少量邮件后就被标记为"已发送"，队列中的剩余任务被删除。
 * 
 * 此脚本用于：
 * 1. 重置活动状态为 'sending'
 * 2. 重新计算正确的 total_recipients
 * 3. 为未发送的订阅者重新创建任务
 * 
 * 用法：
 * php fix-incomplete-campaign.php <campaign_id>
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Campaign;
use App\Models\Subscriber;
use App\Models\CampaignSend;
use App\Services\QueueDistributionService;
use Illuminate\Support\Facades\DB;

// 检查参数
if ($argc < 2) {
    echo "❌ 错误：缺少 campaign_id 参数\n";
    echo "用法：php fix-incomplete-campaign.php <campaign_id>\n";
    exit(1);
}

$campaignId = $argv[1];

echo "\n=== 修复未完全发送的活动 ===\n\n";
echo "活动 ID: {$campaignId}\n\n";

// 加载活动
$campaign = Campaign::with(['lists', 'smtpServer'])->find($campaignId);

if (!$campaign) {
    echo "❌ 错误：活动不存在\n";
    exit(1);
}

echo "活动名称: {$campaign->name}\n";
echo "当前状态: {$campaign->status}\n";
echo "SMTP 服务器: {$campaign->smtpServer->name}\n";
echo "关联列表: " . $campaign->lists->pluck('name')->join(', ') . "\n\n";

echo "--- 当前统计 ---\n";
echo "total_recipients: {$campaign->total_recipients}\n";
echo "total_sent: {$campaign->total_sent}\n";
echo "total_delivered: {$campaign->total_delivered}\n\n";

// 统计实际情况
$actualSent = CampaignSend::where('campaign_id', $campaignId)->count();
$actualProcessed = CampaignSend::where('campaign_id', $campaignId)
    ->whereIn('status', ['sent', 'failed'])
    ->count();
$queueJobs = DB::table('jobs')->where('queue', "campaign_{$campaignId}")->count();

echo "--- 实际情况 ---\n";
echo "campaign_sends 记录: {$actualSent}\n";
echo "已处理任务: {$actualProcessed}\n";
echo "队列中任务: {$queueJobs}\n\n";

// 计算应该发送的总数
$listIds = $campaign->lists->pluck('id')->toArray();
$totalSubscribers = 0;

echo "--- 列表订阅者统计 ---\n";
foreach ($listIds as $listId) {
    $count = Subscriber::whereHas('lists', function ($query) use ($listId) {
        $query->where('lists.id', $listId)
            ->where('list_subscriber.status', 'active');
    })->count();
    
    $totalSubscribers += $count;
    echo "列表 #{$listId}: {$count} 个活跃订阅者\n";
}

echo "\n总活跃订阅者: {$totalSubscribers}\n";
echo "未发送数量: " . ($totalSubscribers - $actualProcessed) . "\n\n";

// 确认是否继续
echo "⚠️  注意：此操作将：\n";
echo "   1. 重置活动状态为 'sending'\n";
echo "   2. 更新 total_recipients 为 {$totalSubscribers}\n";
echo "   3. 为 " . ($totalSubscribers - $actualSent) . " 个未发送的订阅者创建任务\n\n";

echo "是否继续？(yes/no): ";
$handle = fopen("php://stdin", "r");
$line = trim(fgets($handle));

if ($line !== 'yes') {
    echo "操作已取消\n";
    exit(0);
}

echo "\n开始修复...\n\n";

try {
    DB::beginTransaction();
    
    // 1. 重置活动状态
    echo "[1/3] 重置活动状态...\n";
    $campaign->update([
        'status' => 'sending',
        'total_recipients' => $totalSubscribers,
    ]);
    echo "      ✓ 完成\n\n";
    
    // 2. 获取已发送的订阅者 ID
    echo "[2/3] 获取已发送列表...\n";
    $sentSubscriberIds = CampaignSend::where('campaign_id', $campaignId)
        ->pluck('subscriber_id')
        ->toArray();
    echo "      已发送: " . count($sentSubscriberIds) . " 个\n\n";
    
    // 3. 为未发送的订阅者创建任务
    echo "[3/3] 创建发送任务...\n";
    $distributionService = new QueueDistributionService();
    $totalCreated = 0;
    $batchSize = 5000;
    
    foreach ($listIds as $listIndex => $listId) {
        echo "      处理列表 #{$listId} (" . ($listIndex + 1) . "/" . count($listIds) . ")...\n";
        
        $listCreated = 0;
        $lastId = 0;
        $batchNumber = 0;
        
        while (true) {
            // 查询未发送的订阅者
            $subscribers = Subscriber::select(['id', 'email', 'first_name', 'last_name', 'custom_fields'])
                ->whereHas('lists', function ($query) use ($listId) {
                    $query->where('lists.id', $listId)
                        ->where('list_subscriber.status', 'active');
                })
                ->where('subscribers.id', '>', $lastId)
                ->whereNotIn('id', $sentSubscriberIds)
                ->orderBy('subscribers.id', 'asc')
                ->take($batchSize)
                ->get();
            
            if ($subscribers->isEmpty()) {
                break;
            }
            
            $lastId = $subscribers->last()->id;
            $batchNumber++;
            
            // 构建待发送列表
            $subscribersWithList = [];
            foreach ($subscribers as $subscriber) {
                $subscribersWithList[] = [
                    'subscriber' => $subscriber,
                    'list_id' => $listId,
                ];
            }
            
            // 创建任务
            $distributionService->distributeEvenly($campaign, $subscribersWithList);
            $listCreated += count($subscribersWithList);
            $totalCreated += count($subscribersWithList);
            
            echo "         批次 {$batchNumber}: 创建 " . count($subscribersWithList) . " 个任务\n";
            
            // 清理内存
            unset($subscribersWithList, $subscribers);
            gc_collect_cycles();
        }
        
        echo "         ✓ 列表 #{$listId} 完成: {$listCreated} 个任务\n";
    }
    
    DB::commit();
    
    echo "\n✅ 修复完成！\n\n";
    echo "=== 修复摘要 ===\n";
    echo "活动状态: {$campaign->fresh()->status}\n";
    echo "总收件人: {$totalSubscribers}\n";
    echo "已处理: {$actualProcessed}\n";
    echo "新创建任务: {$totalCreated}\n";
    echo "队列: campaign_{$campaignId}\n\n";
    
    echo "提示：请确保 queue worker 正在运行以处理新创建的任务\n";
    echo "  php artisan queue:work --queue=campaign_{$campaignId}\n\n";
    
} catch (\Exception $e) {
    DB::rollBack();
    echo "\n❌ 修复失败: {$e->getMessage()}\n";
    echo "堆栈追踪:\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

