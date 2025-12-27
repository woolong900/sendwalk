<?php

/**
 * 测试任务创建速度
 * 
 * 使用方法：
 * php benchmark-task-creation.php <subscriber_count>
 * 
 * 示例：
 * php benchmark-task-creation.php 1000
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Campaign;
use App\Models\Subscriber;
use App\Jobs\SendCampaignEmail;
use Illuminate\Support\Facades\DB;

if ($argc < 2) {
    echo "使用方法: php benchmark-task-creation.php <subscriber_count>\n";
    echo "示例: php benchmark-task-creation.php 1000\n";
    exit(1);
}

$count = (int)$argv[1];

if ($count < 1 || $count > 10000) {
    echo "❌ 数量必须在 1-10000 之间（仅用于测试）\n";
    exit(1);
}

echo "🔬 任务创建速度基准测试\n";
echo str_repeat("=", 80) . "\n\n";

// 获取一个示例活动和订阅者
$campaign = Campaign::first();
$subscriber = Subscriber::first();

if (!$campaign || !$subscriber) {
    echo "❌ 没有找到活动或订阅者，请先创建\n";
    exit(1);
}

echo "测试参数:\n";
echo "  活动 ID: {$campaign->id}\n";
echo "  订阅者 ID: {$subscriber->id}\n";
echo "  任务数量: {$count}\n";
echo "\n";

// 测试 1: 序列化性能
echo "📊 测试 1: Job 序列化性能\n";
echo str_repeat("-", 80) . "\n";

$startTime = microtime(true);
$jobs = [];

for ($i = 0; $i < $count; $i++) {
    $job = new SendCampaignEmail($campaign->id, $subscriber->id);
    $serialized = serialize($job);
    $jobs[] = $serialized;
}

$duration = microtime(true) - $startTime;
$avgSize = strlen($jobs[0]);
$speed = round($count / $duration, 2);

echo "  完成时间: " . round($duration, 3) . " 秒\n";
echo "  平均速度: {$speed} 次/秒\n";
echo "  序列化大小: {$avgSize} 字节\n";
echo "\n";

// 测试 2: JSON 编码性能
echo "📊 测试 2: 完整 Payload 生成性能\n";
echo str_repeat("-", 80) . "\n";

$startTime = microtime(true);
$payloads = [];

for ($i = 0; $i < $count; $i++) {
    $job = new SendCampaignEmail($campaign->id, $subscriber->id);
    
    $payload = json_encode([
        'uuid' => \Illuminate\Support\Str::uuid()->toString(),
        'displayName' => 'App\\Jobs\\SendCampaignEmail',
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'maxTries' => 1,
        'maxExceptions' => null,
        'failOnTimeout' => false,
        'backoff' => null,
        'timeout' => 120,
        'retryUntil' => null,
        'data' => [
            'commandName' => 'App\\Jobs\\SendCampaignEmail',
            'command' => serialize($job),
        ],
    ]);
    
    $payloads[] = $payload;
}

$duration = microtime(true) - $startTime;
$avgSize = strlen($payloads[0]);
$speed = round($count / $duration, 2);

echo "  完成时间: " . round($duration, 3) . " 秒\n";
echo "  平均速度: {$speed} 次/秒\n";
echo "  Payload 大小: {$avgSize} 字节\n";
echo "\n";

// 测试 3: 批量插入性能（不真正插入，只测试构建）
echo "📊 测试 3: 批量数据构建性能\n";
echo str_repeat("-", 80) . "\n";

$startTime = microtime(true);
$batchData = [];
$now = time();

for ($i = 0; $i < $count; $i++) {
    $job = new SendCampaignEmail($campaign->id, $subscriber->id);
    
    $batchData[] = [
        'queue' => 'test_queue',
        'payload' => json_encode([
            'uuid' => \Illuminate\Support\Str::uuid()->toString(),
            'displayName' => 'App\\Jobs\\SendCampaignEmail',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'maxTries' => 1,
            'maxExceptions' => null,
            'failOnTimeout' => false,
            'backoff' => null,
            'timeout' => 120,
            'retryUntil' => null,
            'data' => [
                'commandName' => 'App\\Jobs\\SendCampaignEmail',
                'command' => serialize($job),
            ],
        ]),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => $now,
        'sort_order' => $i + 1,
        'created_at' => $now,
    ];
}

$duration = microtime(true) - $startTime;
$totalSize = array_sum(array_map('strlen', array_column($batchData, 'payload')));
$avgSize = round($totalSize / $count);
$speed = round($count / $duration, 2);

echo "  完成时间: " . round($duration, 3) . " 秒\n";
echo "  平均速度: {$speed} 次/秒\n";
echo "  平均任务大小: {$avgSize} 字节\n";
echo "  总数据量: " . round($totalSize / 1024, 2) . " KB\n";
echo "\n";

// 外推估算
echo "📈 性能外推 (166,312 个任务)\n";
echo str_repeat("-", 80) . "\n";

$targetCount = 166312;
$multiplier = $targetCount / $count;

$estimatedTime = round($duration * $multiplier, 2);
$estimatedDataSize = round(($totalSize / 1024 / 1024) * $multiplier, 2);

echo "  预计序列化时间: {$estimatedTime} 秒\n";
echo "  预计数据量: {$estimatedDataSize} MB\n";
echo "  预计总体创建时间: " . round($estimatedTime * 1.5, 2) . " 秒 (含数据库插入)\n";
echo "\n";

// 内存使用
$memoryUsed = memory_get_peak_usage(true) / 1024 / 1024;
$estimatedMemory = round($memoryUsed * $multiplier / $count * 1000, 2); // 假设批次 1000

echo "💾 内存使用情况\n";
echo str_repeat("-", 80) . "\n";
echo "  当前测试内存: " . round($memoryUsed, 2) . " MB\n";
echo "  预计峰值内存: {$estimatedMemory} MB (批次 1000)\n";
echo "\n";

echo "✅ 测试完成！\n";

