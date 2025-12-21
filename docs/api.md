# SendWalk API 文档

## 基础信息

- **Base URL**: `http://localhost:8000/api`
- **认证方式**: Bearer Token (Laravel Sanctum)
- **响应格式**: JSON

## 认证 API

### 注册

```
POST /auth/register
```

**请求体**:
```json
{
  "name": "用户名",
  "email": "user@example.com",
  "password": "password123",
  "password_confirmation": "password123"
}
```

**响应**:
```json
{
  "message": "注册成功",
  "data": {
    "user": {
      "id": 1,
      "name": "用户名",
      "email": "user@example.com"
    }
  }
}
```

### 登录

```
POST /auth/login
```

**请求体**:
```json
{
  "email": "user@example.com",
  "password": "password123"
}
```

**响应**:
```json
{
  "message": "登录成功",
  "data": {
    "user": {
      "id": 1,
      "name": "用户名",
      "email": "user@example.com"
    },
    "token": "1|abc123..."
  }
}
```

### 退出登录

```
POST /auth/logout
Headers: Authorization: Bearer {token}
```

### 获取当前用户

```
GET /auth/user
Headers: Authorization: Bearer {token}
```

## 仪表盘 API

### 获取统计数据

```
GET /dashboard/stats
Headers: Authorization: Bearer {token}
```

**响应**:
```json
{
  "data": {
    "total_subscribers": 1500,
    "total_campaigns": 25,
    "total_sent": 10000,
    "avg_open_rate": 25.5,
    "avg_click_rate": 5.2
  }
}
```

## 邮件列表 API

### 获取列表

```
GET /lists
Headers: Authorization: Bearer {token}
```

### 创建列表

```
POST /lists
Headers: Authorization: Bearer {token}
```

**请求体**:
```json
{
  "name": "订阅者列表",
  "description": "描述信息",
  "custom_fields": {
    "company": "公司",
    "phone": "电话"
  },
  "double_optin": true
}
```

### 更新列表

```
PUT /lists/{id}
Headers: Authorization: Bearer {token}
```

### 删除列表

```
DELETE /lists/{id}
Headers: Authorization: Bearer {token}
```

### 导入订阅者

```
POST /lists/{id}/import
Headers: Authorization: Bearer {token}
Content-Type: multipart/form-data
```

**请求体**:
```
file: subscribers.csv
```

## 订阅者 API

### 获取订阅者列表

```
GET /subscribers?list_id=1&status=active&search=john
Headers: Authorization: Bearer {token}
```

### 创建订阅者

```
POST /subscribers
Headers: Authorization: Bearer {token}
```

**请求体**:
```json
{
  "email": "subscriber@example.com",
  "first_name": "John",
  "last_name": "Doe",
  "custom_fields": {
    "company": "Acme Inc"
  },
  "list_ids": [1, 2]
}
```

### 更新订阅者

```
PUT /subscribers/{id}
Headers: Authorization: Bearer {token}
```

### 删除订阅者

```
DELETE /subscribers/{id}
Headers: Authorization: Bearer {token}
```

### 批量导入

```
POST /subscribers/bulk-import
Headers: Authorization: Bearer {token}
Content-Type: multipart/form-data
```

### 批量删除

```
DELETE /subscribers/bulk-delete
Headers: Authorization: Bearer {token}
```

**请求体**:
```json
{
  "ids": [1, 2, 3]
}
```

## 邮件活动 API

### 获取活动列表

```
GET /campaigns
Headers: Authorization: Bearer {token}
```

### 创建活动

```
POST /campaigns
Headers: Authorization: Bearer {token}
```

**请求体**:
```json
{
  "list_id": 1,
  "name": "促销活动",
  "subject": "限时优惠",
  "from_name": "SendWalk",
  "from_email": "noreply@sendwalk.com",
  "reply_to": "support@sendwalk.com",
  "html_content": "<html>...</html>",
  "plain_content": "纯文本内容"
}
```

### 更新活动

```
PUT /campaigns/{id}
Headers: Authorization: Bearer {token}
```

### 删除活动

```
DELETE /campaigns/{id}
Headers: Authorization: Bearer {token}
```

### 发送活动

```
POST /campaigns/{id}/send
Headers: Authorization: Bearer {token}
```

### 定时发送

```
POST /campaigns/{id}/schedule
Headers: Authorization: Bearer {token}
```

**请求体**:
```json
{
  "scheduled_at": "2024-12-31 10:00:00"
}
```

### 复制活动

```
POST /campaigns/{id}/duplicate
Headers: Authorization: Bearer {token}
```

## 自动化流程 API

### 获取流程列表

```
GET /automations
Headers: Authorization: Bearer {token}
```

### 创建流程

```
POST /automations
Headers: Authorization: Bearer {token}
```

**请求体**:
```json
{
  "name": "欢迎邮件系列",
  "description": "新订阅者欢迎流程",
  "list_id": 1,
  "trigger_type": "subscribe",
  "workflow_data": {
    "nodes": [...],
    "edges": [...]
  }
}
```

### 激活流程

```
POST /automations/{id}/activate
Headers: Authorization: Bearer {token}
```

### 停用流程

```
POST /automations/{id}/deactivate
Headers: Authorization: Bearer {token}
```

## 数据分析 API

### 获取总览数据

```
GET /analytics/overview
Headers: Authorization: Bearer {token}
```

### 获取活动详细数据

```
GET /analytics/campaigns/{id}
Headers: Authorization: Bearer {token}
```

**响应**:
```json
{
  "data": {
    "campaign": {...},
    "stats": {
      "sent": 1000,
      "delivered": 980,
      "opened": 250,
      "clicked": 50,
      "bounced": 20,
      "unsubscribed": 5,
      "open_rate": 25.5,
      "click_rate": 5.1
    },
    "links": [...],
    "sends": [...]
  }
}
```

## 追踪 API

### 邮件打开追踪

```
GET /track/open/{campaignId}/{subscriberId}
```

返回 1x1 透明像素图片

### 链接点击追踪

```
GET /track/click/{linkId}/{subscriberId}
```

重定向到原始链接

## 错误响应

所有 API 在出错时返回相应的 HTTP 状态码和错误信息：

```json
{
  "message": "错误信息",
  "errors": {
    "field": ["验证错误信息"]
  }
}
```

### HTTP 状态码

- `200` - 成功
- `201` - 创建成功
- `400` - 请求错误
- `401` - 未认证
- `403` - 无权限
- `404` - 资源不存在
- `422` - 验证失败
- `500` - 服务器错误

## 分页

列表接口返回分页数据：

```json
{
  "data": [...],
  "meta": {
    "current_page": 1,
    "last_page": 10,
    "per_page": 15,
    "total": 150
  }
}
```

## 个性化标签

邮件内容支持以下个性化标签：

- `{email}` - 订阅者邮箱
- `{first_name}` - 名字
- `{last_name}` - 姓氏
- `{full_name}` - 全名
- `{custom_field_name}` - 自定义字段

示例：
```html
<p>你好 {first_name}，</p>
<p>感谢您订阅我们的邮件列表！</p>
```

