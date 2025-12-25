-- 直接修复黑名单 reason 字段
-- 从 ENUM 改为 TEXT 类型

USE sendwalk;

-- 1. 删除 reason 字段上的索引（如果存在）
SET @index_exists = (
    SELECT COUNT(*) 
    FROM information_schema.statistics 
    WHERE table_schema = 'sendwalk' 
    AND table_name = 'blacklist' 
    AND column_name = 'reason'
    AND index_name != 'PRIMARY'
);

SET @drop_index_sql = IF(
    @index_exists > 0,
    'ALTER TABLE blacklist DROP INDEX blacklist_reason_index',
    'SELECT "No index to drop" AS message'
);

PREPARE stmt FROM @drop_index_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. 修改 reason 字段为 TEXT 类型
ALTER TABLE blacklist MODIFY COLUMN reason TEXT NULL;

-- 3. 验证修改
DESCRIBE blacklist;

-- 4. 显示统计
SELECT 
    COUNT(*) as total_records,
    COUNT(reason) as records_with_reason
FROM blacklist;

SELECT '修复完成！reason 字段已改为 TEXT 类型' AS status;

