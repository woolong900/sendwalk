# CORS 错误解决方案

## 🔴 错误信息

```
CORS Error
Access to XMLHttpRequest at 'https://api.sendwalk.com/api/...' 
from origin 'https://edm.sendwalk.com' has been blocked by CORS policy
```

或

```
No 'Access-Control-Allow-Origin' header is present on the requested resource
```

## 🔍 问题原因

CORS（跨域资源共享）错误通常由以下原因引起：

1. **后端 .env 配置不正确**
   - `FRONTEND_URL` 未设置或不匹配
   - `SANCTUM_STATEFUL_DOMAINS` 配置错误
   - `SESSION_DOMAIN` 配置不当

2. **前端 API URL 不匹配**
   - 前端 `.env` 中的 `VITE_API_URL` 错误

3. **Nginx 配置干扰**
   - Nginx 添加了冲突的 CORS 头

4. **缓存未清除**
   - Laravel 配置缓存未更新

5. **Cookie/Session 问题**
   - 跨域 Cookie 设置不正确

## ✅ 解决方案

### 方案 1：使用快速修复脚本（推荐）

```bash
# 在服务器上运行
cd /data/www/sendwalk
chmod +x fix-cors-error.sh
./fix-cors-error.sh

# 重启服务
sudo systemctl restart php8.3-fpm
sudo supervisorctl restart all
```

### 方案 2：手动修复

#### 步骤 1：检查并修复后端配置

```bash
cd /data/www/sendwalk/backend

# 编辑 .env 文件
nano .env
```

**确保以下配置正确**：

```bash
# 应用 URL（后端 API 域名）
APP_URL=https://api.sendwalk.com

# 前端 URL（前端应用域名）
FRONTEND_URL=https://edm.sendwalk.com

# Sanctum 可信任域名（前端域名）
SANCTUM_STATEFUL_DOMAINS=edm.sendwalk.com

# Session Cookie 域名（使用主域名，注意前面的点）
SESSION_DOMAIN=.sendwalk.com
```

> 💡 **重要**:
> - `SESSION_DOMAIN` 前面的 `.` 表示包含所有子域名
> - 这样 `edm.sendwalk.com` 和 `api.sendwalk.com` 都可以共享 session

#### 步骤 2：验证 CORS 配置文件

```bash
# 查看 CORS 配置
cat /data/www/sendwalk/backend/config/cors.php
```

确保配置如下：

```php
<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    
    'allowed_methods' => ['*'],
    
    'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:5173')],
    
    'allowed_origins_patterns' => [],
    
    'allowed_headers' => ['*'],
    
    'exposed_headers' => [],
    
    'max_age' => 0,
    
    'supports_credentials' => true,  // 必须为 true
];
```

#### 步骤 3：清除缓存并重建

```bash
cd /data/www/sendwalk/backend

# 清除所有缓存
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

# 重新生成缓存（生产环境）
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

#### 步骤 4：检查前端配置

```bash
cd /data/www/sendwalk/frontend

# 查看前端环境变量
cat .env
```

**确保前端配置正确**：

```bash
VITE_API_URL=https://api.sendwalk.com
VITE_APP_NAME=SendWalk
```

**如果前端配置有变化，需要重新构建**：

```bash
npm run build
```

#### 步骤 5：检查 Nginx 配置

```bash
# 查看 API Nginx 配置
cat /etc/nginx/conf.d/sendwalk-api.conf
```

**确保 Nginx 不会添加冲突的 CORS 头**。

如果 Nginx 配置中有 `add_header` 相关的 CORS 头，应该删除它们，让 Laravel 来处理 CORS：

```nginx
# ❌ 删除这些（如果存在）
# add_header 'Access-Control-Allow-Origin' '*';
# add_header 'Access-Control-Allow-Methods' 'GET, POST, OPTIONS';
# add_header 'Access-Control-Allow-Headers' '*';
```

**如果修改了 Nginx 配置**：

```bash
# 测试配置
sudo nginx -t

# 重启 Nginx
sudo systemctl restart nginx
```

#### 步骤 6：重启所有服务

```bash
# 重启 PHP-FPM
sudo systemctl restart php8.3-fpm

# 重启 Supervisor 进程
sudo supervisorctl restart all

