#!/bin/bash

echo "📊 SendWalk 服务状态"
echo "════════════════════════════════════"
echo ""

# 检查后端
if lsof -Pi :8000 -sTCP:LISTEN -t >/dev/null ; then
    echo "✅ 后端服务: 运行中 (http://localhost:8000)"
else
    echo "❌ 后端服务: 未运行"
fi

# 检查队列
if pgrep -f "artisan queue:work" > /dev/null; then
    QUEUE_PID=$(pgrep -f "artisan queue:work")
    echo "✅ 队列处理: 运行中 (PID: $QUEUE_PID)"
else
    echo "❌ 队列处理: 未运行"
fi

# 检查前端
if lsof -Pi :5173 -sTCP:LISTEN -t >/dev/null ; then
    echo "✅ 前端服务: 运行中 (http://localhost:5173)"
else
    echo "❌ 前端服务: 未运行"
fi

echo ""
echo "════════════════════════════════════"
