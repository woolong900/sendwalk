<?php

/**
 * 修复卡住的活动（状态为 sending 但队列为空）
 * 
 * 使用方法：
 * php fix-stuck-campaign.php <campaign_id>
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Campaign;
use App\Services\QueueDistributionService;
use App\Models\Subscriber;
use Illuminate\Support\Facades\DB;

if ($argc < 2) {
    echo "使用方法: php fix-stuck-campaign.php <campaign_id>\n";
    exit(1);
}

$campaignId = $argv[1];

echo "正在检查活动 #{$campaignId}...\n\n";

$campaign = Campaign::find($campaignId);

if (!$campaign) {
    echo "❌ 活动不存在\n";
    exit(1);
}

echo "活动信息:\n";
echo "  ID: {$campaign->id}\n";
echo "  名称: {$campaign->name}\n";
echo "  状态: {$campaign->status}\n";
echo "  总收件人数: {$campaign->total_recipients}\n";
echo "  已发送: {$campaign->total_sent}\n";
echo "\n";

// 检查队列
$queueName = "campaign_{$campaign->id}";
$queueJobsCount = DB::table('jobs')
    ->where('queue', $queueName)
    ->whereNull('reserved_at')
    ->count();

echo "队列状态:\n";
echo "  队列名称: {$queueName}\n";
echo "  待处理任务数: {$queueJobsCount}\n";
echo "\n";

if ($campaign->status !== 'sending') {
    echo "⚠️  活动状态不是 'sending'，当前状态: {$campaign->status}\n";
    echo "是否需要重置状态? (y/n): ";
    $answer = trim(fgets(STDIN));
    if ($answer !== 'y') {
        exit(0);
    }
}

if ($queueJobsCount > 0) {
    echo "✅ 队列不为空，活动应该正在正常处理中\n";
    exit(0);
}

echo "🔍 队列为空，正在重新创建任务...\n\n";

// 获取列表ID（兼容单列表和多列表）
$listIds = [];

if ($campaign->lists()->exists()) {
    $listIds = $campaign->lists->pluck('id')->toArray();
    echo "  📋 使用多列表关系: " . implode(', ', $listIds) . "\n";
} elseif ($campaign->list_id) {
    $listIds = [$campaign->list_id];
    echo "  📋 使用单列表字段: {$campaign->list_id}\n";
}

if (empty($listIds)) {
    echo "❌ 活动没有关联的邮件列表\n";
    exit(1);
}

// 为每个列表获取订阅者，保留列表关系信息
$subscribersWithList = [];
$uniqueSubscriberIds = [];

foreach ($listIds as $listId) {
    $listSubscribers = Subscriber::whereHas('lists', function ($query) use ($listId) {
        $query->where('lists.id', $listId)
              ->where('list_subscriber.status', 'active');
    })->get();
    
    echo "  列表 #{$listId}: " . $listSubscribers->count() . " 个活跃订阅者\n";
    
    foreach ($listSubscribers as $subscriber) {
        // 使用订阅者ID去重，确保每个订阅者只发送一次
        if (!in_array($subscriber->id, $uniqueSubscriberIds)) {
            $subscribersWithList[] = [
                'subscriber' => $subscriber,
                'list_id' => $listId,
            ];
            $uniqueSubscriberIds[] = $subscriber->id;
        }
    }
}

if (empty($subscribersWithList)) {
    echo "❌ 没有找到活跃的订阅者\n";
    exit(1);
}

echo "\n总共 " . count($subscribersWithList) . " 个唯一订阅者\n\n";

// 检查已发送记录
$alreadySentCount = DB::table('campaign_sends')
    ->where('campaign_id', $campaign->id)
    ->whereIn('status', ['sent', 'failed'])
    ->count();

if ($alreadySentCount > 0) {
    echo "⚠️  已有 {$alreadySentCount} 个订阅者已发送或失败\n";
    echo "是否要跳过这些订阅者，只为剩余的订阅者创建任务? (y/n): ";
    $answer = trim(fgets(STDIN));
    
    if ($answer === 'y') {
        $alreadySentIds = DB::table('campaign_sends')
            ->where('campaign_id', $campaign->id)
            ->whereIn('status', ['sent', 'failed'])
            ->pluck('subscriber_id')
            ->toArray();
        
        $subscribersWithList = array_filter($subscribersWithList, function($item) use ($alreadySentIds) {
            return !in_array($item['subscriber']->id, $alreadySentIds);
        });
        
        echo "过滤后剩余 " . count($subscribersWithList) . " 个订阅者\n\n";
    }
}

if (empty($subscribersWithList)) {
    echo "✅ 所有订阅者都已处理完成\n";
    echo "是否将活动标记为 'completed'? (y/n): ";
    $answer = trim(fgets(STDIN));
    if ($answer === 'y') {
        $campaign->update(['status' => 'completed', 'completed_at' => now()]);
        echo "✅ 活动已标记为完成\n";
    }
    exit(0);
}

// 创建任务
echo "正在创建发送任务...\n";

$distributionService = new QueueDistributionService();
$result = $distributionService->distributeEvenly($campaign, $subscribersWithList);

echo "\n✅ 任务创建成功！\n";
echo "  队列: {$result['queue']}\n";
echo "  任务数: " . count($subscribersWithList) . "\n";
echo "  分配策略: {$result['distribution']}\n";

// 更新活动状态
if ($campaign->status !== 'sending') {
    $campaign->update(['status' => 'sending']);
    echo "  状态已更新为: sending\n";
}

// 更新总收件人数
$campaign->update(['total_recipients' => count($subscribersWithList) + $alreadySentCount]);

echo "\n✅ 完成！活动应该会继续处理。\n";

