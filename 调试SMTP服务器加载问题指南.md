# 调试 SMTP 服务器加载问题指南

## 问题现象

编辑活动时，偶尔会出现发送服务器无法加载的情况（约 10% 的概率）。

## 已添加的调试信息

### 1. 后端日志（Laravel）

在 `/api/smtp-servers` 端点添加了详细的日志：

**查看日志**：
```bash
# 实时监控日志
tail -f /data/www/sendwalk/backend/storage/logs/laravel-$(date +%Y-%m-%d).log | grep "SMTP Servers API"

# 或者查看最近的日志
tail -200 /data/www/sendwalk/backend/storage/logs/laravel-$(date +%Y-%m-%d).log | grep "SMTP Servers API"
```

**日志内容包括**：
- ✅ 请求ID（用于追踪单个请求）
- ✅ 用户ID和邮箱
- ✅ SQL 查询语句和绑定参数
- ✅ 查询结果数量
- ✅ 服务器ID列表和名称
- ✅ 响应数据结构
- ✅ 请求总耗时

**日志示例**：
```
[2025-12-27 15:00:00] local.INFO: [SMTP Servers API] ========== Request started ==========
{
  "request_id": "smtp_6762e1a0b3f5a",
  "user_id": 1,
  "user_email": "admin@example.com",
  "timestamp": "2025-12-27 15:00:00"
}

[2025-12-27 15:00:00] local.INFO: [SMTP Servers API] Query servers completed
{
  "request_id": "smtp_6762e1a0b3f5a",
  "count": 4,
  "time_ms": 2.5,
  "server_ids": [1, 2, 3, 4],
  "server_names": ["server1", "server2", "server3", "server4"],
  "servers_isEmpty": false,
  "servers_isNotEmpty": true
}

[2025-12-27 15:00:00] local.INFO: [SMTP Servers API] ========== Request completed ==========
{
  "request_id": "smtp_6762e1a0b3f5a",
  "total_time_ms": 15.2,
  "servers_count": 4,
  "final_server_ids": [1, 2, 3, 4]
}
```

### 2. 前端日志（浏览器控制台）

在浏览器开发者工具的 Console 标签中会看到：

**日志内容包括**：
- ✅ 请求ID（前端生成）
- ✅ API 响应状态码
- ✅ 响应数据结构和内容
- ✅ 解析后的服务器列表
- ✅ smtpServers 状态变化
- ✅ campaign 数据加载
- ✅ formData 更新

**日志示例**：
```javascript
[SMTP Servers] ========== Fetching ==========
{
  requestId: "frontend_1735296000000_abc123",
  timestamp: "2025-12-27T15:00:00.000Z"
}

[SMTP Servers] Response received
{
  requestId: "frontend_1735296000000_abc123",
  status: 200,
  hasData: true,
  dataKeys: ["data"],
  rawData: { data: [...] }
}

[SMTP Servers] Parsed servers
{
  requestId: "frontend_1735296000000_abc123",
  serversLength: 4,
  isEmpty: false,
  serverIds: [1, 2, 3, 4],
  serverNames: ["server1", "server2", "server3", "server4"]
}

[Campaign Editor] smtpServers changed
{
  hasSmtpServers: true,
  smtpServersLength: 4,
  isEmpty: false,
  serverIds: [1, 2, 3, 4],
  currentFormDataServerId: "3"
}
```

## 重现和诊断步骤

### 步骤 1：重现问题

1. 打开正式环境 `https://edm.sendwalk.com/campaigns/20/edit`
2. 打开浏览器开发者工具（F12）
3. 切换到 **Console** 标签
4. 切换到 **Network** 标签，筛选 `/smtp-servers`
5. 反复刷新页面（建议 20-30 次）
6. 观察是否出现服务器未加载的情况

### 步骤 2：收集日志

**当问题出现时，立即收集以下信息：**

#### A. 浏览器 Console 日志

在 Console 中查找：
1. **SMTP Servers 日志**：
   - 查看 `[SMTP Servers] Response received` 的 `rawData`
   - 检查 `isEmpty` 是否为 `true`
   - 检查 `serversLength` 是否为 `0` 或 `null`

2. **smtpServers 状态日志**：
   - 查看 `[Campaign Editor] smtpServers changed`
   - 检查 `isEmpty` 和 `smtpServersLength`

3. **完整的请求ID**：
   - 记录 `requestId`（如 `frontend_1735296000000_abc123`）

#### B. Network 标签

