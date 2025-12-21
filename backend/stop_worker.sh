#!/bin/bash

# 停止邮件发送服务脚本（自动扩缩容版本）

echo "=========================================="
echo "  停止邮件发送服务（自动扩缩容）"
echo "=========================================="
echo ""

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# 查找所有相关进程
PROCESSES=$(ps aux | grep -E '(artisan (schedule:work|queue:work-dynamic|queue:manage-workers)|run-worker.sh)' | grep -v grep | awk '{print $2}')

if [ -z "$PROCESSES" ]; then
    echo -e "${YELLOW}没有找到运行中的服务${NC}"
    exit 0
fi

echo "找到以下进程："
ps aux | grep -E '(artisan (schedule:work|queue:work-dynamic|queue:manage-workers)|run-worker.sh)' | grep -v grep
echo ""

# 停止进程
echo -e "${YELLOW}正在停止进程...${NC}"
echo ""

for PID in $PROCESSES; do
    # Kill children first
    pkill -P $PID 2>/dev/null
    
    # Then kill the parent
    kill $PID 2>/dev/null
    if [ $? -eq 0 ]; then
        echo -e "${GREEN}✓${NC} 已停止进程: $PID"
    else
        echo -e "${RED}✗${NC} 无法停止进程: $PID"
    fi
done

echo ""
echo "等待进程退出..."
sleep 2

# 检查是否还有残留进程
REMAINING=$(ps aux | grep -E '(artisan (schedule:work|queue:work-dynamic|queue:manage-workers)|run-worker.sh)' | grep -v grep | awk '{print $2}')

if [ -z "$REMAINING" ]; then
    echo -e "${GREEN}✓ 所有服务已停止${NC}"
else
    echo -e "${YELLOW}警告: 仍有进程在运行，尝试强制终止...${NC}"
    for PID in $REMAINING; do
        pkill -9 -P $PID 2>/dev/null
        kill -9 $PID 2>/dev/null
        echo -e "${GREEN}✓${NC} 强制终止进程: $PID"
    done
fi

echo ""

# 可选：将发送中的活动改回定时状态
echo -e "${YELLOW}检查发送中的活动...${NC}"
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd "$SCRIPT_DIR"

php -r "
require 'vendor/autoload.php';
\$app = require_once 'bootstrap/app.php';
\$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

\$sendingCampaigns = App\Models\Campaign::where('status', 'sending')->get();
if (\$sendingCampaigns->count() > 0) {
    echo '发现 ' . \$sendingCampaigns->count() . ' 个发送中的活动' . PHP_EOL;
    echo '这些活动将保持\"发送中\"状态，重启 Worker 后会继续发送' . PHP_EOL;
    echo '' . PHP_EOL;
    echo '如需重置为定时状态，请运行：' . PHP_EOL;
    echo '  php artisan tinker --execute=\"App\\\\Models\\\\Campaign::where(\'status\', \'sending\')->update([\'status\' => \'scheduled\']);\"' . PHP_EOL;
}
" 2>/dev/null

echo ""
echo "=========================================="
echo -e "${GREEN}  完成${NC}"
echo "=========================================="
echo ""

