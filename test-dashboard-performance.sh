#!/bin/bash

# 仪表盘性能测试脚本
# 用于测试优化前后的性能差异

set -e

echo "=========================================="
echo "仪表盘 API 性能测试"
echo "=========================================="
echo ""

# 检查 jq 是否安装
if ! command -v jq &> /dev/null; then
    echo "警告：未安装 jq，将显示原始响应时间"
    JQ_INSTALLED=false
else
    JQ_INSTALLED=true
fi

# 配置
API_URL="${API_URL:-http://localhost/api}"
TOKEN="${TOKEN:-}"

if [ -z "$TOKEN" ]; then
    echo "请设置 TOKEN 环境变量（Bearer token）"
    echo "示例："
    echo "  export TOKEN='your-token-here'"
    echo "  ./test-dashboard-performance.sh"
    exit 1
fi

echo "测试配置："
echo "  API URL: $API_URL"
echo "  Token: ${TOKEN:0:20}..."
echo ""

# 测试函数
test_dashboard() {
    local test_name=$1
    echo "测试: $test_name"
    echo "----------------------------------------"
    
    # 预热请求（不计时）
    echo "  预热中..."
    curl -s -o /dev/null \
        -H "Authorization: Bearer $TOKEN" \
        -H "Accept: application/json" \
        "$API_URL/dashboard/stats"
    
    # 正式测试（5次取平均）
    local total_time=0
    local iterations=5
    local times=()
    
    echo "  开始测试（共 $iterations 次）..."
    echo ""
    
    for i in $(seq 1 $iterations); do
        local start=$(date +%s%N)
        
        local response=$(curl -s -w "\n%{http_code}\n%{time_total}" \
            -H "Authorization: Bearer $TOKEN" \
            -H "Accept: application/json" \
            "$API_URL/dashboard/stats")
        
        local end=$(date +%s%N)
        local duration=$(echo "scale=3; ($end - $start) / 1000000000" | bc)
        
        # 提取 HTTP 状态码和响应时间
        local http_code=$(echo "$response" | tail -n 2 | head -n 1)
        local curl_time=$(echo "$response" | tail -n 1)
        local body=$(echo "$response" | head -n -2)
        
        times+=($duration)
        echo "  第 $i 次: ${duration}s (HTTP $http_code)"
        
        # 第一次显示数据详情
        if [ "$i" -eq 1 ] && [ "$JQ_INSTALLED" = true ]; then
            echo ""
            echo "  响应数据摘要:"
            echo "  ├─ 总订阅者: $(echo "$body" | jq -r '.data.total_subscribers' 2>/dev/null || echo "N/A")"
            echo "  ├─ 总活动: $(echo "$body" | jq -r '.data.total_campaigns' 2>/dev/null || echo "N/A")"
            echo "  ├─ 已发送: $(echo "$body" | jq -r '.data.total_sent' 2>/dev/null || echo "N/A")"
            echo "  ├─ 打开率: $(echo "$body" | jq -r '.data.avg_open_rate' 2>/dev/null || echo "N/A")%"
            echo "  ├─ 队列长度: $(echo "$body" | jq -r '.data.queue_length' 2>/dev/null || echo "N/A")"
            echo "  └─ Worker数: $(echo "$body" | jq -r '.data.worker_count' 2>/dev/null || echo "N/A")"
            echo ""
        fi
        
        total_time=$(echo "$total_time + $duration" | bc)
        
        # 间隔0.5秒
        sleep 0.5
    done
    
    # 计算统计数据
    local avg_time=$(echo "scale=3; $total_time / $iterations" | bc)
    
    # 计算最小值和最大值
    local min_time=${times[0]}
    local max_time=${times[0]}
    for time in "${times[@]}"; do
        if (( $(echo "$time < $min_time" | bc -l) )); then
            min_time=$time
        fi
        if (( $(echo "$time > $max_time" | bc -l) )); then
            max_time=$time
        fi
    done
    
    echo ""
    echo "  =========================================="
    echo "  性能统计:"
    echo "  ├─ 平均响应时间: ${avg_time}s"
    echo "  ├─ 最快: ${min_time}s"
    echo "  ├─ 最慢: ${max_time}s"
    echo "  └─ 总耗时: ${total_time}s"
    echo "  =========================================="
    echo ""
    
    # 性能评级
    if (( $(echo "$avg_time < 0.5" | bc -l) )); then
        echo "  ✅ 性能等级: 优秀 (< 0.5s)"
    elif (( $(echo "$avg_time < 1.0" | bc -l) )); then
        echo "  ✅ 性能等级: 良好 (< 1s)"
    elif (( $(echo "$avg_time < 2.0" | bc -l) )); then
        echo "  ⚠️  性能等级: 一般 (< 2s)"
    elif (( $(echo "$avg_time < 5.0" | bc -l) )); then
        echo "  ⚠️  性能等级: 较差 (< 5s)"
    else
        echo "  ❌ 性能等级: 很差 (>= 5s) - 需要优化！"
    fi
    
    echo ""
}

# 运行测试
test_dashboard "仪表盘统计 API"

echo "=========================================="
echo "测试完成"
echo "=========================================="
echo ""
echo "性能基准："
echo "  ✅ 优秀: < 0.5 秒"
echo "  ✅ 良好: 0.5-1 秒"
echo "  ⚠️  一般: 1-2 秒"
echo "  ⚠️  较差: 2-5 秒"
echo "  ❌ 很差: > 5 秒"
echo ""
echo "优化建议："
echo "  - 如果响应时间 > 2 秒，请运行数据库迁移添加索引"
echo "  - 如果响应时间 > 5 秒，请检查数据库查询日志"
echo "  - 缓存已启用（5秒过期），第二次请求应该更快"
echo ""

