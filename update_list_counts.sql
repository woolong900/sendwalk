-- 修复邮件列表订阅者数量
-- 此脚本会重新计算所有邮件列表的订阅者数量

-- 更新所有列表的 subscribers_count (活跃订阅者)
UPDATE lists l
SET subscribers_count = (
    SELECT COUNT(*) 
    FROM list_subscriber ls 
    WHERE ls.list_id = l.id 
    AND ls.status = 'active'
);

-- 更新所有列表的 unsubscribed_count (取消订阅者)
UPDATE lists l
SET unsubscribed_count = (
    SELECT COUNT(*) 
    FROM list_subscriber ls 
    WHERE ls.list_id = l.id 
    AND ls.status = 'unsubscribed'
);

-- 验证更新结果
SELECT 
    l.id,
    l.name,
    l.subscribers_count AS '存储的数量',
    l.unsubscribed_count AS '取消订阅数量',
    (SELECT COUNT(*) FROM list_subscriber WHERE list_id = l.id AND status = 'active') AS '实际活跃数量',
    (SELECT COUNT(*) FROM list_subscriber WHERE list_id = l.id AND status = 'unsubscribed') AS '实际取消数量'
FROM lists l
ORDER BY l.id;

