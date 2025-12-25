#!/bin/bash

# 修复 Laravel Storage 权限
# 这个脚本需要在服务器上运行

echo "=========================================="
echo "修复 Laravel Storage 权限"
echo "=========================================="
echo ""

# 检查是否在正确的目录
if [ ! -d "backend/storage" ]; then
    echo "❌ 错误: 请在项目根目录运行此脚本"
    exit 1
fi

echo "1. 修复 storage 目录权限..."
cd backend

# 设置正确的所有者（假设是 www-data）
sudo chown -R www-data:www-data storage
sudo chown -R www-data:www-data bootstrap/cache

echo "   ✓ 所有者设置为 www-data"

# 设置正确的权限
sudo chmod -R 775 storage
sudo chmod -R 775 bootstrap/cache

echo "   ✓ 权限设置为 775"

# 创建必要的子目录
sudo -u www-data mkdir -p storage/app/blacklist_imports
sudo -u www-data mkdir -p storage/app/imports
sudo -u www-data mkdir -p storage/logs
sudo -u www-data mkdir -p storage/framework/cache
sudo -u www-data mkdir -p storage/framework/sessions
sudo -u www-data mkdir -p storage/framework/views

echo "   ✓ 创建必要的子目录"

# 验证权限
echo ""
echo "2. 验证权限..."
ls -la storage/ | head -10

echo ""
echo "=========================================="
echo "✅ 权限修复完成！"
echo "=========================================="
echo ""
echo "现在可以尝试重新上传文件了。"
echo ""

