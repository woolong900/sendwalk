# SMTP 服务器接口性能优化

## 🐛 问题描述

**接口**: `GET /api/smtp-servers`  
**响应时间**: 33 秒（严重性能问题！）  
**正常响应时间**: < 500ms

## 🔍 问题分析

### 性能瓶颈定位

已添加详细的性能日志，现在可以通过日志查看每个步骤的耗时：

```bash
# 查看实时日志
tail -f /data/www/sendwalk/backend/storage/logs/laravel.log | grep "SMTP Servers API"

# 或者查看最近的日志
tail -100 /data/www/sendwalk/backend/storage/logs/laravel.log | grep "SMTP Servers API"
```

### 预期瓶颈

根据代码分析，问题在于：

```php
// SmtpServerController::index()
$servers->each(function ($server) {
    $rateLimitStatus = $server->getRateLimitStatus();  // ← 这里很慢！
    $server->rate_limit_status = $rateLimitStatus['periods'] ?? [];
});
```

**`getRateLimitStatus()` 做了什么？**

```php
// 对每个 SMTP 服务器，查询 3 次 send_logs 表
$periods = [
    'second' => $this->countInSlidingWindow('second', 1),     // 查询 1
    'minute' => $this->countInSlidingWindow('minute', 60),    // 查询 2
    'hour' => $this->countInSlidingWindow('hour', 3600),      // 查询 3
    'day' => $this->emails_sent_today,
];

// 每次 countInSlidingWindow() 执行的 SQL：
// SELECT COUNT(*) FROM send_logs 
// WHERE smtp_server_id = ? 
// AND status IN ('sent', 'failed') 
// AND created_at >= ?
```

**性能问题**：

| SMTP 服务器数量 | 查询次数 | 如果 send_logs 有 100万条 | 总耗时估算 |
|----------------|---------|------------------------|-----------|
| 1 个 | 3 次 | 每次 ~1-3s | ~3-9s |
| 5 个 | 15 次 | 每次 ~1-3s | ~15-45s |
| 10 个 | 30 次 | 每次 ~1-3s | ~30-90s |

**你的情况**：33 秒响应时间，很可能是：
- 有多个 SMTP 服务器（5-10个）
- `send_logs` 表有大量数据（几十万到上百万条）
- 每次查询都要扫描大量数据

### 验证假设

执行以下命令查看数据量：

```bash
cd /data/www/sendwalk/backend
php artisan tinker

# 查看 SMTP 服务器数量
>>> \App\Models\SmtpServer::count()

# 查看 send_logs 数量
>>> \App\Models\SendLog::count()

# 查看最近 1 小时的 send_logs
>>> \App\Models\SendLog::where('created_at', '>=', now()->subHour())->count()
```

## ✅ 解决方案

### 方案 1: 快速修复 - 不在列表接口返回速率限制（推荐）⚡

**原理**：速率限制状态只在发送邮件时需要，列表展示不需要。

```php
public function index(Request $request)
{
    $servers = SmtpServer::where('user_id', $request->user()->id)
        ->latest()
        ->get();

    // ✅ 移除速率限制查询，只返回基本信息
    // $servers->each(function ($server) {
    //     $rateLimitStatus = $server->getRateLimitStatus();
    //     $server->rate_limit_status = $rateLimitStatus['periods'] ?? [];
    // });

    return response()->json([
        'data' => $servers,
    ]);
}
```

**效果**：
- 响应时间从 33 秒降到 < 100ms
- 减少 N*3 次数据库查询（N = 服务器数量）

**前端调整**：
- 如果前端需要速率限制状态，创建单独的接口 `/api/smtp-servers/{id}/rate-limit`
- 只在需要时调用（如发送邮件前检查）

### 方案 2: 使用 Redis 缓存速率限制状态 🔧

**原理**：将速率限制状态缓存到 Redis，避免每次都查询数据库。

