#!/bin/bash

echo "=========================================="
echo "  黑名单页面性能诊断工具"
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

LOG_FILE="backend/storage/logs/laravel.log"

if [ ! -f "$LOG_FILE" ]; then
    echo -e "${RED}❌ 错误: 日志文件不存在: $LOG_FILE${NC}"
    exit 1
fi

echo -e "${BLUE}步骤 1/3: 备份并清空日志${NC}"
echo "-----------------------------------"
if [ -f "$LOG_FILE" ]; then
    BACKUP_FILE="backend/storage/logs/laravel-blacklist-$(date +%Y%m%d-%H%M%S).log"
    echo "备份旧日志到: $BACKUP_FILE"
    cp "$LOG_FILE" "$BACKUP_FILE"
    
    echo "清空日志文件"
    > "$LOG_FILE"
    echo -e "${GREEN}✓${NC} 日志已清空"
else
    echo -e "${YELLOW}⚠${NC}  日志文件不存在"
fi
echo ""

echo -e "${BLUE}步骤 2/3: 检查数据库状态${NC}"
echo "-----------------------------------"

# 检查黑名单记录数
BLACKLIST_COUNT=$(mysql -N -e "SELECT COUNT(*) FROM sendwalk.blacklist" 2>/dev/null || echo "无法查询")
echo "黑名单总记录数: $BLACKLIST_COUNT"

# 检查是否有索引
echo "检查索引..."
mysql -e "SHOW INDEX FROM sendwalk.blacklist" 2>/dev/null || echo "无法查询索引"
echo ""

echo -e "${BLUE}步骤 3/3: 开始实时监控${NC}"
echo "-----------------------------------"
echo ""
echo -e "${GREEN}准备就绪！${NC}"
echo ""
echo -e "${YELLOW}现在请执行以下操作：${NC}"
echo "1. 在浏览器中打开或刷新黑名单页面"
echo "2. 观察下方的实时日志输出"
echo "3. 按 Ctrl+C 停止监控"
echo ""
echo "-----------------------------------"
echo -e "${BLUE}实时性能日志监控：${NC}"
echo "-----------------------------------"
echo ""

# 实时监控
tail -f "$LOG_FILE" | while read line; do
    if echo "$line" | grep -q "\[性能-黑名单\]"; then
        # 提取关键信息
        if echo "$line" | grep -q "开始处理列表请求"; then
            echo ""
            echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
            echo -e "${BLUE}📋 新的黑名单列表请求${NC}"
            echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
            
            REQUEST_ID=$(echo "$line" | grep -o "request_id\":\"[^\"]*" | cut -d'"' -f3)
            PAGE=$(echo "$line" | grep -o "page\":[0-9]*" | cut -d':' -f2)
            HAS_SEARCH=$(echo "$line" | grep -o "has_search\":[^,}]*" | cut -d':' -f2)
            
            echo "请求ID: $REQUEST_ID"
            echo "页码: ${PAGE:-1}"
            echo "有搜索: $HAS_SEARCH"
            echo ""
            
        elif echo "$line" | grep -q "查询构建完成"; then
            DURATION=$(echo "$line" | grep -o "duration_ms\":[0-9.]*" | cut -d':' -f2)
            echo -e "${GREEN}  ✓ 查询构建: ${DURATION}ms${NC}"
            
        elif echo "$line" | grep -q "搜索条件添加完成"; then
            DURATION=$(echo "$line" | grep -o "duration_ms\":[0-9.]*" | cut -d':' -f2)
            SEARCH=$(echo "$line" | grep -o "search_term\":\"[^\"]*" | cut -d'"' -f3)
            echo -e "${GREEN}  ✓ 搜索条件: ${DURATION}ms${NC} (关键词: $SEARCH)"
            
        elif echo "$line" | grep -q "准备执行SQL"; then
            SQL=$(echo "$line" | grep -o "sql\":\"[^\"]*" | cut -d'"' -f3)
            echo -e "${YELLOW}  📝 SQL: ${SQL:0:80}...${NC}"
            
        elif echo "$line" | grep -q "数据库查询完成"; then
            DURATION=$(echo "$line" | grep -o "duration_ms\":[0-9.]*" | cut -d':' -f2)
            TOTAL=$(echo "$line" | grep -o "total_records\":[0-9]*" | cut -d':' -f2)
            COUNT=$(echo "$line" | grep -o "returned_count\":[0-9]*" | cut -d':' -f2)
            
            if (( $(echo "$DURATION > 100" | bc -l) )); then
                echo -e "${RED}  🐌 数据库查询: ${DURATION}ms${NC} (总数: $TOTAL, 返回: $COUNT)"
            else
                echo -e "${GREEN}  ✅ 数据库查询: ${DURATION}ms${NC} (总数: $TOTAL, 返回: $COUNT)"
            fi
            
        elif echo "$line" | grep -q "请求处理完成"; then
            QUERY_BUILD=$(echo "$line" | grep -o "query_build_ms\":[0-9.]*" | cut -d':' -f2)
            DB_QUERY=$(echo "$line" | grep -o "db_query_ms\":[0-9.]*" | cut -d':' -f2)
            RESPONSE=$(echo "$line" | grep -o "response_build_ms\":[0-9.]*" | cut -d':' -f2)
            TOTAL=$(echo "$line" | grep -o "total_duration_ms\":[0-9.]*" | cut -d':' -f2)
            
            echo ""
            echo -e "${BLUE}📊 性能摘要：${NC}"
            echo "  查询构建: ${QUERY_BUILD}ms"
            echo "  数据库查询: ${DB_QUERY}ms"
            echo "  响应构建: ${RESPONSE}ms"
            echo "  ─────────────────────"
            
            if (( $(echo "$TOTAL > 500" | bc -l) )); then
                echo -e "  ${RED}总耗时: ${TOTAL}ms 🐌${NC}"
            elif (( $(echo "$TOTAL > 200" | bc -l) )); then
                echo -e "  ${YELLOW}总耗时: ${TOTAL}ms ⚠️${NC}"
            else
                echo -e "  ${GREEN}总耗时: ${TOTAL}ms ✅${NC}"
            fi
            
        elif echo "$line" | grep -q "数据库查询慢"; then
            echo -e "${YELLOW}  ⚠️  数据库查询超过100ms！${NC}"
            
        elif echo "$line" | grep -q "请求处理慢"; then
            echo -e "${RED}  🚨 请求总耗时超过500ms！${NC}"
            PERCENTAGE=$(echo "$line" | grep -o "percentage_in_db\":\"[^\"]*" | cut -d'"' -f3)
            echo -e "  ${RED}数据库占比: $PERCENTAGE${NC}"
        fi
    fi
done

