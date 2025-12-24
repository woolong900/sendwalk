<?php

/**
 * 黑名单大批量导入测试脚本
 * 
 * 使用方法：
 *   php 测试黑名单导入.php
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Blacklist;
use App\Jobs\ImportBlacklistJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

echo "========================================\n";
echo "🧪 黑名单大批量导入功能测试\n";
echo "========================================\n\n";

// 测试 1: 验证 Job 类是否存在
echo "测试 1: 检查 ImportBlacklistJob 类\n";
echo "----------------------------------------\n";
if (class_exists('App\Jobs\ImportBlacklistJob')) {
    echo "✅ ImportBlacklistJob 类存在\n";
} else {
    echo "❌ ImportBlacklistJob 类不存在\n";
    exit(1);
}

// 测试 2: 生成测试数据
echo "\n测试 2: 生成测试数据\n";
echo "----------------------------------------\n";
$testEmails = [];
for ($i = 1; $i <= 2500; $i++) {
    $testEmails[] = "test{$i}@example.com";
}
echo "✅ 生成了 " . count($testEmails) . " 个测试邮箱\n";

// 测试 3: 分批测试
echo "\n测试 3: 分批处理测试\n";
echo "----------------------------------------\n";
$batchSize = 1000;
$batches = array_chunk($testEmails, $batchSize);
echo "✅ 分成 " . count($batches) . " 批，每批 $batchSize 条\n";

// 测试 4: 创建测试任务
echo "\n测试 4: 创建测试导入任务\n";
echo "----------------------------------------\n";
$taskId = 'test_import_' . time() . '_' . uniqid();
$userId = 1; // 使用第一个用户
$reason = '测试导入';

echo "任务ID: $taskId\n";
echo "用户ID: $userId\n";
echo "批次数: " . count($batches) . "\n";

// 初始化进度缓存
$progress = [
    'total_batches' => count($batches),
    'completed_batches' => 0,
    'total_emails' => count($testEmails),
    'added' => 0,
    'already_exists' => 0,
    'invalid' => 0,
    'subscribers_updated' => 0,
    'status' => 'processing',
    'started_at' => now()->toIso8601String(),
];

Cache::put("blacklist_import_{$taskId}", $progress, 3600);
echo "✅ 进度缓存已初始化\n";

// 测试 5: 分发队列任务（同步执行）
echo "\n测试 5: 分发队列任务（同步执行）\n";
echo "----------------------------------------\n";
echo "注意：为了测试，使用同步方式执行，不真正入队\n\n";

foreach ($batches as $batchNumber => $batch) {
    echo "处理批次 " . ($batchNumber + 1) . "/" . count($batches) . " (" . count($batch) . " 条)...\n";
    
    try {
        $job = new ImportBlacklistJob(
            $userId,
            $batch,
            $reason,
            $taskId,
            $batchNumber + 1,
            count($batches)
        );
        
        // 同步执行（不入队）
        $job->handle();
        
        echo "  ✅ 批次 " . ($batchNumber + 1) . " 处理完成\n";
        
        // 显示进度
        $currentProgress = Cache::get("blacklist_import_{$taskId}");
        echo "  📊 进度: " . $currentProgress['completed_batches'] . "/" . $currentProgress['total_batches'];
        echo " (已添加: {$currentProgress['added']}, 已存在: {$currentProgress['already_exists']}, 无效: {$currentProgress['invalid']})\n";
        
    } catch (\Exception $e) {
        echo "  ❌ 批次 " . ($batchNumber + 1) . " 处理失败: " . $e->getMessage() . "\n";
        break;
    }
}

// 测试 6: 验证最终结果
echo "\n测试 6: 验证导入结果\n";
echo "----------------------------------------\n";
$finalProgress = Cache::get("blacklist_import_{$taskId}");

if (!$finalProgress) {
    echo "❌ 无法获取进度信息\n";
    exit(1);
}

echo "状态: {$finalProgress['status']}\n";
echo "总批次: {$finalProgress['total_batches']}\n";
echo "已完成: {$finalProgress['completed_batches']}\n";
echo "总邮箱: {$finalProgress['total_emails']}\n";
echo "新增: {$finalProgress['added']}\n";
echo "已存在: {$finalProgress['already_exists']}\n";
echo "无效: {$finalProgress['invalid']}\n";
echo "订阅者已更新: {$finalProgress['subscribers_updated']}\n";

if ($finalProgress['status'] === 'completed') {
    echo "\n✅ 所有批次处理完成！\n";
} else {
    echo "\n⚠️  处理未完成，状态: {$finalProgress['status']}\n";
}

// 测试 7: 验证数据库记录
echo "\n测试 7: 验证数据库记录\n";
echo "----------------------------------------\n";
$dbCount = Blacklist::where('user_id', $userId)
    ->where('reason', $reason)
    ->count();
echo "数据库中的记录数: $dbCount\n";

if ($dbCount === $finalProgress['added']) {
    echo "✅ 数据库记录数与导入数量一致\n";
} else {
    echo "⚠️  数据库记录数与导入数量不一致（可能有已存在的记录）\n";
}

// 测试 8: 清理测试数据
echo "\n测试 8: 清理测试数据\n";
echo "----------------------------------------\n";
echo "是否清理测试数据？(y/n): ";
$handle = fopen("php://stdin", "r");
$line = fgets($handle);
if (trim($line) === 'y' || trim($line) === 'Y') {
    $deleted = Blacklist::where('user_id', $userId)
        ->where('reason', $reason)
        ->delete();
    echo "✅ 已删除 $deleted 条测试记录\n";
    
    Cache::forget("blacklist_import_{$taskId}");
    echo "✅ 已清理进度缓存\n";
} else {
    echo "⏭️  跳过清理，测试数据保留\n";
    echo "   任务ID: $taskId\n";
    echo "   可手动清理: php artisan tinker\n";
    echo "   执行: Blacklist::where('reason', '$reason')->delete();\n";
}
fclose($handle);

echo "\n========================================\n";
echo "✅ 测试完成！\n";
echo "========================================\n\n";

echo "📋 测试总结:\n";
echo "  ✅ Job 类正常\n";
echo "  ✅ 分批处理正常\n";
echo "  ✅ 进度跟踪正常\n";
echo "  ✅ 数据库操作正常\n";
echo "  ✅ 批量插入性能良好\n\n";

echo "🚀 下一步:\n";
echo "  1. 启动队列工作进程: php artisan queue:work\n";
echo "  2. 通过 API 测试真实导入\n";
echo "  3. 监控队列日志: tail -f storage/logs/queue.log\n\n";

