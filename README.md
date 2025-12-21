# SendWalk - 邮件营销管理平台

一个功能完整的邮件营销管理平台，类似 MailWizz，支持邮件列表管理、邮件活动创建、自动化营销流程和数据分析。

## 技术栈

### 前端
- React 18+ + TypeScript
- Vite
- Tailwind CSS 3+
- shadcn/ui
- Zustand (全局状态管理)
- TanStack Query v5 (服务端状态)
- React Hook Form + Zod (表单处理)
- ReactFlow (自动化流程编辑器)
- Recharts (数据可视化)

### 后端
- PHP 8.3+
- Laravel 11.x
- MySQL 8.0+
- Redis 7+
- Laravel Sanctum (API认证)
- Laravel Horizon (队列监控)
- Laravel Queue (任务队列)

## 核心功能

1. **用户与权限管理** - 用户注册、登录、角色权限控制
2. **订阅者列表管理** - 批量导入、自定义字段、列表分段
3. **邮件编辑器** - 可视化拖拽编辑器、模板库、A/B测试
4. **邮件发送管理** - 多SMTP服务器、队列管理、速率限制
5. **自动化营销流程** - 可视化流程构建、触发器、条件分支
6. **数据追踪与分析** - 打开率、点击率、可视化报表

## 项目结构

```
sendwalk/
├── frontend/          # React 前端应用
├── backend/           # Laravel 后端应用
├── docker/            # Docker 配置文件
├── docs/              # 项目文档
└── docker-compose.yml # Docker Compose 配置
```

## 快速开始

### 前置要求

- Node.js 18+
- PHP 8.3+
- Composer
- MySQL 8.0+
- Redis 7+
- Docker & Docker Compose (可选)

### 使用 Docker 开发

```bash
# 启动所有服务
docker-compose up -d

# 安装后端依赖
docker-compose exec backend composer install

# 运行数据库迁移
docker-compose exec backend php artisan migrate --seed

# 安装前端依赖
cd frontend
npm install

# 启动前端开发服务器
npm run dev
```

### 本地开发

#### 后端设置

```bash
cd backend

# 安装依赖
composer install

# 复制环境配置
cp .env.example .env

# 生成应用密钥
php artisan key:generate

# 配置数据库和Redis连接（编辑 .env 文件）

# 运行迁移和填充数据
php artisan migrate --seed

# 启动队列worker
php artisan queue:work

# 启动开发服务器
php artisan serve
```

#### 前端设置

```bash
cd frontend

# 安装依赖
npm install

# 复制环境配置
cp .env.example .env

# 启动开发服务器
npm run dev
```

## 环境变量配置

### 后端 (.env)

```env
APP_URL=http://localhost:8000
FRONTEND_URL=http://localhost:5173

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sendwalk
DB_USERNAME=root
DB_PASSWORD=

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

QUEUE_CONNECTION=redis
```

### 前端 (.env)

```env
VITE_API_URL=http://localhost:8000/api
```

## 开发指南

### 代码规范

- 前端使用 ESLint + Prettier
- 后端遵循 PSR-12 编码规范
- Git 提交信息遵循 Conventional Commits

### 数据库迁移

```bash
# 创建新迁移
php artisan make:migration create_xxx_table

# 运行迁移
php artisan migrate

# 回滚迁移
php artisan migrate:rollback
```

### 队列管理

```bash
# 启动队列worker
php artisan queue:work

# 启动Horizon (推荐)
php artisan horizon

# 重启队列worker
php artisan queue:restart
```

## API 文档

API 文档可在 `/docs/api.md` 查看，或在开发环境访问 Swagger UI：
- 开发环境: http://localhost:8000/api/documentation

## 测试

```bash
# 后端测试
cd backend
php artisan test

# 前端测试
cd frontend
npm run test
```

## 部署

请参考 `/docs/deployment.md` 文档。

## 许可证

MIT License

## 贡献

欢迎提交 Issue 和 Pull Request。

