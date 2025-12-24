#!/bin/bash

# 🎨 部署邮件模板系统
# 适用于生产环境

set -e  # 遇到错误立即退出

echo "========================================"
echo "🎨 部署邮件模板系统"
echo "========================================"
echo ""

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# 检查是否在 backend 目录
if [ ! -f "artisan" ]; then
    echo -e "${RED}❌ 错误：请在 backend 目录下运行此脚本${NC}"
    exit 1
fi

echo "========================================"
echo "📋 步骤 1: 备份数据库"
echo "========================================"
echo ""

# 获取数据库配置
DB_DATABASE=$(grep DB_DATABASE .env | cut -d '=' -f2)
DB_USERNAME=$(grep DB_USERNAME .env | cut -d '=' -f2)

if [ -z "$DB_DATABASE" ]; then
    echo -e "${YELLOW}⚠️  警告：未找到数据库配置${NC}"
    echo "请手动备份数据库！"
    read -p "是否继续？(y/n) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        exit 1
    fi
else
    BACKUP_FILE="backup_before_templates_$(date +%Y%m%d_%H%M%S).sql"
    echo "正在备份数据库 $DB_DATABASE 到 ~/$BACKUP_FILE ..."
    
    # 尝试备份
    if mysqldump -u "$DB_USERNAME" -p "$DB_DATABASE" > ~/"$BACKUP_FILE" 2>/dev/null; then
        echo -e "${GREEN}✅ 数据库备份成功！${NC}"
    else
        echo -e "${YELLOW}⚠️  自动备份失败，请手动备份数据库${NC}"
        read -p "是否继续？(y/n) " -n 1 -r
        echo
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            exit 1
        fi
    fi
fi

echo ""
echo "========================================"
echo "🗄️  步骤 2: 运行数据库迁移"
echo "========================================"
echo ""

# 运行迁移
echo "正在运行数据库迁移..."
php artisan migrate --force

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✅ 数据库迁移成功！${NC}"
else
    echo -e "${RED}❌ 数据库迁移失败！${NC}"
    exit 1
fi

echo ""
echo "========================================"
echo "🎨 步骤 3: 创建预设模板"
echo "========================================"
echo ""

echo "正在创建预设模板..."
php artisan db:seed --class=TemplateSeeder --force

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✅ 预设模板创建成功！${NC}"
else
    echo -e "${YELLOW}⚠️  预设模板创建失败（可能已存在）${NC}"
fi

echo ""
echo "========================================"
echo "🔍 步骤 4: 验证安装"
echo "========================================"
echo ""

# 检查模板表
echo "检查 templates 表..."
php artisan db:table templates > /dev/null 2>&1
if [ $? -eq 0 ]; then
    echo -e "${GREEN}✅ templates 表存在${NC}"
    
    # 统计模板数量
    TEMPLATE_COUNT=$(mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" -D "$DB_DATABASE" -se "SELECT COUNT(*) FROM templates" 2>/dev/null || echo "0")
    echo "当前模板数量: $TEMPLATE_COUNT"
    
    if [ "$TEMPLATE_COUNT" -gt 0 ]; then
        echo ""
        echo "模板列表:"
        mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" -D "$DB_DATABASE" -e "SELECT id, name, category, is_default FROM templates LIMIT 10" 2>/dev/null || echo "无法显示模板列表"
    fi
else
    echo -e "${RED}❌ templates 表不存在${NC}"
fi

echo ""
echo "========================================"
echo "🧹 步骤 5: 清理缓存"
echo "========================================"
echo ""

echo "清理配置缓存..."
php artisan config:clear

echo "清理应用缓存..."
php artisan cache:clear

echo "清理路由缓存..."
php artisan route:clear

echo -e "${GREEN}✅ 缓存清理完成${NC}"

echo ""
echo "========================================"
echo "✅ 部署完成！"
echo "========================================"
echo ""

echo "📊 功能清单："
echo "  ✅ 模板管理（创建、编辑、删除、复制）"
echo "  ✅ 模板分类（6种分类）"
echo "  ✅ 模板预览"
echo "  ✅ 变量支持"
echo "  ✅ 系统预设模板（4个）"
echo "  ✅ 搜索和筛选"
echo ""

echo "📝 下一步："
echo "  1. 访问前端：http://yourdomain.com/templates"
echo "  2. 查看预设模板"
echo "  3. 创建自定义模板"
echo "  4. 测试模板预览功能"
echo ""

echo "📖 详细说明："
echo "  查看文件：邮件模板系统部署说明.md"
echo ""

echo -e "${GREEN}🎉 部署成功！${NC}"

