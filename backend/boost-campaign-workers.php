<?php

/**
 * 快速为指定活动增加 workers，加速处理
 * 
 * 使用方法：
 * php boost-campaign-workers.php <campaign_id> <worker_count>
 * 
 * 示例：
 * php boost-campaign-workers.php 18 20
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Campaign;
use Illuminate\Support\Facades\DB;

if ($argc < 3) {
    echo "使用方法: php boost-campaign-workers.php <campaign_id> <worker_count>\n";
    echo "示例: php boost-campaign-workers.php 18 20\n";
    exit(1);
}

$campaignId = $argv[1];
$workerCount = (int)$argv[2];

if ($workerCount < 1 || $workerCount > 50) {
    echo "❌ Worker 数量必须在 1-50 之间\n";
    exit(1);
}

echo "正在为活动 #{$campaignId} 启动 {$workerCount} 个 workers...\n\n";

$campaign = Campaign::find($campaignId);

if (!$campaign) {
    echo "❌ 活动不存在\n";
    exit(1);
}

$queueName = "campaign_{$campaignId}";

// 检查队列任务数
$jobCount = DB::table('jobs')
    ->where('queue', $queueName)
    ->whereNull('reserved_at')
    ->count();

echo "活动: {$campaign->name}\n";
echo "状态: {$campaign->status}\n";
echo "队列任务数: {$jobCount}\n";
echo "\n";

if ($jobCount === 0) {
    echo "⚠️  队列为空，无需启动 workers\n";
    exit(0);
}

$logDir = storage_path('logs');

// 检查已有的 workers
$existingWorkers = shell_exec("ps aux | grep 'campaign:process-queue {$campaignId}' | grep -v grep | wc -l");
$existingWorkers = (int)trim($existingWorkers);

echo "当前运行中的 workers: {$existingWorkers}\n";

if ($existingWorkers >= $workerCount) {
    echo "✅ 已有足够的 workers 在运行\n";
    exit(0);
}

$workersToStart = $workerCount - $existingWorkers;
echo "将启动 {$workersToStart} 个新 workers...\n\n";

$pids = [];

for ($i = 1; $i <= $workersToStart; $i++) {
    $workerId = $existingWorkers + $i;
    $logFile = "{$logDir}/campaign_{$campaignId}-worker-{$workerId}.log";
    
    $cmd = sprintf(
        'nohup bash -c \'echo "[$(date +\"%%Y-%%m-%%d %%H:%%M:%%S\")] Starting dedicated worker #%d for Campaign #%s"; cd %s && php artisan campaign:process-queue %s --sleep=1 --memory=256 2>&1; EXIT_CODE=$?; echo "[$(date +\"%%Y-%%m-%%d %%H:%%M:%%S\")] Worker exited with code $EXIT_CODE"\' > %s 2>&1 & echo $!',
        $workerId,
        $campaignId,
        base_path(),
        $campaignId,
        $logFile
    );
    
    $pid = trim(shell_exec($cmd));
    
    if ($pid) {
        $pids[] = $pid;
        echo "  ✅ Worker #{$workerId} 已启动 (PID: {$pid})\n";
        usleep(100000); // 100ms 延迟，避免瞬间压力过大
    } else {
        echo "  ❌ Worker #{$workerId} 启动失败\n";
    }
}

echo "\n✅ 完成！已启动 " . count($pids) . " 个新 workers\n";
echo "总 workers: " . ($existingWorkers + count($pids)) . "\n";
echo "\n";

// 估算完成时间
$avgSpeed = 5; // 假设每个 worker 每秒处理 5 个任务
$totalWorkers = $existingWorkers + count($pids);
$estimatedSeconds = $jobCount / ($totalWorkers * $avgSpeed);
$estimatedMinutes = round($estimatedSeconds / 60, 1);

echo "📊 处理速度估算:\n";
echo "  Workers: {$totalWorkers}\n";
echo "  任务数: {$jobCount}\n";
echo "  假设速度: {$avgSpeed} 任务/秒/worker\n";
echo "  预计完成时间: {$estimatedMinutes} 分钟\n";
echo "\n";

echo "💡 提示:\n";
echo "  - 查看实时进度: php check-campaign-status.php {$campaignId}\n";
echo "  - 停止所有 workers: pkill -f 'campaign:process-queue {$campaignId}'\n";
echo "  - 查看日志: tail -f {$logDir}/campaign_{$campaignId}-worker-*.log\n";

