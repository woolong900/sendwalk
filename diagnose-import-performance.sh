#!/bin/bash

echo "=========================================="
echo "  导入性能诊断工具"
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

echo -e "${BLUE}诊断选项：${NC}"
echo "1. 实时监控性能日志"
echo "2. 查看最近的慢请求（>1秒）"
echo "3. 查看最近的极慢请求（>5秒）"
echo "4. 查看导入进度请求统计"
echo "5. 查看PHP-FPM状态"
echo "6. 查看Nginx连接数"
echo "7. 全面性能报告"
echo ""
read -p "请选择 (1-7): " choice

case $choice in
    1)
        echo -e "${BLUE}开始实时监控性能日志 (Ctrl+C 停止)${NC}"
        echo "-----------------------------------"
        tail -f "$LOG_FILE" | grep --line-buffered "\[性能\]"
        ;;
    2)
        echo -e "${BLUE}最近的慢请求（>1秒）:${NC}"
        echo "-----------------------------------"
        grep "\[性能\] 慢请求警告" "$LOG_FILE" | tail -20
        ;;
    3)
        echo -e "${BLUE}最近的极慢请求（>5秒）:${NC}"
        echo "-----------------------------------"
        grep "\[性能\] 极慢请求" "$LOG_FILE" | tail -20
        ;;
    4)
        echo -e "${BLUE}导入进度请求统计:${NC}"
        echo "-----------------------------------"
        echo "总请求数:"
        grep "开始处理导入进度请求\|开始处理黑名单导入进度请求" "$LOG_FILE" | wc -l
        echo ""
        echo "最近10个请求的耗时:"
        grep "请求处理完成" "$LOG_FILE" | tail -10 | grep -o "total_duration_ms[^,]*"
        echo ""
        echo "平均耗时:"
        grep "请求处理完成" "$LOG_FILE" | grep -o "total_duration_ms\":[0-9.]*" | cut -d':' -f2 | awk '{sum+=$1; count++} END {if(count>0) print sum/count " ms"; else print "无数据"}'
        ;;
    5)
        echo -e "${BLUE}PHP-FPM 状态:${NC}"
        echo "-----------------------------------"
        echo "进程数:"
        ps aux | grep php-fpm | grep -v grep | wc -l
        echo ""
        echo "进程详情:"
        ps aux | grep php-fpm | grep -v grep | head -10
        echo ""
        echo "内存使用:"
        ps aux | grep php-fpm | grep -v grep | awk '{sum+=$6} END {print sum/1024 " MB"}'
        ;;
    6)
        echo -e "${BLUE}Nginx 连接统计:${NC}"
        echo "-----------------------------------"
        echo "当前连接数:"
        netstat -an | grep :80 | wc -l
        echo ""
        echo "各状态连接数:"
        netstat -an | grep :80 | awk '{print $6}' | sort | uniq -c | sort -rn
        ;;
    7)
        echo -e "${BLUE}=== 全面性能报告 ===${NC}"
        echo ""
        
        echo -e "${YELLOW}1. 系统资源:${NC}"
        echo "-----------------------------------"
        echo "CPU负载:"
        uptime
        echo ""
        echo "内存使用:"
        free -h
        echo ""
        
        echo -e "${YELLOW}2. PHP-FPM状态:${NC}"
        echo "-----------------------------------"
        echo "进程数: $(ps aux | grep php-fpm | grep -v grep | wc -l)"
        echo "内存占用: $(ps aux | grep php-fpm | grep -v grep | awk '{sum+=$6} END {print sum/1024 " MB"}')"
        echo ""
        
        echo -e "${YELLOW}3. Nginx状态:${NC}"
        echo "-----------------------------------"
        echo "总连接数: $(netstat -an | grep :80 | wc -l)"
        echo ""
        
        echo -e "${YELLOW}4. 导入请求统计:${NC}"
        echo "-----------------------------------"
        TOTAL_REQUESTS=$(grep "开始处理导入进度请求\|开始处理黑名单导入进度请求" "$LOG_FILE" | wc -l)
        echo "总请求数: $TOTAL_REQUESTS"
        
        if [ $TOTAL_REQUESTS -gt 0 ]; then
            AVG_DURATION=$(grep "请求处理完成" "$LOG_FILE" | grep -o "total_duration_ms\":[0-9.]*" | cut -d':' -f2 | awk '{sum+=$1; count++} END {if(count>0) print sum/count; else print 0}')
            echo "平均耗时: ${AVG_DURATION} ms"
            
            SLOW_REQUESTS=$(grep "\[性能\] 慢请求警告" "$LOG_FILE" | wc -l)
            echo "慢请求数(>1s): $SLOW_REQUESTS"
            
            VERY_SLOW_REQUESTS=$(grep "\[性能\] 极慢请求" "$LOG_FILE" | wc -l)
            echo "极慢请求数(>5s): $VERY_SLOW_REQUESTS"
        fi
        echo ""
        
        echo -e "${YELLOW}5. 最近10个请求耗时:${NC}"
        echo "-----------------------------------"
        grep "请求处理完成" "$LOG_FILE" | tail -10 | while read line; do
            REQUEST_ID=$(echo "$line" | grep -o "request_id\":\"[^\"]*" | cut -d'"' -f3)
            DURATION=$(echo "$line" | grep -o "total_duration_ms\":[0-9.]*" | cut -d':' -f2)
            STATUS=$(echo "$line" | grep -o "progress_status\":\"[^\"]*" | cut -d'"' -f3)
            echo "Request: $REQUEST_ID | 耗时: ${DURATION}ms | 状态: $STATUS"
        done
        echo ""
        
        echo -e "${YELLOW}6. 缓存性能:${NC}"
        echo "-----------------------------------"
        CACHE_AVG=$(grep "缓存查询完成" "$LOG_FILE" | tail -100 | grep -o "duration_ms\":[0-9.]*" | cut -d':' -f2 | awk '{sum+=$1; count++} END {if(count>0) print sum/count; else print 0}')
        echo "平均缓存查询耗时: ${CACHE_AVG} ms"
        echo ""
        
        echo -e "${GREEN}=== 报告完成 ===${NC}"
        ;;
    *)
        echo -e "${RED}无效选择${NC}"
        exit 1
        ;;
esac

echo ""

