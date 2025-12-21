#!/bin/bash

# Worker 优雅退出测试脚本

echo "======================================"
echo "Worker 优雅退出机制测试"
echo "======================================"
echo ""

# 检查 PCNTL 扩展
echo "1. 检查 PCNTL 扩展..."
if php -m | grep -q pcntl; then
    echo "   ✅ PCNTL 扩展已安装"
else
    echo "   ❌ PCNTL 扩展未安装（优雅退出将不可用）"
fi
echo ""

# 检查是否有正在运行的 Worker
echo "2. 检查正在运行的 Worker..."
RUNNING_WORKERS=$(pgrep -f "campaign:process-queue" | wc -l)
if [ $RUNNING_WORKERS -gt 0 ]; then
    echo "   找到 $RUNNING_WORKERS 个正在运行的 Worker"
    echo ""
    echo "   Worker 列表:"
    ps aux | grep "campaign:process-queue" | grep -v grep | while read line; do
        PID=$(echo $line | awk '{print $2}')
        CAMPAIGN=$(echo $line | grep -o "campaign:process-queue [0-9]\+" | awk '{print $2}')
        echo "   - PID: $PID, Campaign ID: $CAMPAIGN"
    done
    echo ""
    
    # 询问是否测试优雅退出
    read -p "是否要测试优雅退出这些 Worker？(y/n) " -n 1 -r
    echo ""
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        echo ""
        echo "3. 发送 SIGTERM 信号..."
        pgrep -f "campaign:process-queue" | while read pid; do
            echo "   发送信号到 PID: $pid"
            kill $pid
        done
        
        echo ""
        echo "4. 等待 Worker 退出（最多 15 秒）..."
        
        # 等待 Worker 退出
        TIMEOUT=15
        ELAPSED=0
        while [ $ELAPSED -lt $TIMEOUT ]; do
            REMAINING=$(pgrep -f "campaign:process-queue" | wc -l)
            if [ $REMAINING -eq 0 ]; then
                echo "   ✅ 所有 Worker 已优雅退出 (耗时: ${ELAPSED}s)"
                break
            fi
            sleep 1
            ELAPSED=$((ELAPSED + 1))
            echo -n "."
        done
        echo ""
        
        # 检查是否还有 Worker 在运行
        REMAINING=$(pgrep -f "campaign:process-queue" | wc -l)
        if [ $REMAINING -gt 0 ]; then
            echo "   ⚠️  还有 $REMAINING 个 Worker 未退出（可能正在处理任务或休眠）"
            echo ""
            read -p "是否强制终止剩余 Worker？(y/n) " -n 1 -r
            echo ""
            if [[ $REPLY =~ ^[Yy]$ ]]; then
                echo "   强制终止..."
                pkill -9 -f "campaign:process-queue"
                echo "   ✅ 已强制终止"
            fi
        fi
        
        echo ""
        echo "5. 查看日志（最后 20 行）..."
        echo "   ----------------------------------------"
        tail -n 20 storage/logs/laravel.log | grep -A 5 "shutdown"
        echo "   ----------------------------------------"
    fi
else
    echo "   ℹ️  没有正在运行的 Worker"
    echo ""
    echo "   要启动测试 Worker，请运行："
    echo "   php artisan campaign:process-queue <campaign_id>"
fi

echo ""
echo "======================================"
echo "测试完成"
echo "======================================"

