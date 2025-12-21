# SendWalk 开发指南

## 项目结构

```
sendwalk/
├── frontend/                # React 前端应用
│   ├── src/
│   │   ├── components/     # 可复用组件
│   │   │   └── ui/        # shadcn/ui 组件
│   │   ├── layouts/        # 布局组件
│   │   ├── pages/          # 页面组件
│   │   ├── stores/         # Zustand 状态管理
│   │   ├── lib/            # 工具函数和配置
│   │   ├── App.tsx         # 根组件
│   │   └── main.tsx        # 入口文件
│   ├── public/             # 静态资源
│   └── package.json        # 依赖配置
│
├── backend/                # Laravel 后端应用
│   ├── app/
│   │   ├── Console/        # 命令行工具
│   │   ├── Http/
│   │   │   ├── Controllers/  # 控制器
│   │   │   └── Middleware/   # 中间件
│   │   ├── Jobs/           # 队列任务
│   │   ├── Models/         # 数据模型
│   │   ├── Policies/       # 授权策略
│   │   └── Services/       # 业务服务
│   ├── config/             # 配置文件
│   ├── database/
│   │   ├── migrations/     # 数据库迁移
│   │   └── seeders/        # 数据填充
│   ├── routes/             # 路由定义
│   └── composer.json       # 依赖配置
│
├── docker/                 # Docker 配置
│   ├── backend/
│   ├── nginx/
│   └── mysql/
│
├── docs/                   # 项目文档
│   ├── api.md
│   ├── deployment.md
│   └── database-schema.md
│
├── docker-compose.yml      # Docker Compose 配置
└── README.md               # 项目说明
```

## 技术栈说明

### 前端技术栈

#### 核心框架
- **React 18+**: UI 框架
- **TypeScript**: 类型安全
- **Vite**: 构建工具

#### 状态管理
- **Zustand**: 轻量级全局状态管理
- **TanStack Query v5**: 服务端状态管理和缓存

#### UI 框架
- **Tailwind CSS 3+**: CSS 工具类框架
- **shadcn/ui**: 基于 Radix UI 的组件库
- **Lucide React**: 图标库
- **Framer Motion**: 动画库

#### 表单处理
- **React Hook Form**: 表单管理
- **Zod**: 表单验证

#### 专用库
- **ReactFlow**: 自动化流程编辑器
- **Recharts**: 数据可视化
- **TanStack Table v8**: 表格组件
- **React Dropzone**: 文件上传

### 后端技术栈

#### 核心框架
- **PHP 8.3+**: 编程语言
- **Laravel 11.x**: Web 框架

#### 数据库
- **MySQL 8.0+**: 主数据库
- **Redis 7+**: 缓存和队列

#### 认证和授权
- **Laravel Sanctum**: API 认证
- **Spatie Laravel Permission**: 角色权限管理

#### 队列和任务
- **Laravel Queue**: 队列系统
- **Laravel Horizon**: 队列监控

#### 邮件服务
- 支持多种 ESP：
  - SMTP
  - Amazon SES
  - SendGrid
  - Mailgun
  - Postmark

## 开发规范

### 代码风格

#### 前端
- 使用 ESLint 进行代码检查
- 使用 Prettier 格式化代码
- 遵循 React Hooks 最佳实践
- 组件采用函数式组件
- 使用 TypeScript 类型注解

```typescript
// 组件示例
interface ButtonProps {
  onClick: () => void
  children: React.ReactNode
  variant?: 'primary' | 'secondary'
}

export function Button({ onClick, children, variant = 'primary' }: ButtonProps) {
  return (
    <button onClick={onClick} className={cn('btn', `btn-${variant}`)}>
      {children}
    </button>
  )
}
```

#### 后端
- 遵循 PSR-12 编码规范
- 使用 Laravel Pint 格式化代码
- 控制器方法保持简洁，复杂逻辑放入 Service 层
- 使用 FormRequest 进行表单验证
- 使用 Policy 进行授权

```php
// 控制器示例
public function store(CreateCampaignRequest $request)
{
    $this->authorize('create', Campaign::class);
    
    $campaign = $this->campaignService->create(
        $request->user(),
        $request->validated()
    );
    
    return response()->json([
        'message' => '创建成功',
        'data' => $campaign,
    ], 201);
}
```

