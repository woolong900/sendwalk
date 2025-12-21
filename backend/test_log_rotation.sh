#!/bin/bash

# 日志按天滚动测试脚本

echo "======================================"
echo "日志按天滚动功能测试"
echo "======================================"
echo ""

cd /Users/panlei/sendwalk/backend

# 1. 检查配置
echo "1. 检查日志配置..."
LOG_CHANNEL=$(php artisan tinker --execute="echo config('logging.default');" 2>/dev/null | tail -1)
LOG_DAYS=$(php artisan tinker --execute="echo config('logging.channels.daily.days');" 2>/dev/null | tail -1)
echo "   当前日志通道: ${LOG_CHANNEL}"
echo "   日志保留天数: ${LOG_DAYS} 天"
echo ""

# 2. 检查日志文件
echo "2. 检查日志文件..."
TODAY=$(date +%Y-%m-%d)
TODAY_LOG="laravel-${TODAY}.log"

if [ -f "storage/logs/${TODAY_LOG}" ]; then
    FILE_SIZE=$(du -h "storage/logs/${TODAY_LOG}" | cut -f1)
    echo "   ✅ 今日日志文件已创建: ${TODAY_LOG} (${FILE_SIZE})"
else
    echo "   ⚠️  今日日志文件不存在，将在第一次写入时创建"
fi
echo ""

# 3. 测试写入日志
echo "3. 测试写入日志..."
php artisan tinker --execute="
    Log::info('[TEST] Main log channel test');
    Log::channel('worker')->info('[TEST] Worker log channel test');
    Log::channel('email')->info('[TEST] Email log channel test');
    Log::channel('scheduler')->info('[TEST] Scheduler log channel test');
" 2>/dev/null

sleep 1

echo "   ✅ 日志已写入"
echo ""

# 4. 验证日志文件
echo "4. 验证日志文件..."

check_log_file() {
    local log_name=$1
    local log_file="storage/logs/${log_name}-${TODAY}.log"
    
    if [ -f "${log_file}" ]; then
        local last_line=$(tail -1 "${log_file}")
        echo "   ✅ ${log_name}: $(du -h "${log_file}" | cut -f1)"
        echo "      最新日志: ${last_line:0:80}..."
    else
        echo "   ⚠️  ${log_name}: 文件不存在"
    fi
}

check_log_file "laravel"
check_log_file "worker"
check_log_file "email"
check_log_file "scheduler"
echo ""

# 5. 测试清理命令
echo "5. 测试日志清理命令..."
php artisan logs:cleanup --dry-run | grep -E "(Starting|Files|Space|completed)"
echo ""

# 6. 检查调度任务
echo "6. 检查调度任务..."
if grep -q "logs:cleanup" routes/console.php; then
    echo "   ✅ 日志清理任务已配置"
    echo "   执行时间: 每天凌晨 3:00"
else
    echo "   ❌ 日志清理任务未配置"
fi
echo ""

# 7. 显示所有日志文件
echo "7. 当前日志文件列表..."
echo "   ----------------------------------------"
ls -lh storage/logs/*.log 2>/dev/null | awk '{print "   " $9 " - " $5}' || echo "   没有找到日志文件"
echo "   ----------------------------------------"
echo ""

# 8. 磁盘使用情况
echo "8. 日志目录磁盘使用..."
TOTAL_SIZE=$(du -sh storage/logs/ | cut -f1)
FILE_COUNT=$(find storage/logs/ -name "*.log" -type f | wc -l | tr -d ' ')
echo "   总大小: ${TOTAL_SIZE}"
echo "   文件数: ${FILE_COUNT}"
echo ""

# 9. 建议
echo "======================================"
echo "📋 检查完成"
echo "======================================"
echo ""
echo "💡 提示:"
echo "   - 日志会在每天 00:00 自动滚动到新文件"
echo "   - 超过 ${LOG_DAYS} 天的日志会在每天 03:00 自动清理"
echo "   - 可以使用 'php artisan logs:cleanup --days=N' 手动清理"
echo ""
echo "📖 查看日志:"
echo "   tail -f storage/logs/laravel-${TODAY}.log"
echo "   tail -f storage/logs/worker-${TODAY}.log"
echo "   tail -f storage/logs/email-${TODAY}.log"
echo ""

