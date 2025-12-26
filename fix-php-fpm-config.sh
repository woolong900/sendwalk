#!/bin/bash

echo "=========================================="
echo "  修复 PHP-FPM 配置错误"
echo "=========================================="
echo ""

# 颜色定义
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m'

# 检查是否以root或sudo运行
if [ "$EUID" -ne 0 ]; then 
    echo -e "${RED}❌ 请使用 sudo 运行此脚本${NC}"
    echo "用法: sudo ./fix-php-fpm-config.sh"
    exit 1
fi

# 查找PHP版本
PHP_VERSION=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;" 2>/dev/null)
if [ -z "$PHP_VERSION" ]; then
    echo -e "${RED}❌ 未找到 PHP${NC}"
    exit 1
fi
echo "PHP版本: $PHP_VERSION"

# 配置文件路径
CONF_FILE="/etc/php/$PHP_VERSION/fpm/pool.d/www.conf"
if [ ! -f "$CONF_FILE" ]; then
    echo -e "${RED}❌ 配置文件不存在: $CONF_FILE${NC}"
    exit 1
fi
echo "配置文件: $CONF_FILE"
echo ""

echo -e "${BLUE}步骤 1/4: 显示当前错误配置${NC}"
echo "-----------------------------------"
grep "^pm = " "$CONF_FILE" || echo "未找到 pm"
grep "^pm.max_children = " "$CONF_FILE" || echo "未找到 pm.max_children"
grep "^pm.start_servers = " "$CONF_FILE" || echo "未找到 pm.start_servers"
grep "^pm.min_spare_servers = " "$CONF_FILE" || echo "未找到 pm.min_spare_servers"
grep "^pm.max_spare_servers = " "$CONF_FILE" || echo "未找到 pm.max_spare_servers"
echo ""

# 提取当前值
CURRENT_START=$(grep "^pm.start_servers = " "$CONF_FILE" | awk '{print $3}')
CURRENT_MIN=$(grep "^pm.min_spare_servers = " "$CONF_FILE" | awk '{print $3}')
CURRENT_MAX_SPARE=$(grep "^pm.max_spare_servers = " "$CONF_FILE" | awk '{print $3}')

echo -e "${YELLOW}检测到的问题：${NC}"
if [ ! -z "$CURRENT_START" ] && [ ! -z "$CURRENT_MIN" ]; then
    if [ $CURRENT_START -lt $CURRENT_MIN ]; then
        echo -e "${RED}✗ pm.start_servers ($CURRENT_START) < pm.min_spare_servers ($CURRENT_MIN)${NC}"
    fi
fi

if [ ! -z "$CURRENT_START" ] && [ ! -z "$CURRENT_MAX_SPARE" ]; then
    if [ $CURRENT_START -gt $CURRENT_MAX_SPARE ]; then
        echo -e "${RED}✗ pm.start_servers ($CURRENT_START) > pm.max_spare_servers ($CURRENT_MAX_SPARE)${NC}"
    fi
fi
echo ""

echo -e "${BLUE}步骤 2/4: 备份配置${NC}"
echo "-----------------------------------"
BACKUP_FILE="${CONF_FILE}.backup-fix-$(date +%Y%m%d-%H%M%S)"
cp "$CONF_FILE" "$BACKUP_FILE"
echo -e "${GREEN}✓${NC} 已备份到: $BACKUP_FILE"
echo ""

echo -e "${BLUE}步骤 3/4: 应用正确的配置${NC}"
echo "-----------------------------------"

# 推荐的安全配置
MAX_CHILDREN=20
START_SERVERS=5
MIN_SPARE=3
MAX_SPARE=8

echo "将应用以下配置："
echo "  pm = dynamic"
echo "  pm.max_children = $MAX_CHILDREN"
echo "  pm.start_servers = $START_SERVERS"
echo "  pm.min_spare_servers = $MIN_SPARE"
echo "  pm.max_spare_servers = $MAX_SPARE"
echo ""
echo "关系: $MIN_SPARE <= $START_SERVERS <= $MAX_SPARE <= $MAX_CHILDREN ✓"
echo ""

# 修改配置
sed -i "s/^pm = .*/pm = dynamic/" "$CONF_FILE"
sed -i "s/^pm.max_children = .*/pm.max_children = $MAX_CHILDREN/" "$CONF_FILE"
sed -i "s/^pm.start_servers = .*/pm.start_servers = $START_SERVERS/" "$CONF_FILE"
sed -i "s/^pm.min_spare_servers = .*/pm.min_spare_servers = $MIN_SPARE/" "$CONF_FILE"
sed -i "s/^pm.max_spare_servers = .*/pm.max_spare_servers = $MAX_SPARE/" "$CONF_FILE"

echo -e "${GREEN}✓${NC} 配置已更新"
echo ""

# 测试配置
echo "测试配置..."
if php-fpm${PHP_VERSION} -t 2>&1 | grep -q "test is successful"; then
    echo -e "${GREEN}✓${NC} 配置文件测试通过"
else
    echo -e "${RED}❌ 配置文件测试失败${NC}"
    echo "恢复备份..."
    cp "$BACKUP_FILE" "$CONF_FILE"
    exit 1
fi
echo ""

echo -e "${BLUE}步骤 4/4: 重启 PHP-FPM${NC}"
echo "-----------------------------------"
systemctl restart php${PHP_VERSION}-fpm

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓${NC} PHP-FPM 已成功重启"
else
    echo -e "${RED}❌ PHP-FPM 重启失败${NC}"
    echo "查看详细错误："
    systemctl status php${PHP_VERSION}-fpm
    exit 1
fi

sleep 2

# 验证服务状态
if systemctl is-active --quiet php${PHP_VERSION}-fpm; then
    echo -e "${GREEN}✓${NC} PHP-FPM 服务运行正常"
else
    echo -e "${RED}❌ PHP-FPM 服务未运行${NC}"
    systemctl status php${PHP_VERSION}-fpm
    exit 1
fi

# 显示进程数
PROCESS_COUNT=$(ps aux | grep php-fpm | grep -v grep | wc -l)
echo -e "${GREEN}✓${NC} 当前进程数: $PROCESS_COUNT"
echo ""

echo -e "${GREEN}=========================================="
echo "  修复完成！"
echo "==========================================${NC}"
echo ""
echo "✅ PHP-FPM 已恢复正常运行"
echo ""
echo "📝 应用的配置："
echo "  pm.max_children = $MAX_CHILDREN"
echo "  pm.start_servers = $START_SERVERS"
echo "  pm.min_spare_servers = $MIN_SPARE"
echo "  pm.max_spare_servers = $MAX_SPARE"
echo ""
echo "💾 备份文件: $BACKUP_FILE"
echo ""
echo -e "${YELLOW}💡 验证服务：${NC}"
echo "  sudo systemctl status php${PHP_VERSION}-fpm"
echo "  ps aux | grep php-fpm"
echo ""

