# 预览 {sender_domain} 修复说明

## 🐛 问题描述

用户反馈：**预览中 `{sender_domain}` 被替换成了 `example.com`**

### 问题场景

当用户创建活动时：
- ❌ **没有设置** `from_email`（发件人邮箱）
- ✅ **已配置** SMTP 服务器的 `sender_emails`（发件人池）

**预期行为**：
- 预览时应该显示服务器发件人池中第一个邮箱的域名
- 例如：服务器配置了 `noreply@sendwalk.com`，预览应显示 `sendwalk.com`

**实际行为**：
- 预览时显示 `example.com`（默认占位符）

## 🔍 根本原因

### 预览功能的逻辑问题

**前端预览代码**（修复前）：

```typescript
// ❌ 问题代码
const getSenderDomain = () => {
  if (campaign.from_email) {
    const parts = campaign.from_email.split('@')
    return parts[1] || 'example.com'
  }
  return 'example.com'  // ❌ 直接返回占位符
}
```

**问题分析**：
1. 只检查活动的 `from_email`
2. 如果活动没有设置发件人，直接返回 `'example.com'`
3. 没有考虑从 SMTP 服务器的 `sender_emails` 中获取

### 与实际发送的差异

**实际发送时的逻辑**（`backend/app/Jobs/SendCampaignEmail.php`）：

```php
// ✅ 正确的逻辑
$this->fromEmail = $this->campaign->from_email;
if (empty($this->fromEmail)) {
    $this->fromEmail = $this->getRandomSenderEmail($smtpServer);
}

// 然后从 $this->fromEmail 提取域名
private function getSenderDomain(): string
{
    $fromEmail = $this->fromEmail;
    $parts = explode('@', $fromEmail);
    return $parts[1] ?? '';
}
```

**对比**：
- ✅ **发送时**：会从服务器发件人池中选择 → 提取正确的域名
- ❌ **预览时**：直接返回 `example.com` → 不准确

## ✅ 解决方案

### 修复思路

让预览功能模拟实际发送时的逻辑：
1. 优先使用活动的 `from_email`
2. 如果活动没有设置，从 SMTP 服务器的 `sender_emails` 中获取第一个
3. 从获取的邮箱中提取域名

### 修复内容

#### 1. 活动列表页预览（`frontend/src/pages/campaigns/index.tsx`）

**更新 Campaign 类型**：

```typescript
smtp_server?: {
  id: number
  name: string
  sender_emails?: string  // ✅ 添加这个字段
}
```

**优化 getSenderDomain 函数**：

```typescript
const getSenderDomain = () => {
  // 优先使用活动的 from_email
  if (campaign.from_email) {
    const parts = campaign.from_email.split('@')
    return parts[1] || 'example.com'
  }
  
  // ✅ 如果活动没有设置发件人，从服务器的 sender_emails 中获取第一个
  if (campaign.smtp_server?.sender_emails) {
    const senderEmails = campaign.smtp_server.sender_emails
      .split('\n')
      .map(email => email.trim())
      .filter(email => email && email.includes('@'))
    
    if (senderEmails.length > 0) {
      const parts = senderEmails[0].split('@')
      return parts[1] || 'example.com'
    }
  }
  
  // 如果都没有，使用默认值
  return 'example.com'
}
```

#### 2. 活动编辑页预览（`frontend/src/pages/campaigns/editor.tsx`）

**更新 SmtpServer 类型**：

```typescript
interface SmtpServer {
  id: number
  name: string
  type: string
  is_default: boolean
  is_active: boolean
  sender_emails?: string  // ✅ 添加这个字段
}
```

**优化 getPreviewHtml 函数中的 sender_domain 提取**：

