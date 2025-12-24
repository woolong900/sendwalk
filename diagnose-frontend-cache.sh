#!/bin/bash

# 前端缓存问题诊断脚本

echo "=========================================="
echo "前端缓存问题诊断"
echo "=========================================="
echo ""

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# 1. 检查前端构建文件
echo "1. 检查前端构建文件..."
if [ -d "frontend/dist" ]; then
    echo -e "${GREEN}✓${NC} dist 目录存在"
    
    # 检查构建时间
    BUILD_TIME=$(stat -f "%Sm" -t "%Y-%m-%d %H:%M:%S" frontend/dist/index.html 2>/dev/null || stat -c "%y" frontend/dist/index.html 2>/dev/null)
    echo "  构建时间: $BUILD_TIME"
    
    # 检查 JS 文件
    JS_FILES=$(ls frontend/dist/assets/index-*.js 2>/dev/null | wc -l)
    echo "  JS 文件数量: $JS_FILES"
    
    if [ $JS_FILES -eq 0 ]; then
        echo -e "${RED}✗${NC} 没有找到 JS 文件！"
    else
        echo "  最新的 JS 文件:"
        ls -lh frontend/dist/assets/index-*.js | tail -1
    fi
else
    echo -e "${RED}✗${NC} dist 目录不存在，需要构建！"
fi

echo ""

# 2. 检查 Nginx 配置
echo "2. 检查 Nginx 配置..."
if [ -f "/etc/nginx/conf.d/sendwalk-frontend.conf" ]; then
    echo -e "${GREEN}✓${NC} Nginx 配置文件存在"
    
    # 检查是否有 no-cache 配置
    if grep -q "no-cache" /etc/nginx/conf.d/sendwalk-frontend.conf; then
        echo -e "${GREEN}✓${NC} 配置包含 no-cache"
    else
        echo -e "${RED}✗${NC} 配置不包含 no-cache，需要更新！"
    fi
    
    # 测试配置
    echo "  测试 Nginx 配置..."
    sudo nginx -t 2>&1 | grep -q "successful" && echo -e "${GREEN}✓${NC} 配置正确" || echo -e "${RED}✗${NC} 配置有误"
else
    echo -e "${YELLOW}⚠${NC} Nginx 配置文件不存在或路径不同"
fi

echo ""

# 3. 检查 Nginx 进程
echo "3. 检查 Nginx 运行状态..."
if pgrep -x nginx > /dev/null; then
    echo -e "${GREEN}✓${NC} Nginx 正在运行"
    
    # 检查配置重载时间
    NGINX_PID=$(pgrep -x nginx | head -1)
    NGINX_START=$(ps -p $NGINX_PID -o lstart= 2>/dev/null)
    echo "  Nginx 启动时间: $NGINX_START"
else
    echo -e "${RED}✗${NC} Nginx 未运行！"
fi

echo ""

# 4. 测试 HTTP 响应头
echo "4. 测试 HTTP 响应头..."
echo "  检查 index.html 的缓存头..."

# 尝试获取响应头
RESPONSE=$(curl -s -I https://edm.sendwalk.com/ 2>/dev/null || curl -s -I http://localhost/ 2>/dev/null)

if [ ! -z "$RESPONSE" ]; then
    if echo "$RESPONSE" | grep -qi "Cache-Control.*no-cache"; then
        echo -e "${GREEN}✓${NC} 响应头包含 no-cache"
        echo "$RESPONSE" | grep -i "Cache-Control"
    else
        echo -e "${RED}✗${NC} 响应头不包含 no-cache"
        echo "$RESPONSE" | grep -i "Cache-Control"
    fi
else
    echo -e "${YELLOW}⚠${NC} 无法获取响应头（网络问题？）"
fi

echo ""

# 5. 检查 Service Worker
echo "5. 检查 Service Worker..."
if [ -f "frontend/dist/sw.js" ] || [ -f "frontend/dist/service-worker.js" ]; then
    echo -e "${YELLOW}⚠${NC} 发现 Service Worker 文件！这可能导致缓存问题"
    echo "  需要在浏览器中注销 Service Worker"
else
    echo -e "${GREEN}✓${NC} 没有 Service Worker"
fi

echo ""
echo "=========================================="
echo "诊断总结"
echo "=========================================="
echo ""

# 提供修复建议
echo "修复步骤："
echo ""

echo "1. 【服务器端】更新 Nginx 配置并重载:"
echo "   sudo cp nginx/frontend.conf /etc/nginx/conf.d/sendwalk-frontend.conf"
echo "   sudo nginx -t"
echo "   sudo nginx -s reload"
echo ""

echo "2. 【服务器端】重新构建前端:"
echo "   cd frontend"
echo "   rm -rf dist"
echo "   npm run build"
echo ""

echo "3. 【浏览器端】强制清除所有缓存:"
echo ""
echo "   方法 1: 硬刷新（最快）"
echo "   - Windows: Ctrl + Shift + R"
echo "   - Mac: Cmd + Shift + R"
echo ""

echo "   方法 2: 清除网站数据（更彻底）"
echo "   - Chrome: F12 → Application → Storage → Clear site data"
echo "   - Firefox: F12 → Storage → 右键清除"
echo ""

echo "   方法 3: 注销 Service Worker（如果有）"
echo "   - Chrome: F12 → Application → Service Workers → Unregister"
echo ""

echo "   方法 4: 无痕模式测试"
echo "   - Ctrl + Shift + N (Chrome)"
echo "   - Ctrl + Shift + P (Firefox)"
echo ""

echo "4. 【验证】检查是否更新:"
echo "   - 打开浏览器控制台 (F12)"
echo "   - 切换到 Network 标签"
echo "   - 勾选 'Disable cache'"
echo "   - 刷新页面"
echo "   - 查看 index.html 是否从服务器加载 (200 状态)"
echo ""

echo "5. 【终极方案】如果还是不行:"
echo "   - 清除浏览器所有历史记录和缓存"
echo "   - 重启浏览器"
echo "   - 或者使用不同的浏览器测试"
echo ""