```php
public function getRateLimitStatus(): array
{
    $cacheKey = "smtp_server:{$this->id}:rate_limit_status";
    
    // 尝试从缓存获取（缓存 5 秒）
    return Cache::remember($cacheKey, 5, function () {
        $periods = [
            'second' => $this->countInSlidingWindow('second', 1),
            'minute' => $this->countInSlidingWindow('minute', 60),
            'hour' => $this->countInSlidingWindow('hour', 3600),
            'day' => $this->emails_sent_today,
        ];
        
        // ... 其他逻辑 ...
        
        return [
            'periods' => $status,
            // ...
        ];
    });
}
```

**效果**：
- 第一次请求仍然慢，后续 5 秒内的请求直接从缓存返回
- 响应时间降到 < 50ms（缓存命中时）

### 方案 3: 添加索引优化查询（已包含在性能优化迁移中）📊

**原理**：为 `send_logs` 表添加复合索引，加速 `countInSlidingWindow` 查询。

```php
// 已在 add_indexes_to_send_logs_table.php 中包含
$table->index(['smtp_server_id', 'created_at', 'status'], 'idx_server_time_status');
```

**效果**：
- 每次查询从 1-3 秒降到 50-200ms
- 如果有 10 个服务器，总耗时从 30-90 秒降到 1.5-6 秒

### 方案 4: 异步加载速率限制状态（前端优化）🎨

**原理**：列表接口快速返回基本信息，速率限制状态由前端单独请求。

**后端**：
```php
// 1. 列表接口不返回速率限制
public function index(Request $request)
{
    return response()->json([
        'data' => SmtpServer::where('user_id', $request->user()->id)->latest()->get(),
    ]);
}

// 2. 新增单独的速率限制接口
public function batchRateLimits(Request $request)
{
    $serverIds = $request->input('server_ids', []);
    $rateLimits = [];
    
    foreach ($serverIds as $serverId) {
        $server = SmtpServer::find($serverId);
        if ($server && $server->user_id === $request->user()->id) {
            $rateLimits[$serverId] = $server->getRateLimitStatus();
        }
    }
    
    return response()->json(['data' => $rateLimits]);
}
```

**前端**：
```typescript
// 1. 快速加载列表
const servers = await api.get('/smtp-servers')

// 2. 异步加载速率限制（可选）
const rateLimits = await api.post('/smtp-servers/batch-rate-limits', {
  server_ids: servers.map(s => s.id)
})
```

## 📋 实施建议

### 立即实施（方案 1）⚡

**步骤 1**: 修改 SmtpServerController

```bash
cd /data/www/sendwalk/backend
nano app/Http/Controllers/Api/SmtpServerController.php
```

注释掉或删除速率限制查询：

```php
public function index(Request $request)
{
    $servers = SmtpServer::where('user_id', $request->user()->id)
        ->latest()
        ->get();

    // 暂时移除速率限制查询以提升性能
    // TODO: 考虑使用缓存或单独接口
    // $servers->each(function ($server) {
    //     $rateLimitStatus = $server->getRateLimitStatus();
    //     $server->rate_limit_status = $rateLimitStatus['periods'] ?? [];
    // });

    return response()->json([
        'data' => $servers,
    ]);
}
```

**步骤 2**: 清除缓存

```bash
php artisan config:clear
php artisan route:clear
php artisan cache:clear
```

**步骤 3**: 测试

```bash
curl -X GET https://api.sendwalk.com/api/smtp-servers \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -w "\nTime: %{time_total}s\n"
```

预期响应时间：< 500ms

### 计划实施（方案 2 + 3）🔧

**步骤 1**: 运行性能优化迁移（添加索引）

```bash
cd /data/www/sendwalk/backend
php artisan migrate --force
```

**步骤 2**: 实施 Redis 缓存

修改 `SmtpServer` 模型的 `getRateLimitStatus` 方法（见方案 2）。

**步骤 3**: 测试

```bash
# 第一次请求（填充缓存）
time curl -X GET https://api.sendwalk.com/api/smtp-servers/1/rate-limit-status

# 第二次请求（使用缓存）
time curl -X GET https://api.sendwalk.com/api/smtp-servers/1/rate-limit-status
```

## 🔍 查看性能日志

已添加详细日志，可以查看每个步骤的耗时：

