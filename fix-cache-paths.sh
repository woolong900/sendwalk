#!/bin/bash

# SendWalk 缓存路径修复脚本
# 用于修复 "Please provide a valid cache path" 错误

set -e

echo "========================================"
echo "  修复 Laravel 缓存路径问题"
echo "========================================"
echo ""

# 切换到后端目录
cd "$(dirname "$0")/backend"

echo "📂 创建必要的缓存目录..."

# 创建所有必要的缓存和存储目录
mkdir -p storage/framework/cache/data
mkdir -p storage/framework/sessions
mkdir -p storage/framework/views
mkdir -p storage/logs
mkdir -p bootstrap/cache

echo "✓ 目录创建完成"
echo ""

echo "🔧 设置目录权限..."

# 设置正确的所有者和权限
if [ "$(id -u)" -eq 0 ]; then
    # 以 root 运行，设置 www-data 为所有者
    chown -R www-data:www-data storage bootstrap/cache
    chmod -R 775 storage bootstrap/cache
    echo "✓ 权限已设置 (所有者: www-data)"
else
    # 非 root 用户，只设置权限
    chmod -R 775 storage bootstrap/cache 2>/dev/null || chmod -R 755 storage bootstrap/cache
    echo "✓ 权限已设置"
    echo "⚠️  提示: 如果仍有权限问题，请使用 sudo 运行此脚本"
fi

echo ""

echo "🧹 清除旧缓存..."

# 清除所有缓存
php artisan cache:clear 2>/dev/null || echo "  跳过 cache:clear（没有缓存）"
php artisan config:clear 2>/dev/null || echo "  跳过 config:clear"
php artisan route:clear 2>/dev/null || echo "  跳过 route:clear"
php artisan view:clear 2>/dev/null || echo "  跳过 view:clear"

echo "✓ 缓存清理完成"
echo ""

echo "🔄 重新生成缓存..."

# 重新生成缓存
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "✓ 缓存生成完成"
echo ""

echo "✅ 验证目录结构..."

# 验证目录是否存在
if [ -d "storage/framework/cache" ] && [ -d "bootstrap/cache" ]; then
    echo "✓ storage/framework/cache - 存在"
    echo "✓ bootstrap/cache - 存在"
    
    # 显示权限
    echo ""
    echo "📋 目录权限信息:"
    ls -la storage/framework/cache | head -3
    ls -la bootstrap/cache | head -3
else
    echo "❌ 某些目录不存在"
    exit 1
fi

echo ""
echo "========================================"
echo "  ✅ 缓存路径修复完成！"
echo "========================================"
echo ""
echo "如果问题仍然存在，请检查:"
echo "1. .env 文件中的 CACHE_DRIVER 配置"
echo "2. Redis 服务是否正常运行 (如果使用 Redis 缓存)"
echo "3. 文件系统权限是否正确"
echo ""
echo "重启服务:"
echo "  sudo systemctl restart php8.3-fpm"
echo "  sudo supervisorctl restart all"
echo ""

