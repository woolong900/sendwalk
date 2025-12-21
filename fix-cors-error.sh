#!/bin/bash

# SendWalk CORS 错误修复脚本
# 用于修复前端请求后端接口的 CORS 错误

set -e

echo "========================================"
echo "  修复 CORS 跨域错误"
echo "========================================"
echo ""

# 切换到后端目录
cd "$(dirname "$0")/backend"

echo "🔍 检查当前 CORS 配置..."
echo ""

# 检查 .env 文件中的关键配置
if [ -f .env ]; then
    echo "当前配置:"
    grep -E "FRONTEND_URL|SANCTUM_STATEFUL_DOMAINS|SESSION_DOMAIN|APP_URL" .env || echo "  未找到相关配置"
    echo ""
else
    echo "❌ 错误: .env 文件不存在"
    echo "   请先创建 .env 文件"
    exit 1
fi

echo "📝 更新 .env 配置..."
echo ""

# 备份 .env
cp .env .env.backup.$(date +%Y%m%d_%H%M%S)
echo "✓ 已备份 .env 文件"

# 确保必要的 CORS 配置存在
if ! grep -q "FRONTEND_URL=" .env; then
    echo "FRONTEND_URL=https://edm.sendwalk.com" >> .env
    echo "✓ 添加 FRONTEND_URL"
fi

if ! grep -q "SANCTUM_STATEFUL_DOMAINS=" .env; then
    echo "SANCTUM_STATEFUL_DOMAINS=edm.sendwalk.com" >> .env
    echo "✓ 添加 SANCTUM_STATEFUL_DOMAINS"
fi

if ! grep -q "SESSION_DOMAIN=" .env; then
    echo "SESSION_DOMAIN=.sendwalk.com" >> .env
    echo "✓ 添加 SESSION_DOMAIN"
fi

# 更新 FRONTEND_URL（如果不正确）
sed -i.bak 's|FRONTEND_URL=.*|FRONTEND_URL=https://edm.sendwalk.com|' .env
echo "✓ 更新 FRONTEND_URL"

# 更新 SANCTUM_STATEFUL_DOMAINS（添加 localhost 用于开发）
sed -i.bak 's|SANCTUM_STATEFUL_DOMAINS=.*|SANCTUM_STATEFUL_DOMAINS=edm.sendwalk.com,localhost,localhost:5173,127.0.0.1:5173|' .env
echo "✓ 更新 SANCTUM_STATEFUL_DOMAINS"

# 更新 SESSION_DOMAIN
sed -i.bak 's|SESSION_DOMAIN=.*|SESSION_DOMAIN=.sendwalk.com|' .env
echo "✓ 更新 SESSION_DOMAIN"

# 确保 APP_URL 正确
if ! grep -q "APP_URL=" .env; then
    echo "APP_URL=https://api.sendwalk.com" >> .env
    echo "✓ 添加 APP_URL"
else
    sed -i.bak 's|APP_URL=.*|APP_URL=https://api.sendwalk.com|' .env
    echo "✓ 更新 APP_URL"
fi

echo ""
echo "🧹 清除配置缓存..."

# 清除配置缓存
php artisan config:clear
echo "✓ 配置缓存已清除"

# 重新生成配置缓存
php artisan config:cache
echo "✓ 配置缓存已重建"

echo ""
echo "📋 当前 CORS 配置:"
echo ""
grep -E "FRONTEND_URL|SANCTUM_STATEFUL_DOMAINS|SESSION_DOMAIN|APP_URL" .env

echo ""
echo "========================================"
echo "  ✅ CORS 配置已更新！"
echo "========================================"
echo ""
echo "下一步操作:"
echo ""
echo "1. 重启 PHP-FPM:"
echo "   sudo systemctl restart php8.3-fpm"
echo ""
echo "2. 重启 Supervisor 进程:"
echo "   sudo supervisorctl restart all"
echo ""
echo "3. 检查前端 .env 文件:"
echo "   前端环境变量应该是:"
echo "   VITE_API_URL=https://api.sendwalk.com"
echo ""
echo "4. 如果前端 .env 有变化，需要重新构建:"
echo "   cd /data/www/sendwalk/frontend"
echo "   npm run build"
echo ""
echo "5. 测试 CORS:"
echo "   在浏览器中打开 https://edm.sendwalk.com"
echo "   打开开发者工具的 Network 选项卡"
echo "   查看 API 请求是否正常"
echo ""

