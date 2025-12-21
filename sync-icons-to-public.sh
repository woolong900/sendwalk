#!/bin/bash

# 将 dist 目录中手动制作的 icon 同步到 public 目录
# 这样构建时就会使用你自定义的 icon 文件

set -e

echo "========================================"
echo "  同步自定义 Icon 到 Public 目录"
echo "========================================"
echo ""

DIST_DIR="frontend/dist"
PUBLIC_DIR="frontend/public"

# 检查 dist 目录中的 icon 文件
echo "检查 dist 目录中的 icon 文件..."
echo "----------------------------------------"

if [ ! -f "$DIST_DIR/favicon.ico" ]; then
    echo "❌ 错误: 找不到 $DIST_DIR/favicon.ico"
    echo "   请确保你已经在 dist 目录中放置了自定义的 icon 文件"
    exit 1
fi

echo "✓ 找到自定义 icon 文件"
echo ""

# 备份 public 目录中的原文件
echo "备份 public 目录中的原文件..."
echo "----------------------------------------"
BACKUP_DIR="frontend/public/favicon-backup-$(date +%Y%m%d_%H%M%S)"
mkdir -p "$BACKUP_DIR"

for file in favicon.ico favicon.svg favicon-16x16.png favicon-32x32.ico; do
    if [ -f "$PUBLIC_DIR/$file" ]; then
        cp "$PUBLIC_DIR/$file" "$BACKUP_DIR/" 2>/dev/null || true
        echo "✓ 已备份: $file"
    fi
done

echo ""
echo "备份位置: $BACKUP_DIR"
echo ""

# 复制 dist 中的文件到 public
echo "复制自定义 icon 到 public 目录..."
echo "----------------------------------------"

for file in favicon.ico favicon.svg favicon-16x16.png favicon-32x32.ico; do
    if [ -f "$DIST_DIR/$file" ]; then
        cp "$DIST_DIR/$file" "$PUBLIC_DIR/"
        echo "✓ 已复制: $file"
        ls -lh "$PUBLIC_DIR/$file" | awk '{print "  大小: " $5}'
    fi
done

echo ""

# 显示当前的文件
echo "当前 public 目录中的 icon 文件:"
echo "----------------------------------------"
ls -lh "$PUBLIC_DIR"/favicon* 2>/dev/null | awk '{print $9, "-", $5}'

echo ""
echo "========================================"
echo "  ✅ 同步完成！"
echo "========================================"
echo ""
echo "📋 说明:"
echo "  - 原文件已备份到: $BACKUP_DIR"
echo "  - public 目录已更新为你的自定义 icon"
echo "  - 下次构建时会使用这些自定义文件"
echo ""
echo "🔄 如果需要恢复原文件:"
echo "  cp $BACKUP_DIR/* $PUBLIC_DIR/"
echo ""
echo "🚀 现在可以安全地运行 npm run build"
echo "   你的自定义 icon 不会被覆盖"
echo ""

