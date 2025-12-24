#!/bin/bash

# 🧪 测试退信处理功能
# 验证所有功能是否正常工作

echo "========================================"
echo "🧪 测试退信处理功能"
echo "========================================"
echo ""

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# 检查是否在 backend 目录
if [ ! -f "artisan" ]; then
    echo -e "${RED}❌ 错误：请在 backend 目录下运行此脚本${NC}"
    exit 1
fi

# 获取数据库配置
DB_DATABASE=$(grep DB_DATABASE .env | cut -d '=' -f2)
DB_USERNAME=$(grep DB_USERNAME .env | cut -d '=' -f2)
DB_PASSWORD=$(grep DB_PASSWORD .env | cut -d '=' -f2)

if [ -z "$DB_DATABASE" ]; then
    echo -e "${RED}❌ 错误：未找到数据库配置${NC}"
    exit 1
fi

echo "========================================"
echo "📋 测试 1: 检查表结构"
echo "========================================"
echo ""

echo "1️⃣  检查 bounce_logs 表..."
if php artisan db:table bounce_logs > /dev/null 2>&1; then
    echo -e "${GREEN}✅ bounce_logs 表存在${NC}"
    
    # 显示列
    echo ""
    echo "列信息："
    php artisan db:table bounce_logs | grep -A 20 "Column"
else
    echo -e "${RED}❌ bounce_logs 表不存在${NC}"
fi

