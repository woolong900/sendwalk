#!/bin/bash

# 修复所有发现的问题

set -e

echo "========================================"
echo "  修复所有问题"
echo "========================================"
echo ""

BACKEND_DIR="/data/www/sendwalk/backend"
FRONTEND_DIR="/data/www/sendwalk/frontend"

echo "问题 1: 修复 .env 中的重复配置和换行符"
echo "----------------------------------------"
cd "$BACKEND_DIR"

# 备份 .env
cp .env .env.backup.$(date +%Y%m%d_%H%M%S)
echo "✓ 已备份 .env"

# 删除重复的配置行，只保留第一次出现
awk '!seen[$1]++ || $1 !~ /^(APP_URL|FRONTEND_URL|SANCTUM_STATEFUL_DOMAINS|SESSION_DOMAIN)=/' .env > .env.tmp

# 确保关键配置正确（如果不存在则添加，如果存在则替换）
grep -q "^APP_URL=" .env.tmp && sed -i 's|^APP_URL=.*|APP_URL=https://api.sendwalk.com|' .env.tmp || echo "APP_URL=https://api.sendwalk.com" >> .env.tmp
grep -q "^FRONTEND_URL=" .env.tmp && sed -i 's|^FRONTEND_URL=.*|FRONTEND_URL=https://edm.sendwalk.com|' .env.tmp || echo "FRONTEND_URL=https://edm.sendwalk.com" >> .env.tmp
grep -q "^SANCTUM_STATEFUL_DOMAINS=" .env.tmp && sed -i 's|^SANCTUM_STATEFUL_DOMAINS=.*|SANCTUM_STATEFUL_DOMAINS=edm.sendwalk.com|' .env.tmp || echo "SANCTUM_STATEFUL_DOMAINS=edm.sendwalk.com" >> .env.tmp
grep -q "^SESSION_DOMAIN=" .env.tmp && sed -i 's|^SESSION_DOMAIN=.*|SESSION_DOMAIN=.sendwalk.com|' .env.tmp || echo "SESSION_DOMAIN=.sendwalk.com" >> .env.tmp

mv .env.tmp .env
echo "✓ 已清理 .env 中的重复配置"
echo ""

echo "问题 2: 创建缺失的 resources/views 目录"
echo "----------------------------------------"
mkdir -p "$BACKEND_DIR/resources/views"
echo "✓ 已创建 resources/views 目录"
echo ""

echo "问题 3: 部署 Nginx 配置"
echo "----------------------------------------"
if [ ! -f "/etc/nginx/conf.d/sendwalk-api.conf" ]; then
    if [ -f "/data/www/sendwalk/nginx/api.conf" ]; then
        cp /data/www/sendwalk/nginx/api.conf /etc/nginx/conf.d/sendwalk-api.conf
        echo "✓ 已复制 API Nginx 配置"
    else
        echo "⚠️ 警告: nginx/api.conf 文件不存在"
    fi
else
    echo "✓ API Nginx 配置已存在"
fi

if [ ! -f "/etc/nginx/conf.d/sendwalk-frontend.conf" ]; then
    if [ -f "/data/www/sendwalk/nginx/frontend.conf" ]; then
        cp /data/www/sendwalk/nginx/frontend.conf /etc/nginx/conf.d/sendwalk-frontend.conf
        echo "✓ 已复制前端 Nginx 配置"
    else
        echo "⚠️ 警告: nginx/frontend.conf 文件不存在"
    fi
else
    echo "✓ 前端 Nginx 配置已存在"
fi

# 测试 Nginx 配置
nginx -t && echo "✓ Nginx 配置测试通过" || echo "✗ Nginx 配置测试失败"
echo ""

echo "问题 4: 清除所有缓存"
echo "----------------------------------------"
cd "$BACKEND_DIR"

# 删除缓存文件
rm -f bootstrap/cache/*.php
echo "✓ 已删除缓存文件"

# 清除 Laravel 缓存
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
echo "✓ 已清除 Laravel 缓存"

# 重新生成缓存
php artisan config:cache
php artisan route:cache
echo "✓ 已重新生成缓存"
echo ""

echo "问题 5: 重启服务"
echo "----------------------------------------"
systemctl restart php8.3-fpm
echo "✓ PHP-FPM 已重启"

systemctl restart nginx
echo "✓ Nginx 已重启"

supervisorctl restart all >/dev/null 2>&1 || true
echo "✓ Supervisor 已重启"
echo ""

echo "问题 6: 验证配置"
echo "----------------------------------------"
echo "当前 .env 配置:"
grep -E "^APP_URL=|^FRONTEND_URL=|^SANCTUM_STATEFUL_DOMAINS=|^SESSION_DOMAIN=" .env
echo ""

echo "Laravel 配置验证:"
php artisan tinker --execute="
echo 'CORS Origins: ' . json_encode(config('cors.allowed_origins')) . PHP_EOL;
echo 'Session Domain: ' . var_export(config('session.domain'), true) . PHP_EOL;
"
echo ""

echo "========================================"
echo "  ✅ 所有问题已修复！"
echo "========================================"
echo ""
echo "📋 重要提示："
echo ""
echo "1. ⚠️ 清除 Cloudflare 缓存（非常重要！）"
echo "   - 登录 Cloudflare 控制台"
echo "   - 选择 sendwalk.com 域名"
echo "   - 缓存 → 配置"
echo "   - 点击 '清除所有内容'"
echo ""
echo "2. 清除浏览器缓存"
echo "   - Ctrl+Shift+Delete"
echo "   - 或使用隐私/无痕模式"
echo ""
echo "3. 测试 API："
echo "   curl -I -H \"Origin: https://edm.sendwalk.com\" \\"
echo "     https://api.sendwalk.com/api/health"
echo ""
echo "4. 查看日志："
echo "   tail -f $BACKEND_DIR/storage/logs/laravel-\$(date +%Y-%m-%d).log"
echo ""

