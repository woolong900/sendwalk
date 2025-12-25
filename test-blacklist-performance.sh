#!/bin/bash

# 黑名单性能测试脚本
# 用于对比优化前后的性能差异

set -e

echo "=========================================="
echo "  黑名单性能测试"
echo "=========================================="
echo ""

# 颜色定义
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# 检查是否在项目根目录
if [ ! -d "backend" ]; then
    echo -e "${RED}错误: 请在项目根目录执行此脚本${NC}"
    exit 1
fi

cd backend

# 获取用户ID（默认为1）
USER_ID=${1:-1}

echo -e "${BLUE}测试配置:${NC}"
echo "  用户ID: $USER_ID"
echo ""

echo -e "${YELLOW}正在测试查询性能...${NC}"
echo "----------------------------------------"

# 执行性能测试
php artisan tinker --execute="
\$userId = $USER_ID;

echo \"📊 性能测试报告\n\";
echo \"========================================\n\n\";

// 获取总数
\$total = DB::table('blacklist')->where('user_id', \$userId)->count();
echo \"📈 数据量: \" . number_format(\$total) . \" 条\n\n\";

// 测试1: 第1页
echo \"测试 1: 第1页查询\n\";
echo \"-------------------\n\";
\$start = microtime(true);
\$result = DB::table('blacklist')
    ->select(['id', 'email', 'reason', 'created_at'])
    ->where('user_id', \$userId)
    ->orderBy('id', 'desc')
    ->limit(15)
    ->get();
\$time1 = round((microtime(true) - \$start) * 1000, 2);
echo \"⏱️  查询时间: {\$time1}ms\n\";
if (\$time1 < 50) {
    echo \"✅ 优秀! (< 50ms)\n\";
} elseif (\$time1 < 100) {
    echo \"✅ 良好! (< 100ms)\n\";
} elseif (\$time1 < 500) {
    echo \"⚠️  可接受 (< 500ms)\n\";
} else {
    echo \"❌ 需要优化 (> 500ms)\n\";
}
echo \"\n\";

// 测试2: 第10页
echo \"测试 2: 第10页查询\n\";
echo \"-------------------\n\";
\$start = microtime(true);
\$result = DB::table('blacklist')
    ->select(['id', 'email', 'reason', 'created_at'])
    ->where('user_id', \$userId)
    ->orderBy('id', 'desc')
    ->offset(135)
    ->limit(15)
    ->get();
\$time2 = round((microtime(true) - \$start) * 1000, 2);
echo \"⏱️  查询时间: {\$time2}ms\n\";
if (\$time2 < 50) {
    echo \"✅ 优秀! (< 50ms)\n\";
} elseif (\$time2 < 100) {
    echo \"✅ 良好! (< 100ms)\n\";
} elseif (\$time2 < 500) {
    echo \"⚠️  可接受 (< 500ms)\n\";
} else {
    echo \"❌ 需要优化 (> 500ms)\n\";
}
echo \"\n\";

// 测试3: 第100页
echo \"测试 3: 第100页查询\n\";
echo \"-------------------\n\";
\$start = microtime(true);
\$result = DB::table('blacklist')
    ->select(['id', 'email', 'reason', 'created_at'])
    ->where('user_id', \$userId)
    ->orderBy('id', 'desc')
    ->offset(1485)
    ->limit(15)
    ->get();
\$time3 = round((microtime(true) - \$start) * 1000, 2);
echo \"⏱️  查询时间: {\$time3}ms\n\";
if (\$time3 < 100) {
    echo \"✅ 优秀! (< 100ms)\n\";
} elseif (\$time3 < 500) {
    echo \"✅ 良好! (< 500ms)\n\";
} elseif (\$time3 < 1000) {
    echo \"⚠️  可接受 (< 1s)\n\";
} else {
    echo \"❌ 需要优化 (> 1s)\n\";
}
echo \"\n\";

// 测试4: 第1000页
if (\$total > 15000) {
    echo \"测试 4: 第1000页查询\n\";
    echo \"-------------------\n\";
    \$start = microtime(true);
    \$result = DB::table('blacklist')
        ->select(['id', 'email', 'reason', 'created_at'])
        ->where('user_id', \$userId)
        ->orderBy('id', 'desc')
        ->offset(14985)
        ->limit(15)
        ->get();
    \$time4 = round((microtime(true) - \$start) * 1000, 2);
    echo \"⏱️  查询时间: {\$time4}ms\n\";
    if (\$time4 < 200) {
        echo \"✅ 优秀! (< 200ms)\n\";
    } elseif (\$time4 < 500) {
        echo \"✅ 良好! (< 500ms)\n\";
    } elseif (\$time4 < 1000) {
        echo \"⚠️  可接受 (< 1s)\n\";
    } else {
        echo \"❌ 需要优化 (> 1s)\n\";
    }
    echo \"\n\";
}

