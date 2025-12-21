#!/bin/bash

# SendWalk CORS 深度调试脚本
# 收集所有可能相关的信息

echo "========================================"
echo "  CORS 深度调试报告"
echo "========================================"
echo ""
echo "生成时间: $(date '+%Y-%m-%d %H:%M:%S')"
echo ""

BACKEND_DIR="/data/www/sendwalk/backend"
FRONTEND_DIR="/data/www/sendwalk/frontend"

echo "========================================" 
echo "1. 系统信息"
echo "========================================" 
echo "主机名: $(hostname)"
echo "当前用户: $(whoami)"
echo "PHP 版本: $(php -v | head -1)"
echo ""

echo "========================================" 
echo "2. 后端 .env 配置（敏感信息已隐藏）"
echo "========================================" 
if [ -f "$BACKEND_DIR/.env" ]; then
    grep -E "^APP_URL=|^APP_ENV=|^APP_DEBUG=|^FRONTEND_URL=|^SANCTUM_STATEFUL_DOMAINS=|^SESSION_DOMAIN=|^SESSION_DRIVER=|^CACHE_DRIVER=" "$BACKEND_DIR/.env"
    echo ""
    
    # 检查关键配置
    echo "配置验证:"
    SESSION_DOMAIN=$(grep "^SESSION_DOMAIN=" "$BACKEND_DIR/.env" | cut -d'=' -f2)
    if [ "$SESSION_DOMAIN" = ".sendwalk.com" ]; then
        echo "  ✓ SESSION_DOMAIN 正确（有点）: $SESSION_DOMAIN"
    else
        echo "  ✗ SESSION_DOMAIN 错误: '$SESSION_DOMAIN' (应该是 .sendwalk.com)"
    fi
else
    echo "✗ .env 文件不存在！"
fi
echo ""

echo "========================================" 
echo "3. Laravel 实际生效的配置"
echo "========================================" 
if [ -f "$BACKEND_DIR/artisan" ]; then
    cd "$BACKEND_DIR"
    echo "CORS allowed_origins:"
    php artisan tinker --execute="echo json_encode(config('cors.allowed_origins'));"
    echo ""
    
    echo "CORS supports_credentials:"
    php artisan tinker --execute="echo var_export(config('cors.supports_credentials'), true);"
    echo ""
    
    echo "Sanctum stateful domains:"
    php artisan tinker --execute="echo json_encode(config('sanctum.stateful'));"
    echo ""
    
    echo "Session domain:"
    php artisan tinker --execute="echo var_export(config('session.domain'), true);"
    echo ""
    
    echo "Session driver:"
    php artisan tinker --execute="echo config('session.driver');"
    echo ""
else
    echo "✗ artisan 文件不存在"
fi
echo ""

echo "========================================" 
echo "4. 前端配置"
echo "========================================" 
if [ -f "$FRONTEND_DIR/.env" ]; then
    cat "$FRONTEND_DIR/.env"
else
    echo "⚠️ 前端 .env 不存在"
fi
echo ""

if [ -d "$FRONTEND_DIR/dist" ]; then
    echo "前端构建时间:"
    ls -lh "$FRONTEND_DIR/dist/index.html" | awk '{print $6, $7, $8}'
    echo ""
    
    echo "检查构建产物中的 API URL:"
    grep -r "api\.sendwalk\.com" "$FRONTEND_DIR/dist/assets/" 2>/dev/null | head -3 || echo "  未找到 API URL"
else
    echo "⚠️ 前端未构建（dist 目录不存在）"
fi
echo ""

echo "========================================" 
echo "5. Nginx 配置检查"
echo "========================================" 
if [ -f "/etc/nginx/conf.d/sendwalk-api.conf" ]; then
    echo "Nginx API 配置存在"
    echo "检查 CORS 相关头:"
    grep -i "access-control" /etc/nginx/conf.d/sendwalk-api.conf || echo "  未找到 CORS 头（正常，应该由 Laravel 处理）"
else
    echo "⚠️ Nginx API 配置不存在"
fi
echo ""

echo "========================================" 
echo "6. 服务状态"
echo "========================================" 
echo "PHP-FPM: $(systemctl is-active php8.3-fpm)"
echo "Nginx: $(systemctl is-active nginx)"
echo "Redis: $(systemctl is-active redis-server 2>/dev/null || echo 'not installed')"
echo "MySQL: $(systemctl is-active mysql 2>/dev/null || systemctl is-active mariadb 2>/dev/null || echo 'unknown')"
echo ""

