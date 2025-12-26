#!/bin/bash

echo "=========================================="
echo "  部署邮件活动完整统计列"
echo "=========================================="
echo ""

# 颜色定义
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# 检查是否在项目根目录
if [ ! -d "backend" ] || [ ! -d "frontend" ]; then
    echo -e "${RED}❌ 错误: 请在项目根目录执行此脚本${NC}"
    exit 1
fi

echo -e "${BLUE}步骤 1/3: 拉取最新代码${NC}"
echo "-----------------------------------"
git pull
echo ""

echo -e "${BLUE}步骤 2/3: 重新构建前端${NC}"
echo "-----------------------------------"
cd frontend
echo "正在安装依赖（如有更新）..."
npm install --silent

echo "正在构建生产版本..."
npm run build
cd ..
echo ""

echo -e "${BLUE}步骤 3/3: 检查部署状态${NC}"
echo "-----------------------------------"

# 检查前端构建产物
if [ -d "frontend/dist" ]; then
    echo -e "${GREEN}✓${NC} 前端构建成功"
    BUILD_SIZE=$(du -sh frontend/dist/ | cut -f1)
    echo "  构建产物大小: $BUILD_SIZE"
else
    echo -e "${RED}✗${NC} 前端构建失败"
    exit 1
fi

# 检查后端文件
if [ -f "backend/app/Models/Campaign.php" ]; then
    echo -e "${GREEN}✓${NC} 后端文件检查通过"
    
    # 检查是否包含新的属性
    if grep -q "delivery_rate" backend/app/Models/Campaign.php && \
       grep -q "bounce_rate" backend/app/Models/Campaign.php && \
       grep -q "unsubscribe_rate" backend/app/Models/Campaign.php; then
        echo -e "${GREEN}✓${NC} 新增统计属性已添加（送达率、弹回率、取消订阅率）"
    else
        echo -e "${YELLOW}⚠${NC}  警告: 未找到新增的统计属性"
    fi
else
    echo -e "${RED}✗${NC} 后端文件不完整"
    exit 1
fi

echo ""
echo -e "${GREEN}=========================================="
echo -e "  ✅ 部署完成！"
echo -e "==========================================${NC}"
echo ""
echo -e "${YELLOW}下一步操作：${NC}"
echo ""
echo "1. 如果使用 Nginx 托管前端："
echo "   • 将 frontend/dist/ 的内容复制到 Nginx 根目录"
echo "   • 或确保 Nginx 配置指向正确的 dist 目录"
echo ""
echo "2. 清理浏览器缓存："
echo "   • 按 Ctrl+F5 (Windows/Linux)"
echo "   • 按 Cmd+Shift+R (Mac)"
echo ""
echo "3. 如果使用 Cloudflare："
echo "   • 登录 Cloudflare 控制台"
echo "   • 清除缓存（Purge Cache）"
echo ""
echo -e "${BLUE}新增功能：${NC}"
echo ""
echo "邮件活动列表现在包含完整的统计信息："
echo ""
echo "  ✅ 打开率    - 邮件被打开的比例（可点击查看详情）"
echo "  ✅ 点击率    - 链接被点击的比例"
echo "  ✅ 投诉率    - 收件人投诉的比例（可点击查看详情）"
echo "  🆕 送达率    - 邮件成功送达的比例"
echo "  🆕 弹回率    - 邮件无法送达的比例"
echo "  🆕 取消订阅  - 收件人退订的比例"
echo ""
echo "表格现在支持水平滚动以适应所有列（最小宽度: 1590px）"
echo ""
echo -e "${BLUE}查看详细说明：${NC}"
echo "• 邮件活动统计列完善说明.md"
echo "• 邮件活动投诉率功能说明.md"
echo ""

