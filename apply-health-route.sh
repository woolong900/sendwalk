#!/bin/bash

# 应用 health 路由更新

set -e

echo "========================================"
echo "  应用更新并测试"
echo "========================================"
echo ""

BACKEND_DIR="/data/www/sendwalk/backend"

echo "1. 清除路由缓存"
echo "========================================"
cd "$BACKEND_DIR"
php artisan route:clear
php artisan route:cache
echo "✓ 路由缓存已更新"
echo ""

echo "2. 验证 health 路由"
echo "========================================"
php artisan route:list | grep health || echo "⚠️ 未找到 health 路由"
echo ""

echo "3. 重启 PHP-FPM"
echo "========================================"
systemctl restart php8.3-fpm
echo "✓ PHP-FPM 已重启"
echo ""

echo "4. 测试 health 端点"
echo "========================================"
echo "本地测试:"
curl -s https://api.sendwalk.com/api/health | jq '.' || echo "无法解析 JSON"
echo ""

echo "CORS 测试:"
curl -s -I \
  -H "Origin: https://edm.sendwalk.com" \
  https://api.sendwalk.com/api/health | grep -E "HTTP|access-control"
echo ""

echo "========================================"
echo "  ✅ 更新完成"
echo "========================================"
echo ""
echo "现在请在浏览器中测试："
echo ""
echo "1. 打开浏览器无痕模式"
echo "2. 访问 https://edm.sendwalk.com"
echo "3. F12 → Console"
echo "4. 运行测试代码（见 浏览器CORS测试指南.md）"
echo ""

