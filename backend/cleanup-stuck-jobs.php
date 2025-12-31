<?php

/**
 * 清理卡住的队列任务
 * 
 * 用法：php cleanup-stuck-jobs.php
 * 
 * 此脚本会：
 * 1. 将 attempts >= 5 的任务移动到 failed_jobs 表
 * 2. 删除这些卡住的任务
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

echo "========================================\n";
echo "  清理卡住的队列任务\n";
echo "========================================\n\n";

// 定义最大重试次数
$maxAttempts = 5;

// 查找超过最大重试次数的任务
$stuckJobs = DB::table('jobs')
    ->where('attempts', '>=', $maxAttempts)
    ->get();

echo "找到 {$stuckJobs->count()} 个卡住的任务（attempts >= {$maxAttempts}）\n\n";

if ($stuckJobs->isEmpty()) {
    echo "✅ 没有需要清理的任务\n";
    exit(0);
}

// 确认操作
echo "即将执行以下操作：\n";
echo "  1. 将这些任务移动到 failed_jobs 表\n";
echo "  2. 从 jobs 表中删除这些任务\n\n";

echo "是否继续？(y/n): ";
$handle = fopen("php://stdin", "r");
$line = fgets($handle);
fclose($handle);

if (trim($line) !== 'y') {
    echo "操作已取消\n";
    exit(0);
}

echo "\n";

// 处理每个卡住的任务
$successCount = 0;
$errorCount = 0;

foreach ($stuckJobs as $job) {
    try {
        DB::beginTransaction();
        
        // 移动到 failed_jobs
        DB::table('failed_jobs')->insert([
            'uuid' => Str::uuid(),
            'connection' => 'database',
            'queue' => $job->queue,
            'payload' => $job->payload,
            'exception' => "Job exceeded maximum attempts ({$job->attempts}). Cleaned up by cleanup-stuck-jobs.php",
            'failed_at' => now(),
        ]);
        
        // 从 jobs 表删除
        DB::table('jobs')->where('id', $job->id)->delete();
        
        DB::commit();
        
        echo "✅ 已清理任务 #{$job->id} (queue: {$job->queue}, attempts: {$job->attempts})\n";
        $successCount++;
        
    } catch (\Exception $e) {
        DB::rollBack();
        echo "❌ 清理任务 #{$job->id} 失败: {$e->getMessage()}\n";
        $errorCount++;
    }
}

echo "\n========================================\n";
echo "清理完成！\n";
echo "  成功: {$successCount}\n";
echo "  失败: {$errorCount}\n";
echo "========================================\n";

// 显示当前队列状态
echo "\n当前队列状态：\n";
$queueStats = DB::table('jobs')
    ->select('queue', DB::raw('COUNT(*) as count'), DB::raw('MAX(attempts) as max_attempts'))
    ->groupBy('queue')
    ->get();

if ($queueStats->isEmpty()) {
    echo "  队列为空\n";
} else {
    foreach ($queueStats as $stat) {
        echo "  {$stat->queue}: {$stat->count} 个任务 (最大重试次数: {$stat->max_attempts})\n";
    }
}

