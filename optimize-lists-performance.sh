#!/bin/bash

# 邮件列表性能优化部署脚本
# 此脚本将应用缓存计数器优化，大幅提升列表页面加载速度

set -e

echo "=========================================="
echo "邮件列表性能优化部署"
echo "=========================================="
echo ""

# 检查是否在正确的目录
if [ ! -f "backend/artisan" ]; then
    echo "错误：请在项目根目录运行此脚本"
    exit 1
fi

cd backend

echo "1. 运行数据库迁移（添加 unsubscribed_count 字段）..."
php artisan migrate --force

echo ""
echo "2. 重新计算所有列表的订阅者计数..."
php artisan lists:recalculate-counts

echo ""
echo "3. 清除应用缓存..."
php artisan config:clear
php artisan cache:clear

echo ""
echo "=========================================="
echo "优化完成！"
echo "=========================================="
echo ""
echo "优化内容："
echo "  ✓ 添加了 unsubscribed_count 缓存字段"
echo "  ✓ 创建了 ListSubscriber 模型观察者自动维护计数"
echo "  ✓ 优化了 API 查询，使用缓存字段替代复杂关联查询"
echo "  ✓ 重新计算了现有数据的计数"
echo ""
echo "预期效果："
echo "  - 列表页面加载时间从 7-8 秒降低到 < 1 秒"
echo "  - 减少了数据库查询负载"
echo "  - 计数自动实时更新"
echo ""

