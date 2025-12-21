#!/bin/bash

# SendWalk CORS 问题诊断脚本
# 用于诊断和显示详细的 CORS 配置信息

set -e

echo "========================================"
echo "  CORS 问题诊断工具"
echo "========================================"
echo ""

BACKEND_DIR="/data/www/sendwalk/backend"
FRONTEND_DIR="/data/www/sendwalk/frontend"

echo "📋 第1步: 检查域名配置"
echo "----------------------------------------"
echo "前端域名: edm.sendwalk.com"
echo "后端域名: api.sendwalk.com"
echo ""

echo "📋 第2步: 检查后端 .env 配置"
echo "----------------------------------------"
if [ -f "$BACKEND_DIR/.env" ]; then
    echo "✓ .env 文件存在"
    echo ""
    echo "当前 CORS 相关配置:"
    grep -E "^APP_URL=|^FRONTEND_URL=|^SANCTUM_STATEFUL_DOMAINS=|^SESSION_DOMAIN=" "$BACKEND_DIR/.env" || echo "  ⚠️ 缺少关键配置"
    echo ""
    
    # 检查具体的值
    APP_URL=$(grep "^APP_URL=" "$BACKEND_DIR/.env" | cut -d'=' -f2)
    FRONTEND_URL=$(grep "^FRONTEND_URL=" "$BACKEND_DIR/.env" | cut -d'=' -f2)
    SANCTUM_DOMAINS=$(grep "^SANCTUM_STATEFUL_DOMAINS=" "$BACKEND_DIR/.env" | cut -d'=' -f2)
    SESSION_DOMAIN=$(grep "^SESSION_DOMAIN=" "$BACKEND_DIR/.env" | cut -d'=' -f2)
    
    echo "配置检查:"
    if [ "$APP_URL" = "https://api.sendwalk.com" ]; then
        echo "  ✓ APP_URL 正确"
    else
        echo "  ✗ APP_URL 错误: $APP_URL (应该是: https://api.sendwalk.com)"
    fi
    
    if [ "$FRONTEND_URL" = "https://edm.sendwalk.com" ]; then
        echo "  ✓ FRONTEND_URL 正确"
    else
        echo "  ✗ FRONTEND_URL 错误: $FRONTEND_URL (应该是: https://edm.sendwalk.com)"
    fi
    
    if [ "$SANCTUM_DOMAINS" = "edm.sendwalk.com" ]; then
        echo "  ✓ SANCTUM_STATEFUL_DOMAINS 正确"
    else
        echo "  ✗ SANCTUM_STATEFUL_DOMAINS 错误: $SANCTUM_DOMAINS (应该是: edm.sendwalk.com)"
    fi
    
    if [ "$SESSION_DOMAIN" = ".sendwalk.com" ]; then
        echo "  ✓ SESSION_DOMAIN 正确（注意前面的点）"
    else
        echo "  ✗ SESSION_DOMAIN 错误: $SESSION_DOMAIN (应该是: .sendwalk.com)"
    fi
else
    echo "✗ .env 文件不存在"
fi
echo ""

echo "📋 第3步: 检查前端 .env 配置"
echo "----------------------------------------"
if [ -f "$FRONTEND_DIR/.env" ]; then
    echo "✓ 前端 .env 文件存在"
    echo ""
    cat "$FRONTEND_DIR/.env"
    echo ""
    
    API_URL=$(grep "^VITE_API_URL=" "$FRONTEND_DIR/.env" | cut -d'=' -f2)
    if [ "$API_URL" = "https://api.sendwalk.com" ]; then
        echo "  ✓ VITE_API_URL 正确"
    else
        echo "  ✗ VITE_API_URL 错误: $API_URL (应该是: https://api.sendwalk.com)"
    fi
else
    echo "✗ 前端 .env 文件不存在"
fi
echo ""

echo "📋 第4步: 检查 Laravel CORS 配置"
echo "----------------------------------------"
if [ -f "$BACKEND_DIR/config/cors.php" ]; then
    echo "✓ CORS 配置文件存在"
    echo ""
    echo "关键配置:"
    grep -A 1 "allowed_origins" "$BACKEND_DIR/config/cors.php" | head -2
    grep "supports_credentials" "$BACKEND_DIR/config/cors.php"
else
    echo "✗ CORS 配置文件不存在"
fi
echo ""

echo "📋 第5步: 测试 API 连接"
echo "----------------------------------------"
echo "测试健康检查端点..."
curl -s -I https://api.sendwalk.com/api/health 2>&1 | head -15 || echo "  ⚠️ 无法连接到 API"
echo ""

echo "📋 第6步: 测试 CORS 预检请求"
echo "----------------------------------------"
echo "发送 OPTIONS 请求..."
CORS_TEST=$(curl -s -I \
    -H "Origin: https://edm.sendwalk.com" \
    -H "Access-Control-Request-Method: GET" \
    -H "Access-Control-Request-Headers: Content-Type" \
    -X OPTIONS \
    https://api.sendwalk.com/api/health 2>&1)

echo "$CORS_TEST" | head -20
echo ""

if echo "$CORS_TEST" | grep -q "Access-Control-Allow-Origin"; then
    echo "✓ CORS 头存在"
    echo "  $(echo "$CORS_TEST" | grep "Access-Control-Allow-Origin")"
else
    echo "✗ 缺少 Access-Control-Allow-Origin 头"
fi

if echo "$CORS_TEST" | grep -q "Access-Control-Allow-Credentials"; then
    echo "✓ Credentials 头存在"
    echo "  $(echo "$CORS_TEST" | grep "Access-Control-Allow-Credentials")"
else
    echo "✗ 缺少 Access-Control-Allow-Credentials 头"
fi
echo ""

echo "📋 第7步: 检查 PHP-FPM 状态"
echo "----------------------------------------"
systemctl is-active php8.3-fpm >/dev/null 2>&1 && echo "✓ PHP-FPM 运行中" || echo "✗ PHP-FPM 未运行"
echo ""

echo "📋 第8步: 检查 Redis 状态（如果使用）"
echo "----------------------------------------"
systemctl is-active redis-server >/dev/null 2>&1 && echo "✓ Redis 运行中" || echo "⚠️ Redis 未运行（如果不使用可忽略）"
echo ""

echo "📋 第9步: 查看最近的错误日志"
echo "----------------------------------------"
if [ -f "$BACKEND_DIR/storage/logs/laravel-$(date +%Y-%m-%d).log" ]; then
    echo "Laravel 日志 (最近 20 行):"
    tail -20 "$BACKEND_DIR/storage/logs/laravel-$(date +%Y-%m-%d).log" | grep -i "cors\|origin\|header" || echo "  没有 CORS 相关错误"
else
    echo "  没有今天的日志文件"
fi
echo ""

echo "========================================"
echo "  诊断完成"
echo "========================================"
echo ""
echo "🔧 建议的修复步骤:"
echo ""
echo "1. 如果配置有误，运行修复脚本:"
echo "   ./fix-cors-error.sh"
echo ""
echo "2. 清除配置缓存:"
echo "   cd $BACKEND_DIR"
echo "   php artisan config:clear"
echo "   php artisan config:cache"
echo ""
echo "3. 重启服务:"
echo "   sudo systemctl restart php8.3-fpm"
echo "   sudo supervisorctl restart all"
echo ""
echo "4. 查看实时日志:"
echo "   tail -f $BACKEND_DIR/storage/logs/laravel-$(date +%Y-%m-%d).log"
echo ""

