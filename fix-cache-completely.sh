#!/bin/bash

# 彻底修复缓存问题脚本

echo "=========================================="
echo "彻底修复前端缓存问题"
echo "=========================================="
echo ""

set -e

# 1. 更新 Nginx 配置
echo "步骤 1: 更新 Nginx 配置"
echo "----------------------------------------"
if [ -f "/etc/nginx/conf.d/sendwalk-frontend.conf" ]; then
    echo "复制新配置..."
    sudo cp nginx/frontend.conf /etc/nginx/conf.d/sendwalk-frontend.conf
    echo "✓ 配置已更新"
    
    echo ""
    echo "测试配置..."
    sudo nginx -t
    
    echo ""
    echo "重新加载 Nginx..."
    sudo nginx -s reload
    echo "✓ Nginx 已重新加载"
else
    echo "⚠ 未找到 Nginx 配置文件，可能需要手动配置"
fi

echo ""

# 2. 清理并重新构建
echo "步骤 2: 重新构建前端"
echo "----------------------------------------"
cd frontend

echo "清理旧文件..."
rm -rf dist
echo "✓ 旧文件已清理"

echo ""
echo "重新构建..."
npm run build
echo "✓ 构建完成"

echo ""
echo "构建结果:"
ls -lh dist/assets/*.js | head -5

cd ..

echo ""

# 3. 验证部署
echo "步骤 3: 验证部署"
echo "----------------------------------------"

echo "检查 index.html 响应头..."
sleep 2  # 等待 Nginx 重载完成

RESPONSE=$(curl -I https://edm.sendwalk.com/ 2>/dev/null || curl -I http://localhost/ 2>/dev/null || echo "")

if [ ! -z "$RESPONSE" ]; then
    if echo "$RESPONSE" | grep -qi "Cache-Control.*no-cache"; then
        echo "✓ 缓存头设置正确"
        echo "$RESPONSE" | grep -i "Cache-Control"
    else
        echo "✗ 缓存头可能不正确"
        echo "$RESPONSE" | grep -i "Cache-Control"
    fi
else
    echo "⚠ 无法验证响应头（可能是网络问题）"
fi

echo ""

# 4. 生成版本标识
echo "步骤 4: 生成版本标识"
echo "----------------------------------------"

VERSION_FILE="frontend/dist/version.json"
BUILD_TIME=$(date '+%Y-%m-%d %H:%M:%S')
BUILD_HASH=$(date +%s)

cat > "$VERSION_FILE" << EOF
{
  "version": "1.0.0",
  "buildTime": "$BUILD_TIME",
  "buildHash": "$BUILD_HASH"
}
EOF

echo "✓ 版本文件已创建: $VERSION_FILE"
echo "  构建时间: $BUILD_TIME"
echo "  构建 Hash: $BUILD_HASH"

echo ""

# 5. 清除 Cloudflare 缓存提示
echo "步骤 5: 清除 Cloudflare 缓存"
echo "----------------------------------------"
echo "请手动操作:"
echo "1. 登录 Cloudflare"
echo "2. 选择域名 edm.sendwalk.com"
echo "3. 缓存 → 清除缓存 → 清除所有内容"
echo ""
read -p "已清除 Cloudflare 缓存？(y/N) " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "⚠ 请记得清除 Cloudflare 缓存"
fi

echo ""

# 6. 浏览器缓存清除指南
echo "=========================================="
echo "部署完成！"
echo "=========================================="
echo ""
echo "现在需要清除浏览器缓存："
echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "方法 1: 硬刷新（推荐，最快）"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  Windows: Ctrl + Shift + R 或 Ctrl + F5"
echo "  Mac:     Cmd + Shift + R"
echo ""

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "方法 2: 开发者工具 + 硬刷新（更彻底）"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  1. 打开开发者工具 (F12)"
echo "  2. 切换到 Network 标签"
echo "  3. 勾选 'Disable cache'"
echo "  4. 右键点击刷新按钮 → 清空缓存并硬性重新加载"
echo ""

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "方法 3: 清除站点数据（最彻底）"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  Chrome:"
echo "    1. F12 打开开发者工具"
echo "    2. Application 标签"
echo "    3. Storage → Clear site data"
echo "    4. 全选并点击 'Clear site data'"
echo ""
echo "  Firefox:"
echo "    1. F12 打开开发者工具"
echo "    2. Storage 标签"
echo "    3. 右键 → Clear All"
echo ""

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "方法 4: 无痕模式测试"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  Ctrl + Shift + N (Chrome) 或 Ctrl + Shift + P (Firefox)"
echo "  无痕模式没有缓存，可以看到真实的最新版本"
echo ""

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "验证是否更新:"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  1. 访问: https://edm.sendwalk.com/version.json"
echo "  2. 检查 buildHash: $BUILD_HASH"
echo "  3. 在控制台输入: fetch('/version.json').then(r=>r.json()).then(console.log)"
echo ""

echo "如果还是不行，请运行诊断脚本:"
echo "  ./diagnose-frontend-cache.sh"
echo ""

