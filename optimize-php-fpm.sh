#!/bin/bash

echo "=========================================="
echo "  PHP-FPM 进程数优化脚本"
echo "=========================================="
echo ""

# 颜色定义
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# 检查是否以root或sudo运行
if [ "$EUID" -ne 0 ]; then 
    echo -e "${RED}❌ 请使用 sudo 运行此脚本${NC}"
    echo "用法: sudo ./optimize-php-fpm.sh"
    exit 1
fi

echo -e "${BLUE}步骤 1/6: 检查当前状态${NC}"
echo "-----------------------------------"

# 查找PHP版本
PHP_VERSION=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;" 2>/dev/null)
if [ -z "$PHP_VERSION" ]; then
    echo -e "${RED}❌ 未找到 PHP${NC}"
    exit 1
fi
echo "PHP版本: $PHP_VERSION"

# 查找配置文件
CONF_FILE="/etc/php/$PHP_VERSION/fpm/pool.d/www.conf"
if [ ! -f "$CONF_FILE" ]; then
    echo -e "${RED}❌ 配置文件不存在: $CONF_FILE${NC}"
    echo "尝试查找配置文件..."
    CONF_FILE=$(find /etc/php -name "www.conf" 2>/dev/null | head -1)
    if [ -z "$CONF_FILE" ]; then
        echo -e "${RED}❌ 无法找到 PHP-FPM 配置文件${NC}"
        exit 1
    fi
fi
echo -e "${GREEN}✓${NC} 配置文件: $CONF_FILE"

# 当前进程数
CURRENT_PROCESSES=$(ps aux | grep php-fpm | grep -v grep | wc -l)
echo "当前进程数: $CURRENT_PROCESSES"
echo ""

echo -e "${BLUE}步骤 2/6: 显示当前配置${NC}"
echo "-----------------------------------"
echo "当前 pm 配置:"
grep "^pm = " "$CONF_FILE" || echo "  未找到"
grep "^pm.max_children = " "$CONF_FILE" || echo "  未找到 pm.max_children"
grep "^pm.start_servers = " "$CONF_FILE" || echo "  未找到 pm.start_servers"
grep "^pm.min_spare_servers = " "$CONF_FILE" || echo "  未找到 pm.min_spare_servers"
grep "^pm.max_spare_servers = " "$CONF_FILE" || echo "  未找到 pm.max_spare_servers"
echo ""

echo -e "${BLUE}步骤 3/6: 内存检查${NC}"
echo "-----------------------------------"
TOTAL_MEM=$(free -m | awk 'NR==2{print $2}')
AVAILABLE_MEM=$(free -m | awk 'NR==2{print $7}')
echo "总内存: ${TOTAL_MEM}MB"
echo "可用内存: ${AVAILABLE_MEM}MB"

# 根据内存推荐配置
if [ $TOTAL_MEM -lt 2048 ]; then
    RECOMMENDED_MAX_CHILDREN=10
    echo -e "${YELLOW}⚠️  内存较小(<2GB)，推荐 max_children=10${NC}"
elif [ $TOTAL_MEM -lt 4096 ]; then
    RECOMMENDED_MAX_CHILDREN=20
    echo -e "${GREEN}✓${NC} 内存适中(2-4GB)，推荐 max_children=20"
elif [ $TOTAL_MEM -lt 8192 ]; then
    RECOMMENDED_MAX_CHILDREN=30
    echo -e "${GREEN}✓${NC} 内存充足(4-8GB)，推荐 max_children=30"
else
    RECOMMENDED_MAX_CHILDREN=50
    echo -e "${GREEN}✓${NC} 内存充裕(>8GB)，推荐 max_children=50"
fi
echo ""

echo -e "${BLUE}步骤 4/6: 选择优化方案${NC}"
echo "-----------------------------------"
echo "1. 保守方案 (max_children=10, 适合1-2GB内存)"
echo "2. 推荐方案 (max_children=20, 适合2-4GB内存) ⭐"
echo "3. 积极方案 (max_children=30, 适合4-8GB内存)"
echo "4. 高性能方案 (max_children=50, 适合8GB+内存)"
echo "5. 自定义"
echo "6. 退出"
echo ""
read -p "请选择方案 (1-6, 推荐2): " choice

case $choice in
    1)
        MAX_CHILDREN=10
        START_SERVERS=3
        MIN_SPARE=2
        MAX_SPARE=5
        echo -e "${GREEN}已选择: 保守方案${NC}"
        ;;
    2)
        MAX_CHILDREN=20
        START_SERVERS=5
        MIN_SPARE=3
        MAX_SPARE=8
        echo -e "${GREEN}已选择: 推荐方案${NC}"
        ;;
    3)
        MAX_CHILDREN=30
        START_SERVERS=8
        MIN_SPARE=5
        MAX_SPARE=12
        echo -e "${GREEN}已选择: 积极方案${NC}"
        ;;
    4)
        MAX_CHILDREN=50
        START_SERVERS=10
        MIN_SPARE=8
        MAX_SPARE=20
        echo -e "${GREEN}已选择: 高性能方案${NC}"
        ;;
    5)
        echo "自定义配置:"
        read -p "pm.max_children (推荐$RECOMMENDED_MAX_CHILDREN): " MAX_CHILDREN
        MAX_CHILDREN=${MAX_CHILDREN:-$RECOMMENDED_MAX_CHILDREN}
        
        START_SERVERS=$((MAX_CHILDREN / 4))
        read -p "pm.start_servers (推荐$START_SERVERS): " START_SERVERS_INPUT
        START_SERVERS=${START_SERVERS_INPUT:-$START_SERVERS}
        
        MIN_SPARE=$((MAX_CHILDREN / 8))
        read -p "pm.min_spare_servers (推荐$MIN_SPARE): " MIN_SPARE_INPUT
        MIN_SPARE=${MIN_SPARE_INPUT:-$MIN_SPARE}
        
        MAX_SPARE=$((MAX_CHILDREN / 3))
        read -p "pm.max_spare_servers (推荐$MAX_SPARE): " MAX_SPARE_INPUT
        MAX_SPARE=${MAX_SPARE_INPUT:-$MAX_SPARE}
        
        echo -e "${GREEN}已设置自定义配置${NC}"
        ;;
    6)
        echo "退出"
        exit 0
        ;;
    *)
        echo -e "${RED}无效选择，使用推荐方案${NC}"
        MAX_CHILDREN=20
        START_SERVERS=5
        MIN_SPARE=3
        MAX_SPARE=8
        ;;
