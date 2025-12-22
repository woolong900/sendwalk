# {sender_domain} 标签替换说明

## 🎯 问题

用户创建活动时如果没有设置发件人（`from_email`），系统会使用发件服务器的发件人进行发送。那么邮件内容中的 `{sender_domain}` 标签能否被正确替换？

## ✅ 答案

**能够被正确替换！** 系统设计时已经考虑到了这个场景。

## 📋 工作流程

### 1. 确定发件人邮箱

**文件**: `backend/app/Jobs/SendCampaignEmail.php`

**位置**: `handle` 方法第 150-154 行

```php
// Determine from_email: use campaign's or randomly select from server's pool
$this->fromEmail = $this->campaign->from_email;
if (empty($this->fromEmail)) {
    $this->fromEmail = $this->getRandomSenderEmail($smtpServer);
}
```

**流程**：
```
检查活动的 from_email
    ↓
是否为空？
    ↓
  否                     是
    ↓                     ↓
使用活动的发件人      从服务器发件人池中选择
    ↓                     ↓
$this->fromEmail      $this->fromEmail
```

### 2. 从服务器获取发件人

**方法**: `getRandomSenderEmail(SmtpServer $smtpServer)`

**位置**: 第 407 行开始

```php
private function getRandomSenderEmail(SmtpServer $smtpServer): string
{
    return \DB::transaction(function() use ($smtpServer) {
        // Lock the row for update to prevent race conditions
        $server = SmtpServer::lockForUpdate()->find($smtpServer->id);
        
        if (empty($server->sender_emails)) {
            throw new \Exception('Campaign from_email is empty and SMTP server has no sender emails configured');
        }

        // Parse sender_emails (one email per line)
        $emails = array_filter(
            array_map('trim', explode("\n", $server->sender_emails)),
            function($email) {
                return filter_var($email, FILTER_VALIDATE_EMAIL);
            }
        );
        
        // Round-robin selection
        // ...
    });
}
```

### 3. 替换标签

**方法**: `replacePersonalizationTags()`

**位置**: 第 323 行开始

```php
private function replacePersonalizationTags(string $content, Subscriber $subscriber): string
{
    $senderDomain = $this->getSenderDomain();
    // ...
    
    $systemReplacements = [
        '{campaign_id}' => $this->campaign->id,
        '{date}' => date('md'),
        '{list_name}' => $listName,
        '{server_name}' => $serverName,
        '{sender_domain}' => $senderDomain, // ✅ 这里替换
        '{unsubscribe_url}' => $unsubscribeUrl,
    ];
    
    // 替换所有花括号标签
    $content = str_replace(array_keys($replacements), array_values($replacements), $content);
    
    return $content;
}
```

### 4. 提取发件人域名

**方法**: `getSenderDomain()`

**位置**: 第 378 行开始

```php
private function getSenderDomain(): string
{
    // Use the determined from_email (either from campaign or randomly selected)
    $fromEmail = $this->fromEmail ?? $this->campaign->from_email ?? '';
    if (empty($fromEmail)) {
        return '';
    }
    $parts = explode('@', $fromEmail);
    return $parts[1] ?? '';
}
```

**示例**：
```
$this->fromEmail = "noreply@sendwalk.com"
    ↓
explode('@', ...)
    ↓
$parts = ["noreply", "sendwalk.com"]
    ↓
return $parts[1]
    ↓
"sendwalk.com"
```

## 🔍 完整示例

### 场景 1: 活动设置了发件人

```
活动配置:
  - from_email: "support@example.com"
  - 邮件内容: "发件域名是 {sender_domain}"

发送流程:
  1. $this->fromEmail = "support@example.com"
  2. getSenderDomain() → "example.com"
  3. 替换标签 → "发件域名是 example.com"

✅ 结果: 邮件内容中显示 "发件域名是 example.com"
```

### 场景 2: 活动未设置发件人（使用服务器发件人）

```
活动配置:
  - from_email: (空)
  - 邮件内容: "发件域名是 {sender_domain}"

服务器配置:
  - sender_emails:
    noreply@sendwalk.com
    hello@sendwalk.com
    info@sendwalk.com

发送流程:
  1. 检查活动 from_email → 空
  2. 调用 getRandomSenderEmail() → "noreply@sendwalk.com" (轮询选择)
  3. $this->fromEmail = "noreply@sendwalk.com"
  4. getSenderDomain() → "sendwalk.com"
  5. 替换标签 → "发件域名是 sendwalk.com"

✅ 结果: 邮件内容中显示 "发件域名是 sendwalk.com"
```

### 场景 3: 多个域名轮询

```
服务器配置:
  - sender_emails:
    user1@domain1.com
    user2@domain2.com
    user3@domain3.com

第1封邮件:
  - 选择: user1@domain1.com
  - {sender_domain} → "domain1.com"

第2封邮件:
  - 选择: user2@domain2.com
  - {sender_domain} → "domain2.com"

第3封邮件:
  - 选择: user3@domain3.com
  - {sender_domain} → "domain3.com"

✅ 每封邮件的 {sender_domain} 都会被正确替换为实际发件人的域名
```

## 🎨 执行顺序

```
[1] SendCampaignEmail Job 启动
    ↓
[2] 确定发件人邮箱
    ├─ 检查活动 from_email
    └─ 如果为空，从服务器发件人池中选择
    ↓
[3] $this->fromEmail 已设置
    ↓
[4] 替换邮件主题中的标签
    └─ replacePersonalizationTags($subject)
    ↓
[5] 替换邮件内容中的标签
    └─ replacePersonalizationTags($htmlContent)
        └─ 调用 getSenderDomain()
            └─ 从 $this->fromEmail 提取域名
            └─ 返回域名
        └─ 替换 {sender_domain}
    ↓
[6] 发送邮件
```

