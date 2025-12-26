#!/bin/bash

echo "=========================================="
echo "  立即开始导入性能诊断"
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

echo -e "${BLUE}步骤 1/4: 拉取最新代码${NC}"
echo "-----------------------------------"
git pull
echo ""

echo -e "${BLUE}步骤 2/4: 检查服务状态${NC}"
echo "-----------------------------------"

# 检查PHP-FPM
PHP_FPM_COUNT=$(ps aux | grep php-fpm | grep -v grep | wc -l)
echo "PHP-FPM 进程数: $PHP_FPM_COUNT"

if [ $PHP_FPM_COUNT -lt 3 ]; then
    echo -e "${RED}⚠️  警告: PHP-FPM 进程数过少！${NC}"
else
    echo -e "${GREEN}✓${NC} PHP-FPM 运行正常"
fi
echo ""

# 检查Nginx
if pgrep nginx > /dev/null; then
    echo -e "${GREEN}✓${NC} Nginx 运行正常"
else
    echo -e "${RED}✗${NC} Nginx 未运行"
fi
echo ""

# 检查Redis
if redis-cli ping &> /dev/null; then
    echo -e "${GREEN}✓${NC} Redis 运行正常"
else
    echo -e "${YELLOW}⚠${NC}  Redis 可能未运行或无法连接"
fi
echo ""

# 检查MySQL
if mysqladmin ping &> /dev/null; then
    echo -e "${GREEN}✓${NC} MySQL 运行正常"
else
    echo -e "${YELLOW}⚠${NC}  MySQL 可能未运行或无法连接"
fi
echo ""

echo -e "${BLUE}步骤 3/4: 清空旧日志${NC}"
echo "-----------------------------------"
LOG_FILE="backend/storage/logs/laravel.log"
if [ -f "$LOG_FILE" ]; then
    BACKUP_FILE="backend/storage/logs/laravel-backup-$(date +%Y%m%d-%H%M%S).log"
    echo "备份旧日志到: $BACKUP_FILE"
    cp "$LOG_FILE" "$BACKUP_FILE"
    
    echo "清空日志文件"
    > "$LOG_FILE"
    echo -e "${GREEN}✓${NC} 日志已清空，便于查看新日志"
else
    echo -e "${YELLOW}⚠${NC}  日志文件不存在"
fi
echo ""

echo -e "${BLUE}步骤 4/4: 开始实时监控${NC}"
echo "-----------------------------------"
echo ""
echo -e "${GREEN}准备就绪！${NC}"
echo ""
echo -e "${YELLOW}现在请执行以下操作：${NC}"
echo "1. 在浏览器中开始导入订阅者或黑名单"
echo "2. 观察下方的实时日志输出"
echo "3. 按 Ctrl+C 停止监控"
echo ""
echo "-----------------------------------"
echo -e "${BLUE}实时性能日志监控：${NC}"
echo "-----------------------------------"
echo ""

# 实时监控
tail -f "$LOG_FILE" | while read line; do
    if echo "$line" | grep -q "\[性能\]"; then
        # 提取关键信息
        if echo "$line" | grep -q "开始处理导入进度请求\|开始处理黑名单导入进度请求"; then
            echo -e "${BLUE}📨 新请求到达${NC}"
            echo "$line" | grep -o "request_id\":\"[^\"]*" | cut -d'"' -f3
            
        elif echo "$line" | grep -q "缓存查询完成"; then
            DURATION=$(echo "$line" | grep -o "duration_ms\":[0-9.]*" | cut -d':' -f2)
            STATUS=$(echo "$line" | grep -o "progress_status\":\"[^\"]*" | cut -d'"' -f3)
            
            if (( $(echo "$DURATION > 50" | bc -l) )); then
                echo -e "${RED}  ⚠️  缓存查询慢: ${DURATION}ms${NC} (状态: $STATUS)"
            else
                echo -e "${GREEN}  ✓ 缓存查询: ${DURATION}ms${NC} (状态: $STATUS)"
            fi
            
        elif echo "$line" | grep -q "请求处理完成"; then
            TOTAL=$(echo "$line" | grep -o "total_duration_ms\":[0-9.]*" | cut -d':' -f2)
            STATUS=$(echo "$line" | grep -o "progress_status\":\"[^\"]*" | cut -d'"' -f3)
            
            if (( $(echo "$TOTAL > 1000" | bc -l) )); then
                echo -e "${RED}  🐌 总耗时: ${TOTAL}ms${NC} (状态: $STATUS)"
            elif (( $(echo "$TOTAL > 100" | bc -l) )); then
                echo -e "${YELLOW}  ⏱️  总耗时: ${TOTAL}ms${NC} (状态: $STATUS)"
            else
                echo -e "${GREEN}  ✅ 总耗时: ${TOTAL}ms${NC} (状态: $STATUS)"
            fi
            echo ""
            
        elif echo "$line" | grep -q "慢请求警告"; then
            echo -e "${YELLOW}⚠️  检测到慢请求！${NC}"
            
        elif echo "$line" | grep -q "极慢请求"; then
            echo -e "${RED}🚨 检测到极慢请求！${NC}"
        fi
    fi
done

