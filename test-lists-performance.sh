#!/bin/bash

# 邮件列表性能测试脚本
# 用于对比优化前后的性能差异

set -e

echo "=========================================="
echo "邮件列表 API 性能测试"
echo "=========================================="
echo ""

# 检查 jq 是否安装
if ! command -v jq &> /dev/null; then
    echo "警告：未安装 jq，将显示原始 JSON 响应"
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
    echo "  ./test-lists-performance.sh"
    exit 1
fi

echo "测试配置："
echo "  API URL: $API_URL"
echo "  Token: ${TOKEN:0:20}..."
echo ""

# 测试函数
test_api() {
    local test_name=$1
    echo "测试: $test_name"
    echo "----------------------------------------"
    
    # 预热请求（不计时）
    curl -s -o /dev/null \
        -H "Authorization: Bearer $TOKEN" \
        -H "Accept: application/json" \
        "$API_URL/lists"
    
    # 正式测试（3次取平均）
    local total_time=0
    local iterations=3
    
    for i in $(seq 1 $iterations); do
        local start=$(date +%s%N)
        
        local response=$(curl -s -w "\n%{http_code}\n%{time_total}" \
            -H "Authorization: Bearer $TOKEN" \
            -H "Accept: application/json" \
            "$API_URL/lists")
        
        local end=$(date +%s%N)
        local duration=$(echo "scale=3; ($end - $start) / 1000000000" | bc)
        
        # 提取 HTTP 状态码和响应时间
        local http_code=$(echo "$response" | tail -n 2 | head -n 1)
        local curl_time=$(echo "$response" | tail -n 1)
        local body=$(echo "$response" | head -n -2)
        
        echo "  第 $i 次: ${duration}s (HTTP $http_code)"
        
        if [ "$i" -eq 1 ] && [ "$JQ_INSTALLED" = true ]; then
            local list_count=$(echo "$body" | jq '.data | length' 2>/dev/null || echo "N/A")
            echo "  列表数量: $list_count"
        fi
        
        total_time=$(echo "$total_time + $duration" | bc)
    done
    
    local avg_time=$(echo "scale=3; $total_time / $iterations" | bc)
    echo ""
    echo "  平均响应时间: ${avg_time}s"
    echo ""
}

# 运行测试
test_api "获取邮件列表"

echo "=========================================="
echo "测试完成"
echo "=========================================="
echo ""
echo "性能基准："
echo "  优秀: < 1 秒"
echo "  良好: 1-2 秒"
echo "  一般: 2-5 秒"
echo "  较差: > 5 秒"
echo ""

