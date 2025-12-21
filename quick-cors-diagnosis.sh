#!/bin/bash

# 快速 CORS 诊断脚本
# 收集所有关键信息

echo "========================================"
echo "  快速 CORS 诊断"
echo "========================================"
echo ""

BACKEND_DIR="/data/www/sendwalk/backend"

echo "1. 检查 .env 配置（显示不可见字符）"
echo "========================================"
cd "$BACKEND_DIR"
echo "SESSION_DOMAIN:"
grep "^SESSION_DOMAIN=" .env | cat -A
echo ""
echo "SANCTUM_STATEFUL_DOMAINS:"
grep "^SANCTUM_STATEFUL_DOMAINS=" .env | cat -A
echo ""
echo "APP_URL:"
grep "^APP_URL=" .env
echo ""
echo "FRONTEND_URL:"
grep "^FRONTEND_URL=" .env
echo ""

echo "2. Laravel 实际生效的配置"
echo "========================================"
php artisan tinker --execute="
echo 'CORS Origins: ' . json_encode(config('cors.allowed_origins')) . PHP_EOL;
echo 'CORS Credentials: ' . var_export(config('cors.supports_credentials'), true) . PHP_EOL;
echo 'CORS Paths: ' . json_encode(config('cors.paths')) . PHP_EOL;
echo 'Session Domain: ' . var_export(config('session.domain'), true) . PHP_EOL;
echo 'Sanctum Stateful: ' . json_encode(config('sanctum.stateful')) . PHP_EOL;
"
echo ""

echo "3. 测试 /api/health 端点"
echo "========================================"
echo "GET 请求:"
curl -s -I \
  -H "Origin: https://edm.sendwalk.com" \
  https://api.sendwalk.com/api/health 2>&1 | grep -E "^HTTP|^access-control|^server|^cf-"
echo ""

echo "4. 测试 /api/auth/login 端点（OPTIONS 预检）"
echo "========================================"
echo "OPTIONS 请求:"
curl -s -I -X OPTIONS \
  -H "Origin: https://edm.sendwalk.com" \
  -H "Access-Control-Request-Method: POST" \
  -H "Access-Control-Request-Headers: Content-Type, Authorization" \
  https://api.sendwalk.com/api/auth/login 2>&1 | grep -E "^HTTP|^access-control|^server|^cf-"
echo ""

echo "5. 测试 /api/auth/login 端点（POST 请求）"
echo "========================================"
echo "POST 请求:"
curl -s -I -X POST \
  -H "Origin: https://edm.sendwalk.com" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  https://api.sendwalk.com/api/auth/login 2>&1 | grep -E "^HTTP|^access-control|^server|^cf-"
echo ""

echo "6. 检查 Cloudflare 缓存状态"
echo "========================================"
curl -s -I https://api.sendwalk.com/api/health | grep -i "cf-cache-status"
echo ""

echo "7. 检查 Nginx 配置"
echo "========================================"
if [ -f "/etc/nginx/conf.d/sendwalk-api.conf" ]; then
    echo "Nginx API 配置存在"
    echo "检查是否有 add_header Access-Control:"
    grep -i "access-control" /etc/nginx/conf.d/sendwalk-api.conf || echo "  无 (正确，应该由 Laravel 处理)"
else
    echo "⚠️ Nginx API 配置不存在！"
fi
echo ""

echo "8. 检查路由是否正确"
echo "========================================"
php artisan route:list | grep -E "api/health|api/auth/login" | head -5
echo ""

echo "9. 检查中间件"
echo "========================================"
echo "查找 HandleCors 中间件:"
grep -r "HandleCors" app/Http/Kernel.php || echo "  未找到"
echo ""

echo "10. 最近的 Laravel 错误"
echo "========================================"
if [ -f "storage/logs/laravel-$(date +%Y-%m-%d).log" ]; then
    echo "今天的错误日志 (最后 10 行):"
    tail -10 "storage/logs/laravel-$(date +%Y-%m-%d).log" | grep -i "error\|exception" || echo "  无错误"
else
    echo "  今天没有日志文件"
fi
echo ""

echo "========================================"
echo "  诊断完成"
echo "========================================"
echo ""
echo "📋 关键检查项："
echo ""
echo "1. SESSION_DOMAIN 必须是: .sendwalk.com (有点)"
echo "2. CORS Origins 必须是: [\"https://edm.sendwalk.com\"]"
echo "3. API 响应必须包含:"
echo "   - access-control-allow-origin: https://edm.sendwalk.com"
echo "   - access-control-allow-credentials: true"
echo "4. cf-cache-status 应该是: DYNAMIC 或 BYPASS"
echo ""
echo "⚠️ 如果 CORS 头缺失或错误，检查："
echo "   - Cloudflare SSL 模式是否是 Full (strict)"
echo "   - Cloudflare 是否设置了 API 缓存绕过规则"
echo "   - 浏览器中的实际错误是什么"
echo ""