esac
echo ""

echo -e "${YELLOW}将应用以下配置：${NC}"
echo "  pm = dynamic"
echo "  pm.max_children = $MAX_CHILDREN"
echo "  pm.start_servers = $START_SERVERS"
echo "  pm.min_spare_servers = $MIN_SPARE"
echo "  pm.max_spare_servers = $MAX_SPARE"
echo ""

read -p "确认应用配置? (y/n): " confirm
if [ "$confirm" != "y" ] && [ "$confirm" != "Y" ]; then
    echo "已取消"
    exit 0
fi
echo ""

echo -e "${BLUE}步骤 5/6: 备份并修改配置${NC}"
echo "-----------------------------------"

# 备份
BACKUP_FILE="${CONF_FILE}.backup-$(date +%Y%m%d-%H%M%S)"
cp "$CONF_FILE" "$BACKUP_FILE"
echo -e "${GREEN}✓${NC} 已备份到: $BACKUP_FILE"

# 修改配置
# 如果配置行存在就修改，不存在就添加
if grep -q "^pm = " "$CONF_FILE"; then
    sed -i "s/^pm = .*/pm = dynamic/" "$CONF_FILE"
else
    sed -i "/^\[www\]/a pm = dynamic" "$CONF_FILE"
fi

if grep -q "^pm.max_children = " "$CONF_FILE"; then
    sed -i "s/^pm.max_children = .*/pm.max_children = $MAX_CHILDREN/" "$CONF_FILE"
else
    sed -i "/^pm = /a pm.max_children = $MAX_CHILDREN" "$CONF_FILE"
fi

if grep -q "^pm.start_servers = " "$CONF_FILE"; then
    sed -i "s/^pm.start_servers = .*/pm.start_servers = $START_SERVERS/" "$CONF_FILE"
else
    sed -i "/^pm.max_children = /a pm.start_servers = $START_SERVERS" "$CONF_FILE"
fi

if grep -q "^pm.min_spare_servers = " "$CONF_FILE"; then
    sed -i "s/^pm.min_spare_servers = .*/pm.min_spare_servers = $MIN_SPARE/" "$CONF_FILE"
else
    sed -i "/^pm.start_servers = /a pm.min_spare_servers = $MIN_SPARE" "$CONF_FILE"
fi

if grep -q "^pm.max_spare_servers = " "$CONF_FILE"; then
    sed -i "s/^pm.max_spare_servers = .*/pm.max_spare_servers = $MAX_SPARE/" "$CONF_FILE"
else
    sed -i "/^pm.min_spare_servers = /a pm.max_spare_servers = $MAX_SPARE" "$CONF_FILE"
fi

echo -e "${GREEN}✓${NC} 配置已更新"
echo ""

# 测试配置
echo "测试配置文件..."
if php-fpm${PHP_VERSION} -t 2>/dev/null; then
    echo -e "${GREEN}✓${NC} 配置文件测试通过"
else
    echo -e "${RED}❌ 配置文件测试失败！${NC}"
    echo "恢复备份..."
    cp "$BACKUP_FILE" "$CONF_FILE"
    echo "已恢复原配置"
    exit 1
fi
echo ""

echo -e "${BLUE}步骤 6/6: 重启 PHP-FPM${NC}"
echo "-----------------------------------"
systemctl restart php${PHP_VERSION}-fpm

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓${NC} PHP-FPM 已重启"
else
    echo -e "${RED}❌ PHP-FPM 重启失败${NC}"
    exit 1
fi

# 等待进程启动
sleep 2

# 验证
NEW_PROCESSES=$(ps aux | grep php-fpm | grep -v grep | wc -l)
echo ""
echo -e "${GREEN}=========================================="
echo "  优化完成！"
echo "==========================================${NC}"
echo ""
echo "📊 对比："
echo "  优化前进程数: $CURRENT_PROCESSES"
echo "  优化后进程数: $NEW_PROCESSES"
echo "  最大进程数: $MAX_CHILDREN"
echo ""
echo "📝 新配置："
echo "  pm = dynamic"
echo "  pm.max_children = $MAX_CHILDREN"
echo "  pm.start_servers = $START_SERVERS"
echo "  pm.min_spare_servers = $MIN_SPARE"
echo "  pm.max_spare_servers = $MAX_SPARE"
echo ""
echo "💾 备份文件: $BACKUP_FILE"
echo ""
echo -e "${YELLOW}💡 建议：${NC}"
echo "  1. 监控进程数变化: watch -n 1 'ps aux | grep php-fpm | wc -l'"
echo "  2. 查看PHP-FPM日志: sudo tail -f /var/log/php${PHP_VERSION}-fpm.log"
echo "  3. 测试性能: cd /data/www/sendwalk && ./快速诊断黑名单性能.sh"
echo ""
echo -e "${GREEN}✅ 如需恢复，运行: sudo cp $BACKUP_FILE $CONF_FILE && sudo systemctl restart php${PHP_VERSION}-fpm${NC}"
echo ""

