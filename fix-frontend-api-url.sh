#!/bin/bash

# 修复前端 API URL 配置

set -e

echo "========================================"
echo "  修复前端 API URL 配置"
echo "========================================"
echo ""

FRONTEND_DIR="/data/www/sendwalk/frontend"

cd "$FRONTEND_DIR"

echo "问题分析:"
echo "----------------------------------------"
echo "当前前端请求的 URL: https://api.sendwalk.com/auth/login"
echo "正确的 URL 应该是:   https://api.sendwalk.com/api/auth/login"
echo ""
echo "原因: VITE_API_URL 缺少 /api 后缀"
echo ""

echo "当前 .env 配置:"
echo "----------------------------------------"
cat .env 2>/dev/null || echo "  .env 文件不存在"
echo ""

echo "修复配置:"
echo "----------------------------------------"

# 备份
if [ -f .env ]; then
    cp .env .env.backup.$(date +%Y%m%d_%H%M%S)
    echo "✓ 已备份 .env"
fi

# 创建正确的 .env
cat > .env << 'EOF'
VITE_API_URL=https://api.sendwalk.com/api
VITE_APP_NAME=SendWalk
EOF

echo "✓ 已更新 .env"
echo ""

echo "新的配置:"
echo "----------------------------------------"
cat .env
echo ""

echo "重新构建前端:"
echo "----------------------------------------"
echo "  删除旧构建..."
rm -rf dist

echo "  开始构建..."
npm run build

if [ -d "dist" ]; then
    echo "✓ 前端构建成功"
    
    echo ""
    echo "验证构建产物中的 API URL:"
    grep -r "api\.sendwalk\.com" dist/assets/ | head -3 || echo "  未找到 API URL"
else
    echo "✗ 前端构建失败"
    exit 1
fi

echo ""
echo "========================================"
echo "  ✅ 修复完成！"
echo "========================================"
echo ""
echo "关键修复:"
echo "  VITE_API_URL: https://api.sendwalk.com/api"
echo "              (注意最后的 /api)"
echo ""
echo "现在请:"
echo "  1. 清除浏览器缓存（Ctrl+Shift+Delete）"
echo "  2. 或使用无痕模式"
echo "  3. 访问 https://edm.sendwalk.com"
echo "  4. 尝试登录/注册"
echo ""
echo "应该就可以正常工作了！🎉"
echo ""

