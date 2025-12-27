#!/bin/bash

# 验证前端部署状态
# 用法：在正式环境运行 bash verify-frontend-deployment.sh

FRONTEND_DIR="/data/www/sendwalk/frontend"
DIST_DIR="$FRONTEND_DIR/dist"

echo "=== 验证前端部署状态 ==="
echo ""

# 检查目录是否存在
if [ ! -d "$DIST_DIR" ]; then
    echo "❌ 错误: $DIST_DIR 目录不存在"
    exit 1
fi

# 1. 检查 index.html 修改时间
echo "1️⃣ 检查 index.html 最后修改时间："
if [ -f "$DIST_DIR/index.html" ]; then
    if [[ "$OSTYPE" == "darwin"* ]]; then
        # macOS
        stat -f "   文件: %N%n   修改时间: %Sm%n   大小: %z 字节" "$DIST_DIR/index.html"
    else
        # Linux
        stat -c "   文件: %n%n   修改时间: %y%n   大小: %s 字节" "$DIST_DIR/index.html"
    fi
    
    # 计算距离现在多久
    if [[ "$OSTYPE" == "darwin"* ]]; then
        MODIFIED=$(stat -f %m "$DIST_DIR/index.html")
    else
        MODIFIED=$(stat -c %Y "$DIST_DIR/index.html")
    fi
    NOW=$(date +%s)
    DIFF=$((NOW - MODIFIED))
    HOURS=$((DIFF / 3600))
    DAYS=$((HOURS / 24))
    
    if [ $HOURS -lt 1 ]; then
        echo "   ✅ 最近更新（不到1小时前）"
    elif [ $HOURS -lt 24 ]; then
        echo "   ⚠️  $HOURS 小时前更新"
    else
        echo "   ⚠️  $DAYS 天前更新（可能是旧版本）"
    fi
else
    echo "   ❌ index.html 不存在"
fi
echo ""

# 2. 检查 JS 文件
echo "2️⃣ 检查 JavaScript 文件："
JS_COUNT=$(find "$DIST_DIR/assets" -name "*.js" 2>/dev/null | wc -l)
if [ $JS_COUNT -gt 0 ]; then
    echo "   找到 $JS_COUNT 个 JS 文件"
    echo ""
    echo "   最新的 5 个 JS 文件："
    find "$DIST_DIR/assets" -name "*.js" -type f -printf '%T@ %p %s\n' 2>/dev/null | sort -rn | head -5 | while read time file size; do
        filename=$(basename "$file")
        size_kb=$((size / 1024))
        echo "      - $filename (${size_kb}KB)"
    done
else
    echo "   ❌ 未找到 JS 文件"
fi
echo ""

# 3. 检查关键代码特征
echo "3️⃣ 检查关键代码特征："

# 检查 campaignDataLoaded（新版本标识）
if grep -r "campaignDataLoaded" "$DIST_DIR/assets"/*.js >/dev/null 2>&1; then
    echo "   ✅ 找到 'campaignDataLoaded'（新版本）"
else
    echo "   ❌ 未找到 'campaignDataLoaded'（旧版本）"
fi

# 检查 smtp_server_id toString 处理
if grep -r "smtp_server_id" "$DIST_DIR/assets"/*.js >/dev/null 2>&1; then
    echo "   ✅ 找到 'smtp_server_id' 相关代码"
    
    if grep -r "smtp_server_id.*toString" "$DIST_DIR/assets"/*.js >/dev/null 2>&1; then
        echo "   ✅ 找到 'toString' 处理（修复已应用）"
    else
        echo "   ⚠️  未找到 'toString' 处理（可能未包含修复）"
    fi
else
    echo "   ❌ 未找到 'smtp_server_id' 相关代码"
fi

# 检查 Select 组件
if grep -r "SelectTrigger" "$DIST_DIR/assets"/*.js >/dev/null 2>&1; then
    echo "   ✅ 找到 Select 组件代码"
else
    echo "   ❌ 未找到 Select 组件代码"
fi
echo ""

# 4. 检查源代码是否是最新的
echo "4️⃣ 检查源代码状态："
if [ -d "$FRONTEND_DIR/.git" ]; then
    cd "$FRONTEND_DIR"
    
    # 获取当前分支
    BRANCH=$(git rev-parse --abbrev-ref HEAD 2>/dev/null)
    echo "   当前分支: $BRANCH"
    
    # 获取最后一次提交
    LAST_COMMIT=$(git log -1 --pretty=format:"%h - %s (%cr)" 2>/dev/null)
    echo "   最后提交: $LAST_COMMIT"
    
    # 检查是否有未拉取的更新
    git fetch origin >/dev/null 2>&1
    LOCAL=$(git rev-parse @ 2>/dev/null)
    REMOTE=$(git rev-parse @{u} 2>/dev/null)
    
    if [ "$LOCAL" = "$REMOTE" ]; then
        echo "   ✅ 代码是最新的"
    else
        echo "   ⚠️  远程有新的提交，请执行 git pull"
    fi
    
    # 检查是否有未提交的更改
    if git diff-index --quiet HEAD -- 2>/dev/null; then
        echo "   ✅ 没有未提交的更改"
    else
        echo "   ⚠️  有未提交的更改"
        git status --short
    fi
else
    echo "   ⚠️  不是 git 仓库"
fi
echo ""

# 5. 建议
echo "=== 建议操作 ==="
echo ""

# 判断是否需要重新构建
NEED_REBUILD=false

if [ $HOURS -gt 24 ]; then
    echo "⚠️  dist 目录超过24小时未更新，建议重新构建"
    NEED_REBUILD=true
fi

if ! grep -r "campaignDataLoaded" "$DIST_DIR/assets"/*.js >/dev/null 2>&1; then
    echo "⚠️  未找到新版本代码特征，需要重新构建"
    NEED_REBUILD=true
fi

if [ "$NEED_REBUILD" = true ]; then
    echo ""
    echo "📋 重新构建步骤："
    echo "   cd $FRONTEND_DIR"
    echo "   git pull                    # 拉取最新代码"
    echo "   npm install                 # 更新依赖（如有需要）"
    echo "   npm run build               # 构建"
    echo ""
    echo "   # 构建完成后，强制刷新浏览器："
    echo "   # Windows/Linux: Ctrl + Shift + R"
    echo "   # macOS: Cmd + Shift + R"
else
    echo "✅ 前端代码看起来是最新的"
    echo ""
    echo "如果编辑活动仍然有问题，请："
    echo "   1. 强制刷新浏览器（Ctrl+Shift+R 或 Cmd+Shift+R）"
    echo "   2. 清空浏览器缓存"
    echo "   3. 使用无痕模式测试"
    echo "   4. 检查浏览器控制台的错误信息"
fi

echo ""
echo "=== 验证完成 ==="

