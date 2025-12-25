#!/bin/bash

# 黑名单性能优化脚本
# 用于优化200万+数据的翻页性能

set -e

echo "=========================================="
echo "  黑名单性能优化"
echo "=========================================="
echo ""

# 颜色定义
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# 检查是否在项目根目录
if [ ! -d "backend" ]; then
    echo -e "${RED}错误: 请在项目根目录执行此脚本${NC}"
    exit 1
fi

cd backend

echo -e "${YELLOW}步骤 1/3: 运行数据库迁移（添加索引）${NC}"
echo "----------------------------------------"
php artisan migrate --force
echo ""

echo -e "${YELLOW}步骤 2/3: 检查索引创建情况${NC}"
echo "----------------------------------------"
php artisan tinker --execute="
\$indexes = DB::select('SHOW INDEX FROM blacklist WHERE Key_name LIKE \"idx_%\"');
foreach (\$indexes as \$idx) {
    echo \"✓ \" . \$idx->Key_name . \" (\" . \$idx->Column_name . \")\n\";
}
"
echo ""

echo -e "${YELLOW}步骤 3/3: 测试查询性能${NC}"
echo "----------------------------------------"
php artisan tinker --execute="
\$userId = 1; // 修改为实际用户ID
echo \"测试查询性能...\n\";

// 测试1: 第1页
\$start = microtime(true);
\$result = DB::table('blacklist')
    ->select(['id', 'email', 'reason', 'created_at'])
    ->where('user_id', \$userId)
    ->orderBy('id', 'desc')
    ->limit(15)
    ->get();
\$time1 = round((microtime(true) - \$start) * 1000, 2);
echo \"✓ 第1页查询: {$time1}ms\n\";

// 测试2: 第100页
\$start = microtime(true);
\$result = DB::table('blacklist')
    ->select(['id', 'email', 'reason', 'created_at'])
    ->where('user_id', \$userId)
    ->orderBy('id', 'desc')
    ->offset(1485)
    ->limit(15)
    ->get();
\$time2 = round((microtime(true) - \$start) * 1000, 2);
echo \"✓ 第100页查询: {$time2}ms\n\";

// 测试3: 搜索查询
\$start = microtime(true);
\$result = DB::table('blacklist')
    ->select(['id', 'email', 'reason', 'created_at'])
    ->where('user_id', \$userId)
    ->where('email', 'like', '%test%')
    ->orderBy('id', 'desc')
    ->limit(15)
    ->get();
\$time3 = round((microtime(true) - \$start) * 1000, 2);
echo \"✓ 搜索查询: {$time3}ms\n\";

echo \"\n性能评估:\n\";
if (\$time2 < 100) {
    echo \"✓ 优秀! 翻页速度 < 100ms\n\";
} elseif (\$time2 < 500) {
    echo \"✓ 良好! 翻页速度 < 500ms\n\";
} else {
    echo \"⚠ 需要进一步优化\n\";
}
"
echo ""

echo -e "${GREEN}=========================================="
echo -e "  优化完成！"
echo -e "==========================================${NC}"
echo ""
echo "📊 优化效果:"
echo "  • 添加了复合索引 (user_id, id)"
echo "  • 添加了时间索引 (created_at)"
echo "  • 使用主键排序代替时间排序"
echo "  • 只查询必要字段"
echo ""
echo "🎯 预期性能:"
echo "  • 第1页: < 50ms"
echo "  • 第100页: < 100ms"
echo "  • 第10000页: < 500ms"
echo ""
echo "💡 提示:"
echo "  如果数据量超过500万，建议考虑:"
echo "  1. 添加搜索功能（避免深度翻页）"
echo "  2. 使用 Elasticsearch 进行全文搜索"
echo "  3. 实现数据归档（删除旧数据）"
echo ""