echo ""
echo "2️⃣  检查 subscribers 表新字段..."
COLUMNS=$(mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" -D "$DB_DATABASE" -e "SHOW COLUMNS FROM subscribers WHERE Field IN ('bounce_count', 'last_bounce_at')" 2>/dev/null)
if [ -n "$COLUMNS" ]; then
    echo -e "${GREEN}✅ subscribers 表字段存在${NC}"
    echo "$COLUMNS"
else
    echo -e "${RED}❌ subscribers 表字段不存在${NC}"
fi

echo ""
echo "3️⃣  检查 blacklist 表新字段..."
COLUMNS=$(mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" -D "$DB_DATABASE" -e "SHOW COLUMNS FROM blacklist WHERE Field IN ('reason', 'notes')" 2>/dev/null)
if [ -n "$COLUMNS" ]; then
    echo -e "${GREEN}✅ blacklist 表字段存在${NC}"
    echo "$COLUMNS"
else
    echo -e "${RED}❌ blacklist 表字段不存在${NC}"
fi

echo ""
echo "========================================"
echo "📊 测试 2: 查询数据统计"
echo "========================================"
echo ""

echo "1️⃣  退信日志统计..."
BOUNCE_COUNT=$(mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" -D "$DB_DATABASE" -se "SELECT COUNT(*) FROM bounce_logs" 2>/dev/null)
echo "总退信日志数: $BOUNCE_COUNT"

if [ "$BOUNCE_COUNT" -gt 0 ]; then
    echo ""
    echo "按类型统计:"
    mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" -D "$DB_DATABASE" -e "
        SELECT 
            bounce_type,
            COUNT(*) as count,
            COUNT(DISTINCT email) as unique_emails
        FROM bounce_logs
        GROUP BY bounce_type
    " 2>/dev/null
    
    echo ""
    echo "最近 5 条退信记录:"
    mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" -D "$DB_DATABASE" -e "
        SELECT 
            email,
            bounce_type,
            error_code,
            LEFT(error_message, 50) as error_msg,
            created_at
        FROM bounce_logs
        ORDER BY created_at DESC
        LIMIT 5
    " 2>/dev/null
fi

echo ""
echo "2️⃣  黑名单统计（退信相关）..."
BLACKLIST_COUNT=$(mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" -D "$DB_DATABASE" -se "SELECT COUNT(*) FROM blacklist WHERE reason IN ('hard_bounce', 'soft_bounce')" 2>/dev/null)
echo "退信导致的黑名单数: $BLACKLIST_COUNT"

if [ "$BLACKLIST_COUNT" -gt 0 ]; then
    echo ""
    echo "按原因统计:"
    mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" -D "$DB_DATABASE" -e "
        SELECT 
            reason,
            COUNT(*) as count
        FROM blacklist
        WHERE reason IN ('hard_bounce', 'soft_bounce')
        GROUP BY reason
    " 2>/dev/null
    
    echo ""
    echo "最近 5 条退信黑名单:"
    mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" -D "$DB_DATABASE" -e "
        SELECT 
            email,
            reason,
            LEFT(notes, 50) as notes,
            created_at
        FROM blacklist
        WHERE reason IN ('hard_bounce', 'soft_bounce')
        ORDER BY created_at DESC
        LIMIT 5
    " 2>/dev/null
fi

echo ""
echo "3️⃣  订阅者退信统计..."
BOUNCED_COUNT=$(mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" -D "$DB_DATABASE" -se "SELECT COUNT(*) FROM subscribers WHERE status = 'bounced' OR bounce_count > 0" 2>/dev/null)
echo "有退信记录的订阅者数: $BOUNCED_COUNT"

if [ "$BOUNCED_COUNT" -gt 0 ]; then
    echo ""
    echo "退信次数分布:"
    mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" -D "$DB_DATABASE" -e "
        SELECT 
            bounce_count,
            COUNT(*) as count
        FROM subscribers
        WHERE bounce_count > 0
        GROUP BY bounce_count
        ORDER BY bounce_count DESC
    " 2>/dev/null
    
    echo ""
    echo "最近退信的订阅者:"
    mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" -D "$DB_DATABASE" -e "
        SELECT 
            email,
            status,
            bounce_count,
            last_bounce_at
        FROM subscribers
        WHERE last_bounce_at IS NOT NULL
        ORDER BY last_bounce_at DESC
        LIMIT 5
    " 2>/dev/null
fi

echo ""
echo "========================================"
echo "🔍 测试 3: 检查 BounceHandler 类"
echo "========================================"
echo ""

if [ -f "app/Services/BounceHandler.php" ]; then
    echo -e "${GREEN}✅ BounceHandler.php 存在${NC}"
    
    # 检查关键配置
    echo ""
    echo "配置信息:"
    grep -A 1 "SOFT_BOUNCE_THRESHOLD\|SOFT_BOUNCE_WINDOW_DAYS" app/Services/BounceHandler.php | grep "const"
else
    echo -e "${RED}❌ BounceHandler.php 不存在${NC}"
fi

echo ""
echo "========================================"
echo "🔄 测试 4: 检查 Job 集成"
echo "========================================"
echo ""

if grep -q "BounceHandler" app/Jobs/SendCampaignEmail.php; then
    echo -e "${GREEN}✅ SendCampaignEmail.php 已集成 BounceHandler${NC}"
    
    # 显示集成代码片段
    echo ""
    echo "集成代码片段:"
    grep -A 5 "BounceHandler" app/Jobs/SendCampaignEmail.php | head -10
else
    echo -e "${RED}❌ SendCampaignEmail.php 未集成 BounceHandler${NC}"
fi

echo ""
echo "========================================"
echo "📝 测试 5: 检查日志"
echo "========================================"
echo ""

if [ -f "storage/logs/laravel.log" ]; then
    echo "最近的退信相关日志:"
    echo ""
    grep -i "bounce\|退信" storage/logs/laravel.log | tail -10
    
    if [ $? -ne 0 ]; then
        echo -e "${YELLOW}⚠️  未找到退信相关日志（可能还没有退信发生）${NC}"
    fi
else
    echo -e "${YELLOW}⚠️  日志文件不存在${NC}"
fi

echo ""
echo "========================================"
echo "✅ 测试完成"
echo "========================================"
echo ""

echo "📊 测试结果总结:"
echo "  - 退信日志: $BOUNCE_COUNT 条"
echo "  - 退信黑名单: $BLACKLIST_COUNT 条"
echo "  - 有退信记录的订阅者: $BOUNCED_COUNT 个"
echo ""

echo "💡 下一步测试建议:"
echo "  1. 发送邮件到不存在的邮箱测试硬退信"
echo "     例如: test_nonexistent_$(date +%s)@example.com"
echo ""
echo "  2. 查看实时日志:"
echo "     tail -f storage/logs/laravel.log | grep -i bounce"
echo ""
echo "  3. 查询退信详情:"
echo "     mysql -u $DB_USERNAME -p$DB_PASSWORD -D $DB_DATABASE -e 'SELECT * FROM bounce_logs ORDER BY created_at DESC LIMIT 10;'"
echo ""

echo -e "${GREEN}🎉 测试完成！${NC}"