### Git 工作流

#### 分支策略
- `main`: 生产环境分支
- `develop`: 开发分支
- `feature/*`: 功能分支
- `bugfix/*`: 错误修复分支
- `hotfix/*`: 紧急修复分支

#### 提交信息规范
遵循 Conventional Commits 规范：

```
<type>(<scope>): <subject>

<body>

<footer>
```

类型：
- `feat`: 新功能
- `fix`: 错误修复
- `docs`: 文档更新
- `style`: 代码格式（不影响代码运行）
- `refactor`: 重构
- `perf`: 性能优化
- `test`: 测试相关
- `chore`: 构建过程或辅助工具的变动

示例：
```
feat(campaigns): add A/B testing support

- Add A/B test configuration in campaign model
- Implement split testing logic
- Add analytics for variant performance

Closes #123
```

## 功能实现指南

### 1. 添加新的 API 端点

#### 后端步骤

1. **创建模型和迁移**
```bash
php artisan make:model Feature -m
```

2. **编写迁移文件**
```php
// database/migrations/xxxx_create_features_table.php
public function up()
{
    Schema::create('features', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained();
        $table->string('name');
        $table->timestamps();
    });
}
```

3. **创建控制器**
```bash
php artisan make:controller Api/FeatureController --api
```

4. **添加路由**
```php
// routes/api.php
Route::apiResource('features', FeatureController::class);
```

5. **创建 FormRequest**
```bash
php artisan make:request StoreFeatureRequest
```

6. **创建 Policy**
```bash
php artisan make:policy FeaturePolicy --model=Feature
```

#### 前端步骤

1. **创建 API 服务**
```typescript
// src/lib/api.ts
export async function getFeatures() {
  return fetcher<Feature[]>('/features')
}

export async function createFeature(data: CreateFeatureData) {
  return api.post('/features', data)
}
```

2. **创建页面组件**
```typescript
// src/pages/features/index.tsx
export default function FeaturesPage() {
  const { data, isLoading } = useQuery({
    queryKey: ['features'],
    queryFn: getFeatures,
  })
  
  // ...
}
```

### 2. 添加新的自动化节点类型

1. **定义节点类型**
```typescript
// frontend/src/types/automation.ts
export interface DelayNode extends BaseNode {
  type: 'delay'
  data: {
    duration: number
    unit: 'minutes' | 'hours' | 'days'
  }
}
```

2. **创建节点组件**
```typescript
// frontend/src/components/automation/nodes/DelayNode.tsx
export function DelayNode({ data }: NodeProps<DelayNode>) {
  return (
    <div className="automation-node">
      <ClockIcon />
      <span>等待 {data.duration} {data.unit}</span>
    </div>
  )
}
```

3. **后端处理逻辑**
```php
// app/Services/AutomationService.php
public function processDelayNode($node, $subscriber)
{
    $delay = $node['data']['duration'];
    $unit = $node['data']['unit'];
    
    ProcessNextStep::dispatch($automation, $subscriber)
        ->delay(now()->add($delay, $unit));
}
```

### 3. 集成新的邮件服务提供商

1. **添加服务商类型**
```php
// database/migrations/xxxx_add_new_provider.php
// 在 smtp_servers 表的 type 枚举中添加新类型
```

2. **实现发送逻辑**
```php
// app/Services/EmailService.php
private function configureMailer(SmtpServer $smtpServer): void
{
    switch ($smtpServer->type) {
        case 'new_provider':
            // 配置新服务商
            break;
    }
}
```

3. **添加前端配置界面**
```typescript
// frontend/src/pages/settings/smtp-servers.tsx
// 添加新服务商的配置表单
```

## 测试指南

### 后端测试

#### 单元测试
```bash
php artisan test --filter=CampaignTest
```

```php
// tests/Unit/CampaignTest.php
public function test_campaign_can_be_created()
{
    $user = User::factory()->create();
    $list = MailingList::factory()->create(['user_id' => $user->id]);
    
    $campaign = Campaign::create([
        'user_id' => $user->id,
        'list_id' => $list->id,
        'name' => 'Test Campaign',
        'subject' => 'Test Subject',
        // ...
    ]);
    
    $this->assertDatabaseHas('campaigns', [
        'name' => 'Test Campaign',
    ]);
}
```

