# 🚨 SMTP 服务器自动暂停功能

## 功能概述

当检测到 "Excessive message rate from sender" 或其他频率限制错误时，系统会**自动暂停该 SMTP 服务器 5 分钟**，避免继续触发限制导致账号被封禁。

---

## ✨ 功能特性

### 1. 自动检测频率限制错误

系统会自动检测以下错误模式：

- ✅ `Excessive message rate`
- ✅ `Too many messages`
- ✅ `Rate limit exceeded`
- ✅ `Sending rate exceeded`
- ✅ `Throttle`
- ✅ `Quota exceeded`
- ✅ `Message rate limit`
- ✅ `451 4.7.0` - Temporary rate limit
- ✅ `452 4.2.1` - User exceeded max connections
- ✅ `421 4.7.0` - Too many errors from IP

### 2. 自动暂停机制

当检测到频率限制错误时：

1. **立即暂停** SMTP 服务器 5 分钟
2. **记录日志** 包含详细的暂停信息
3. **阻止发送** 该服务器在暂停期间不会被使用
4. **自动恢复** 5 分钟后自动解除暂停

### 3. 手动恢复

如果需要提前恢复服务器，可以通过 API 手动恢复。

---

## 📊 日志示例

### 自动暂停日志

```
[2024-12-25 10:30:15] WARNING: SMTP server auto-paused due to rate limit error
{
  "campaign_id": 123,
  "smtp_server_id": 5,
  "smtp_server_name": "Gmail SMTP",
  "error_message": "Excessive message rate from sender",
  "pause_duration": "5 minutes"
}
```

```
[2024-12-25 10:30:15] WARNING: SMTP server temporarily paused
{
  "server_id": 5,
  "server_name": "Gmail SMTP",
  "pause_minutes": 5,
  "paused_until": "2024-12-25 10:35:15",
  "reason": "Excessive message rate detected"
}
```

### 手动恢复日志

```
[2024-12-25 10:32:00] INFO: SMTP server resumed
{
  "server_id": 5,
  "server_name": "Gmail SMTP"
}
```

---

## 🔧 API 使用

### 1. 查看服务器状态（包含暂停信息）

**请求：**
```http
GET /api/smtp-servers/{serverId}/rate-limit-status
Authorization: Bearer YOUR_TOKEN
```

**响应：**
```json
{
  "data": {
    "periods": {
      "second": { "limit": 14, "current": 2, "available": 12, "percentage": 14.3, "status": "normal" },
      "minute": { "limit": 60, "current": 15, "available": 45, "percentage": 25.0, "status": "normal" },
      "hour": { "limit": 500, "current": 120, "available": 380, "percentage": 24.0, "status": "normal" },
      "day": { "limit": 5000, "current": 1200, "available": 3800, "percentage": 24.0, "status": "normal" }
    },
    "can_send": false,
    "blocked_by": "temporarily_paused",
    "max_available": 0,
    "most_restrictive": null,
    "wait_seconds": 180,
    "is_paused": true,
    "pause_remaining_seconds": 180
  }
}
```

### 2. 手动恢复服务器

**请求：**
```http
POST /api/smtp-servers/{serverId}/resume
Authorization: Bearer YOUR_TOKEN
```

**成功响应：**
```json
{
  "message": "服务器已恢复",
  "data": {
    "id": 5,
    "name": "Gmail SMTP",
    "is_active": true,
    ...
  }
}
```

**错误响应（服务器未被暂停）：**
```json
{
  "message": "服务器未被暂停"
}
```

---

## 🔍 系统行为

### 发送任务处理流程

```
1. 活动开始发送
   ↓
2. 获取可用的 SMTP 服务器
   ├─ 检查 is_active (是否激活)
   ├─ 检查 isTemporarilyPaused (是否被暂停)
   └─ 检查 rate limits (频率限制)
   ↓
3. 如果服务器被暂停
   ├─ 跳过该服务器
   └─ 尝试使用其他服务器
   ↓
4. 发送邮件
   ↓
5. 如果收到频率限制错误
   ├─ 自动暂停服务器 5 分钟
   ├─ 记录详细日志
   └─ 任务重新入队（使用其他服务器）
```

### 暂停状态检查

系统在以下时机检查暂停状态：

1. **canSend()** - 发送前检查
2. **checkRateLimits()** - 频率限制检查
3. **getRateLimitStatus()** - API 状态查询

---

## 💡 最佳实践

### 1. 配置多个 SMTP 服务器

```
建议配置至少 2-3 个 SMTP 服务器：
- 当一个服务器被暂停时，系统会自动使用其他服务器
- 避免因单个服务器暂停而导致发送完全停止
```