```typescript
// 提取发件人域名
let senderDomain = 'example.com'

// 优先使用活动的 from_email
if (formData.from_email) {
  const parts = formData.from_email.split('@')
  senderDomain = parts[1] || 'example.com'
} else if (formData.smtp_server_id) {
  // ✅ 如果活动没有设置发件人，从选中的服务器的 sender_emails 中获取第一个
  const selectedServer = smtpServers?.find(s => s.id.toString() === formData.smtp_server_id)
  if (selectedServer?.sender_emails) {
    const senderEmails = selectedServer.sender_emails
      .split('\n')
      .map(email => email.trim())
      .filter(email => email && email.includes('@'))
    
    if (senderEmails.length > 0) {
      const parts = senderEmails[0].split('@')
      senderDomain = parts[1] || 'example.com'
    }
  }
}
```

## 📊 修复效果对比

### 场景 1: 活动设置了发件人

```
活动配置:
  - from_email: "support@sendwalk.com"
  - 邮件内容: "发件域名是 {sender_domain}"

修复前: "发件域名是 sendwalk.com" ✅（正常）
修复后: "发件域名是 sendwalk.com" ✅（不变）
```

### 场景 2: 活动未设置发件人（使用服务器发件人池）

```
活动配置:
  - from_email: (空)
  - 邮件内容: "发件域名是 {sender_domain}"

服务器配置:
  - sender_emails:
    noreply@sendwalk.com
    hello@sendwalk.com

修复前: "发件域名是 example.com" ❌（错误）
修复后: "发件域名是 sendwalk.com" ✅（正确）
```

### 场景 3: 活动和服务器都没有配置

```
活动配置:
  - from_email: (空)
  - 邮件内容: "发件域名是 {sender_domain}"

服务器配置:
  - sender_emails: (空)

修复前: "发件域名是 example.com" ✅（合理）
修复后: "发件域名是 example.com" ✅（不变）
```

## 🎯 预览与实际发送的一致性

### 修复前 ❌

| 场景 | 预览显示 | 实际发送 | 是否一致 |
|-----|---------|---------|---------|
| 活动有 from_email | sendwalk.com | sendwalk.com | ✅ 一致 |
| 活动无 from_email | **example.com** | sendwalk.com | ❌ **不一致** |
| 都没有配置 | example.com | (发送失败) | ✅ 一致 |

**问题**：场景2不一致，用户看到的预览与实际发送的邮件不同。

### 修复后 ✅

| 场景 | 预览显示 | 实际发送 | 是否一致 |
|-----|---------|---------|---------|
| 活动有 from_email | sendwalk.com | sendwalk.com | ✅ 一致 |
| 活动无 from_email | **sendwalk.com** | sendwalk.com | ✅ **一致** |
| 都没有配置 | example.com | (发送失败) | ✅ 一致 |

**效果**：所有场景都一致，预览所见即所得（WYSIWYG）。

## 🔧 技术细节

### 数据流

```
[前端] 活动编辑/列表页
    ↓
[API] GET /campaigns 或 GET /campaigns/:id
    ↓
[后端] CampaignController
    └─ with(['smtpServer'])  // 关联加载 SMTP 服务器
    ↓
[数据库] smtp_servers 表
    └─ 包含 sender_emails 字段
    ↓
[响应] JSON 数据
    └─ campaign.smtp_server.sender_emails
    ↓
[前端] 预览功能
    └─ 从 sender_emails 提取第一个邮箱
    └─ 提取域名
    └─ 替换 {sender_domain}
```

### sender_emails 字段格式

**数据库存储**：

```
sender_emails (TEXT):
noreply@sendwalk.com
hello@sendwalk.com
info@sendwalk.com
```

**前端解析**：

```typescript
const senderEmails = campaign.smtp_server.sender_emails
  .split('\n')           // 按行分割
  .map(email => email.trim())  // 去除空格
  .filter(email => email && email.includes('@'))  // 过滤有效邮箱

// 取第一个
const firstEmail = senderEmails[0]  // "noreply@sendwalk.com"
const domain = firstEmail.split('@')[1]  // "sendwalk.com"
```

### 为什么取第一个？

在预览时，我们：
- ✅ **不应该**实际调用轮询逻辑（那是发送时才做的）
- ✅ **不应该**修改服务器的 `sender_email_index`（避免影响实际发送）
- ✅ **应该**提供一个稳定、可预测的预览结果