1. 找到 `/api/smtp-servers` 请求
2. 查看 **Headers**：
   - Request URL
   - Status Code
   - Request Headers（特别是 Authorization）
3. 查看 **Response**：
   - **如果为空**，这是关键信息！
   - 截图或复制完整响应

#### C. 后端日志

在服务器上：
```bash
# 查找对应时间点的日志
grep "SMTP Servers API" /data/www/sendwalk/backend/storage/logs/laravel-$(date +%Y-%m-%d).log | tail -100

# 或者搜索特定的 request_id（如果能从前端日志匹配）
grep "smtp_6762e1a0b3f5a" /data/www/sendwalk/backend/storage/logs/laravel-$(date +%Y-%m-%d).log
```

**关键检查点**：
- ✅ 查询是否执行：`[SMTP Servers API] Query servers completed`
- ✅ `count` 是多少？
- ✅ `servers_isEmpty` 是 true 还是 false？
- ✅ `server_ids` 数组是否为空？

### 步骤 3：对比正常和异常情况

**正常情况**：
```
后端日志：count = 4, server_ids = [1, 2, 3, 4]
前端Console：serversLength = 4, isEmpty = false
Network：Response 有内容 { data: [...4 items] }
```

**异常情况（可能的几种情况）**：

#### 情况 A：后端查询就是空
```
后端日志：count = 0, server_ids = []
前端Console：serversLength = 0, isEmpty = true
Network：Response { data: [] }
```
→ **问题在数据库查询**

#### 情况 B：后端查询有数据，但响应为空
```
后端日志：count = 4, server_ids = [1, 2, 3, 4]
前端Console：serversLength = 0, isEmpty = true
Network：Response 为空或 { data: [] }
```
→ **问题在响应构建或传输过程**

#### 情况 C：后端和 Network 都正常，但前端解析失败
```
后端日志：count = 4
Network：Response { data: [...4 items] }
前端Console：serversLength = 0 或 null
```
→ **问题在前端数据解析**

## 可能的原因和解决方案

### 原因 1：数据库连接池耗尽

**症状**：
- 后端日志中 `count = 0`
- SQL 查询执行了但没有结果

**检查**：
```bash
# 查看数据库连接数
mysql -u root -p -e "SHOW PROCESSLIST;"
```

**解决**：增加数据库连接池大小

### 原因 2：缓存问题

**症状**：
- 有时有数据，有时没有
- 后端日志正常但响应为空

**检查**：
```bash
php artisan cache:clear
php artisan config:clear
```

### 原因 3：Eloquent 序列化问题

**症状**：
- 后端查询有数据
- 但 JSON 响应为空

**检查后端日志**：
- 看 `Preparing response` 中的 `response_data_count`
- 如果是 4 但响应为空，说明序列化有问题

### 原因 4：中间件拦截

**症状**：
- 某些请求被拦截
- 状态码可能是 401 或 403

**检查 Network Headers**：
- 看是否有 Authorization token
- 检查 token 是否过期

### 原因 5：竞态条件（前端）

**症状**：
- Network 有数据
- 但前端状态为空

**检查前端日志**：
- `smtpServers changed` 是否多次触发
- 是否有后续的更新覆盖了数据

## 下一步行动

根据收集到的日志，我们可以：

1. **如果后端日志显示 `count = 0`**：
   - 检查数据库
   - 检查用户ID是否正确
   - 检查是否有软删除的问题

2. **如果后端有数据但响应为空**：
   - 检查 `rate_limit_status` 构建是否有异常
   - 检查 `getPausedSenders()` 是否有问题
   - 可能需要添加 try-catch

3. **如果响应正常但前端解析失败**：
   - 检查 React Query 的缓存策略
   - 检查是否有其他地方清空了数据

## 临时解决方案

在找到根本原因之前，可以添加重试机制：

```typescript
const { data: smtpServers } = useQuery<SmtpServer[]>({
  queryKey: ['smtp-servers'],
  queryFn: async () => {
    // ... 获取数据
  },
  retry: 3,  // 失败时重试 3 次
  retryDelay: 1000,  // 每次重试间隔 1 秒
  staleTime: 30000,  // 30秒内使用缓存
})
```

## 联系方式

如果收集到异常日志，请提供：
1. 完整的前端 Console 日志（截图或文本）
2. 对应时间点的后端日志（最近 100 行）
3. Network 标签中的完整请求和响应
4. 出现问题的确切时间

这样我们就能精确定位问题所在！

