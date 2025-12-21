#!/bin/bash

echo "======================================"
echo "  Favicon 文件检查"
echo "======================================"
echo ""

cd public

echo "📂 检查文件..."
echo ""

check_file() {
    local file=$1
    local desc=$2
    
    if [ -f "$file" ]; then
        local size=$(ls -lh "$file" | awk '{print $5}')
        echo "✅ $desc"
        echo "   文件: $file"
        echo "   大小: $size"
    else
        echo "❌ $desc - 文件不存在"
    fi
    echo ""
}

check_file "favicon.svg" "SVG 格式（推荐）"
check_file "favicon-32x32.png" "PNG 32x32"
check_file "favicon-16x16.png" "PNG 16x16"
check_file "favicon.ico" "ICO 格式"

echo "======================================"
echo "📋 HTML 配置检查"
echo "======================================"
echo ""

cd ..
if grep -q "favicon.svg" index.html && \
   grep -q "favicon-32x32.png" index.html && \
   grep -q "favicon-16x16.png" index.html && \
   grep -q "favicon.ico" index.html; then
    echo "✅ HTML 配置正确"
    echo ""
    echo "引用的图标文件:"
    grep -E "favicon\.(svg|png|ico)" index.html | sed 's/^/   /'
else
    echo "❌ HTML 配置缺失"
fi

echo ""
echo "======================================"
echo "🚀 测试方法"
echo "======================================"
echo ""
echo "1. 启动开发服务器:"
echo "   npm run dev"
echo ""
echo "2. 在浏览器中访问:"
echo "   http://localhost:5173"
echo ""
echo "3. 查看浏览器标签，应该显示蓝底白字的 S 图标"
echo ""
echo "======================================"
