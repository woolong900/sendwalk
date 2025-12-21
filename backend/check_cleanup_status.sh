#!/bin/bash

echo "======================================"
echo "  系统清理状态检查"
echo "======================================"
echo ""

cd /Users/panlei/sendwalk/backend

# 1. 检查调度器
echo "1. 调度器状态:"
if pgrep -f "schedule:work" > /dev/null; then
    SCHEDULER_PID=$(pgrep -f "schedule:work")
    echo "   ✅ 运行中 (PID: $SCHEDULER_PID)"
else
    echo "   ❌ 未运行"
    echo "   启动命令: php artisan schedule:work &"
fi
echo ""

# 2. 检查队列任务数量
echo "2. 队列任务:"
php artisan tinker --execute="
echo '   总任务: ' . DB::table('jobs')->count() . PHP_EOL;
echo '   失败任务: ' . DB::table('failed_jobs')->count() . PHP_EOL;
" 2>/dev/null
echo ""

# 3. 检查日志文件
echo "3. 日志文件:"
LOG_COUNT=$(ls -1 storage/logs/*.log 2>/dev/null | wc -l | tr -d ' ')
LOG_SIZE=$(du -sh storage/logs/ 2>/dev/null | cut -f1)
echo "   文件数: ${LOG_COUNT}"
echo "   总大小: ${LOG_SIZE}"
OLDEST_LOG=$(ls -t storage/logs/*.log 2>/dev/null | tail -1)
if [ -n "$OLDEST_LOG" ]; then
    OLDEST_DATE=$(stat -f "%Sm" -t "%Y-%m-%d" "$OLDEST_LOG" 2>/dev/null || stat -c "%y" "$OLDEST_LOG" 2>/dev/null | cut -d' ' -f1)
    echo "   最早文件: $(basename $OLDEST_LOG) (${OLDEST_DATE})"
fi
echo ""

# 4. 检查 SendLog
echo "4. SendLog 记录:"
php artisan tinker --execute="
\$count = App\Models\SendLog::count();
echo '   总记录: ' . number_format(\$count) . PHP_EOL;
if (\$count > 0) {
    \$oldest = App\Models\SendLog::orderBy('created_at', 'asc')->first();
    \$latest = App\Models\SendLog::orderBy('created_at', 'desc')->first();
    echo '   最早: ' . \$oldest->created_at->format('Y-m-d') . PHP_EOL;
    echo '   最新: ' . \$latest->created_at->format('Y-m-d') . PHP_EOL;
    
    \$size = DB::select('SELECT 
        ROUND((data_length + index_length) / 1024 / 1024, 2) AS size_mb 
        FROM information_schema.TABLES 
        WHERE table_schema = DATABASE() 
        AND table_name = \"send_logs\"')[0]->size_mb ?? 0;
    echo '   表大小: ' . \$size . ' MB' . PHP_EOL;
}
" 2>/dev/null
echo ""

# 5. 检查定时任务配置
echo "5. 定时任务配置:"
if [ -f "routes/console.php" ]; then
    echo "   ✅ 配置文件存在"
    grep -E "queue:clean|logs:cleanup|sendlogs:cleanup" routes/console.php | sed 's/^/   /' | head -10
else
    echo "   ❌ 配置文件不存在"
fi
echo ""

# 6. 测试清理命令
echo "6. 清理命令测试:"
echo "   测试 logs:cleanup..."
if php artisan logs:cleanup --dry-run 2>&1 | grep -q "Starting log cleanup"; then
    echo "   ✅ logs:cleanup 可用"
else
    echo "   ❌ logs:cleanup 不可用"
fi

echo "   测试 sendlogs:cleanup..."
if php artisan sendlogs:cleanup --dry-run 2>&1 | grep -q "Starting SendLog cleanup"; then
    echo "   ✅ sendlogs:cleanup 可用"
else
    echo "   ❌ sendlogs:cleanup 不可用"
fi
echo ""

# 7. 磁盘使用情况
echo "7. 磁盘使用:"
DISK_USAGE=$(df -h . | tail -1 | awk '{print $5}')
echo "   磁盘使用率: ${DISK_USAGE}"
if [[ ${DISK_USAGE%\%} -gt 80 ]]; then
    echo "   ⚠️  磁盘使用率超过 80%，建议清理"
fi
echo ""

echo "======================================"
echo "📋 检查完成"
echo "======================================"
echo ""
echo "💡 建议:"
echo "   - 确保调度器始终运行"
echo "   - 定期检查磁盘使用情况"
echo "   - 根据需要调整保留天数"
echo ""
echo "📖 详细文档:"
echo "   cat 系统清理汇总.md"
echo ""
