#!/bin/bash

# Laravel Scheduler Cron 配置脚本

set -e

echo "========================================"
echo "  配置 Laravel Scheduler Cron"
echo "========================================"
echo ""

PROJECT_DIR="/data/www/sendwalk"
BACKEND_DIR="${PROJECT_DIR}/backend"

echo "📋 检测到的定时任务:"
echo "----------------------------------------"
echo "1. campaigns:process-scheduled   - 每分钟执行"
echo "   (处理到时间的定时活动)"
echo ""
echo "2. automations:process           - 每分钟执行"
echo "   (处理自动化邮件)"
echo ""
echo "3. queue:clean                   - 每天 02:00"
echo "   (清理旧队列任务)"
echo ""
echo "4. logs:cleanup                  - 每天 03:00"
echo "   (清理30天前的日志)"
echo ""
echo "5. sendlogs:cleanup              - 每天 04:00"
echo "   (清理30天前的发送日志)"
echo ""

echo "🔧 配置 Cron 任务"
echo "----------------------------------------"

# 检查当前用户
CURRENT_USER=$(whoami)
echo "当前用户: $CURRENT_USER"
echo ""

# 生成 cron 条目
CRON_ENTRY="* * * * * cd ${BACKEND_DIR} && /usr/bin/php artisan schedule:run >> /dev/null 2>&1"

echo "将添加的 cron 任务:"
echo "$CRON_ENTRY"
echo ""

# 检查是否已存在
if crontab -l 2>/dev/null | grep -q "artisan schedule:run"; then
    echo "⚠️  Cron 任务已存在，跳过添加"
    echo ""
    echo "当前的 cron 配置:"
    crontab -l | grep artisan || true
else
    echo "添加 cron 任务..."
    
    # 备份现有 crontab
    crontab -l > /tmp/crontab.backup 2>/dev/null || true
    
    # 添加新任务
    (crontab -l 2>/dev/null || true; echo "$CRON_ENTRY") | crontab -
    
    echo "✓ Cron 任务已添加"
fi

echo ""
echo "验证 cron 配置:"
echo "----------------------------------------"
crontab -l | grep artisan
echo ""

echo "测试 scheduler:"
echo "----------------------------------------"
cd "$BACKEND_DIR"
php artisan schedule:list
echo ""

echo "========================================"
echo "  ✅ Cron 配置完成！"
echo "========================================"
echo ""
echo "📝 重要说明:"
echo ""
echo "1. Laravel Scheduler 工作原理:"
echo "   - Cron 每分钟调用一次 schedule:run"
echo "   - Laravel 检查哪些任务该执行"
echo "   - 自动运行到时间的任务"
echo ""
echo "2. 查看 cron 配置:"
echo "   crontab -l"
echo ""
echo "3. 编辑 cron 配置:"
echo "   crontab -e"
echo ""
echo "4. 删除 cron 配置:"
echo "   crontab -r"
echo ""
echo "5. 查看 cron 日志:"
echo "   grep CRON /var/log/syslog"
echo "   或"
echo "   tail -f /var/log/cron"
echo ""
echo "6. 手动测试 scheduler:"
echo "   cd ${BACKEND_DIR}"
echo "   php artisan schedule:run"
echo ""
echo "7. 查看定时任务列表:"
echo "   php artisan schedule:list"
echo ""
echo "⚠️  注意事项:"
echo ""
echo "- Cron 使用的用户必须有权限执行 PHP 和访问项目目录"
echo "- 如果使用 www-data 用户，确保文件权限正确"
echo "- 如果任务没有执行，检查 storage/logs/laravel.log"
echo ""

