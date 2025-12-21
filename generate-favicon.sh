#!/bin/bash

# 生成 favicon.ico 脚本

echo "========================================"
echo "  生成 Favicon"
echo "========================================"
echo ""

FRONTEND_DIR="/data/www/sendwalk/frontend/public"

cd "$FRONTEND_DIR"

echo "方法 1: 使用 ImageMagick (convert)"
echo "----------------------------------------"

if command -v convert &> /dev/null; then
    echo "✓ ImageMagick 已安装"
    
    # 从 SVG 生成多个尺寸的 PNG，然后合并成 ICO
    echo "生成 favicon.ico..."
    convert favicon.svg \
        -resize 16x16 -density 16x16 favicon-16.png
    convert favicon.svg \
        -resize 32x32 -density 32x32 favicon-32.png
    convert favicon.svg \
        -resize 48x48 -density 48x48 favicon-48.png
    
    convert favicon-16.png favicon-32.png favicon-48.png favicon.ico
    
    # 清理临时文件
    rm favicon-16.png favicon-32.png favicon-48.png
    
    echo "✓ favicon.ico 已生成"
else
    echo "✗ ImageMagick 未安装"
    echo ""
    echo "安装 ImageMagick:"
    echo "  Ubuntu/Debian: sudo apt-get install imagemagick"
    echo "  CentOS/RHEL:   sudo yum install ImageMagick"
    echo "  macOS:         brew install imagemagick"
fi

echo ""
echo "方法 2: 使用在线工具"
echo "----------------------------------------"
echo "如果服务器上没有 ImageMagick，可以："
echo ""
echo "1. 下载 favicon.svg 到本地"
echo "2. 访问: https://convertio.co/zh/svg-ico/"
echo "3. 上传 favicon.svg"
echo "4. 下载生成的 favicon.ico"
echo "5. 上传回服务器"
echo ""

echo "方法 3: 使用 rsvg-convert"
echo "----------------------------------------"

if command -v rsvg-convert &> /dev/null; then
    echo "✓ rsvg-convert 已安装"
    
    # 生成 PNG
    rsvg-convert -w 32 -h 32 favicon.svg -o favicon-32.png
    
    # 再用其他工具转成 ICO
    if command -v convert &> /dev/null; then
        convert favicon-32.png favicon.ico
        rm favicon-32.png
        echo "✓ favicon.ico 已生成"
    else
        echo "⚠️  已生成 PNG，但需要 ImageMagick 转换为 ICO"
    fi
else
    echo "✗ rsvg-convert 未安装"
    echo ""
    echo "安装 librsvg:"
    echo "  Ubuntu/Debian: sudo apt-get install librsvg2-bin"
    echo "  CentOS/RHEL:   sudo yum install librsvg2-tools"
fi

echo ""
echo "========================================"
echo "  提示"
echo "========================================"
echo ""
echo "生成后，复制到部署目录:"
echo "  cp favicon.ico /data/www/sendwalk/frontend/dist/"
echo ""
echo "或者在构建时自动复制（已在 vite.config.ts 中配置）"
echo ""

