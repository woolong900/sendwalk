#!/bin/bash

# 邮件发送服务启动脚本（自动扩缩容版本）
# 包含: 任务调度器 + Worker 自动管理器

echo "=========================================="
echo "  启动邮件发送服务（自动扩缩容）"
echo "=========================================="
echo ""

# 获取脚本所在目录
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd "$SCRIPT_DIR"

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# 检查 PHP
if ! command -v php &> /dev/null; then
    echo -e "${RED}错误: 未找到 PHP${NC}"
    exit 1
fi

echo -e "${GREEN}✓${NC} PHP 版本: $(php -v | head -n 1)"
echo ""

# 检测并停止旧进程
echo -e "${YELLOW}检查是否有旧服务正在运行...${NC}"

# 查找所有相关进程
OLD_PIDS=$(ps aux | grep -E "schedule:work|queue:manage-workers|queue:work-dynamic" | grep -v grep | awk '{print $2}')

if [ -n "$OLD_PIDS" ]; then
    echo -e "${YELLOW}发现旧服务进程，正在停止...${NC}"
    echo "$OLD_PIDS" | while read pid; do
        if [ -n "$pid" ]; then
            echo "  停止进程: $pid"
            kill $pid 2>/dev/null
        fi
    done
    
    # 等待进程退出
    sleep 2
    
    # 检查是否还有进程存活，如果有则强制终止
    REMAINING_PIDS=$(ps aux | grep -E "schedule:work|queue:manage-workers|queue:work-dynamic" | grep -v grep | awk '{print $2}')
    if [ -n "$REMAINING_PIDS" ]; then
        echo -e "${YELLOW}强制终止残留进程...${NC}"
        echo "$REMAINING_PIDS" | while read pid; do
            if [ -n "$pid" ]; then
                kill -9 $pid 2>/dev/null
            fi
        done
        sleep 1
    fi
    
    echo -e "${GREEN}✓${NC} 旧服务已停止"
else
    echo -e "${GREEN}✓${NC} 没有旧服务运行"
fi
echo ""

# 创建日志目录
mkdir -p storage/logs

# 启动 Laravel 任务调度器
echo -e "${YELLOW}启动任务调度器...${NC}"
echo "  - 每分钟检查定时活动"
echo "  - 日志: storage/logs/scheduler.log"
echo ""

nohup php artisan schedule:work > storage/logs/scheduler.log 2>&1 &
SCHEDULER_PID=$!
echo -e "${GREEN}✓${NC} 任务调度器已启动 (PID: $SCHEDULER_PID)"
echo ""

sleep 1

# 启动 Worker 自动管理器（按队列管理）
echo -e "${YELLOW}启动 Worker 自动管理器（按队列）...${NC}"
echo "  - 模式: 每个队列独立管理"
echo "  - 最小 Worker/队列: 1"
echo "  - 最大 Worker/队列: 4"
echo "  - 检查间隔: 10秒"
echo "  - 扩容阈值: 50 任务/Worker"
echo "  - 缩容阈值: 10 任务/Worker"
echo "  - 日志: storage/logs/manager.log"
echo ""

nohup php artisan queue:manage-workers \
    --min=1 \
    --max=4 \
    --check-interval=10 \
    --scale-up-threshold=50 \
    --scale-down-threshold=10 \
    > storage/logs/manager.log 2>&1 &
MANAGER_PID=$!
echo -e "${GREEN}✓${NC} Worker 管理器已启动 (PID: $MANAGER_PID)"

echo ""
echo "=========================================="
echo -e "${GREEN}  所有服务已启动！${NC}"
echo "=========================================="
echo ""
echo "查看日志："
echo "  任务调度器: tail -f storage/logs/scheduler.log"
echo "  Worker 管理器: tail -f storage/logs/manager.log"
echo "  活动 Worker:  tail -f storage/logs/campaign_*-worker-*.log"
echo ""
echo "停止所有服务："
echo "  ./stop_worker.sh"
echo ""
echo "查看实时状态："
echo "  watch -n 1 'ps aux | grep artisan'"
echo ""