# 查看状态
sudo systemctl status php8.3-fpm
sudo supervisorctl status
```

### 方案 3：开发环境调试模式

如果在开发环境，可以临时设置更宽松的 CORS：

```bash
# 编辑 config/cors.php
nano backend/config/cors.php
```

```php
'allowed_origins' => ['*'],  // 临时允许所有来源
```

> ⚠️ **警告**: 不要在生产环境使用 `*`，这会带来安全风险

## 🔍 验证修复

### 1. 检查后端配置

```bash
cd /data/www/sendwalk/backend

# 查看实际生效的配置
php artisan tinker

# 在 tinker 中运行:
config('cors.allowed_origins')
config('sanctum.stateful')
config('session.domain')
```

预期输出：
```php
// cors.allowed_origins
=> [
     "https://edm.sendwalk.com",
   ]

// sanctum.stateful
=> [
     "edm.sendwalk.com",
   ]

// session.domain
=> ".sendwalk.com"
```

### 2. 测试 API 请求

```bash
# 测试基本 API 调用
curl -I https://api.sendwalk.com/api/health

# 测试带 Origin 头的请求
curl -H "Origin: https://edm.sendwalk.com" \
     -H "Access-Control-Request-Method: GET" \
     -H "Access-Control-Request-Headers: X-Requested-With" \
     -X OPTIONS \
     --verbose \
     https://api.sendwalk.com/api/campaigns
```

应该看到响应头包含：
```
Access-Control-Allow-Origin: https://edm.sendwalk.com
Access-Control-Allow-Credentials: true
```

### 3. 浏览器测试

1. 打开浏览器访问 `https://edm.sendwalk.com`
2. 打开开发者工具（F12）
3. 切换到 **Network** 选项卡
4. 执行一个 API 请求
5. 查看请求的 **Response Headers**

应该看到：
```
Access-Control-Allow-Origin: https://edm.sendwalk.com
Access-Control-Allow-Credentials: true
```

### 4. 查看日志

```bash
# 查看 Laravel 日志
tail -50 /data/www/sendwalk/backend/storage/logs/laravel-$(date +%Y-%m-%d).log

# 查看 Nginx 错误日志
sudo tail -50 /var/log/nginx/sendwalk-api-error.log

# 查看 PHP-FPM 日志
sudo tail -50 /var/log/php8.3-fpm.log
```

## 🚨 常见相关问题

### 问题 1: "Credentials flag is true, but Access-Control-Allow-Credentials is not"

**原因**: `supports_credentials` 未设置为 `true`

**解决方案**:
```php
// config/cors.php
'supports_credentials' => true,  // 必须为 true
```

### 问题 2: "The value of the 'Access-Control-Allow-Origin' header must not be the wildcard '*'"

**原因**: 当 `supports_credentials` 为 `true` 时，不能使用 `*`

**解决方案**:
```php
// config/cors.php
'allowed_origins' => [env('FRONTEND_URL')],  // 使用具体域名
'supports_credentials' => true,
```

### 问题 3: OPTIONS 请求返回 405

**原因**: Laravel 路由未正确处理 OPTIONS 请求

**解决方案**: 确保 CORS 中间件已启用：

```php
// app/Http/Kernel.php
protected $middleware = [
    // ...
    \Illuminate\Http\Middleware\HandleCors::class,  // 确保存在
];
```

### 问题 4: Cookie 未被发送

**原因**: 
- 前端请求未设置 `credentials: 'include'`
- `SESSION_DOMAIN` 配置不正确

**解决方案**:

后端：
```bash
# .env
SESSION_DOMAIN=.sendwalk.com  # 注意前面的点
```

前端（在 `lib/api.ts`）：
```typescript
export const api = axios.create({
  baseURL: API_URL,
  withCredentials: true,  // 必须设置
})
```

### 问题 5: localhost 开发环境 CORS 错误

**开发环境配置**:

```bash
# backend/.env
FRONTEND_URL=http://localhost:5173
SANCTUM_STATEFUL_DOMAINS=localhost:5173
SESSION_DOMAIN=localhost
```

```bash
# frontend/.env
VITE_API_URL=http://localhost:8000
```

## 📋 配置检查清单

完成以下检查：

### 后端配置

