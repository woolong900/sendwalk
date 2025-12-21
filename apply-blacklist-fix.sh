#!/bin/bash

# 应用黑名单上传功能优化
# 修复"跳过"提示信息问题

set -e

echo "======================================"
echo "  应用黑名单上传功能优化"
echo "======================================"
echo ""

# 检查是否在项目根目录
if [ ! -f "deploy.sh" ]; then
    echo "❌ 错误：请在项目根目录运行此脚本"
    exit 1
fi

echo "【1/3】清除后端缓存..."
cd backend
php artisan config:clear
php artisan route:clear
php artisan cache:clear
echo "  ✓ 后端缓存已清除"
echo ""

echo "【2/3】重新构建前端..."
cd ../frontend
npm run build
echo "  ✓ 前端构建完成"
echo ""

echo "【3/3】重启服务（如果需要）..."
cd ..
# 检查是否在生产环境
if [ -f "/etc/nginx/conf.d/sendwalk-frontend.conf" ]; then
    echo "  检测到生产环境，重启服务..."
    sudo systemctl reload nginx
    echo "  ✓ Nginx 已重载"
    
    # 检查 supervisor
    if command -v supervisorctl &> /dev/null; then
        sudo supervisorctl status sendwalk-worker:* > /dev/null 2>&1 && \
            sudo supervisorctl restart sendwalk-worker:* && \
            echo "  ✓ 队列工作器已重启" || \
            echo "  ℹ️  未检测到队列工作器"
    fi
else
    echo "  ℹ️  本地开发环境，无需重启服务"
fi

echo ""
echo "======================================"
echo "  ✅ 优化应用完成"
echo "======================================"
echo ""
echo "【修改内容】"
echo "  ✓ 无论邮箱是否已存在，都会更新订阅者状态"
echo "  ✓ 区分"新增"、"已存在"、"无效"三种情况"
echo "  ✓ 提示信息更清晰友好"
echo ""
echo "【新的提示示例】"
echo "  - 全新邮箱: \"新增 100 个，更新订阅者 50 个\""
echo "  - 重复邮箱: \"已存在 100 个，更新订阅者 20 个\""
echo "  - 混合情况: \"新增 50 个，已存在 40 个，无效 10 个，更新订阅者 35 个\""
echo ""
echo "【测试方法】"
echo "  1. 访问前端黑名单页面"
echo "  2. 批量上传邮箱"
echo "  3. 观察新的提示信息"
echo ""
echo "【详细说明】"
echo "  查看: 黑名单上传功能优化说明.md"
echo ""

