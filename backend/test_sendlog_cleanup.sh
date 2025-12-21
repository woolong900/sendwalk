#!/bin/bash

# SendLog 清理功能测试脚本

echo "======================================"
echo "SendLog 清理功能测试"
echo "======================================"
echo ""

cd /Users/panlei/sendwalk/backend

# 1. 检查命令是否可用
echo "1. 检查清理命令..."
if php artisan list | grep -q "sendlogs:cleanup"; then
    echo "   ✅ sendlogs:cleanup 命令已注册"
else
    echo "   ❌ sendlogs:cleanup 命令未找到"
    exit 1
fi
echo ""

# 2. 查看当前数据统计
echo "2. 当前 SendLog 数据统计..."
php artisan tinker --execute="
\$total = App\Models\SendLog::count();
echo '   总记录数: ' . \$total . PHP_EOL;

if (\$total > 0) {
    \$oldest = App\Models\SendLog::orderBy('created_at', 'asc')->first();
    \$latest = App\Models\SendLog::orderBy('created_at', 'desc')->first();
    echo '   最早记录: ' . \$oldest->created_at . PHP_EOL;
    echo '   最新记录: ' . \$latest->created_at . PHP_EOL;
    
    \$sent = App\Models\SendLog::where('status', 'sent')->count();
    \$failed = App\Models\SendLog::where('status', 'failed')->count();
    echo '   成功: ' . \$sent . ', 失败: ' . \$failed . PHP_EOL;
} else {
    echo '   (暂无数据)' . PHP_EOL;
}
" 2>/dev/null
echo ""

# 3. 测试 Dry Run（30天）
echo "3. 测试清理命令（Dry Run，保留30天）..."
php artisan sendlogs:cleanup --dry-run 2>&1 | head -20
echo ""

# 4. 测试 Dry Run（7天）
echo "4. 测试清理命令（Dry Run，保留7天）..."
php artisan sendlogs:cleanup --days=7 --dry-run 2>&1 | head -20
echo ""

# 5. 检查定时任务配置
echo "5. 检查定时任务配置..."
if grep -q "sendlogs:cleanup" routes/console.php; then
    echo "   ✅ 定时任务已配置"
    grep -A 2 "sendlogs:cleanup" routes/console.php | sed 's/^/   /'
else
    echo "   ❌ 定时任务未配置"
fi
echo ""

# 6. 检查调度器状态
echo "6. 检查调度器状态..."
if pgrep -f "schedule:work" > /dev/null; then
    SCHEDULER_PID=$(pgrep -f "schedule:work")
    echo "   ✅ 调度器正在运行 (PID: $SCHEDULER_PID)"
else
    echo "   ⚠️  调度器未运行"
    echo "   启动命令: php artisan schedule:work &"
fi
echo ""

# 7. 查看帮助信息
echo "7. 命令帮助信息..."
php artisan sendlogs:cleanup --help | head -20
echo ""

echo "======================================"
echo "📋 测试完成"
echo "======================================"
echo ""
echo "💡 提示:"
echo "   - 自动清理：每天凌晨 4:00，保留 30 天"
echo "   - 手动清理：php artisan sendlogs:cleanup"
echo "   - 预览删除：php artisan sendlogs:cleanup --dry-run"
echo "   - 自定义天数：php artisan sendlogs:cleanup --days=7"
echo ""
echo "📖 查看详细文档:"
echo "   cat SendLog清理说明.md"
echo ""

