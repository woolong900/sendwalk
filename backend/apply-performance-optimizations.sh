#!/bin/bash

# 性能优化部署脚本
# 日期: 2025-12-28
# 说明: 应用系统性能审查后的高优先级优化

set -e

echo "========================================="
echo "系统性能优化 - 部署"
echo "========================================="
echo ""

# 检测环境
if [ -f "/data/www/sendwalk/backend/artisan" ]; then
    BACKEND_DIR="/data/www/sendwalk/backend"
    echo "✓ 检测到正式环境"
else
    BACKEND_DIR="/Users/panlei/sendwalk/backend"
    echo "✓ 检测到本地开发环境"
fi

cd "$BACKEND_DIR"

echo ""
echo "1. 备份当前文件..."
mkdir -p backups/$(date +%Y%m%d)
cp app/Http/Controllers/Api/SubscriberController.php backups/$(date +%Y%m%d)/SubscriberController.php.backup
cp app/Http/Controllers/Api/TemplateController.php backups/$(date +%Y%m%d)/TemplateController.php.backup
echo "   ✓ 备份完成"

echo ""
echo "2. 应用数据库迁移（添加索引）..."
php artisan migrate --force
echo "   ✓ 迁移完成"

echo ""
echo "3. 清理缓存..."
php artisan cache:clear
php artisan config:clear
php artisan route:clear
echo "   ✓ 缓存已清理"

echo ""
echo "4. 验证优化效果..."
echo "   测试建议："
echo "   • 访问订阅者列表页，观察加载时间"
echo "   • 访问模板列表页，观察响应大小"
echo "   • 查看打开记录，观察查询速度"

echo ""
echo "========================================="
echo "✅ 部署完成！"
echo "========================================="
echo ""
echo "📊 预期优化效果："
echo ""
echo "   优化项 1: SubscriberController"
echo "   • 合并双重 whereHas 为单个查询"
echo "   • 预期：查询时间减少 30-50%"
echo ""
echo "   优化项 2: TemplateController"
echo "   • 列表页不返回完整 HTML 内容"
echo "   • 预期：响应大小减少 80-90%"
echo ""
echo "   优化项 3: email_opens 索引"
echo "   • 添加 3 个复合索引"
echo "   • 预期：打开记录查询时间减少 50-70%"
echo ""
echo "📚 详细报告: backend/系统性能审查报告.md"
echo ""

