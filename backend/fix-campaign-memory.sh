#!/bin/bash

# 紧急修复活动内存溢出问题
# 使用方法：在正式环境运行 bash fix-campaign-memory.sh

set -e

echo "=== 紧急修复活动内存溢出问题 ==="
echo ""

# 检查是否在正确的目录
if [ ! -f "artisan" ]; then
    echo "❌ 错误: 请在 backend 目录下运行此脚本"
    echo "   cd /data/www/sendwalk/backend && bash fix-campaign-memory.sh"
    exit 1
fi

CONTROLLER_FILE="app/Http/Controllers/Api/CampaignController.php"
BACKUP_FILE="app/Http/Controllers/Api/CampaignController.php.backup-$(date +%Y%m%d-%H%M%S)"

echo "1. 备份原文件..."
cp "$CONTROLLER_FILE" "$BACKUP_FILE"
echo "   ✅ 备份到: $BACKUP_FILE"
echo ""

echo "2. 检查当前代码..."
if grep -q "\$campaign->load(\['list', 'lists', 'sends', 'smtpServer'\]);" "$CONTROLLER_FILE"; then
    echo "   ⚠️  发现问题：正在加载 sends 关系"
    echo ""
    
    echo "3. 修复代码..."
    
    # 使用 sed 替换（兼容 Linux 和 macOS）
    if [[ "$OSTYPE" == "darwin"* ]]; then
        # macOS
        sed -i '' "s/\$campaign->load(\['list', 'lists', 'sends', 'smtpServer'\]);/\$campaign->load(['list', 'lists', 'smtpServer']);/" "$CONTROLLER_FILE"
    else
        # Linux
        sed -i "s/\$campaign->load(\['list', 'lists', 'sends', 'smtpServer'\]);/\$campaign->load(['list', 'lists', 'smtpServer']);/" "$CONTROLLER_FILE"
    fi
    
    echo "   ✅ 已移除 sends 关系的加载"
    echo ""
    
elif grep -q "\$campaign->load(\['list', 'lists', 'smtpServer'\]);" "$CONTROLLER_FILE"; then
    echo "   ✅ 代码已经是正确的（没有加载 sends）"
    echo ""
    echo "如果问题仍然存在，可能是其他原因，请检查："
    echo "  1. PHP-FPM 是否已重启"
    echo "  2. 缓存是否已清理"
    echo "  3. 活动是否有其他关系导致内存溢出"
    exit 0
else
    echo "   ⚠️  警告: 未找到预期的代码模式"
    echo "   请手动检查文件: $CONTROLLER_FILE"
    echo "   查找 'public function show' 方法"
    exit 1
fi

echo "4. 验证修改..."
if grep -q "\$campaign->load(\['list', 'lists', 'smtpServer'\]);" "$CONTROLLER_FILE"; then
    echo "   ✅ 修改成功"
else
    echo "   ❌ 修改可能失败，请手动检查"
    exit 1
fi
echo ""

echo "5. 清理缓存..."
php artisan config:clear
php artisan route:clear
php artisan view:clear
echo "   ✅ 缓存已清理"
echo ""

echo "6. 重启 PHP-FPM..."
echo "   请运行以下命令之一（根据您的系统）："
echo "   sudo systemctl restart php8.3-fpm"
echo "   sudo service php8.3-fpm restart"
echo "   sudo systemctl restart php-fpm"
echo ""

echo "=== 修复完成 ==="
echo ""
echo "📝 下一步："
echo "   1. 重启 PHP-FPM"
echo "   2. 测试访问: https://edm.sendwalk.com/campaigns/20/edit"
echo "   3. 如果问题解决，请提交代码更新"
echo ""
echo "🔙 如需恢复，运行："
echo "   cp $BACKUP_FILE $CONTROLLER_FILE"