echo "========================================" 
echo "7. 测试 API 连接"
echo "========================================" 
echo "测试 1: 基本 API 调用"
HEALTH_CHECK=$(curl -s -I https://api.sendwalk.com/api/health 2>&1)
if echo "$HEALTH_CHECK" | grep -q "HTTP"; then
    echo "✓ API 可访问"
    echo "$HEALTH_CHECK" | head -10
else
    echo "✗ API 无法访问"
    echo "$HEALTH_CHECK"
fi
echo ""

echo "测试 2: CORS 预检请求 (OPTIONS)"
CORS_TEST=$(curl -s -i \
    -H "Origin: https://edm.sendwalk.com" \
    -H "Access-Control-Request-Method: POST" \
    -H "Access-Control-Request-Headers: Content-Type, Authorization" \
    -X OPTIONS \
    https://api.sendwalk.com/api/campaigns 2>&1)

echo "$CORS_TEST" | head -20
echo ""

if echo "$CORS_TEST" | grep -qi "access-control-allow-origin"; then
    echo "✓ 响应包含 Access-Control-Allow-Origin"
    echo "  $(echo "$CORS_TEST" | grep -i "access-control-allow-origin")"
else
    echo "✗ 响应不包含 Access-Control-Allow-Origin"
fi

if echo "$CORS_TEST" | grep -qi "access-control-allow-credentials"; then
    echo "✓ 响应包含 Access-Control-Allow-Credentials"
    echo "  $(echo "$CORS_TEST" | grep -i "access-control-allow-credentials")"
else
    echo "✗ 响应不包含 Access-Control-Allow-Credentials"
fi
echo ""

echo "测试 3: 实际 GET 请求"
GET_TEST=$(curl -s -i \
    -H "Origin: https://edm.sendwalk.com" \
    https://api.sendwalk.com/api/health 2>&1)
echo "$GET_TEST" | head -15
echo ""

echo "========================================" 
echo "8. 最近的错误日志"
echo "========================================" 
echo "Laravel 日志 (最近 30 行):"
if [ -f "$BACKEND_DIR/storage/logs/laravel-$(date +%Y-%m-%d).log" ]; then
    tail -30 "$BACKEND_DIR/storage/logs/laravel-$(date +%Y-%m-%d).log" | grep -E "error|exception|cors|origin" -i || echo "  没有相关错误"
else
    echo "  今天没有日志文件"
    LATEST_LOG=$(ls -t "$BACKEND_DIR/storage/logs/laravel-"*.log 2>/dev/null | head -1)
    if [ -n "$LATEST_LOG" ]; then
        echo "  最近的日志: $LATEST_LOG"
        tail -20 "$LATEST_LOG" | grep -E "error|exception|cors|origin" -i || echo "  没有相关错误"
    fi
fi
echo ""

echo "Nginx 错误日志 (最近 20 行):"
if [ -f "/var/log/nginx/sendwalk-api-error.log" ]; then
    sudo tail -20 /var/log/nginx/sendwalk-api-error.log 2>/dev/null || echo "  无权限读取"
else
    echo "  日志文件不存在"
fi
echo ""

echo "========================================" 
echo "9. 缓存文件检查"
echo "========================================" 
if [ -f "$BACKEND_DIR/bootstrap/cache/config.php" ]; then
    echo "配置缓存文件存在"
    echo "修改时间: $(stat -c %y "$BACKEND_DIR/bootstrap/cache/config.php" 2>/dev/null || stat -f %Sm "$BACKEND_DIR/bootstrap/cache/config.php")"
    echo ""
    echo "检查缓存中的 CORS 配置:"
    grep -A 5 "cors" "$BACKEND_DIR/bootstrap/cache/config.php" | head -10
else
    echo "⚠️ 配置缓存文件不存在"
fi
echo ""

echo "========================================" 
echo "10. 域名解析检查"
echo "========================================" 
echo "edm.sendwalk.com:"
nslookup edm.sendwalk.com 2>/dev/null | grep -A 2 "Name:" || host edm.sendwalk.com 2>/dev/null || echo "  无法解析"
echo ""

echo "api.sendwalk.com:"
nslookup api.sendwalk.com 2>/dev/null | grep -A 2 "Name:" || host api.sendwalk.com 2>/dev/null || echo "  无法解析"
echo ""

echo "========================================" 
echo "11. SSL 证书检查"
echo "========================================" 
echo "api.sendwalk.com 证书信息:"
echo | openssl s_client -connect api.sendwalk.com:443 -servername api.sendwalk.com 2>/dev/null | openssl x509 -noout -dates 2>/dev/null || echo "  无法获取证书信息"
echo ""

echo "========================================" 
echo "12. PHP-FPM 进程检查"
echo "========================================" 
ps aux | grep php-fpm | grep -v grep | head -5
echo ""

echo "========================================" 
echo "总结和建议"
echo "========================================" 
echo ""
echo "请检查以上输出，特别注意："
echo "1. SESSION_DOMAIN 是否有前面的点: .sendwalk.com"
echo "2. Laravel 配置是否正确加载"
echo "3. CORS 测试是否返回正确的头"
echo "4. 前端构建时间是否是最近的"
echo "5. 服务是否都在运行"
echo ""
echo "如果 CORS 头不存在，可能的原因："
echo "- config/cors.php 配置错误"
echo "- HandleCors 中间件未加载"
echo "- Nginx 拦截了请求"
echo "- 路由配置问题"
echo ""
echo "========================================" 
echo "调试报告完成"
echo "========================================" 