## ⚠️ 边缘情况

### 情况 1: 活动和服务器都没有发件人

```php
if (empty($server->sender_emails)) {
    throw new \Exception('Campaign from_email is empty and SMTP server has no sender emails configured');
}
```

**处理**: 抛出异常，任务失败

### 情况 2: 发件人邮箱格式错误

```php
$emails = array_filter(
    array_map('trim', explode("\n", $server->sender_emails)),
    function($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL);
    }
);
```

**处理**: 过滤掉无效的邮箱地址

### 情况 3: 无法提取域名

```php
$parts = explode('@', $fromEmail);
return $parts[1] ?? '';
```

**处理**: 如果没有 @ 符号或没有域名部分，返回空字符串

## 📝 标签替换清单

系统支持的所有标签：

### 订阅者标签
- `{email}` - 订阅者邮箱
- `{first_name}` - 订阅者名字
- `{last_name}` - 订阅者姓氏
- `{full_name}` - 订阅者全名
- `{自定义字段}` - 任何自定义字段

### 系统标签
- `{campaign_id}` - 活动 ID
- `{date}` - 日期（格式：MMDD）
- `{list_name}` - 列表名称
- `{server_name}` - 服务器名称
- `{sender_domain}` ✅ - 发件人域名
- `{unsubscribe_url}` - 退订链接

### 自定义标签
- `{标签名}` - 用户创建的随机值标签

## ✅ 测试验证

### 测试步骤

1. **创建活动（不设置发件人）**
   ```
   - 活动名称: 测试 sender_domain
   - from_email: (留空)
   - 邮件内容: "发件域名是：{sender_domain}"
   ```

2. **配置服务器发件人**
   ```
   SMTP 服务器设置:
   - sender_emails:
     test1@example.com
     test2@example.com
   ```

3. **发送测试邮件**
   ```
   发送给测试订阅者
   ```

4. **验证结果**
   ```
   邮件内容应显示:
   "发件域名是：example.com"
   
   ✅ {sender_domain} 被正确替换
   ```

### 验证多域名轮询

1. **配置多个域名**
   ```
   sender_emails:
     user@domain1.com
     user@domain2.com
   ```

2. **发送2封邮件**
   ```
   第1封: "发件域名是：domain1.com"
   第2封: "发件域名是：domain2.com"
   ```

3. **确认轮询**
   ```
   ✅ 每封邮件的域名与实际发件人匹配
   ```

## 🔧 调试建议

如果发现 `{sender_domain}` 没有被替换，检查以下内容：

### 1. 检查日志

```bash
tail -f /data/www/sendwalk/backend/storage/logs/laravel.log
```

查找错误信息：
```
Campaign from_email is empty and SMTP server has no sender emails configured
```

### 2. 检查服务器配置

```sql
SELECT id, name, sender_emails FROM smtp_servers WHERE is_active = 1;
```

确保 `sender_emails` 字段有值。

### 3. 检查活动配置

```sql
SELECT id, name, from_email, smtp_server_id FROM campaigns WHERE id = ?;
```

查看活动是否设置了发件人。

### 4. 测试标签替换

在邮件内容中添加调试信息：
```html
<p>发件人: {sender_domain}</p>
<p>活动ID: {campaign_id}</p>
<p>日期: {date}</p>
```

如果其他标签能正常替换，但 `{sender_domain}` 不行，说明可能是发件人邮箱的问题。

## 💡 最佳实践

### 1. 服务器发件人配置

推荐配置多个同域名的发件人：

```
sender_emails:
noreply@yourdomain.com
hello@yourdomain.com
info@yourdomain.com
support@yourdomain.com
```

**优点**：
- ✅ {sender_domain} 始终是同一个域名
- ✅ 便于品牌识别
- ✅ 邮件内容一致性好

### 2. 跨域名发送

如果需要从多个域名发送，建议创建多个 SMTP 服务器：

```
服务器 A:
- sender_emails: user@domain1.com

服务器 B:
- sender_emails: user@domain2.com
```

然后在活动中选择对应的服务器。

### 3. 邮件内容设计

在使用 `{sender_domain}` 时，可以这样设计：

```html
<p>本邮件由 {sender_domain} 发送</p>
<p>如有疑问，请联系 support@{sender_domain}</p>
<p><a href="https://{sender_domain}">访问我们的网站</a></p>
```

**注意**: `{sender_domain}` 只返回域名部分（如 `example.com`），不包括 `http://` 或 `https://`。

## 📊 总结

| 场景 | 发件人来源 | {sender_domain} 替换 | 结果 |
|-----|----------|-------------------|------|
| 活动设置了 from_email | 活动配置 | ✅ 正确替换 | 使用活动的发件人域名 |
| 活动未设置 from_email | 服务器发件人池 | ✅ 正确替换 | 使用服务器选择的发件人域名 |
| 都没有配置 | 无 | ❌ 抛出异常 | 任务失败，不会发送 |

## ✅ 结论

**`{sender_domain}` 标签能够被正确替换！**

系统在设计时就考虑到了这个场景：

1. ✅ 优先使用活动的发件人
2. ✅ 如果活动没有设置，从服务器发件人池中选择
3. ✅ 在替换标签时，从实际使用的发件人中提取域名
4. ✅ 支持轮询多个发件人，每封邮件的域名都与实际发件人匹配

**不需要任何额外配置，开箱即用！**

---

如有其他疑问，欢迎随时咨询。