所以选择第一个邮箱作为预览，这样：
- 用户每次预览看到的都是一样的
- 不会干扰实际发送的轮询机制
- 提供了足够准确的预览效果

## 🧪 测试验证

### 测试步骤

1. **配置 SMTP 服务器**
   ```
   添加/编辑 SMTP 服务器
   sender_emails 填入:
     noreply@testdomain.com
     hello@testdomain.com
   ```

2. **创建活动（不设置发件人）**
   ```
   - 活动名称: 测试 sender_domain
   - from_email: (留空)
   - smtp_server: 选择上面配置的服务器
   - 邮件内容: "发件域名是：{sender_domain}"
   ```

3. **预览邮件**
   ```
   点击"预览"按钮
   ```

4. **验证结果**
   ```
   修复前: "发件域名是：example.com" ❌
   修复后: "发件域名是：testdomain.com" ✅
   ```

### 多场景测试

#### 测试用例 1: 单个发件人

```
sender_emails: noreply@domain1.com
预期: {sender_domain} → "domain1.com" ✅
```

#### 测试用例 2: 多个发件人

```
sender_emails:
  user1@domain1.com
  user2@domain1.com
预期: {sender_domain} → "domain1.com" ✅（使用第一个）
```

#### 测试用例 3: 多个域名

```
sender_emails:
  user1@domain1.com
  user2@domain2.com
预期: {sender_domain} → "domain1.com" ✅（使用第一个）
注意: 实际发送时会轮询，可能是 domain2.com
```

#### 测试用例 4: 空行和格式

```
sender_emails:
  (空行)
  noreply@domain1.com
  (空行)
  hello@domain1.com
预期: {sender_domain} → "domain1.com" ✅（自动过滤空行）
```

#### 测试用例 5: 活动有发件人

```
活动 from_email: custom@domain2.com
服务器 sender_emails: user@domain1.com
预期: {sender_domain} → "domain2.com" ✅（优先使用活动的）
```

## ⚠️ 注意事项

### 1. 预览 vs 实际发送的差异

**预览**：
- 总是使用服务器发件人池的**第一个**邮箱
- 提供稳定、可预测的预览效果

**实际发送**：
- 使用**轮询机制**，每封邮件可能使用不同的发件人
- 如果配置了多个域名，不同邮件的 `{sender_domain}` 可能不同

**建议**：
- ✅ 服务器发件人池使用**同一个域名**的多个邮箱
- ❌ 避免在发件人池中混合多个域名

### 2. 后端 API 数据

确保后端 API 返回 `smtp_server` 时包含 `sender_emails` 字段。

**检查**：

```bash
# 获取活动详情
curl -X GET https://api.sendwalk.com/api/campaigns/1 \
  -H "Authorization: Bearer YOUR_TOKEN" | jq '.data.smtp_server'

# 应该包含 sender_emails 字段
{
  "id": 1,
  "name": "My Server",
  "sender_emails": "noreply@domain.com\nhello@domain.com"
}
```

### 3. 性能考虑

`sender_emails` 字段可能包含大量邮箱地址（每行一个），但：
- ✅ 前端只需要解析第一个邮箱
- ✅ 解析过程很快（字符串分割和过滤）
- ✅ 不会影响性能

## 📝 总结

### 问题
- 预览时 `{sender_domain}` 总是显示 `example.com`
- 当活动没有设置发件人，但服务器有发件人池时，预览不准确

### 解决
- 优化预览逻辑，从服务器发件人池中获取第一个邮箱
- 提取域名并替换 `{sender_domain}`
- 使预览与实际发送保持一致

### 效果
- ✅ 预览更准确，所见即所得
- ✅ 与实际发送逻辑保持一致
- ✅ 用户体验更好，不会产生困惑

### 修改文件
- `frontend/src/pages/campaigns/index.tsx`
- `frontend/src/pages/campaigns/editor.tsx`

### 部署
- 前端已构建 ✅
- 刷新页面即可生效

---

**修复完成！** 现在预览功能会正确显示 `{sender_domain}`，不再总是 `example.com`。

