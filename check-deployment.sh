#!/bin/bash

# SendWalk 部署状态检查脚本

echo "======================================"
echo "  SendWalk 部署状态检查"
echo "======================================"
echo ""

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

PROJECT_DIR="/data/www/sendwalk"
BACKEND_DIR="${PROJECT_DIR}/backend"
FRONTEND_DIR="${PROJECT_DIR}/frontend"

# 检查函数
check_service() {
    local service=$1
    local name=$2
    
    if systemctl is-active --quiet $service; then
        echo -e "${GREEN}✓${NC} ${name}: 运行中"
        return 0
    else
        echo -e "${RED}✗${NC} ${name}: 未运行"
        return 1
    fi
}

check_file() {
    local file=$1
    local name=$2
    
    if [ -f "$file" ]; then
        echo -e "${GREEN}✓${NC} ${name}: 存在"
        return 0
    else
        echo -e "${RED}✗${NC} ${name}: 不存在"
        return 1
    fi
}

check_dir() {
    local dir=$1
    local name=$2
    
    if [ -d "$dir" ]; then
        echo -e "${GREEN}✓${NC} ${name}: 存在"
        return 0
    else
        echo -e "${RED}✗${NC} ${name}: 不存在"
        return 1
    fi
}

# 1. 检查系统服务
echo "1. 系统服务检查:"
check_service "nginx" "Nginx"
check_service "php8.3-fpm" "PHP-FPM"
check_service "mysql" "MySQL"
check_service "redis-server" "Redis"
check_service "supervisor" "Supervisor"
echo ""

# 2. 检查项目目录
echo "2. 项目目录检查:"
check_dir "$PROJECT_DIR" "项目目录"
check_dir "$BACKEND_DIR" "后端目录"
check_dir "$FRONTEND_DIR" "前端目录"
check_dir "$BACKEND_DIR/vendor" "后端依赖"
check_dir "$FRONTEND_DIR/dist" "前端构建"
echo ""

# 3. 检查关键文件
echo "3. 关键文件检查:"
check_file "$BACKEND_DIR/.env" "后端配置"
check_file "$BACKEND_DIR/artisan" "Artisan"
check_file "$FRONTEND_DIR/dist/index.html" "前端入口"
check_file "/etc/nginx/sites-enabled/sendwalk-api" "Nginx API 配置"
check_file "/etc/nginx/sites-enabled/sendwalk-frontend" "Nginx Frontend 配置"
check_file "/etc/supervisor/conf.d/sendwalk-scheduler.conf" "Scheduler 配置"
check_file "/etc/supervisor/conf.d/sendwalk-worker-manager.conf" "Worker 配置"
echo ""

# 4. 检查文件权限
echo "4. 文件权限检查:"
if [ -w "$BACKEND_DIR/storage" ]; then
    echo -e "${GREEN}✓${NC} storage 目录: 可写"
else
    echo -e "${RED}✗${NC} storage 目录: 不可写"
fi

if [ -w "$BACKEND_DIR/bootstrap/cache" ]; then
    echo -e "${GREEN}✓${NC} bootstrap/cache: 可写"
else
    echo -e "${RED}✗${NC} bootstrap/cache: 不可写"
fi
echo ""

# 5. 检查 Supervisor 进程
echo "5. Supervisor 进程检查:"
if command -v supervisorctl &> /dev/null; then
    supervisorctl status | sed 's/^/   /'
else
    echo -e "${RED}✗${NC} supervisorctl 命令不可用"
fi
echo ""

# 6. 检查数据库连接
echo "6. 数据库连接检查:"
cd $BACKEND_DIR
if php artisan tinker --execute="DB::connection()->getPdo(); echo 'OK';" 2>/dev/null | grep -q "OK"; then
    echo -e "${GREEN}✓${NC} 数据库连接: 正常"
else
    echo -e "${RED}✗${NC} 数据库连接: 失败"
fi
echo ""

# 7. 检查 Redis 连接
echo "7. Redis 连接检查:"
if redis-cli ping 2>/dev/null | grep -q "PONG"; then
    echo -e "${GREEN}✓${NC} Redis 连接: 正常"
else
    echo -e "${RED}✗${NC} Redis 连接: 失败"
fi
echo ""

# 8. 检查端口监听
echo "8. 端口监听检查:"
if netstat -tuln | grep -q ":80 "; then
    echo -e "${GREEN}✓${NC} 端口 80: 监听中"
else
    echo -e "${RED}✗${NC} 端口 80: 未监听"
fi

if netstat -tuln | grep -q ":443 "; then
    echo -e "${GREEN}✓${NC} 端口 443: 监听中"
else
    echo -e "${YELLOW}⚠${NC}  端口 443: 未监听 (SSL 未配置)"
fi

if netstat -tuln | grep -q ":3306 "; then
    echo -e "${GREEN}✓${NC} 端口 3306: 监听中 (MySQL)"
else
    echo -e "${RED}✗${NC} 端口 3306: 未监听"
fi

