#!/bin/bash

# 黑名单导入问题诊断脚本
# 用于排查为什么导入没有数据

echo "=========================================="
echo "黑名单导入问题诊断"
echo "=========================================="
echo ""

# 检查是否在正确的目录
if [ ! -d "backend" ]; then
    echo "❌ 错误: 请在项目根目录运行此脚本"
    exit 1
fi

cd backend

echo "1. 检查队列服务状态"
echo "────────────────────────────────────────"
if command -v supervisorctl &> /dev/null; then
    echo "Supervisor 状态:"
    sudo supervisorctl status laravel-worker:* 2>/dev/null || echo "  ⚠️  队列服务未配置"
else
    echo "  ⚠️  Supervisor 未安装"
fi

echo ""
echo "检查队列进程:"
QUEUE_PROCS=$(ps aux | grep "queue:work" | grep -v grep)
if [ -z "$QUEUE_PROCS" ]; then
    echo "  ❌ 没有队列进程在运行！"
    echo "  这是导入失败的主要原因！"
else
    echo "  ✓ 队列进程正在运行:"
    echo "$QUEUE_PROCS" | sed 's/^/    /'
fi

echo ""
echo "2. 检查存储目录权限"
echo "────────────────────────────────────────"
echo "storage/app 权限:"
ls -la storage/app/ | head -5

if [ -d "storage/app/blacklist_imports" ]; then
    echo ""
    echo "storage/app/blacklist_imports 权限:"
    ls -la storage/app/blacklist_imports/ | head -5
    
    FILE_COUNT=$(ls -1 storage/app/blacklist_imports/ 2>/dev/null | wc -l)
    echo ""
    echo "临时文件数量: $FILE_COUNT"
    if [ $FILE_COUNT -gt 0 ]; then
        echo "  ⚠️  有未处理的文件，队列可能有问题"
        ls -lh storage/app/blacklist_imports/
    fi
else
    echo "  ⚠️  blacklist_imports 目录不存在"
fi

echo ""
echo "3. 检查最近的日志"
echo "────────────────────────────────────────"
if [ -f "storage/logs/laravel.log" ]; then
    echo "最近 20 行日志（黑名单相关）:"
    tail -100 storage/logs/laravel.log | grep -i "黑名单\|blacklist\|import" | tail -20
    
    echo ""
    echo "错误日志:"
    tail -50 storage/logs/laravel.log | grep -i "ERROR\|Exception\|failed" | tail -10
else
    echo "  ❌ 日志文件不存在"
fi

if [ -f "storage/logs/worker.log" ]; then
    echo ""
    echo "队列日志（最近 10 行）:"
    tail -10 storage/logs/worker.log
fi

echo ""
echo "4. 检查数据库连接"
echo "────────────────────────────────────────"
php artisan tinker --execute="echo 'Database: ' . DB::connection()->getDatabaseName() . PHP_EOL; echo 'Blacklist count: ' . DB::table('blacklist')->count() . PHP_EOL;"

echo ""
echo "5. 检查 Redis/缓存"
echo "────────────────────────────────────────"
CACHE_DRIVER=$(php artisan tinker --execute="echo config('cache.default');" 2>/dev/null)
echo "缓存驱动: $CACHE_DRIVER"

if [ "$CACHE_DRIVER" = "redis" ]; then
    if command -v redis-cli &> /dev/null; then
        echo "Redis 导入任务:"
        redis-cli KEYS "blacklist_import:*" 2>/dev/null || echo "  无法连接 Redis"
    else
        echo "  Redis 未安装"
    fi
fi

echo ""
echo "6. 测试队列任务"
echo "────────────────────────────────────────"
echo "尝试手动处理一个队列任务..."
timeout 5s php artisan queue:work --once 2>&1 | head -10 || echo "  没有待处理的任务"

echo ""
echo "7. 检查失败的任务"
echo "────────────────────────────────────────"
php artisan queue:failed | head -20

echo ""
echo "=========================================="
echo "诊断总结"
echo "=========================================="
echo ""

# 给出建议
if [ -z "$QUEUE_PROCS" ]; then
    echo "🔴 关键问题: 队列服务未运行"
    echo ""
    echo "解决方案:"
    echo "  1. 配置 Supervisor:"
    echo "     cd /data/www/sendwalk"
    echo "     sudo ./setup-queue-worker.sh"
    echo ""
    echo "  2. 或临时运行队列（测试用）:"
    echo "     cd /data/www/sendwalk/backend"
    echo "     nohup php artisan queue:work > storage/logs/queue.log 2>&1 &"
    echo ""
else
    echo "✅ 队列服务正在运行"
    echo ""
    echo "如果仍然没有导入数据，检查:"
    echo "  1. 查看日志: tail -f storage/logs/laravel.log"
    echo "  2. 查看失败任务: php artisan queue:failed"
    echo "  3. 重试失败任务: php artisan queue:retry all"
    echo ""
fi

echo "实时监控命令:"
echo "  - 查看日志: tail -f backend/storage/logs/laravel.log | grep 黑名单"
echo "  - 查看队列: watch -n 1 'ps aux | grep queue:work'"
echo "  - 查看数据: mysql -u root -p -e 'SELECT COUNT(*) FROM sendwalk.blacklist'"
echo ""

