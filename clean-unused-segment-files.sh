#!/bin/bash

# 清理未使用的列表分段功能相关文件
# segments 功能从未实现，可以安全删除

echo "=========================================="
echo "清理未使用的列表分段功能"
echo "=========================================="
echo ""

# 检查是否在正确的目录
if [ ! -d "backend" ]; then
    echo "❌ 错误: 请在项目根目录运行此脚本"
    exit 1
fi

echo "以下文件将被删除:"
echo "────────────────────────────────────────"
echo "1. backend/app/Models/Segment.php"
echo "2. backend/app/Http/Controllers/Api/SegmentController.php"
echo "3. backend/database/migrations/2025_12_24_182329_create_segments_table.php"
echo ""
echo "以下数据库表将被删除:"
echo "────────────────────────────────────────"
echo "1. segments (如果存在)"
echo ""

read -p "确认删除？(y/N) " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "已取消"
    exit 0
fi

echo ""
echo "1. 删除模型文件..."
if [ -f "backend/app/Models/Segment.php" ]; then
    rm backend/app/Models/Segment.php
    echo "   ✓ 已删除 Segment.php"
else
    echo "   - Segment.php 不存在"
fi

echo ""
echo "2. 删除控制器文件..."
if [ -f "backend/app/Http/Controllers/Api/SegmentController.php" ]; then
    rm backend/app/Http/Controllers/Api/SegmentController.php
    echo "   ✓ 已删除 SegmentController.php"
else
    echo "   - SegmentController.php 不存在"
fi

echo ""
echo "3. 删除旧的创建表迁移..."
if [ -f "backend/database/migrations/2025_12_24_182329_create_segments_table.php" ]; then
    rm backend/database/migrations/2025_12_24_182329_create_segments_table.php
    echo "   ✓ 已删除 create_segments_table.php"
else
    echo "   - create_segments_table.php 不存在"
fi

echo ""
echo "4. 运行迁移删除数据库表..."
cd backend

# 检查表是否存在
TABLE_EXISTS=$(mysql -u root -p sendwalk -N -e "
    SELECT COUNT(*) 
    FROM information_schema.tables 
    WHERE table_schema = 'sendwalk' 
    AND table_name = 'segments'
" 2>/dev/null)

if [ "$TABLE_EXISTS" = "1" ]; then
    echo "   segments 表存在，正在删除..."
    php artisan migrate --path=database/migrations/2025_12_25_130000_remove_segments_table.php
    
    if [ $? -eq 0 ]; then
        echo "   ✓ segments 表已删除"
    else
        echo "   ❌ 删除表失败"
        exit 1
    fi
else
    echo "   - segments 表不存在"
fi

echo ""
echo "5. 验证清理结果..."
echo "   检查文件..."
[ ! -f "backend/app/Models/Segment.php" ] && echo "   ✓ Segment.php 已删除" || echo "   ⚠️ Segment.php 仍存在"
[ ! -f "backend/app/Http/Controllers/Api/SegmentController.php" ] && echo "   ✓ SegmentController.php 已删除" || echo "   ⚠️ SegmentController.php 仍存在"

echo ""
echo "   检查数据库..."
TABLE_STILL_EXISTS=$(mysql -u root -p sendwalk -N -e "
    SELECT COUNT(*) 
    FROM information_schema.tables 
    WHERE table_schema = 'sendwalk' 
    AND table_name = 'segments'
" 2>/dev/null)

if [ "$TABLE_STILL_EXISTS" = "0" ]; then
    echo "   ✓ segments 表已删除"
else
    echo "   ⚠️ segments 表仍存在"
fi

echo ""
echo "=========================================="
echo "✅ 清理完成！"
echo "=========================================="
echo ""
echo "已删除未使用的列表分段功能相关文件和数据库表。"
echo ""

