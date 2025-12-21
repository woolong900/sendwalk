#!/bin/bash

# 安全地重置发送中的活动

echo "=========================================="
echo "  重置发送中的活动"
echo "=========================================="
echo ""

# 颜色定义
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# 获取脚本所在目录
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd "$SCRIPT_DIR"

php -r "
require 'vendor/autoload.php';
\$app = require_once 'bootstrap/app.php';
\$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo '检查发送中的活动...' . PHP_EOL;
\$sendingCampaigns = App\Models\Campaign::where('status', 'sending')->get();

if (\$sendingCampaigns->count() === 0) {
    echo '没有发送中的活动' . PHP_EOL;
    exit(0);
}

echo '发现 ' . \$sendingCampaigns->count() . ' 个发送中的活动:' . PHP_EOL;
echo '' . PHP_EOL;

foreach (\$sendingCampaigns as \$campaign) {
    echo 'Campaign #' . \$campaign->id . ': ' . \$campaign->name . PHP_EOL;
    echo '  Recipients: ' . \$campaign->total_recipients . PHP_EOL;
    echo '  Sent: ' . \$campaign->total_sent . PHP_EOL;
    echo '  Scheduled at: ' . \$campaign->scheduled_at . PHP_EOL;
    
    // 将 scheduled_at 改到未来（1小时后），避免立即被调度器处理
    \$newScheduledAt = now()->addHour();
    
    \$campaign->update([
        'status' => 'scheduled',
        'scheduled_at' => \$newScheduledAt,
        'total_sent' => 0,
    ]);
    
    // 清除该活动的队列任务
    \$deletedJobs = DB::table('jobs')
        ->where('queue', 'smtp_' . \$campaign->smtp_server_id)
        ->delete();
    
    echo '  ✅ 已重置为定时状态' . PHP_EOL;
    echo '  ✅ 新的定时时间: ' . \$newScheduledAt . PHP_EOL;
    echo '  ✅ 清除了 ' . \$deletedJobs . ' 个队列任务' . PHP_EOL;
    echo '' . PHP_EOL;
}

echo '✅ 所有活动已安全重置' . PHP_EOL;
echo '' . PHP_EOL;
echo '注意：活动的定时时间已改到 1 小时后' . PHP_EOL;
echo '如需立即发送，请在前端修改定时时间' . PHP_EOL;
"

echo ""
echo "=========================================="
echo -e "${GREEN}  完成${NC}"
echo "=========================================="
echo ""

