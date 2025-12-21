#!/bin/bash

echo "🚀 正在启动 SendWalk 邮件营销平台..."
echo ""

# 检查后端是否已启动
if lsof -Pi :8000 -sTCP:LISTEN -t >/dev/null ; then
    echo "⚠️  后端服务已在运行 (端口 8000)"
else
    echo "▶️  启动后端服务..."
    cd backend && php artisan serve &
    sleep 2
fi

# 检查队列是否已启动
if pgrep -f "artisan queue:work" > /dev/null; then
    echo "⚠️  队列处理已在运行"
else
    echo "▶️  启动队列处理..."
    cd backend && php artisan queue:work redis --tries=3 --timeout=60 &
    sleep 1
fi

# 检查前端是否已启动
if lsof -Pi :5173 -sTCP:LISTEN -t >/dev/null ; then
    echo "⚠️  前端服务已在运行 (端口 5173)"
else
    echo "▶️  启动前端服务..."
    cd frontend && npm run dev &
fi

echo ""
echo "✅ 所有服务已启动！"
echo ""
echo "📍 访问地址："
echo "   前端: http://localhost:5173"
echo "   后端: http://localhost:8000"
echo ""
echo "💡 提示："
echo "   - 按 Ctrl+C 停止所有服务"
echo "   - 查看队列状态: cd backend && php artisan queue:monitor"
echo ""
