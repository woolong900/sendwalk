#!/bin/bash

echo "🛑 正在停止 SendWalk 邮件营销平台..."
echo ""

# 停止后端服务
if lsof -Pi :8000 -sTCP:LISTEN -t >/dev/null ; then
    echo "▶️  停止后端服务..."
    lsof -ti:8000 | xargs kill -9
fi

# 停止队列处理
if pgrep -f "artisan queue:work" > /dev/null; then
    echo "▶️  停止队列处理..."
    pkill -f "artisan queue:work"
fi

# 停止前端服务
if lsof -Pi :5173 -sTCP:LISTEN -t >/dev/null ; then
    echo "▶️  停止前端服务..."
    lsof -ti:5173 | xargs kill -9
fi

echo ""
echo "✅ 所有服务已停止！"