#### 功能测试
```php
// tests/Feature/CampaignApiTest.php
public function test_user_can_create_campaign()
{
    $user = User::factory()->create();
    $list = MailingList::factory()->create(['user_id' => $user->id]);
    
    $response = $this->actingAs($user)
        ->postJson('/api/campaigns', [
            'list_id' => $list->id,
            'name' => 'Test Campaign',
            'subject' => 'Test Subject',
            // ...
        ]);
    
    $response->assertStatus(201)
        ->assertJson([
            'message' => '邮件活动创建成功',
        ]);
}
```

### 前端测试

#### 组件测试
```typescript
// src/components/Button.test.tsx
import { render, fireEvent } from '@testing-library/react'
import { Button } from './Button'

describe('Button', () => {
  it('calls onClick when clicked', () => {
    const handleClick = jest.fn()
    const { getByText } = render(
      <Button onClick={handleClick}>Click me</Button>
    )
    
    fireEvent.click(getByText('Click me'))
    expect(handleClick).toHaveBeenCalledTimes(1)
  })
})
```

## 性能优化

### 前端优化

1. **代码分割**
```typescript
// 使用动态导入
const CampaignEditor = lazy(() => import('@/pages/campaigns/editor'))
```

2. **查询优化**
```typescript
// 使用 TanStack Query 的缓存和预取
const { data } = useQuery({
  queryKey: ['campaigns'],
  queryFn: getCampaigns,
  staleTime: 5 * 60 * 1000, // 5分钟
})
```

3. **虚拟滚动**
```typescript
// 对于长列表使用虚拟滚动
import { useVirtualizer } from '@tanstack/react-virtual'
```

### 后端优化

1. **数据库查询优化**
```php
// 使用 eager loading 避免 N+1 问题
$campaigns = Campaign::with('list', 'sends')->get();

// 使用索引
$campaigns = Campaign::where('status', 'sent')
    ->orderBy('created_at', 'desc')
    ->get();
```

2. **缓存策略**
```php
// 缓存频繁访问的数据
$stats = Cache::remember('dashboard.stats.' . $userId, 3600, function () use ($userId) {
    return $this->calculateStats($userId);
});
```

3. **队列优化**
```php
// 批量处理
SendCampaignEmail::dispatch($campaign, $subscribers)
    ->onQueue('high-priority');
```

## 调试技巧

### 前端调试

1. **React DevTools**: 查看组件树和状态
2. **TanStack Query DevTools**: 查看查询状态
3. **浏览器开发者工具**: 网络请求和性能分析

### 后端调试

1. **Laravel Telescope**: API 请求和查询分析
```bash
composer require laravel/telescope --dev
php artisan telescope:install
```

2. **日志记录**
```php
Log::info('Campaign sending', [
    'campaign_id' => $campaign->id,
    'recipients' => $recipients->count(),
]);
```

3. **Horizon Dashboard**: 队列监控
```
http://localhost:8000/horizon
```

## 常见问题

### Q: 如何添加自定义邮件头？

A: 在 `EmailService` 中修改：
```php
$message->getHeaders()->addTextHeader('X-Custom-Header', 'value');
```

### Q: 如何实现邮件限流？

A: 使用 Redis 和 Laravel 的 RateLimiter：
```php
RateLimiter::attempt(
    'send-email:' . $smtpServer->id,
    $smtpServer->rate_limit_hour,
    function() use ($campaign, $subscriber) {
        $this->sendEmail($campaign, $subscriber);
    }
);
```

### Q: 如何处理大量订阅者导入？

A: 使用队列批处理：
```php
Bus::batch([
    new ImportSubscribersBatch($file, $listId),
])->dispatch();
```

## 资源链接

- [React 官方文档](https://react.dev/)
- [Laravel 官方文档](https://laravel.com/docs)
- [TanStack Query 文档](https://tanstack.com/query)
- [Tailwind CSS 文档](https://tailwindcss.com/docs)
- [shadcn/ui 文档](https://ui.shadcn.com/)

