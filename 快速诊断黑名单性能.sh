#!/bin/bash

echo "=========================================="
echo "  快速诊断黑名单页面性能"
echo "=========================================="
echo ""

# 颜色定义
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# 检查是否在项目根目录
if [ ! -d "backend" ]; then
    echo -e "${RED}❌ 错误: 请在项目根目录执行此脚本${NC}"
    exit 1
fi

echo -e "${BLUE}步骤 1/5: 拉取最新代码${NC}"
echo "-----------------------------------"
git pull
echo ""

echo -e "${BLUE}步骤 2/5: 检查数据库状态${NC}"
echo "-----------------------------------"
BLACKLIST_COUNT=$(mysql -N -e "SELECT COUNT(*) FROM sendwalk.blacklist" 2>/dev/null || echo "无法查询")
echo "黑名单总记录数: ${BLACKLIST_COUNT}"

if [ "$BLACKLIST_COUNT" != "无法查询" ] && [ "$BLACKLIST_COUNT" -gt 1000000 ]; then
    echo -e "${YELLOW}⚠️  数据量较大（>100万），可能会有性能问题${NC}"
fi
echo ""

echo -e "${BLUE}步骤 3/5: 检查索引${NC}"
echo "-----------------------------------"
INDEX_COUNT=$(mysql -N -e "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema='sendwalk' AND table_name='blacklist'" 2>/dev/null || echo "0")
echo "索引数量: ${INDEX_COUNT}"

if [ "$INDEX_COUNT" -lt 3 ]; then
    echo -e "${YELLOW}⚠️  索引可能不足，建议运行 php artisan migrate${NC}"
else
    echo -e "${GREEN}✓${NC} 索引配置正常"
fi
echo ""

echo -e "${BLUE}步骤 4/5: 备份并清空日志${NC}"
echo "-----------------------------------"
LOG_FILE="backend/storage/logs/laravel.log"
if [ -f "$LOG_FILE" ]; then
    BACKUP_FILE="backend/storage/logs/laravel-backup-$(date +%Y%m%d-%H%M%S).log"
    echo "备份: $BACKUP_FILE"
    cp "$LOG_FILE" "$BACKUP_FILE"
    > "$LOG_FILE"
    echo -e "${GREEN}✓${NC} 日志已清空"
else
    echo -e "${YELLOW}⚠${NC}  日志文件不存在"
fi
echo ""

echo -e "${BLUE}步骤 5/5: 开始实时监控${NC}"
echo "-----------------------------------"
echo ""
echo -e "${GREEN}准备完成！${NC}"
echo ""
echo -e "${YELLOW}📋 现在请执行以下操作：${NC}"
echo ""
echo "  1️⃣  在浏览器中打开黑名单页面"
echo "  2️⃣  尝试翻页（特别是翻到后面的页）"
echo "  3️⃣  尝试搜索功能"
echo "  4️⃣  观察下方的实时性能日志"
echo "  5️⃣  按 Ctrl+C 停止监控"
echo ""
echo -e "${BLUE}💡 注意观察：${NC}"
echo "  - 数据库查询耗时（重点）"
echo "  - 总耗时"
echo "  - 是否有慢查询警告"
echo ""
echo "-----------------------------------"
echo -e "${BLUE}🔍 实时性能监控（黑名单）：${NC}"
echo "-----------------------------------"
echo ""

# 实时监控（简化版，只显示关键信息）
tail -f "$LOG_FILE" | while read line; do
    if echo "$line" | grep -q "\[性能-黑名单\]"; then
        if echo "$line" | grep -q "开始处理列表请求"; then
            echo ""
            echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
            PAGE=$(echo "$line" | grep -o "page\":[0-9]*" | cut -d':' -f2)
            SEARCH=$(echo "$line" | grep -o "search\":\"[^\"]*" | cut -d'"' -f3)
            
            echo -e "${BLUE}📋 新请求${NC} | 页码: ${PAGE:-1}"
            [ ! -z "$SEARCH" ] && echo "   搜索: $SEARCH"
            
        elif echo "$line" | grep -q "数据库查询完成"; then
            DURATION=$(echo "$line" | grep -o "duration_ms\":[0-9.]*" | cut -d':' -f2)
            TOTAL=$(echo "$line" | grep -o "total_records\":[0-9]*" | cut -d':' -f2)
            
            if (( $(echo "$DURATION > 1000" | bc -l) )); then
                echo -e "${RED}   🐌 数据库: ${DURATION}ms (总数: $TOTAL)${NC}"
            elif (( $(echo "$DURATION > 100" | bc -l) )); then
                echo -e "${YELLOW}   ⚠️  数据库: ${DURATION}ms (总数: $TOTAL)${NC}"
            else
                echo -e "${GREEN}   ✅ 数据库: ${DURATION}ms (总数: $TOTAL)${NC}"
            fi
            
        elif echo "$line" | grep -q "请求处理完成"; then
            DB=$(echo "$line" | grep -o "db_query_ms\":[0-9.]*" | cut -d':' -f2)
            TOTAL=$(echo "$line" | grep -o "total_duration_ms\":[0-9.]*" | cut -d':' -f2)
            
            if (( $(echo "$TOTAL > 1000" | bc -l) )); then
                echo -e "${RED}   🔥 总耗时: ${TOTAL}ms (数据库: ${DB}ms)${NC}"
            elif (( $(echo "$TOTAL > 500" | bc -l) )); then
                echo -e "${YELLOW}   ⏱️  总耗时: ${TOTAL}ms (数据库: ${DB}ms)${NC}"
            else
                echo -e "${GREEN}   ⚡ 总耗时: ${TOTAL}ms (数据库: ${DB}ms)${NC}"
            fi
            
        elif echo "$line" | grep -q "数据库查询慢"; then
            echo -e "${RED}   🚨 检测到慢查询！${NC}"
            
        elif echo "$line" | grep -q "请求处理慢"; then
            PERCENTAGE=$(echo "$line" | grep -o "percentage_in_db\":\"[^\"]*" | cut -d'"' -f3)
            echo -e "${RED}   📊 数据库占比: $PERCENTAGE${NC}"
        fi
    fi
done

