<?php

/**
 * 检查活动状态和队列情况
 * 
 * 使用方法：
 * php check-campaign-status.php <campaign_id>
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Campaign;
use Illuminate\Support\Facades\DB;

if ($argc < 2) {
    echo "使用方法: php check-campaign-status.php <campaign_id>\n";
    exit(1);
}

$campaignId = $argv[1];

echo "正在检查活动 #{$campaignId}...\n\n";
echo str_repeat("=", 80) . "\n";

$campaign = Campaign::with(['lists', 'list'])->find($campaignId);

if (!$campaign) {
    echo "❌ 活动不存在\n";
    exit(1);
}

// 1. 活动基本信息
echo "📋 活动信息:\n";
echo "  ID: {$campaign->id}\n";
echo "  名称: {$campaign->name}\n";
echo "  状态: {$campaign->status}\n";
echo "  创建时间: {$campaign->created_at}\n";
echo "  定时发送时间: " . ($campaign->scheduled_at ?? 'N/A') . "\n";
echo "\n";

// 2. 列表信息
echo "📋 关联列表:\n";
$listIds = [];
if ($campaign->lists()->exists()) {
    foreach ($campaign->lists as $list) {
        echo "  - 列表 #{$list->id}: {$list->name}\n";
        $listIds[] = $list->id;
    }
} elseif ($campaign->list_id) {
    $list = $campaign->list;
    echo "  - 列表 #{$list->id}: {$list->name}\n";
    $listIds[] = $list->id;
} else {
    echo "  ⚠️  没有关联列表\n";
}
echo "\n";

// 3. 订阅者统计
if (!empty($listIds)) {
    echo "👥 订阅者统计:\n";
    foreach ($listIds as $listId) {
        $activeCount = DB::table('list_subscriber')
            ->where('list_id', $listId)
            ->where('status', 'active')
            ->count();
        echo "  列表 #{$listId}: {$activeCount} 个活跃订阅者\n";
    }
    
    $uniqueCount = DB::table('list_subscriber')
        ->whereIn('list_id', $listIds)
        ->where('status', 'active')
        ->distinct('subscriber_id')
        ->count('subscriber_id');
    echo "  去重后总数: {$uniqueCount} 个唯一订阅者\n";
    echo "\n";
}

// 4. 发送进度
echo "📊 发送进度:\n";
echo "  总收件人数: {$campaign->total_recipients}\n";
echo "  已发送: {$campaign->total_sent}\n";
echo "  打开数: {$campaign->total_opened}\n";
echo "  点击数: {$campaign->total_clicked}\n";
echo "  退订数: {$campaign->total_unsubscribed}\n";

if ($campaign->total_recipients > 0) {
    $progress = round($campaign->total_sent / $campaign->total_recipients * 100, 2);
    echo "  进度: {$progress}%\n";
}
echo "\n";

// 5. 队列状态
$queueName = "campaign_{$campaign->id}";
echo "🔄 队列状态:\n";
echo "  队列名称: {$queueName}\n";

$pendingJobs = DB::table('jobs')
    ->where('queue', $queueName)
    ->whereNull('reserved_at')
    ->count();

$reservedJobs = DB::table('jobs')
    ->where('queue', $queueName)
    ->whereNotNull('reserved_at')
    ->count();

$totalJobs = $pendingJobs + $reservedJobs;

echo "  待处理任务: {$pendingJobs}\n";
echo "  处理中任务: {$reservedJobs}\n";
echo "  总任务数: {$totalJobs}\n";

if ($totalJobs > 0) {
    $minSort = DB::table('jobs')->where('queue', $queueName)->min('sort_order');
    $maxSort = DB::table('jobs')->where('queue', $queueName)->max('sort_order');
    echo "  排序范围: {$minSort} - {$maxSort}\n";
}
echo "\n";

// 6. 发送记录统计
echo "📝 发送记录:\n";
$sentCount = DB::table('campaign_sends')
    ->where('campaign_id', $campaign->id)
    ->where('status', 'sent')
    ->count();

$failedCount = DB::table('campaign_sends')
    ->where('campaign_id', $campaign->id)
    ->where('status', 'failed')
    ->count();

$pendingCount = DB::table('campaign_sends')
    ->where('campaign_id', $campaign->id)
    ->where('status', 'pending')
    ->count();

echo "  已发送: {$sentCount}\n";
echo "  失败: {$failedCount}\n";
echo "  待处理: {$pendingCount}\n";
echo "  总记录数: " . ($sentCount + $failedCount + $pendingCount) . "\n";
echo "\n";

// 7. 诊断和建议
echo "🔍 诊断:\n";

if ($campaign->status === 'sending' && $totalJobs === 0 && $campaign->total_sent < $campaign->total_recipients) {
    echo "  ⚠️  活动状态为 'sending' 但队列为空，且未完成发送\n";
    echo "  💡 建议: 运行修复脚本\n";
    echo "     php fix-stuck-campaign.php {$campaignId}\n";
} elseif ($campaign->status === 'sending' && $totalJobs > 0) {
    echo "  ✅ 活动正在正常处理中\n";
    if ($reservedJobs === 0 && $pendingJobs > 0) {
        echo "  ⚠️  有待处理任务但没有正在处理的任务\n";
        echo "  💡 建议: 检查队列处理器是否正在运行\n";
        echo "     ps aux | grep 'queue:work'\n";
    }
} elseif ($campaign->status === 'completed') {
    echo "  ✅ 活动已完成\n";
} elseif ($campaign->status === 'scheduled') {
    echo "  ⏰ 活动已定时，等待调度器处理\n";
    echo "  💡 定时时间: " . ($campaign->scheduled_at ?? 'N/A') . "\n";
} else {
    echo "  ℹ️  活动状态: {$campaign->status}\n";
}

echo "\n";
echo str_repeat("=", 80) . "\n";

