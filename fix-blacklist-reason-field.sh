#!/bin/bash

# 修复黑名单 reason 字段类型
# 从 ENUM 改为 TEXT，允许用户自定义原因

echo "=========================================="
echo "修复黑名单 reason 字段"
echo "=========================================="
echo ""

# 检查是否在正确的目录
if [ ! -d "backend" ]; then
    echo "❌ 错误: 请在项目根目录运行此脚本"
    exit 1
fi

cd backend

echo "1. 检查当前 reason 字段类型..."
FIELD_TYPE=$(mysql -u root -p sendwalk -e "
    SELECT DATA_TYPE, COLUMN_TYPE 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'sendwalk'
    AND TABLE_NAME = 'blacklist' 
    AND COLUMN_NAME = 'reason'
" -N)

echo "   当前类型: $FIELD_TYPE"
echo ""

if [[ $FIELD_TYPE == *"enum"* ]]; then
    echo "2. 字段类型是 ENUM，需要修复..."
    
    # 运行迁移
    echo "   运行迁移..."
    php artisan migrate --path=database/migrations/2025_12_25_120000_fix_blacklist_reason_field.php
    
    if [ $? -eq 0 ]; then
        echo "   ✓ 迁移成功"
    else
        echo "   ❌ 迁移失败"
        exit 1
    fi
    
    echo ""
    echo "3. 验证修复..."
    NEW_TYPE=$(mysql -u root -p sendwalk -e "
        SELECT DATA_TYPE, COLUMN_TYPE 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = 'sendwalk'
        AND TABLE_NAME = 'blacklist' 
        AND COLUMN_NAME = 'reason'
    " -N)
    
    echo "   新类型: $NEW_TYPE"
    
    if [[ $NEW_TYPE == *"text"* ]]; then
        echo "   ✓ 字段已成功修改为 TEXT"
    else
        echo "   ⚠️  字段类型可能不正确"
    fi
else
    echo "2. 字段类型已经是非 ENUM 类型，无需修复"
fi

echo ""
echo "=========================================="
echo "✅ 完成！"
echo "=========================================="
echo ""
echo "现在可以使用任意文本作为黑名单原因了。"
echo ""

