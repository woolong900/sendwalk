# 检查浏览器 CORS 错误详细信息

## 🔍 需要收集的信息

请在浏览器中执行以下操作并提供截图或复制文字：

### 1. Console 错误信息

打开浏览器：
1. 访问 `https://edm.sendwalk.com`
2. 按 `F12` 打开开发者工具
3. 切换到 **Console** 选项卡
4. 尝试登录或触发 API 调用
5. 复制完整的红色错误信息

**典型的 CORS 错误格式：**
```
Access to XMLHttpRequest at 'https://api.sendwalk.com/api/xxx' 
from origin 'https://edm.sendwalk.com' has been blocked by CORS policy: 
Response to preflight request doesn't pass access control check: 
No 'Access-Control-Allow-Origin' header is present on the requested resource.
```

**需要知道的关键信息：**
- 具体是哪个 API 端点报错？
- 错误信息的完整内容？
- 是 preflight request 还是实际请求的错误？

---

### 2. Network 请求详情

在开发者工具中：
1. 切换到 **Network** 选项卡
2. 勾选 **Preserve log**
3. 清空当前日志（垃圾桶图标）
4. 尝试登录
5. 找到失败的请求（通常是红色的）

**对于失败的请求，提供以下信息：**

#### (1) 请求基本信息
- Request URL: （例如 `https://api.sendwalk.com/api/auth/login`）
- Request Method: （例如 `POST` 或 `OPTIONS`）
- Status Code: （例如 `200`、`404`、`(failed)` 等）

#### (2) Request Headers（请求头）
点击失败的请求 → **Headers** 选项卡 → **Request Headers**

关键信息：
```
Origin: https://edm.sendwalk.com
Referer: https://edm.sendwalk.com/
Content-Type: application/json
...
```

#### (3) Response Headers（响应头）
在同一个位置查看 **Response Headers**

关键信息（有或没有）：
```
access-control-allow-origin: ?
access-control-allow-credentials: ?
access-control-allow-methods: ?
access-control-allow-headers: ?
...
```

#### (4) 如果有 OPTIONS 请求
如果看到有 `OPTIONS` 方法的请求（预检请求），也提供同样的信息。

---

### 3. 特定测试

在浏览器 Console 中直接运行以下 JavaScript 代码：

```javascript
// 测试 1: 直接调用 API
fetch('https://api.sendwalk.com/api/health', {
  method: 'GET',
  credentials: 'include',
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json'
  }
})
.then(response => {
  console.log('Status:', response.status);
  console.log('Headers:', [...response.headers.entries()]);
  return response.json();
})
.then(data => console.log('Data:', data))
.catch(error => console.error('Error:', error));

// 测试 2: POST 请求（会触发 preflight）
fetch('https://api.sendwalk.com/api/auth/login', {
  method: 'POST',
  credentials: 'include',
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json'
  },
  body: JSON.stringify({
    email: 'test@test.com',
    password: 'test123'
  })
})
.then(response => {
  console.log('Login Status:', response.status);
  console.log('Login Headers:', [...response.headers.entries()]);
  return response.text();
})
.then(data => console.log('Login Response:', data))
.catch(error => console.error('Login Error:', error));
```

复制输出结果。

---

### 4. Cloudflare 设置检查

请确认以下 Cloudflare 设置：

#### (1) SSL/TLS 模式
- 路径：SSL/TLS → 概述
- **必须是**: Full (strict) 或 Full
- **不能是**: Flexible

#### (2) 缓存设置
- 路径：缓存 → 配置
- 确认已点击"清除所有内容"
- 考虑临时设置：开发模式（Development Mode）

#### (3) 页面规则
- 路径：规则 → 页面规则
- 检查是否有规则影响 `api.sendwalk.com/*`
- 特别检查缓存级别设置

#### (4) 防火墙规则
- 路径：安全性 → WAF
- 检查是否有规则阻止请求

---

## 📝 快速检查命令

同时在服务器上运行以下命令并提供输出：

```bash
# 1. 检查 .env 配置（显示不可见字符）
cd /data/www/sendwalk/backend
echo "=== Checking SESSION_DOMAIN ==="
grep "^SESSION_DOMAIN=" .env | cat -A
echo ""
grep "^SANCTUM_STATEFUL_DOMAINS=" .env | cat -A
echo ""

# 2. 验证 Laravel 配置
echo "=== Laravel Config ==="
php artisan tinker --execute="
echo 'CORS Origins: ' . json_encode(config('cors.allowed_origins')) . PHP_EOL;
echo 'CORS Credentials: ' . var_export(config('cors.supports_credentials'), true) . PHP_EOL;
echo 'Session Domain: ' . var_export(config('session.domain'), true) . PHP_EOL;
echo 'Sanctum Stateful: ' . json_encode(config('sanctum.stateful')) . PHP_EOL;
"

# 3. 测试实际的 OPTIONS 请求
echo "=== Testing OPTIONS Request ==="
curl -v -X OPTIONS \
  -H "Origin: https://edm.sendwalk.com" \
  -H "Access-Control-Request-Method: POST" \
  -H "Access-Control-Request-Headers: Content-Type, Authorization" \
  https://api.sendwalk.com/api/auth/login 2>&1 | grep -i "< "

# 4. 测试 POST 请求
echo ""
echo "=== Testing POST Request ==="
curl -v -X POST \
  -H "Origin: https://edm.sendwalk.com" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"email":"test@test.com","password":"test"}' \
  https://api.sendwalk.com/api/auth/login 2>&1 | grep -i "< "

# 5. 检查 Nginx 配置
echo ""
echo "=== Nginx API Config ==="
cat /etc/nginx/conf.d/sendwalk-api.conf | grep -i "add_header"

# 6. 检查 PHP-FPM 日志
echo ""
echo "=== Recent PHP-FPM Errors ==="
sudo tail -20 /var/log/php8.3-fpm.log 2>/dev/null || echo "No PHP-FPM log"
```

---

## 🎯 请提供以上所有信息

有了这些信息，我就能准确定位问题所在。

特别重要的是：
1. ✅ 浏览器 Console 的完整错误信息
2. ✅ Network 中失败请求的完整 Headers
3. ✅ 服务器端的测试命令输出
4. ✅ Cloudflare 的 SSL 模式设置

---

## 💡 可能的其他原因

如果以上都正确，问题可能是：

1. **Cloudflare 的 SSL 模式不对**
   - Flexible 模式会导致问题
   - 必须使用 Full 或 Full (strict)

2. **Cloudflare 缓存了 OPTIONS 响应**
   - 需要等待几分钟
   - 或开启"开发模式"

3. **前端和后端的域名不匹配**
   - 检查前端实际发送请求的 URL
   - 检查 VITE_API_URL 环境变量

4. **Sanctum CSRF Cookie 问题**
   - 可能需要先请求 `/sanctum/csrf-cookie`

5. **Session 配置问题**
   - SESSION_DRIVER 设置
   - SESSION_DOMAIN 格式

请提供以上信息，我会精确找出问题！

