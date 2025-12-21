#!/bin/bash

# SendWalk CORS 完整修复脚本
# 一键修复所有 CORS 相关问题

set -e

echo "========================================"
echo "  CORS 完整修复工具"
echo "========================================"
echo ""

# 检查是否以 root 运行
if [ "$EUID" -ne 0 ]; then 
    echo "⚠️  建议使用 sudo 运行此脚本以确保权限正确"
    echo "   sudo ./fix-cors-complete.sh"
    echo ""
    read -p "是否继续? (y/n) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        exit 1
    fi
fi

BACKEND_DIR="/data/www/sendwalk/backend"
FRONTEND_DIR="/data/www/sendwalk/frontend"

echo "📋 步骤 1/8: 检查目录"
echo "----------------------------------------"
if [ ! -d "$BACKEND_DIR" ]; then
    echo "❌ 错误: 后端目录不存在 $BACKEND_DIR"
    exit 1
fi
echo "✓ 后端目录存在"

if [ ! -d "$FRONTEND_DIR" ]; then
    echo "❌ 错误: 前端目录不存在 $FRONTEND_DIR"
    exit 1
fi
echo "✓ 前端目录存在"
echo ""

echo "📋 步骤 2/8: 更新后端配置"
echo "----------------------------------------"
cd "$BACKEND_DIR"

# 备份 .env
if [ -f .env ]; then
    cp .env .env.backup.$(date +%Y%m%d_%H%M%S)
    echo "✓ 已备份 .env"
fi

# 确保配置存在并更新
grep -q "^APP_URL=" .env && sed -i.bak 's|^APP_URL=.*|APP_URL=https://api.sendwalk.com|' .env || echo "APP_URL=https://api.sendwalk.com" >> .env
grep -q "^FRONTEND_URL=" .env && sed -i.bak 's|^FRONTEND_URL=.*|FRONTEND_URL=https://edm.sendwalk.com|' .env || echo "FRONTEND_URL=https://edm.sendwalk.com" >> .env
grep -q "^SANCTUM_STATEFUL_DOMAINS=" .env && sed -i.bak 's|^SANCTUM_STATEFUL_DOMAINS=.*|SANCTUM_STATEFUL_DOMAINS=edm.sendwalk.com|' .env || echo "SANCTUM_STATEFUL_DOMAINS=edm.sendwalk.com" >> .env
grep -q "^SESSION_DOMAIN=" .env && sed -i.bak 's|^SESSION_DOMAIN=.*|SESSION_DOMAIN=.sendwalk.com|' .env || echo "SESSION_DOMAIN=.sendwalk.com" >> .env

echo "✓ 后端配置已更新"
echo ""

echo "📋 步骤 3/8: 清除所有缓存"
echo "----------------------------------------"
php artisan config:clear || echo "  跳过 config:clear"
php artisan cache:clear || echo "  跳过 cache:clear"
php artisan route:clear || echo "  跳过 route:clear"
php artisan view:clear || echo "  跳过 view:clear"
echo "✓ Laravel 缓存已清除"
echo ""

echo "📋 步骤 4/8: 重新生成缓存"
echo "----------------------------------------"
php artisan config:cache
php artisan route:cache
php artisan view:cache
echo "✓ Laravel 缓存已重建"
echo ""

echo "📋 步骤 5/8: 更新前端配置"
echo "----------------------------------------"
cd "$FRONTEND_DIR"

# 创建/更新前端 .env
cat > .env << 'EOF'
VITE_API_URL=https://api.sendwalk.com
VITE_APP_NAME=SendWalk
EOF

echo "✓ 前端配置已更新"
echo ""

echo "📋 步骤 6/8: 重新构建前端"
echo "----------------------------------------"
echo "  删除旧构建..."
rm -rf dist

echo "  构建前端（这可能需要几分钟）..."
npm run build

if [ -d "dist" ]; then
    echo "✓ 前端构建成功"
    
    # 验证 API URL
    if grep -r "api.sendwalk.com" dist/assets/ >/dev/null 2>&1; then
        echo "✓ 构建产物包含正确的 API URL"
    else
        echo "⚠️  警告: 构建产物中未找到 API URL"
    fi
else
    echo "❌ 前端构建失败"
    exit 1
fi
echo ""

echo "📋 步骤 7/8: 重启服务"
echo "----------------------------------------"
echo "  重启 PHP-FPM..."
systemctl restart php8.3-fpm
echo "✓ PHP-FPM 已重启"

echo "  重启 Nginx..."
systemctl restart nginx
echo "✓ Nginx 已重启"

echo "  重启 Supervisor 进程..."
supervisorctl restart all >/dev/null 2>&1 || echo "  （Supervisor 可能未配置）"
echo "✓ Supervisor 进程已重启"
echo ""

echo "📋 步骤 8/8: 验证配置"
echo "----------------------------------------"
cd "$BACKEND_DIR"

echo "当前配置:"
grep -E "^APP_URL=|^FRONTEND_URL=|^SANCTUM_STATEFUL_DOMAINS=|^SESSION_DOMAIN=" .env

echo ""
echo "测试 API 连接..."
if curl -s -I https://api.sendwalk.com/api/health >/dev/null 2>&1; then
    echo "✓ API 可访问"
else
    echo "⚠️  警告: API 可能无法访问"
fi

echo ""
echo "测试 CORS..."
CORS_HEADER=$(curl -s -I \
    -H "Origin: https://edm.sendwalk.com" \
    -X OPTIONS \
    https://api.sendwalk.com/api/health 2>&1 | grep -i "access-control-allow-origin")

if [ -n "$CORS_HEADER" ]; then
    echo "✓ CORS 头存在"
    echo "  $CORS_HEADER"
else
    echo "⚠️  警告: CORS 头未找到"
fi
echo ""

echo "========================================"
echo "  ✅ 修复完成！"
echo "========================================"
echo ""
echo "📋 验证步骤:"
echo ""
echo "1. 清除浏览器缓存（Ctrl+Shift+Delete）"
echo ""
echo "2. 访问前端:"
echo "   https://edm.sendwalk.com"
echo ""
echo "3. 打开开发者工具（F12）"
echo "   - 切换到 Network 选项卡"
echo "   - 尝试登录或调用 API"
echo "   - 检查请求的 Response Headers"
echo "   - 应该看到: access-control-allow-origin: https://edm.sendwalk.com"
echo ""
echo "4. 检查 Console 选项卡"
echo "   - 不应该有 CORS 错误"
echo ""
echo "5. 如果仍有问题，查看日志:"
echo "   tail -50 $BACKEND_DIR/storage/logs/laravel-$(date +%Y-%m-%d).log"
echo ""
echo "📚 详细文档:"
echo "   cat CORS问题完整排查指南.md"
echo ""

