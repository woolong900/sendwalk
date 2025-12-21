#!/bin/bash

# Worker 状态查看脚本

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo "=========================================="
echo "  邮件发送服务状态"
echo "=========================================="
echo ""

# 检查调度器
SCHEDULER_COUNT=$(ps aux | grep "php.*artisan schedule:work" | grep -v grep | wc -l)
if [ $SCHEDULER_COUNT -gt 0 ]; then
    echo -e "${GREEN}✓${NC} 任务调度器：运行中 (${SCHEDULER_COUNT} 个进程)"
    ps aux | grep "php.*artisan schedule:work" | grep -v grep | awk '{printf "  PID: %s, 启动时间: %s\n", $2, $9}'
else
    echo -e "${RED}✗${NC} 任务调度器：未运行"
fi
echo ""

# 检查 Worker 管理器
MANAGER_COUNT=$(ps aux | grep "php.*artisan queue:manage-workers" | grep -v grep | wc -l)
if [ $MANAGER_COUNT -gt 0 ]; then
    echo -e "${GREEN}✓${NC} Worker 管理器：运行中 (${MANAGER_COUNT} 个进程)"
    ps aux | grep "php.*artisan queue:manage-workers" | grep -v grep | awk '{printf "  PID: %s, 启动时间: %s\n", $2, $9}'
else
    echo -e "${RED}✗${NC} Worker 管理器：未运行"
fi
echo ""

# 检查 Worker 进程（只统计真实的 PHP worker，不包括 bash 包装器）
WORKER_COUNT=$(ps aux | grep "artisan queue:work-dynamic" | grep -v grep | grep -v bash | wc -l)
WRAPPER_COUNT=$(ps aux | grep "bash.*queue:work-dynamic" | grep -v grep | wc -l)

if [ $WORKER_COUNT -gt 0 ]; then
    echo -e "${GREEN}✓${NC} Worker 进程：${WORKER_COUNT} 个活跃"
    ps aux | grep "artisan queue:work-dynamic" | grep -v grep | grep -v bash | awk '{printf "  Worker PID: %s, 内存: %sMB, CPU: %s%%, 启动: %s\n", $2, int($6/1024), $3, $9}'
else
    echo -e "${YELLOW}⚠${NC}  Worker 进程：0 个活跃（可能正在重启）"
fi

if [ $WRAPPER_COUNT -gt 0 ]; then
    echo -e "${BLUE}ℹ${NC}  Bash 包装器：${WRAPPER_COUNT} 个（自动重启机制）"
fi
echo ""

# 显示最新的管理器日志
if [ -f "storage/logs/manager.log" ]; then
    echo "=========================================="
    echo "  最新状态（来自管理器日志）"
    echo "=========================================="
    tail -1 storage/logs/manager.log
    echo ""
fi

# 显示队列状态
echo "=========================================="
echo "  队列统计"
echo "=========================================="

# 获取脚本所在目录
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd "$SCRIPT_DIR"

php -r "
require 'vendor/autoload.php';
\$app = require_once 'bootstrap/app.php';
\$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

\$totalJobs = DB::table('jobs')->count();
\$availableJobs = DB::table('jobs')->whereNull('reserved_at')->count();
\$reservedJobs = DB::table('jobs')->whereNotNull('reserved_at')->count();
\$sendingCampaigns = App\Models\Campaign::where('status', 'sending')->count();

echo '待处理任务：' . \$totalJobs . ' 个' . PHP_EOL;
echo '  - 可用：' . \$availableJobs . ' 个' . PHP_EOL;
echo '  - 处理中：' . \$reservedJobs . ' 个' . PHP_EOL;
echo '正在发送的活动：' . \$sendingCampaigns . ' 个' . PHP_EOL;
"

echo ""
echo "=========================================="
echo ""
echo "查看实时日志："
echo "  活动 Worker:    tail -f storage/logs/campaign_*-worker-*.log"
echo "  Worker 管理器:  tail -f storage/logs/manager.log"
echo "  任务调度器:     tail -f storage/logs/scheduler.log"
echo ""