// 测试5: 搜索查询
echo \"测试 5: 搜索查询\n\";
echo \"-------------------\n\";
\$start = microtime(true);
\$result = DB::table('blacklist')
    ->select(['id', 'email', 'reason', 'created_at'])
    ->where('user_id', \$userId)
    ->where('email', 'like', '%test%')
    ->orderBy('id', 'desc')
    ->limit(15)
    ->get();
\$time5 = round((microtime(true) - \$start) * 1000, 2);
\$count = \$result->count();
echo \"⏱️  查询时间: {\$time5}ms\n\";
echo \"📊 结果数量: {\$count} 条\n\";
if (\$time5 < 100) {
    echo \"✅ 优秀! (< 100ms)\n\";
} elseif (\$time5 < 500) {
    echo \"✅ 良好! (< 500ms)\n\";
} elseif (\$time5 < 1000) {
    echo \"⚠️  可接受 (< 1s)\n\";
} else {
    echo \"❌ 需要优化 (> 1s)\n\";
}
echo \"\n\";

// 测试6: COUNT 查询
echo \"测试 6: 统计查询\n\";
echo \"-------------------\n\";
\$start = microtime(true);
\$count = DB::table('blacklist')
    ->where('user_id', \$userId)
    ->count();
\$time6 = round((microtime(true) - \$start) * 1000, 2);
echo \"⏱️  查询时间: {\$time6}ms\n\";
echo \"📊 总记录数: \" . number_format(\$count) . \" 条\n\";
if (\$time6 < 50) {
    echo \"✅ 优秀! (< 50ms)\n\";
} elseif (\$time6 < 200) {
    echo \"✅ 良好! (< 200ms)\n\";
} elseif (\$time6 < 500) {
    echo \"⚠️  可接受 (< 500ms)\n\";
} else {
    echo \"❌ 需要优化 (> 500ms)\n\";
}
echo \"\n\";

// 综合评分
echo \"========================================\n\";
echo \"📊 综合评分\n\";
echo \"========================================\n\";

\$avgTime = (\$time1 + \$time2 + \$time3 + \$time5 + \$time6) / 5;
echo \"平均查询时间: \" . round(\$avgTime, 2) . \"ms\n\";

if (\$avgTime < 100) {
    echo \"🏆 总体评价: 优秀!\n\";
    echo \"✅ 性能完全满足要求\n\";
} elseif (\$avgTime < 300) {
    echo \"👍 总体评价: 良好!\n\";
    echo \"✅ 性能基本满足要求\n\";
} elseif (\$avgTime < 1000) {
    echo \"⚠️  总体评价: 可接受\n\";
    echo \"💡 建议: 考虑进一步优化\n\";
} else {
    echo \"❌ 总体评价: 需要优化\n\";
    echo \"💡 建议: 检查索引和查询语句\n\";
}

echo \"\n\";
echo \"========================================\n\";
echo \"📋 索引检查\n\";
echo \"========================================\n\";

\$indexes = DB::select('SHOW INDEX FROM blacklist WHERE Key_name LIKE \"idx_%\"');
if (count(\$indexes) > 0) {
    echo \"✅ 已创建优化索引:\n\";
    foreach (\$indexes as \$idx) {
        echo \"   • \" . \$idx->Key_name . \" (\" . \$idx->Column_name . \")\n\";
    }
} else {
    echo \"⚠️  未找到优化索引\n\";
    echo \"💡 建议: 运行 php artisan migrate 创建索引\n\";
}

echo \"\n\";
echo \"========================================\n\";
echo \"💡 优化建议\n\";
echo \"========================================\n\";

if (\$total > 5000000) {
    echo \"⚠️  数据量超过500万，建议:\n\";
    echo \"   1. 实施数据归档策略\n\";
    echo \"   2. 考虑使用 Elasticsearch\n\";
    echo \"   3. 实现分区表\n\";
} elseif (\$total > 1000000) {
    echo \"💡 数据量超过100万，建议:\n\";
    echo \"   1. 定期清理无效数据\n\";
    echo \"   2. 优化搜索功能\n\";
    echo \"   3. 考虑缓存常用查询\n\";
} else {
    echo \"✅ 数据量在合理范围内\n\";
}

echo \"\n\";
"

echo ""
echo -e "${GREEN}=========================================="
echo -e "  测试完成！"
echo -e "==========================================${NC}"
echo ""
echo "💡 提示:"
echo "  • 如果查询时间 > 1秒，请运行优化脚本:"
echo "    ./optimize-blacklist.sh"
echo ""
echo "  • 如果已优化但仍然慢，请检查:"
echo "    1. 数据库服务器负载"
echo "    2. 索引是否正确创建"
echo "    3. 表是否需要优化 (OPTIMIZE TABLE)"
echo ""

