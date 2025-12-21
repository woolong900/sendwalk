#!/bin/bash

# 测试暂停/恢复功能

echo "=========================================="
echo "  测试活动暂停/恢复功能"
echo "=========================================="
echo ""

cd "$(dirname "$0")"

echo "步骤 1: 创建一个测试活动（sending 状态）"
php -r "
require 'vendor/autoload.php';
\$app = require_once 'bootstrap/app.php';
\$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// 找一个 sending 状态的活动，或创建一个
\$campaign = App\Models\Campaign::where('status', 'sending')->first();

if (!\$campaign) {
    echo '没有发送中的活动，创建测试数据...' . PHP_EOL;
    // 这里可以创建测试数据，但现在先跳过
    echo '请先创建一个发送中的活动进行测试' . PHP_EOL;
    exit(1);
}

echo '找到活动: Campaign #' . \$campaign->id . ': ' . \$campaign->name . PHP_EOL;
echo '  Status: ' . \$campaign->status . PHP_EOL;
echo '  Sent: ' . \$campaign->total_sent . '/' . \$campaign->total_recipients . PHP_EOL;

// 检查队列任务
\$queueName = 'smtp_' . \$campaign->smtp_server_id;
\$jobs = DB::table('jobs')->where('queue', \$queueName)->get();
echo '  Queue jobs: ' . \$jobs->count() . PHP_EOL;

if (\$jobs->count() > 0) {
    echo '  Available at: ' . date('Y-m-d H:i:s', \$jobs->first()->available_at) . PHP_EOL;
}

echo PHP_EOL . '准备测试...' . PHP_EOL;
echo 'Campaign ID: ' . \$campaign->id . PHP_EOL;
" || exit 1

echo ""
echo "=========================================="
echo "  测试完成"
echo "=========================================="
echo ""
echo "使用 Postman 或 curl 测试 API:"
echo ""
echo "1. 暂停活动:"
echo "   POST /api/campaigns/{id}/pause"
echo ""
echo "2. 恢复活动:"
echo "   POST /api/campaigns/{id}/resume"
echo ""

