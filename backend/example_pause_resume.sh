#!/bin/bash

# 暂停/恢复功能示例

echo "=========================================="
echo "  暂停/恢复功能演示"
echo "=========================================="
echo ""

cd "$(dirname "$0")"

echo "示例 1: 暂停一个发送中的活动"
echo "--------------------------------------"
echo ""
echo "前提条件："
echo "  - 活动状态：sending"
echo "  - 队列中有待发送任务"
echo ""
echo "执行："
echo "  curl -X POST http://localhost:8000/api/campaigns/12/pause \\"
echo "       -H 'Authorization: Bearer YOUR_TOKEN'"
echo ""
echo "结果："
echo "  ✅ 活动状态：sending → paused"
echo "  ✅ 队列任务被延迟"
echo "  ✅ 不再发送新邮件"
echo ""

echo "示例 2: 恢复一个已暂停的活动"
echo "--------------------------------------"
echo ""
echo "前提条件："
echo "  - 活动状态：paused"
echo ""
echo "执行："
echo "  curl -X POST http://localhost:8000/api/campaigns/12/resume \\"
echo "       -H 'Authorization: Bearer YOUR_TOKEN'"
echo ""
echo "结果："
echo "  ✅ 活动状态：paused → sending"
echo "  ✅ 队列任务立即可用"
echo "  ✅ Worker 继续处理"
echo ""

echo "示例 3: 查看暂停的效果"
echo "--------------------------------------"
echo ""

php -r "
require 'vendor/autoload.php';
\$app = require_once 'bootstrap/app.php';
\$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo '当前暂停的活动：' . PHP_EOL;
\$paused = App\Models\Campaign::where('status', 'paused')->get();

if (\$paused->count() === 0) {
    echo '  (无)' . PHP_EOL;
} else {
    foreach (\$paused as \$campaign) {
        echo '  Campaign #' . \$campaign->id . ': ' . \$campaign->name . PHP_EOL;
        echo '    Recipients: ' . \$campaign->total_recipients . PHP_EOL;
        echo '    Sent: ' . \$campaign->total_sent . PHP_EOL;
        echo '    Remaining: ' . (\$campaign->total_recipients - \$campaign->total_sent) . PHP_EOL;
    }
}
" || echo "无法查询数据库"

echo ""
echo "=========================================="
echo "  完成"
echo "=========================================="
echo ""

