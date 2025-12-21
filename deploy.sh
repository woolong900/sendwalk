#!/bin/bash

# SendWalk 一键部署脚本
# 用法: ./deploy.sh [production|staging]

set -e

ENVIRONMENT=${1:-production}

echo "======================================"
echo "  SendWalk 自动部署脚本"
echo "  环境: ${ENVIRONMENT}"
echo "======================================"
echo ""

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# 项目目录
PROJECT_DIR="/data/www/sendwalk"
BACKEND_DIR="${PROJECT_DIR}/backend"
FRONTEND_DIR="${PROJECT_DIR}/frontend"

# 检查是否为 root 用户
if [ "$EUID" -ne 0 ]; then 
    echo -e "${RED}错误: 请使用 root 用户运行此脚本${NC}"
    echo "使用: sudo ./deploy.sh"
    exit 1
fi

# 步骤1：拉取最新代码
echo -e "${YELLOW}步骤 1/8: 拉取最新代码${NC}"
cd ${PROJECT_DIR}
git fetch origin
git pull origin main
echo -e "${GREEN}✓${NC} 代码更新完成"
echo ""

# 步骤2：后端依赖更新
echo -e "${YELLOW}步骤 2/8: 更新后端依赖${NC}"
cd ${BACKEND_DIR}

if [ "$ENVIRONMENT" == "production" ]; then
    su - www-data -s /bin/bash -c "cd ${BACKEND_DIR} && composer install --optimize-autoloader --no-dev"
else
    su - www-data -s /bin/bash -c "cd ${BACKEND_DIR} && composer install"
fi

echo -e "${GREEN}✓${NC} 后端依赖更新完成"
echo ""

# 步骤3：数据库迁移
echo -e "${YELLOW}步骤 3/8: 运行数据库迁移${NC}"
php artisan migrate --force
echo -e "${GREEN}✓${NC} 数据库迁移完成"
echo ""

# 步骤4：创建缓存目录和重建缓存
echo -e "${YELLOW}步骤 4/8: 创建缓存目录和重建缓存${NC}"

# 确保所有必要的缓存目录存在
mkdir -p ${BACKEND_DIR}/storage/


mkdir -p ${BACKEND_DIR}/storage/framework/sessions
mkdir -p ${BACKEND_DIR}/storage/

mkdir -p ${BACKEND_DIR}/storage/logs
mkdir -p ${BACKEND_DIR}/bootstrap/cache

php artisan config:clear

php artisan route:clear
php artisan view:clear
php artisan cache:clear

if [ "$ENVIRONMENT" == "production" ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

echo -e "${GREEN}✓${NC} 缓存重建完成"
echo ""

# 步骤5：前端构建
echo -e "${YELLOW}步骤 5/8: 构建前端${NC}"
cd ${FRONTEND_DIR}
npm install
npm run build
echo -e "${GREEN}✓${NC} 前端构建完成"
echo ""

# 步骤6：设置权限
echo -e "${YELLOW}步骤 6/8: 设置文件权限${NC}"
chown -R www-data:www-data ${PROJECT_DIR}
chmod -R 755 ${PROJECT_DIR}
chmod -R 775 ${BACKEND_DIR}/storage ${BACKEND_DIR}/bootstrap/cache
echo -e "${GREEN}✓${NC} 权限设置完成"
echo ""

# 步骤7：重启服务
echo -e "${YELLOW}步骤 7/8: 重启服务${NC}"

# 重启 PHP-FPM
systemctl restart php8.3-fpm
echo "  ✓ PHP-FPM 已重启"

# 重启 Nginx
nginx -t && systemctl restart nginx
echo "  ✓ Nginx 已重启"

# 重启 Supervisor 管理的进程
supervisorctl restart all
echo "  ✓ Supervisor 进程已重启"

echo -e "${GREEN}✓${NC} 服务重启完成"
echo ""

# 步骤8：验证部署
echo -e "${YELLOW}步骤 8/8: 验证部署${NC}"

# 检查 Supervisor 状态
echo "  Supervisor 进程状态:"
supervisorctl status | sed 's/^/    /'

# 检查服务状态
echo ""
echo "  服务状态:"
systemctl is-active --quiet php8.3-fpm && echo "    ✓ PHP-FPM: 运行中" || echo "    ✗ PHP-FPM: 未运行"
systemctl is-active --quiet nginx && echo "    ✓ Nginx: 运行中" || echo "    ✗ Nginx: 未运行"
systemctl is-active --quiet mysql && echo "    ✓ MySQL: 运行中" || echo "    ✗ MySQL: 未运行"
systemctl is-active --quiet redis-server && echo "    ✓ Redis: 运行中" || echo "    ✗ Redis: 未运行"

echo ""
echo "======================================"
echo -e "  ${GREEN}✅ 部署完成！${NC}"
echo "======================================"
echo ""
echo "📊 部署信息:"
echo "  环境: ${ENVIRONMENT}"
echo "  时间: $(date '+%Y-%m-%d %H:%M:%S')"
echo "  Git 版本: $(cd ${PROJECT_DIR} && git rev-parse --short HEAD)"
echo ""
echo "📖 查看日志:"
echo "  Laravel: tail -f ${BACKEND_DIR}/storage/logs/laravel-\$(date +%Y-%m-%d).log"
echo "  Scheduler: tail -f ${BACKEND_DIR}/storage/logs/scheduler.log"
echo "  Worker: tail -f ${BACKEND_DIR}/storage/logs/manager.log"
echo "  Nginx: tail -f /var/log/nginx/sendwalk-api-error.log"
echo ""
echo "🔍 测试访问:"
echo "  前端: https://www.sendwalk.com"
echo "  API: https://api.sendwalk.com/api/health"
echo ""