```bash
# 实时查看日志
tail -f /data/www/sendwalk/backend/storage/logs/laravel.log

# 过滤 SMTP Servers API 相关日志
tail -f /data/www/sendwalk/backend/storage/logs/laravel.log | grep "SMTP Servers API"

# 查看详细的滑动窗口查询日志
tail -f /data/www/sendwalk/backend/storage/logs/laravel.log | grep "SmtpServer"
```

### 日志格式

```json
[2025-12-22 19:30:00] INFO: [SMTP Servers API] Request started
{
  "user_id": 1,
  "timestamp": "2025-12-22 19:30:00"
}

[2025-12-22 19:30:00] INFO: [SMTP Servers API] Query servers completed
{
  "count": 5,
  "time_ms": 45.23
}

[2025-12-22 19:30:05] INFO: [SMTP Servers API] Processing server rate limits
{
  "server_id": 1,
  "server_name": "Server 1"
}

[2025-12-22 19:30:10] DEBUG: [SmtpServer] Counting sliding window
{
  "server_id": 1,
  "period": "second",
  "duration_seconds": 1,
  "start_time": "2025-12-22 19:29:59"
}

[2025-12-22 19:30:11] DEBUG: [SmtpServer] Sliding window count completed
{
  "server_id": 1,
  "period": "second",
  "count": 10,
  "time_ms": 1234.56  ← 这里会显示查询耗时
}

[2025-12-22 19:30:33] INFO: [SMTP Servers API] Request completed
{
  "total_time_ms": 33000.00  ← 总耗时
}
```

## 📊 性能对比

### 优化前

| 指标 | 值 |
|-----|---|
| 响应时间 | 33,000 ms (33秒) |
| 数据库查询 | 30+ 次 |
| CPU 使用 | 高 |
| 用户体验 | 极差 |

### 优化后（方案 1）

| 指标 | 值 |
|-----|---|
| 响应时间 | < 100 ms |
| 数据库查询 | 1 次 |
| CPU 使用 | 低 |
| 用户体验 | 优秀 |

### 优化后（方案 2 + 3）

| 指标 | 值 |
|-----|---|
| 响应时间（缓存命中） | < 50 ms |
| 响应时间（缓存未命中） | 500-2000 ms |
| 数据库查询 | 1-30 次（取决于缓存） |
| CPU 使用 | 低 |
| 用户体验 | 良好 |

## ⚠️ 注意事项

### 方案 1 的影响

**前端可能受影响的地方**：

1. SMTP 服务器列表页面
   - 如果显示速率限制状态，需要单独加载
   
2. 发送活动前的检查
   - 仍然可以在发送时调用 `getRateLimitStatus()`

**建议**：
- 检查前端代码中是否使用了 `rate_limit_status` 字段
- 如果使用了，考虑：
  - 移除显示（最简单）
  - 单独加载（方案 4）
  - 使用缓存（方案 2）

### 方案 2 需要 Redis

确保 Redis 已安装并配置：

```bash
# 检查 Redis 是否运行
redis-cli ping
# 应该返回 PONG

# 检查 Laravel 配置
cd /data/www/sendwalk/backend
php artisan tinker
>>> config('cache.default')
# 应该是 'redis'
```

## ✅ 验证清单

优化后，验证以下内容：

- [ ] `/api/smtp-servers` 响应时间 < 500ms
- [ ] 前端 SMTP 服务器列表正常显示
- [ ] 可以创建/编辑 SMTP 服务器
- [ ] 发送邮件功能正常
- [ ] 日志中没有错误

## 🎯 总结

**问题根源**：
- 每次请求列表都要为每个服务器查询 3 次 `send_logs` 表
- `send_logs` 表数据量大，查询慢
- 没有索引优化

**推荐方案**：
1. ⚡ **立即**：方案 1（移除列表接口的速率限制查询）
2. 🔧 **短期**：方案 3（添加索引，已在性能优化迁移中）
3. 📊 **中期**：方案 2（Redis 缓存）
4. 🎨 **长期**：方案 4（前端异步加载）

**预期效果**：
- 响应时间从 33 秒降到 < 100ms
- 用户体验显著改善

---

**实施优先级**：立即实施方案 1，然后根据需求考虑其他方案。

