#!/bin/bash

# 前端打包测试脚本

echo "=========================================="
echo "前端打包测试"
echo "=========================================="
echo ""

cd frontend

echo "1. 清理旧的构建文件..."
rm -rf dist

echo ""
echo "2. 开始构建..."
echo ""

npm run build

echo ""
echo "=========================================="
echo "构建完成！"
echo "=========================================="
echo ""

# 检查打包结果
if [ -d "dist" ]; then
    echo "✅ 构建成功"
    echo ""
    echo "打包文件列表："
    echo "----------------------------------------"
    ls -lh dist/assets/*.js 2>/dev/null || echo "没有 JS 文件"
    echo ""
    echo "总大小："
    du -sh dist
    echo ""
    echo "Gzip 压缩后大小估算："
    find dist -type f -name "*.js" -exec gzip -c {} \; | wc -c | awk '{print $1/1024/1024 " MB"}'
else
    echo "❌ 构建失败"
    exit 1
fi

echo ""
echo "提示："
echo "  - 运行 'npm run preview' 预览构建结果"
echo "  - 检查 dist/assets/ 目录查看分割的 chunk"
echo ""

