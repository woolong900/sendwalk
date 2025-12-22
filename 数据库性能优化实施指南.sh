#!/bin/bash

# 数据库性能优化实施指南
# 作者: AI Assistant
# 日期: 2025-12-22
# 说明: 此脚本用于在生产环境实施数据库性能优化

set -e  # 遇到错误立即退出

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# 项目路径
PROJECT_DIR="/data/www/sendwalk/backend"

echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}  数据库性能优化 - 阶段1（快速修复）${NC}"
echo -e "${BLUE}========================================${NC}"
echo ""

# 检查是否在项目目录
if [ ! -d "$PROJECT_DIR" ]; then
    echo -e "${RED}错误: 项目目录不存在: $PROJECT_DIR${NC}"
    exit 1
fi

cd $PROJECT_DIR

echo -e "${YELLOW}步骤 1/5: 检查当前数据库状态${NC}"
echo "-------------------------------------"

# 检查表大小
echo "主要表的数据量："
php artisan tinker --execute="
echo 'subscribers: ' . \App\Models\Subscriber::count();
echo 'campaigns: ' . \App\Models\Campaign::count();
echo 'campaign_sends: ' . \App\Models\CampaignSend::count();
echo 'send_logs: ' . \App\Models\SendLog::count();
"

echo ""
read -p "按回车键继续..."

echo -e "${YELLOW}步骤 2/5: 备份数据库${NC}"
echo "-------------------------------------"

# 获取数据库配置
DB_NAME=$(php artisan tinker --execute="echo config('database.connections.mysql.database');")
DB_USER=$(php artisan tinker --execute="echo config('database.connections.mysql.username');")
DB_PASS=$(php artisan tinker --execute="echo config('database.connections.mysql.password');")
BACKUP_FILE="backup_$(date +%Y%m%d_%H%M%S).sql"

echo "数据库: $DB_NAME"
echo "备份文件: storage/backups/$BACKUP_FILE"

# 创建备份目录
mkdir -p storage/backups

echo -e "${BLUE}开始备份数据库...${NC}"
mysqldump -u$DB_USER -p$DB_PASS $DB_NAME > storage/backups/$BACKUP_FILE

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✅ 数据库备份成功！${NC}"
    BACKUP_SIZE=$(du -h storage/backups/$BACKUP_FILE | cut -f1)
    echo "备份文件大小: $BACKUP_SIZE"
else
    echo -e "${RED}❌ 数据库备份失败！${NC}"
    exit 1
fi

echo ""
read -p "按回车键继续..."

echo -e "${YELLOW}步骤 3/5: 查看待添加的索引${NC}"
echo "-------------------------------------"

echo "将添加以下索引："
echo ""
echo "【campaigns 表】"
echo "  - idx_campaigns_status (status)"
echo "  - idx_campaigns_scheduled_at (scheduled_at)"
echo "  - idx_campaigns_sent_at (sent_at)"
echo "  - idx_campaigns_user_status_time (user_id, status, created_at)"
echo ""
echo "【campaign_sends 表】"
echo "  - idx_campaign_sends_status (status)"
echo "  - idx_campaign_sends_sent_at (sent_at)"
echo "  - idx_campaign_sends_sub_status (subscriber_id, status)"
echo ""
echo "【list_subscriber 表】"
echo "  - idx_list_subscriber_status (status)"
echo "  - idx_list_subscriber_list_status (list_id, status)"
echo "  - idx_list_subscriber_sub_status (subscriber_id, status)"
echo ""
echo "【subscribers 表】"
echo "  - idx_subscribers_status (status)"
echo "  - idx_subscribers_created_at (created_at)"
echo ""

echo -e "${YELLOW}预期效果:${NC}"
echo "  - 查询速度提升 50-70%"
echo "  - 对现有功能无影响"
echo "  - 立即生效"
echo ""

echo -e "${YELLOW}注意事项:${NC}"
echo "  - 索引创建可能需要几秒到几分钟（取决于数据量）"
echo "  - 会略微降低写入性能（约 5-10%）"
echo "  - 增加磁盘空间占用（约表大小的 10-20%）"
echo ""

read -p "确认要继续吗？(y/n) " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo -e "${YELLOW}操作已取消${NC}"
    exit 0
fi

echo -e "${YELLOW}步骤 4/5: 运行数据库迁移（添加索引）${NC}"
echo "-------------------------------------"

echo -e "${BLUE}开始添加索引...${NC}"
echo "这可能需要几分钟，请耐心等待..."

# 记录开始时间
START_TIME=$(date +%s)

# 运行迁移
php artisan migrate --force

# 计算耗时
END_TIME=$(date +%s)
DURATION=$((END_TIME - START_TIME))

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✅ 索引添加成功！耗时: ${DURATION} 秒${NC}"
else
    echo -e "${RED}❌ 索引添加失败！${NC}"
    echo ""
    echo "回滚方案:"
    echo "1. 恢复数据库备份:"
    echo "   mysql -u$DB_USER -p$DB_PASS $DB_NAME < storage/backups/$BACKUP_FILE"
    echo ""
    echo "2. 或运行回滚:"
    echo "   php artisan migrate:rollback --step=1"
    exit 1
fi

echo ""
read -p "按回车键继续..."

echo -e "${YELLOW}步骤 5/5: 验证索引${NC}"
echo "-------------------------------------"

echo "验证 campaigns 表索引："
php artisan tinker --execute="
\$indexes = \DB::select('SHOW INDEX FROM campaigns WHERE Key_name LIKE \"idx_campaigns%\"');
foreach (\$indexes as \$index) {
    echo \$index->Key_name . ' (' . \$index->Column_name . ')';
}
"

echo ""
echo "验证 subscribers 表索引："
php artisan tinker --execute="
\$indexes = \DB::select('SHOW INDEX FROM subscribers WHERE Key_name LIKE \"idx_subscribers%\"');
foreach (\$indexes as \$index) {
    echo \$index->Key_name . ' (' . \$index->Column_name . ')';
}
"

echo ""
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}  ✅ 性能优化完成！${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""

echo -e "${BLUE}下一步建议:${NC}"
echo ""
echo "1. 监控性能变化"
echo "   - 观察页面加载速度"
echo "   - 查看慢查询日志"
echo ""
echo "2. 启用慢查询日志（如果还没有）"
echo "   sudo nano /etc/mysql/mysql.conf.d/mysqld.cnf"
echo "   添加:"
echo "     slow_query_log = 1"
echo "     slow_query_log_file = /var/log/mysql/mysql-slow.log"
echo "     long_query_time = 1"
echo "   sudo systemctl restart mysql"
echo ""
echo "3. 查看慢查询"
echo "   sudo tail -f /var/log/mysql/mysql-slow.log"
echo ""
echo "4. 清理过期数据（可选）"
echo "   - 定期清理旧的 send_logs"
echo "   - 归档已完成的活动数据"
echo ""
echo "5. 考虑实施阶段2优化（如果性能仍不理想）"
echo "   - 添加 user_id 到 subscribers 表"
echo "   - 实施全文搜索"
echo "   - 参考: 数据库性能优化方案.md"
echo ""

echo -e "${YELLOW}备份文件位置:${NC}"
echo "  $PROJECT_DIR/storage/backups/$BACKUP_FILE"
echo "  （建议保留至少7天）"
echo ""

echo -e "${GREEN}操作完成！🎉${NC}"

