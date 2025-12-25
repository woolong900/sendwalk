#!/bin/bash

# 简单快速修复黑名单 reason 字段
# 使用 SQL 脚本直接修复

echo "=========================================="
echo "快速修复黑名单 reason 字段"
echo "=========================================="
echo ""

# 检查是否在正确的目录
if [ ! -d "backend" ]; then
    echo "❌ 错误: 请在项目根目录运行此脚本"
    exit 1
fi

echo "1. 检查当前字段类型..."
mysql -u root -p sendwalk -e "DESCRIBE blacklist;" | grep reason
echo ""

echo "2. 检查是否有索引..."
mysql -u root -p sendwalk -e "SHOW INDEX FROM blacklist WHERE Column_name = 'reason';"
echo ""

read -p "确认修复？(y/N) " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "已取消"
    exit 0
fi

echo ""
echo "3. 执行修复..."

# 方法 1: 直接执行 SQL 命令
echo "   删除索引（如果存在）..."
mysql -u root -p sendwalk -e "
    ALTER TABLE blacklist DROP INDEX blacklist_reason_index;
" 2>/dev/null || echo "   (索引可能不存在，跳过)"

echo "   修改字段类型..."
mysql -u root -p sendwalk -e "
    ALTER TABLE blacklist MODIFY COLUMN reason TEXT NULL;
"

if [ $? -eq 0 ]; then
    echo "   ✓ 修改成功"
else
    echo "   ❌ 修改失败"
    exit 1
fi

echo ""
echo "4. 验证修复..."
mysql -u root -p sendwalk -e "DESCRIBE blacklist;" | grep reason
echo ""

echo "5. 测试插入自定义原因..."
mysql -u root -p sendwalk -e "
    DELETE FROM blacklist WHERE email = 'test-fix@example.com';
    INSERT INTO blacklist (user_id, email, reason, created_at, updated_at) 
    VALUES (1, 'test-fix@example.com', 'mailwizz导入测试', NOW(), NOW());
    SELECT * FROM blacklist WHERE email = 'test-fix@example.com';
"

if [ $? -eq 0 ]; then
    echo ""
    echo "   ✓ 测试成功"
else
    echo ""
    echo "   ❌ 测试失败"
    exit 1
fi

echo ""
echo "=========================================="
echo "✅ 修复完成！"
echo "=========================================="
echo ""
echo "现在可以使用任意文本作为黑名单原因了。"
echo ""

