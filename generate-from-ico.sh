#!/bin/bash

# 从现有的 favicon.ico 生成其他尺寸的图标

set -e

echo "========================================"
echo "  从 ICO 生成其他尺寸的图标"
echo "========================================"
echo ""

# 检查源文件
SOURCE_ICO="frontend/dist/favicon.ico"

if [ ! -f "$SOURCE_ICO" ]; then
    echo "❌ 错误: 找不到源文件 $SOURCE_ICO"
    exit 1
fi

echo "✓ 找到源文件: $SOURCE_ICO"
echo ""

# 检查 ImageMagick 是否安装
if ! command -v convert &> /dev/null; then
    echo "❌ 错误: 未安装 ImageMagick"
    echo ""
    echo "安装方法:"
    echo "  macOS:         brew install imagemagick"
    echo "  Ubuntu/Debian: sudo apt-get install imagemagick"
    echo "  CentOS/RHEL:   sudo yum install ImageMagick"
    echo ""
    exit 1
fi

echo "✓ ImageMagick 已安装"
echo ""

# 切换到 dist 目录
cd frontend/dist

echo "🔧 生成文件..."
echo "----------------------------------------"

# 1. 生成 favicon-16x16.png
echo "1. 生成 favicon-16x16.png..."
convert favicon.ico[0] -resize 16x16 favicon-16x16.png 2>/dev/null || \
convert favicon.ico -resize 16x16 favicon-16x16.png

if [ -f "favicon-16x16.png" ]; then
    echo "   ✓ favicon-16x16.png 已生成"
    ls -lh favicon-16x16.png | awk '{print "   大小: " $5}'
else
    echo "   ✗ 生成失败"
fi

echo ""

# 2. 生成 favicon-32x32.png (先生成 PNG)
echo "2. 生成 favicon-32x32.png (中间步骤)..."
convert favicon.ico[1] -resize 32x32 favicon-32x32.png 2>/dev/null || \
convert favicon.ico -resize 32x32 favicon-32x32.png

if [ -f "favicon-32x32.png" ]; then
    echo "   ✓ favicon-32x32.png 已生成"
    ls -lh favicon-32x32.png | awk '{print "   大小: " $5}'
else
    echo "   ✗ 生成失败"
fi

echo ""

# 3. 从 PNG 生成 favicon-32x32.ico
echo "3. 生成 favicon-32x32.ico..."
convert favicon-32x32.png favicon-32x32.ico

if [ -f "favicon-32x32.ico" ]; then
    echo "   ✓ favicon-32x32.ico 已生成"
    ls -lh favicon-32x32.ico | awk '{print "   大小: " $5}'
else
    echo "   ✗ 生成失败"
fi

echo ""

# 4. 同时也复制到 public 目录（可选）
echo "4. 复制到 public 目录..."
cd ../..
cp frontend/dist/favicon-16x16.png frontend/public/ 2>/dev/null && \
    echo "   ✓ favicon-16x16.png → public/" || true
cp frontend/dist/favicon-32x32.ico frontend/public/ 2>/dev/null && \
    echo "   ✓ favicon-32x32.ico → public/" || true
cp frontend/dist/favicon-32x32.png frontend/public/ 2>/dev/null && \
    echo "   ✓ favicon-32x32.png → public/" || true

echo ""
echo "========================================"
echo "  ✅ 生成完成！"
echo "========================================"
echo ""
echo "生成的文件:"
echo "  frontend/dist/favicon-16x16.png"
echo "  frontend/dist/favicon-32x32.ico"
echo "  frontend/dist/favicon-32x32.png (中间文件，可删除)"
echo ""
echo "已复制到:"
echo "  frontend/public/favicon-16x16.png"
echo "  frontend/public/favicon-32x32.ico"
echo ""

# 显示所有 favicon 文件
echo "当前的 favicon 文件:"
echo "----------------------------------------"
ls -lh frontend/dist/favicon* 2>/dev/null || true
echo ""

echo "💡 提示:"
echo "  - favicon-16x16.png 用于浏览器标签页"
echo "  - favicon-32x32.ico 用于任务栏和书签"
echo "  - 可以删除 favicon-32x32.png（中间文件）"
echo ""

