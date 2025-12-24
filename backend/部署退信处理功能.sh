#!/bin/bash

# 🔥 部署退信处理和自动黑名单功能
# 适用于生产环境

set -e  # 遇到错误立即退出

echo "========================================"
echo "🔥 部署退信处理功能"
echo "========================================"
echo ""

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# 检查是否在 backend 目录
if [ ! -f "artisan" ]; then
    echo -e "${RED}❌ 错误：请在 backend 目录下运行此脚本${NC}"
    exit 1
fi

echo "========================================"
echo "📋 步骤 1: 备份数据库"
echo "========================================"
echo ""

# 获取数据库配置
DB_DATABASE=$(grep DB_DATABASE .env | cut -d '=' -f2)
DB_USERNAME=$(grep DB_USERNAME .env | cut -d '=' -f2)

if [ -z "$DB_DATABASE" ]; then
    echo -e "${YELLOW}⚠️  警告：未找到数据库配置${NC}"
    echo "请手动备份数据库！"
    read -p "是否继续？(y/n) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        exit 1
    fi
else
    BACKUP_FILE="backup_before_bounce_handler_$(date +%Y%m%d_%H%M%S).sql"
    echo "正在备份数据库 $DB_DATABASE 到 ~/$BACKUP_FILE ..."
    
    # 尝试备份（可能需要密码）
    if mysqldump -u "$DB_USERNAME" -p "$DB_DATABASE" > ~/"$BACKUP_FILE" 2>/dev/null; then
        echo -e "${GREEN}✅ 数据库备份成功！${NC}"
    else
        echo -e "${YELLOW}⚠️  自动备份失败，请手动备份数据库${NC}"
        read -p "是否继续？(y/n) " -n 1 -r
        echo
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            exit 1
        fi
    fi
fi

echo ""
echo "========================================"
echo "🗄️  步骤 2: 运行数据库迁移"
echo "========================================"
echo ""

# 运行迁移
echo "正在运行数据库迁移..."
php artisan migrate --force

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✅ 数据库迁移成功！${NC}"
else
    echo -e "${RED}❌ 数据库迁移失败！${NC}"
    exit 1
fi

echo ""
echo "========================================"
echo "🔍 步骤 3: 验证表结构"
echo "========================================"
echo ""

# 检查新表是否存在
echo "检查 bounce_logs 表..."
php artisan db:table bounce_logs > /dev/null 2>&1
if [ $? -eq 0 ]; then
    echo -e "${GREEN}✅ bounce_logs 表创建成功${NC}"
else
    echo -e "${RED}❌ bounce_logs 表不存在${NC}"
fi

echo "检查 subscribers 表字段..."
SUBSCRIBERS_CHECK=$(mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" -D "$DB_DATABASE" -e "SHOW COLUMNS FROM subscribers LIKE 'bounce_count'" 2>/dev/null | wc -l)
if [ "$SUBSCRIBERS_CHECK" -gt 1 ]; then
    echo -e "${GREEN}✅ subscribers 表字段添加成功${NC}"
else
    echo -e "${YELLOW}⚠️  subscribers 表字段可能未添加${NC}"
fi

echo "检查 blacklist 表字段..."
BLACKLIST_CHECK=$(mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" -D "$DB_DATABASE" -e "SHOW COLUMNS FROM blacklist LIKE 'notes'" 2>/dev/null | wc -l)
if [ "$BLACKLIST_CHECK" -gt 1 ]; then
    echo -e "${GREEN}✅ blacklist 表字段添加成功${NC}"
else
    echo -e "${YELLOW}⚠️  blacklist 表字段可能未添加${NC}"
fi

echo ""
echo "========================================"
echo "🧹 步骤 4: 清理缓存"
echo "========================================"
echo ""

echo "清理配置缓存..."
php artisan config:clear

echo "清理应用缓存..."
php artisan cache:clear

echo "清理路由缓存..."
php artisan route:clear

echo "清理视图缓存..."
php artisan view:clear

echo -e "${GREEN}✅ 缓存清理完成${NC}"

echo ""
echo "========================================"
echo "🔄 步骤 5: 重启队列 Worker"
echo "========================================"
echo ""

echo "发送重启信号给所有 Worker..."
php artisan queue:restart

echo "等待 5 秒让 Worker 重启..."
sleep 5

# 检查 Worker 是否运行
WORKER_COUNT=$(ps aux | grep -E "campaign:process-queue|queue:work" | grep -v grep | wc -l)
echo "当前运行的 Worker 数量: $WORKER_COUNT"

if [ "$WORKER_COUNT" -gt 0 ]; then
    echo -e "${GREEN}✅ Worker 正在运行${NC}"
else
    echo -e "${YELLOW}⚠️  警告：未检测到运行中的 Worker${NC}"
    echo "请手动启动 Worker："
    echo "  php artisan queue:work default --sleep=3 --tries=1 &"
    echo "  php artisan manage:workers &"
fi

echo ""
echo "========================================"
echo "✅ 部署完成！"
echo "========================================"
echo ""

echo "📊 功能清单："
echo "  ✅ 硬退信自动黑名单（5xx 错误）"
echo "  ✅ 软退信计数（7天内3次失败）"
echo "  ✅ 退信日志记录"
echo "  ✅ 智能错误检测"
echo ""

echo "📝 下一步："
echo "  1. 查看日志：tail -f storage/logs/laravel.log | grep -i bounce"
echo "  2. 测试功能：发送邮件到不存在的邮箱"
echo "  3. 查看退信日志：SELECT * FROM bounce_logs ORDER BY created_at DESC LIMIT 10;"
echo "  4. 查看黑名单：SELECT * FROM blacklist WHERE reason IN ('hard_bounce', 'soft_bounce');"
echo ""

echo "📖 详细说明："
echo "  查看文件：退信处理功能部署说明.md"
echo ""

echo -e "${GREEN}🎉 部署成功！${NC}"