### 2. 合理设置频率限制

```php
// 在 SMTP 服务器设置中配置合理的限制
rate_limit_second: 14  // 每秒最多 14 封
rate_limit_minute: 60  // 每分钟最多 60 封
rate_limit_hour: 500   // 每小时最多 500 封
rate_limit_day: 5000   // 每天最多 5000 封
```

### 3. 监控日志

```bash
# 监控暂停事件
tail -f backend/storage/logs/laravel.log | grep "temporarily paused"

# 监控频率限制错误
tail -f backend/storage/logs/laravel.log | grep "rate limit"
```

### 4. 及时处理告警

- 如果频繁出现自动暂停，说明发送频率过高
- 建议降低活动发送速率或增加 SMTP 服务器
- 检查 SMTP 服务商的限制政策

---

## 🔐 技术实现

### 1. 暂停状态存储

使用 Laravel Cache（Redis）存储暂停状态：

```php
// 缓存键格式
Cache::put("smtp_server_paused_{$serverId}", $pausedUntil, $minutes * 60);

// 示例
Cache::put("smtp_server_paused_5", 1703475315, 300); // 暂停到时间戳 1703475315（5分钟）
```

### 2. 错误检测

使用正则表达式匹配多种频率限制错误：

```php
private function isRateLimitError(string $errorMessage): bool
{
    $patterns = [
        '/excessive message rate/i',
        '/too many messages/i',
        '/rate limit exceeded/i',
        // ... 更多模式
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $errorMessage)) {
            return true;
        }
    }
    
    return false;
}
```

### 3. 自动恢复

利用 Redis 的 TTL（Time To Live）自动过期机制：

```
- 暂停时设置缓存键，TTL = 300秒（5分钟）
- 5分钟后，Redis 自动删除缓存键
- 系统检查 isTemporarilyPaused() 返回 false
- 服务器自动恢复可用
```

---

## 🆘 故障排查

### Q1: 服务器一直被暂停怎么办？

**原因：** 可能是发送频率持续超过限制

**解决：**
```bash
# 1. 手动恢复服务器
POST /api/smtp-servers/{serverId}/resume

# 2. 降低发送速率
# 调整 SMTP 服务器的频率限制配置

# 3. 增加更多 SMTP 服务器
# 分散发送负载
```

### Q2: 如何查看暂停历史？

```bash
# 查看日志
grep "temporarily paused" backend/storage/logs/laravel.log

# 或使用 jq 格式化输出
grep "temporarily paused" backend/storage/logs/laravel.log | jq '.'
```

### Q3: 暂停功能不生效？

**检查清单：**
1. ✅ Redis 服务是否正常运行
2. ✅ Laravel Cache 配置是否正确
3. ✅ 错误消息是否匹配检测模式
4. ✅ 查看日志确认是否触发暂停逻辑

```bash
# 检查 Redis
redis-cli ping

# 查看 Cache 配置
cat backend/config/cache.php | grep default

# 测试 Cache
php artisan tinker
>>> Cache::put('test', 'value', 60);
>>> Cache::get('test');
```

---

## 📈 性能影响

- **CPU 影响：** 微乎其微（仅增加字符串匹配和缓存查询）
- **内存影响：** 每个暂停的服务器占用 < 100 bytes Redis 内存
- **延迟影响：** < 1ms（Redis 缓存查询）

---

## 🎯 总结

### 核心价值

1. ✅ **保护账号** - 避免因频率限制导致账号被封
2. ✅ **自动恢复** - 无需人工干预，5 分钟后自动恢复
3. ✅ **多服务器支持** - 自动切换到其他可用服务器
4. ✅ **详细日志** - 完整记录暂停和恢复事件
5. ✅ **灵活控制** - 支持手动恢复功能

### 修改的文件

1. `backend/app/Models/SmtpServer.php`
   - 添加 `isTemporarilyPaused()`
   - 添加 `getPauseRemainingTime()`
   - 添加 `pauseTemporarily()`
   - 添加 `resume()`
   - 更新 `canSend()` 和 `checkRateLimits()`

2. `backend/app/Jobs/SendCampaignEmail.php`
   - 添加错误检测逻辑
   - 添加 `isRateLimitError()` 方法
   - 自动暂停服务器

3. `backend/app/Http/Controllers/Api/SmtpServerController.php`
   - 更新 `getRateLimitStatus()` 返回暂停信息
   - 添加 `resume()` 方法

4. `backend/routes/api.php`
   - 添加 `/smtp-servers/{id}/resume` 路由

---

**功能已完成！** 🎉

现在系统会自动检测并处理频率限制错误，保护您的 SMTP 账号安全。

