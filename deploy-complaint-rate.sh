#!/bin/bash

echo "=========================================="
echo "  部署邮件活动投诉率功能"
echo "=========================================="
echo ""

# 颜色定义
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
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
npm install

echo "正在构建生产版本..."
npm run build
cd ..
echo ""

echo -e "${BLUE}步骤 3/3: 检查部署状态${NC}"
echo "-----------------------------------"

# 检查前端构建产物
if [ -d "frontend/dist" ]; then
    echo -e "${GREEN}✓${NC} 前端构建成功"
    echo "  构建产物位置: frontend/dist/"
else
    echo -e "${RED}✗${NC} 前端构建失败"
    exit 1
fi

# 检查后端文件
if [ -f "backend/app/Models/Campaign.php" ] && \
   [ -f "backend/app/Http/Controllers/Api/CampaignAnalyticsController.php" ]; then
    echo -e "${GREEN}✓${NC} 后端文件检查通过"
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
echo -e "${BLUE}功能特性：${NC}"
echo "• 在邮件活动列表中显示投诉率"
echo "• 点击投诉率查看详细的投诉报告"
echo "• 支持搜索邮箱地址"
echo "• 分页浏览投诉记录"
echo ""
echo "详细说明请查看: 邮件活动投诉率功能说明.md"
echo ""

