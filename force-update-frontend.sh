#!/bin/bash

# 前端强制更新脚本
# 用于解决浏览器缓存问题

echo "=========================================="
echo "前端强制更新部署"
echo "=========================================="
echo ""

# 检查是否在正确的目录
if [ ! -d "frontend" ]; then
    echo "错误：请在项目根目录运行此脚本"
    exit 1
fi

echo "1. 清理旧的构建文件..."
rm -rf frontend/dist
echo "   ✓ 清理完成"

echo ""
echo "2. 重新构建前端..."
cd frontend
npm run build
if [ $? -ne 0 ]; then
    echo "   ✗ 构建失败"
    exit 1
fi
cd ..
echo "   ✓ 构建完成"

echo ""
echo "3. 更新 Nginx 配置..."
if [ -f "/etc/nginx/conf.d/sendwalk-frontend.conf" ] || [ -f "/etc/nginx/sites-available/sendwalk-frontend.conf" ]; then
    echo "   检测到 Nginx 配置文件"
    echo "   请手动复制新的配置文件："
    echo "   sudo cp nginx/frontend.conf /etc/nginx/conf.d/sendwalk-frontend.conf"
    echo ""
    read -p "   已复制配置文件？(y/N) " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        echo "   测试 Nginx 配置..."
        sudo nginx -t
        if [ $? -eq 0 ]; then
            echo "   ✓ 配置文件正确"
            echo ""
            echo "   重新加载 Nginx..."
            sudo nginx -s reload
            echo "   ✓ Nginx 已重新加载"
        else
            echo "   ✗ 配置文件错误，请检查"
            exit 1
        fi
    else
        echo "   ⚠ 跳过 Nginx 配置更新"
    fi
else
    echo "   ⚠ 未检测到 Nginx 配置文件（开发环境？）"
fi

echo ""
echo "=========================================="
echo "部署完成！"
echo "=========================================="
echo ""
echo "接下来的步骤："
echo ""
echo "1. 清除 Cloudflare 缓存（已完成）"
echo ""
echo "2. 清除浏览器缓存："
echo "   - Chrome/Edge: Ctrl+Shift+Delete -> 清除缓存图像和文件"
echo "   - Firefox: Ctrl+Shift+Delete -> 缓存的网页内容"
echo "   - Safari: Command+Option+E"
echo ""
echo "3. 硬刷新浏览器："
echo "   - Windows: Ctrl+Shift+R 或 Ctrl+F5"
echo "   - Mac: Command+Shift+R"
echo ""
echo "4. 如果还是不更新，尝试："
echo "   - 无痕模式/隐私浏览"
echo "   - 清除网站数据"
echo "   - 使用不同浏览器测试"
echo ""
echo "优化说明："
echo "   ✓ index.html 已设置为不缓存"
echo "   ✓ JS/CSS 文件使用 hash 命名，可以长期缓存"
echo "   ✓ 下次更新将立即生效"
echo ""