- [ ] `.env` 文件存在
- [ ] `APP_URL=https://api.sendwalk.com`
- [ ] `FRONTEND_URL=https://edm.sendwalk.com`
- [ ] `SANCTUM_STATEFUL_DOMAINS=edm.sendwalk.com`
- [ ] `SESSION_DOMAIN=.sendwalk.com` (注意前面的点)
- [ ] `config/cors.php` 中 `supports_credentials => true`
- [ ] `config/cors.php` 中 `allowed_origins` 使用 `env('FRONTEND_URL')`
- [ ] 已清除配置缓存 (`php artisan config:clear`)
- [ ] 已重建配置缓存 (`php artisan config:cache`)

### 前端配置

- [ ] `.env` 文件存在
- [ ] `VITE_API_URL=https://api.sendwalk.com`
- [ ] `lib/api.ts` 中 `withCredentials: true`
- [ ] 如果 .env 有变化，已重新构建 (`npm run build`)

### Nginx 配置

- [ ] Nginx 配置中没有冲突的 CORS 头
- [ ] 如果修改了 Nginx，已重启服务

### 服务状态

- [ ] PHP-FPM 已重启
- [ ] Supervisor 进程已重启
- [ ] 所有服务正常运行

### 测试验证

- [ ] `curl` 测试返回正确的 CORS 头
- [ ] 浏览器中可以成功调用 API
- [ ] 浏览器开发者工具中没有 CORS 错误
- [ ] Cookie 正常发送和接收

## 🔧 域名说明

### 当前项目域名结构

```
sendwalk.com (主域名)
├── edm.sendwalk.com      (前端应用)
└── api.sendwalk.com      (后端 API)
```

### Session Domain 配置

```bash
SESSION_DOMAIN=.sendwalk.com
```

- 前面的 `.` 非常重要
- 表示 session cookie 可以在所有 `*.sendwalk.com` 子域名之间共享
- 包括 `edm.sendwalk.com` 和 `api.sendwalk.com`

### CORS 工作原理

```
浏览器访问: https://edm.sendwalk.com
↓
发起 API 请求: https://api.sendwalk.com/api/xxx
↓
浏览器检查: Origin (edm.sendwalk.com) 是否允许访问
↓
后端返回: Access-Control-Allow-Origin: https://edm.sendwalk.com
↓
浏览器: ✓ 允许请求
```

## 📚 相关文档

- **Laravel CORS 文档**: https://laravel.com/docs/cors
- **Laravel Sanctum 文档**: https://laravel.com/docs/sanctum
- **MDN CORS 指南**: https://developer.mozilla.org/zh-CN/docs/Web/HTTP/CORS

## 💡 开发建议

### 开发环境

在开发环境，建议使用：

```bash
# backend/.env
FRONTEND_URL=http://localhost:5173
SANCTUM_STATEFUL_DOMAINS=localhost:5173,127.0.0.1:5173
SESSION_DOMAIN=localhost
```

### 生产环境

在生产环境，必须使用具体域名：

```bash
# backend/.env
FRONTEND_URL=https://edm.sendwalk.com
SANCTUM_STATEFUL_DOMAINS=edm.sendwalk.com
SESSION_DOMAIN=.sendwalk.com
```

### 调试技巧

1. **使用浏览器开发者工具**
   - Network 选项卡查看请求头和响应头
   - Console 查看具体的 CORS 错误信息

2. **查看 Laravel 日志**
   ```bash
   tail -f storage/logs/laravel-$(date +%Y-%m-%d).log
   ```

3. **使用 tinker 验证配置**
   ```bash
   php artisan tinker
   config('cors')
   config('sanctum')
   ```

## ✅ 快速修复命令汇总

```bash
# 1. 修复 CORS 配置
cd /data/www/sendwalk
./fix-cors-error.sh

# 2. 重启服务
sudo systemctl restart php8.3-fpm
sudo supervisorctl restart all

# 3. 验证配置
cd backend
php artisan tinker
# 运行: config('cors.allowed_origins')

# 4. 测试 API
curl -H "Origin: https://edm.sendwalk.com" \
     -I https://api.sendwalk.com/api/health

# 5. 查看日志
tail -50 storage/logs/laravel-$(date +%Y-%m-%d).log
```

---

**CORS 问题解决后，前端应该能够正常调用后端 API！** ✅

