<?php

/**
 * 修复因系统错误被错误加入黑名单的邮箱
 * 
 * 使用方法：
 * php fix-incorrect-blacklist.php [--dry-run] [--start-time="2025-12-31 10:00:00"] [--end-time="2025-12-31 12:00:00"]
 * 
 * 参数：
 *   --dry-run      只显示会被删除的记录，不实际执行
 *   --start-time   开始时间（默认：2025-12-31 09:00:00）
 *   --end-time     结束时间（默认：2025-12-31 13:00:00）
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "========================================\n";
echo "  修复错误加入黑名单的邮箱\n";
echo "========================================\n\n";

// 解析参数
$dryRun = in_array('--dry-run', $argv);
$startTime = '2025-12-31 09:00:00';
$endTime = '2025-12-31 13:00:00';

foreach ($argv as $arg) {
    if (strpos($arg, '--start-time=') === 0) {
        $startTime = substr($arg, 13);
    }
    if (strpos($arg, '--end-time=') === 0) {
        $endTime = substr($arg, 11);
    }
}

echo "时间范围: {$startTime} 到 {$endTime}\n";
echo "模式: " . ($dryRun ? "预览（不执行）" : "执行") . "\n\n";

// 1. 查找该时间段内加入黑名单的记录
echo "📋 查询该时间段加入黑名单的记录...\n\n";

$blacklistEntries = DB::table('blacklist')
    ->whereBetween('created_at', [$startTime, $endTime])
    ->get();

if ($blacklistEntries->isEmpty()) {
    echo "✅ 该时间段没有黑名单记录\n";
    exit(0);
}

echo "找到 {$blacklistEntries->count()} 条黑名单记录\n\n";

// 按 reason 分组显示
$byReason = $blacklistEntries->groupBy('reason');
echo "按原因分布:\n";
foreach ($byReason as $reason => $items) {
    echo "  {$reason}: {$items->count()} 条\n";
}
echo "\n";

// 2. 显示一些示例记录
echo "示例记录 (前10条):\n";
echo str_repeat("-", 80) . "\n";
foreach ($blacklistEntries->take(10) as $entry) {
    echo "  ID: {$entry->id}\n";
    echo "  Email: {$entry->email}\n";
    echo "  Reason: {$entry->reason}\n";
    echo "  Notes: " . ($entry->notes ?? 'N/A') . "\n";
    echo "  Created: {$entry->created_at}\n";
    echo str_repeat("-", 80) . "\n";
}

if ($blacklistEntries->count() > 10) {
    echo "... 还有 " . ($blacklistEntries->count() - 10) . " 条记录\n\n";
}

// 3. 询问用户确认
if (!$dryRun) {
    echo "\n⚠️  即将执行以下操作:\n";
    echo "  1. 删除 {$blacklistEntries->count()} 条黑名单记录\n";
    echo "  2. 将对应的订阅者状态从 blacklisted 恢复为 active\n\n";
    
    echo "是否继续? (y/n): ";
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    fclose($handle);
    
    if (trim($line) !== 'y') {
        echo "操作已取消\n";
        exit(0);
    }
}

// 4. 执行修复
$emails = $blacklistEntries->pluck('email')->unique()->toArray();
$blacklistIds = $blacklistEntries->pluck('id')->toArray();

if ($dryRun) {
    echo "\n📋 [预览] 将删除以下邮箱的黑名单记录:\n";
    foreach (array_slice($emails, 0, 20) as $email) {
        echo "  - {$email}\n";
    }
    if (count($emails) > 20) {
        echo "  ... 还有 " . (count($emails) - 20) . " 个邮箱\n";
    }
    
    // 预览受影响的订阅者
    $affectedBlacklisted = DB::table('subscribers')
        ->whereIn('email', $emails)
        ->where('status', 'blacklisted')
        ->count();
    $affectedBounced = DB::table('subscribers')
        ->whereIn('email', $emails)
        ->where('status', 'bounced')
        ->count();
    
    echo "\n📊 受影响的订阅者:\n";
    echo "  - status = blacklisted: {$affectedBlacklisted} 个\n";
    echo "  - status = bounced: {$affectedBounced} 个\n";
    
    // 预览退信日志
    $affectedBounceLogs = DB::table('bounce_logs')
        ->whereIn('email', $emails)
        ->whereBetween('created_at', [$startTime, $endTime])
        ->count();
    echo "  - bounce_logs 记录: {$affectedBounceLogs} 条\n";
    
    echo "\n[预览模式] 没有执行任何更改\n";
    exit(0);
}

echo "\n开始修复...\n";

try {
    DB::beginTransaction();
    
    // 删除黑名单记录
    $deletedCount = DB::table('blacklist')
        ->whereIn('id', $blacklistIds)
        ->delete();
    echo "✅ 删除了 {$deletedCount} 条黑名单记录\n";
    
    // 获取订阅者 IDs
    $subscriberIds = DB::table('subscribers')
        ->whereIn('email', $emails)
        ->pluck('id')
        ->toArray();
    
    // 恢复订阅者状态（同时处理 blacklisted 和 bounced 状态）
    $restoredFromBlacklisted = DB::table('subscribers')
        ->whereIn('email', $emails)
        ->where('status', 'blacklisted')
        ->update(['status' => 'active', 'updated_at' => now()]);
    
    $restoredFromBounced = DB::table('subscribers')
        ->whereIn('email', $emails)
        ->where('status', 'bounced')
        ->update([
            'status' => 'active', 
            'bounce_count' => 0,  // 重置退信计数
            'last_bounce_at' => null,
            'updated_at' => now()
        ]);
    
    $totalRestoredSubscribers = $restoredFromBlacklisted + $restoredFromBounced;
    echo "✅ 恢复了 {$totalRestoredSubscribers} 个订阅者的状态为 active\n";
    echo "   - 从 blacklisted 恢复: {$restoredFromBlacklisted}\n";
    echo "   - 从 bounced 恢复: {$restoredFromBounced}\n";
    
    // 恢复 list_subscriber 状态（同时处理两种状态）
    $restoredListFromBlacklisted = DB::table('list_subscriber')
        ->whereIn('subscriber_id', $subscriberIds)
        ->where('status', 'blacklisted')
        ->update(['status' => 'active', 'updated_at' => now()]);
    
    $restoredListFromBounced = DB::table('list_subscriber')
        ->whereIn('subscriber_id', $subscriberIds)
        ->where('status', 'bounced')
        ->update(['status' => 'active', 'updated_at' => now()]);
    
    $totalRestoredListSubscribers = $restoredListFromBlacklisted + $restoredListFromBounced;
    echo "✅ 恢复了 {$totalRestoredListSubscribers} 个列表订阅关系的状态\n";
    
    // 删除相关的 bounce_logs 记录
    $deletedBounceLogs = DB::table('bounce_logs')
        ->whereIn('email', $emails)
        ->whereBetween('created_at', [$startTime, $endTime])
        ->delete();
    echo "✅ 删除了 {$deletedBounceLogs} 条退信日志记录\n";
    
    DB::commit();
    
    echo "\n========================================\n";
    echo "  修复完成！\n";
    echo "========================================\n";
    echo "  删除黑名单: {$deletedCount} 条\n";
    echo "  恢复订阅者: {$totalRestoredSubscribers} 个\n";
    echo "  恢复列表关系: {$totalRestoredListSubscribers} 个\n";
    echo "  删除退信日志: {$deletedBounceLogs} 条\n";
    echo "========================================\n";
    
} catch (\Exception $e) {
    DB::rollBack();
    echo "❌ 修复失败: {$e->getMessage()}\n";
    exit(1);
}