if netstat -tuln | grep -q ":6379 "; then
    echo -e "${GREEN}✓${NC} 端口 6379: 监听中 (Redis)"
else
    echo -e "${RED}✗${NC} 端口 6379: 未监听"
fi
echo ""

# 9. 检查日志文件
echo "9. 日志文件检查:"
LOG_DIR="$BACKEND_DIR/storage/logs"
if [ -d "$LOG_DIR" ]; then
    LOG_COUNT=$(find $LOG_DIR -name "*.log" | wc -l)
    LOG_SIZE=$(du -sh $LOG_DIR 2>/dev/null | cut -f1)
    echo "   日志文件数: $LOG_COUNT"
    echo "   日志目录大小: $LOG_SIZE"
    
    # 检查最新的日志文件
    LATEST_LOG=$(ls -t $LOG_DIR/*.log 2>/dev/null | head -1)
    if [ -n "$LATEST_LOG" ]; then
        echo "   最新日志: $(basename $LATEST_LOG)"
        # 检查最近是否有错误
        ERROR_COUNT=$(grep -i "error\|exception" $LATEST_LOG 2>/dev/null | wc -l)
        if [ $ERROR_COUNT -gt 0 ]; then
            echo -e "${YELLOW}⚠${NC}   最近有 $ERROR_COUNT 个错误/异常"
        else
            echo -e "${GREEN}✓${NC}   最近无错误"
        fi
    fi
else
    echo -e "${RED}✗${NC} 日志目录不存在"
fi
echo ""

# 10. 检查磁盘空间
echo "10. 磁盘空间检查:"
DISK_USAGE=$(df -h / | tail -1 | awk '{print $5}' | sed 's/%//')
echo "   根分区使用率: ${DISK_USAGE}%"
if [ $DISK_USAGE -gt 80 ]; then
    echo -e "${RED}⚠${NC}   磁盘空间不足（>${DISK_USAGE}%）"
elif [ $DISK_USAGE -gt 70 ]; then
    echo -e "${YELLOW}⚠${NC}   磁盘空间有限（${DISK_USAGE}%）"
else
    echo -e "${GREEN}✓${NC}   磁盘空间充足"
fi
echo ""

# 11. 检查 SSL 证书
echo "11. SSL 证书检查:"
if [ -f "/etc/letsencrypt/live/api.sendwalk.com/fullchain.pem" ]; then
    CERT_EXPIRY=$(openssl x509 -enddate -noout -in /etc/letsencrypt/live/api.sendwalk.com/fullchain.pem | cut -d= -f 2)
    echo -e "${GREEN}✓${NC} API SSL 证书: 已配置"
    echo "   到期时间: $CERT_EXPIRY"
else
    echo -e "${YELLOW}⚠${NC}  API SSL 证书: 未配置"
fi

if [ -f "/etc/letsencrypt/live/www.sendwalk.com/fullchain.pem" ]; then
    CERT_EXPIRY=$(openssl x509 -enddate -noout -in /etc/letsencrypt/live/www.sendwalk.com/fullchain.pem | cut -d= -f 2)
    echo -e "${GREEN}✓${NC} Frontend SSL 证书: 已配置"
    echo "   到期时间: $CERT_EXPIRY"
else
    echo -e "${YELLOW}⚠${NC}  Frontend SSL 证书: 未配置"
fi
echo ""

# 12. 测试 API 健康检查
echo "12. API 健康检查:"
# 尝试 HTTPS
if command -v curl &> /dev/null; then
    API_RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" https://api.sendwalk.com/api/health 2>/dev/null)
    if [ "$API_RESPONSE" == "200" ]; then
        echo -e "${GREEN}✓${NC} API 响应 (HTTPS): 正常 (200)"
    else
        # 尝试 HTTP
        API_RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" http://api.sendwalk.com/api/health 2>/dev/null)
        if [ "$API_RESPONSE" == "200" ]; then
            echo -e "${YELLOW}⚠${NC}  API 响应 (HTTP): 正常 (200) - 建议配置 SSL"
        else
            echo -e "${RED}✗${NC} API 响应: 失败 (${API_RESPONSE})"
        fi
    fi
else
    echo -e "${YELLOW}⚠${NC}  curl 未安装，无法测试 API"
fi
echo ""

echo "======================================"
echo "  检查完成"
echo "======================================"
echo ""
echo "💡 提示:"
echo "   - 如有错误，请查看相关日志文件"
echo "   - 确保所有服务正常运行"
echo "   - 定期检查磁盘空间和日志大小"
echo ""
echo "📖 查看日志:"
echo "   Laravel: tail -f $BACKEND_DIR/storage/logs/laravel-\$(date +%Y-%m-%d).log"
echo "   Scheduler: tail -f $BACKEND_DIR/storage/logs/scheduler.log"
echo "   Worker: tail -f $BACKEND_DIR/storage/logs/manager.log"
echo "   Nginx: tail -f /var/log/nginx/sendwalk-api-error.log"
echo ""

