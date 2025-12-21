# 浏览器 CORS 测试指南

## 🎯 重要发现

**服务器端的 CORS 配置是完美的！** 

所有 API 端点都返回了正确的 CORS 头：
- ✅ `access-control-allow-origin: https://edm.sendwalk.com`
- ✅ `access-control-allow-credentials: true`
- ✅ OPTIONS 预检请求工作正常
- ✅ POST 请求返回正确的 CORS 头

**如果浏览器还在显示错误，很可能不是真正的 CORS 错误！**

---

## 🔍 常见的"看起来像 CORS 但不是 CORS"的错误

### 错误 1: 401 Unauthorized（最常见）

**浏览器显示**：
```
GET https://api.sendwalk.com/api/campaigns 401 (Unauthorized)
```

**Console 可能显示**：
```
Access to XMLHttpRequest at 'https://api.sendwalk.com/api/campaigns' 
from origin 'https://edm.sendwalk.com' has been blocked by CORS policy: 
Response to preflight request doesn't pass access control check: 
It does not have HTTP ok status.
```

**实际问题**：
- ❌ 不是 CORS 问题！
- ✅ 是认证问题！需要先登录

**解决方法**：
- 先登录获取 token
- 确保 token 正确发送

---

### 错误 2: 422 Validation Error

**浏览器显示**：
```
POST https://api.sendwalk.com/api/auth/login 422 (Unprocessable Entity)
```

**实际问题**：
- ❌ 不是 CORS 问题！
- ✅ 是表单验证错误

**解决方法**：
- 检查发送的数据格式
- 确保必填字段都已填写

---

### 错误 3: Network Error（真正的连接问题）

**浏览器显示**：
```
GET https://api.sendwalk.com/api/xxx net::ERR_CONNECTION_REFUSED
```

**实际问题**：
- ❌ 不是 CORS 问题！
- ✅ 服务器无法访问或宕机

---

## 📸 请在浏览器中测试

### 测试 1: 打开前端页面

```bash
1. 打开无痕模式
2. 访问 https://edm.sendwalk.com
3. F12 → Console 选项卡
4. F12 → Network 选项卡（勾选 Preserve log）
```

### 测试 2: 在 Console 中运行测试代码

粘贴以下代码到 Console 并运行：

```javascript
// 测试 health check
console.log('=== 测试 1: Health Check ===');
fetch('https://api.sendwalk.com/api/health', {
  method: 'GET',
  credentials: 'include',
  headers: {
    'Accept': 'application/json'
  }
})
.then(async r => {
  console.log('✓ Health Check Status:', r.status);
  console.log('✓ Health Check Data:', await r.json());
  console.log('✓ CORS 头:');
  console.log('  - access-control-allow-origin:', r.headers.get('access-control-allow-origin'));
  console.log('  - access-control-allow-credentials:', r.headers.get('access-control-allow-credentials'));
})
.catch(error => {
  console.error('✗ Health Check 失败:', error);
});

// 测试登录（会返回 422 但 CORS 应该正常）
console.log('\n=== 测试 2: 登录接口 ===');
fetch('https://api.sendwalk.com/api/auth/login', {
  method: 'POST',
  credentials: 'include',
  headers: {
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({
    email: 'test@example.com',
    password: 'test123456'
  })
})
.then(async r => {
  console.log('✓ Login Status:', r.status, r.statusText);
  const data = await r.json();
  console.log('✓ Login Response:', data);
  console.log('✓ CORS 头:');
  console.log('  - access-control-allow-origin:', r.headers.get('access-control-allow-origin'));
  console.log('  - access-control-allow-credentials:', r.headers.get('access-control-allow-credentials'));
})
.catch(error => {
  console.error('✗ Login 失败:', error);
  console.error('  Error name:', error.name);
  console.error('  Error message:', error.message);
});

// 测试受保护的接口（会返回 401）
console.log('\n=== 测试 3: 受保护接口（campaigns）===');
fetch('https://api.sendwalk.com/api/campaigns', {
  method: 'GET',
  credentials: 'include',
  headers: {
    'Accept': 'application/json'
  }
})
.then(async r => {
  console.log('✓ Campaigns Status:', r.status, r.statusText);
  console.log('✓ CORS 头:');
  console.log('  - access-control-allow-origin:', r.headers.get('access-control-allow-origin'));
  console.log('  - access-control-allow-credentials:', r.headers.get('access-control-allow-credentials'));
  if (r.status === 401) {
    console.log('⚠️ 401 是正常的（未登录），但 CORS 头应该存在！');
  }
})
.catch(error => {
  console.error('✗ Campaigns 失败:', error);
  console.error('  Error name:', error.name);
  console.error('  Error message:', error.message);
});
```

### 测试 3: 查看 Network 选项卡

运行上述代码后，在 Network 选项卡中：

1. 找到 `health` 请求
   - Status 应该是 200
   - Response Headers 应该有 `access-control-allow-origin`

2. 找到 `login` 请求
   - Status 可能是 422（验证错误，正常）
   - Response Headers 应该有 `access-control-allow-origin`

3. 找到 `campaigns` 请求
   - Status 可能是 401（未认证，正常）
   - Response Headers 应该有 `access-control-allow-origin`

**关键**：即使 Status 是 401/422，只要 Response Headers 中有 CORS 头，就说明 CORS 配置是正确的！

---

## 🎯 如何判断是否是真正的 CORS 错误

### 真正的 CORS 错误特征：

1. **Network 选项卡中请求状态显示 "(failed)" 或 "CORS error"**
2. **Response Headers 完全为空或缺少 `access-control-allow-origin`**
3. **Console 错误明确提到 "CORS policy" 且原因是 "No 'Access-Control-Allow-Origin' header"**

### 不是 CORS 错误的特征：

1. **有明确的 HTTP Status Code（200/401/422/500 等）**
2. **Response Headers 中有 `access-control-allow-origin`**
3. **Console 错误是 "401 Unauthorized" 或 "422 Unprocessable Entity"**

---

## 📋 请提供以下信息

运行上述测试代码后，请提供：

### 1. Console 的完整输出
复制所有 `console.log` 的输出

### 2. 如果有红色错误
完整复制错误信息

### 3. Network 选项卡截图
显示请求的 Status、Response Headers

### 4. 你看到的具体问题
- 登录按钮点击后发生了什么？
- 看到什么错误提示？
- 页面有什么反应？

---

## 🚀 更新后的部署步骤

我已经添加了 `/api/health` 路由，请在服务器上运行：

```bash
cd /data/www/sendwalk/backend

# 清除路由缓存
php artisan route:clear
php artisan route:cache

# 验证新路由
php artisan route:list | grep health

# 测试 health 端点
curl https://api.sendwalk.com/api/health

# 重启服务
sudo systemctl restart php8.3-fpm
```

---

## 💡 如果测试代码全部成功

如果上述所有测试代码都显示：
- ✓ Status: 200/401/422（有状态码）
- ✓ CORS 头存在

那么 **CORS 配置是完全正确的**！

你看到的错误可能是：
1. 需要先登录才能访问某些页面
2. 表单验证错误
3. 前端代码逻辑问题
4. 不是 CORS 错误

---

**请运行测试代码并提供 Console 的完整输出！** 🎯

