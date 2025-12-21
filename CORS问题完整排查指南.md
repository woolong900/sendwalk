# CORS 问题完整排查指南

## 🔴 如果还在报 CORS 错误

即使配置看起来正确，仍可能遇到 CORS 错误。以下是完整的排查步骤。

## 🔍 立即诊断

```bash
cd /data/www/sendwalk
chmod +x diagnose-cors.sh
./diagnose-cors.sh
```

这个脚本会检查所有关键配置并测试 CORS 响应。

## ⚠️ 常见陷阱

### 陷阱 1: 配置缓存未清除

**症状**: 修改了 .env 但不生效

**解决方案**:
```bash
cd /data/www/sendwalk/backend

# 必须清除缓存
php artisan config:clear
php artisan cache:clear

# 重新生成缓存
php artisan config:cache

# 重启 PHP-FPM（很重要！）
sudo systemctl restart php8.3-fpm
```

### 陷阱 2: SESSION_DOMAIN 前面缺少点

**错误配置**:
```bash
SESSION_DOMAIN=sendwalk.com  # ✗ 错误
```

**正确配置**:
```bash
SESSION_DOMAIN=.sendwalk.com  # ✓ 正确（注意前面的点）
```

**为什么需要这个点**:
- `.sendwalk.com` 表示所有子域名都可以共享 session
- 包括 `edm.sendwalk.com` 和 `api.sendwalk.com`

### 陷阱 3: 前端构建未更新

**症状**: 前端 .env 修改了但不生效

**解决方案**:
```bash
cd /data/www/sendwalk/frontend

# 删除旧的构建
rm -rf dist

# 重新构建
npm run build

# 验证 API URL
grep -r "api.sendwalk.com" dist/assets/
```

### 陷阱 4: 多个 CORS 中间件冲突

**检查**: Nginx 和 Laravel 都添加了 CORS 头

**解决方案**: 让 Laravel 处理 CORS，Nginx 不要添加

```bash
# 检查 Nginx 配置
cat /etc/nginx/conf.d/sendwalk-api.conf | grep -i "access-control"

# 如果有输出，需要删除这些行
```

### 陷阱 5: HTTPS/HTTP 协议混淆

**检查配置**:
```bash
# 所有 URL 必须使用 HTTPS
grep -E "APP_URL|FRONTEND_URL" /data/www/sendwalk/backend/.env

# 应该全部是 https://
APP_URL=https://api.sendwalk.com
FRONTEND_URL=https://edm.sendwalk.com
```

## 🔧 完整修复流程

### 步骤 1: 运行诊断

```bash
cd /data/www/sendwalk
./diagnose-cors.sh > cors-diagnosis.txt
cat cors-diagnosis.txt
```

### 步骤 2: 修复配置

```bash
# 运行修复脚本
./fix-cors-error.sh

# 验证配置
cd backend
grep -E "APP_URL|FRONTEND_URL|SANCTUM|SESSION" .env
```

### 步骤 3: 清除所有缓存

```bash
cd /data/www/sendwalk/backend

# 清除 Laravel 缓存
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

# 清除 opcache（如果启用）
sudo systemctl reload php8.3-fpm

# 重新生成缓存
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### 步骤 4: 验证前端配置并重建

```bash
cd /data/www/sendwalk/frontend

# 检查前端 .env
cat .env

# 应该看到:
# VITE_API_URL=https://api.sendwalk.com

# 如果不正确，修复它:
echo "VITE_API_URL=https://api.sendwalk.com" > .env
echo "VITE_APP_NAME=SendWalk" >> .env

# 重新构建
rm -rf dist
npm run build

# 验证构建结果中的 API URL
grep -r "api.sendwalk.com" dist/assets/ | head -3
```

### 步骤 5: 重启所有服务

```bash
# 重启 PHP-FPM
sudo systemctl restart php8.3-fpm

# 重启 Nginx
sudo systemctl restart nginx

# 重启 Supervisor 进程
sudo supervisorctl restart all

# 验证服务状态
sudo systemctl status php8.3-fpm
sudo systemctl status nginx
sudo supervisorctl status
```

### 步骤 6: 测试 CORS

```bash
# 测试 OPTIONS 预检请求
curl -X OPTIONS \
  -H "Origin: https://edm.sendwalk.com" \
  -H "Access-Control-Request-Method: POST" \
  -H "Access-Control-Request-Headers: Content-Type, Authorization" \
  -i \
  https://api.sendwalk.com/api/campaigns

# 应该看到:
# HTTP/2 204
# access-control-allow-origin: https://edm.sendwalk.com
# access-control-allow-credentials: true
```

### 步骤 7: 浏览器测试

1. 清除浏览器缓存（Ctrl+Shift+Delete）
2. 打开隐私/无痕模式
3. 访问 `https://edm.sendwalk.com`
4. 打开开发者工具（F12）
5. 切换到 Network 选项卡
6. 尝试登录或调用 API
7. 查看请求的 Response Headers

**应该看到**:
```
access-control-allow-origin: https://edm.sendwalk.com
access-control-allow-credentials: true
```

## 🐛 深度调试

### 查看 Laravel 配置

```bash
cd /data/www/sendwalk/backend
php artisan tinker

# 在 tinker 中运行以下命令:
```

```php
// 查看 CORS 配置
config('cors')

// 查看 allowed_origins
config('cors.allowed_origins')
// 应该输出: ["https://edm.sendwalk.com"]

// 查看 supports_credentials
config('cors.supports_credentials')
// 应该输出: true

// 查看 Sanctum 配置
config('sanctum.stateful')
// 应该输出: ["edm.sendwalk.com", ...]

// 查看 session domain
config('session.domain')
// 应该输出: ".sendwalk.com"
```

### 查看实时日志

在一个终端中：
```bash
# 监控 Laravel 日志
tail -f /data/www/sendwalk/backend/storage/logs/laravel-$(date +%Y-%m-%d).log
```

在另一个终端中：
```bash
# 监控 Nginx 错误日志
sudo tail -f /var/log/nginx/sendwalk-api-error.log
```

然后在浏览器中触发 API 请求，观察日志输出。

### 检查 PHP-FPM 配置

```bash
# 查看 PHP-FPM 配置
sudo cat /etc/php/8.3/fpm/pool.d/www.conf | grep -E "user|group"

# 应该是:
# user = www-data
# group = www-data
```

### 检查文件权限

```bash
# 检查后端权限
ls -la /data/www/sendwalk/backend/ | head -10

# storage 目录必须可写
ls -la /data/www/sendwalk/backend/storage/

# 应该看到:
# drwxrwxr-x ... www-data www-data ... storage
```

## 🔬 高级问题排查

### 问题: OPTIONS 请求返回 404

**原因**: 路由不存在或被过滤

**解决方案**:
```bash
cd /data/www/sendwalk/backend

# 查看路由
php artisan route:list | grep "api/"

# 确保 api/* 路由存在

# 检查 CORS 配置
cat config/cors.php | grep paths
# 应该包含: 'paths' => ['api/*', 'sanctum/csrf-cookie'],
```

### 问题: 返回 200 但没有 CORS 头

**原因**: Laravel CORS 中间件未加载

**解决方案**:
```bash
# 检查中间件
cat app/Http/Kernel.php | grep HandleCors

# 应该在 $middleware 数组中看到:
# \Illuminate\Http\Middleware\HandleCors::class,

# 如果没有，添加它
nano app/Http/Kernel.php
```

### 问题: 预检请求成功但实际请求失败

**原因**: 可能是认证问题，不是 CORS 问题

**检查**:
```bash
# 查看具体的错误响应
curl -H "Origin: https://edm.sendwalk.com" \
     -H "Authorization: Bearer YOUR_TOKEN" \
     -i \
     https://api.sendwalk.com/api/campaigns
```

### 问题: 在本地开发可以，生产不行

**检查差异**:

```bash
# 开发环境
FRONTEND_URL=http://localhost:5173
SANCTUM_STATEFUL_DOMAINS=localhost:5173

# 生产环境
FRONTEND_URL=https://edm.sendwalk.com
SANCTUM_STATEFUL_DOMAINS=edm.sendwalk.com

# 确保没有混用
```

## 📋 完整配置参考

### backend/.env（生产环境）

```bash
# 应用配置
APP_NAME=SendWalk
APP_ENV=production
APP_KEY=base64:your_generated_key_here
APP_DEBUG=false
APP_URL=https://api.sendwalk.com
APP_TIMEZONE=Asia/Shanghai

# 数据库配置
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sendwalk
DB_USERNAME=sendwalk
DB_PASSWORD=your_password

# 缓存和队列
CACHE_DRIVER=redis
QUEUE_CONNECTION=database
SESSION_DRIVER=redis
SESSION_LIFETIME=120

# Redis 配置
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
REDIS_DB=0
REDIS_CACHE_DB=1

# 前端 URL（关键！）
FRONTEND_URL=https://edm.sendwalk.com

# CORS 配置（关键！）
SANCTUM_STATEFUL_DOMAINS=edm.sendwalk.com,localhost,localhost:5173,127.0.0.1:5173
SESSION_DOMAIN=.sendwalk.com

# 安全配置
BCRYPT_ROUNDS=12

# 日志配置
LOG_CHANNEL=daily
LOG_LEVEL=warning
LOG_DAILY_DAYS=30
```

### frontend/.env（生产环境）

```bash
VITE_API_URL=https://api.sendwalk.com
VITE_APP_NAME=SendWalk
```

### backend/config/cors.php

```php
<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    
    'allowed_methods' => ['*'],
    
    // 关键：使用 FRONTEND_URL 环境变量
    'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:5173')],
    
    'allowed_origins_patterns' => [],
    
    'allowed_headers' => ['*'],
    
    'exposed_headers' => [],
    
    'max_age' => 0,
    
    // 关键：必须为 true
    'supports_credentials' => true,
];
```

## ✅ 最终检查清单

完成以下所有项目：

### 配置检查
- [ ] `backend/.env` 中 `FRONTEND_URL=https://edm.sendwalk.com`
- [ ] `backend/.env` 中 `APP_URL=https://api.sendwalk.com`
- [ ] `backend/.env` 中 `SANCTUM_STATEFUL_DOMAINS=edm.sendwalk.com`
- [ ] `backend/.env` 中 `SESSION_DOMAIN=.sendwalk.com` （注意点）
- [ ] `frontend/.env` 中 `VITE_API_URL=https://api.sendwalk.com`
- [ ] `config/cors.php` 中 `supports_credentials => true`

### 缓存和构建
- [ ] 已运行 `php artisan config:clear`
- [ ] 已运行 `php artisan config:cache`
- [ ] 前端已重新构建 `npm run build`
- [ ] 已验证构建产物包含正确的 API URL

### 服务状态
- [ ] PHP-FPM 已重启
- [ ] Nginx 已重启
- [ ] Supervisor 进程已重启
- [ ] 所有服务运行正常

### CORS 测试
- [ ] `curl OPTIONS` 测试返回 CORS 头
- [ ] 浏览器 Network 中看到 CORS 头
- [ ] 浏览器 Console 中没有 CORS 错误
- [ ] 可以成功登录和调用 API

### 域名和 SSL
- [ ] `edm.sendwalk.com` DNS 解析正确
- [ ] `api.sendwalk.com` DNS 解析正确
- [ ] SSL 证书有效且支持两个域名
- [ ] HTTPS 访问正常（无证书警告）

## 🆘 仍然无法解决？

### 收集信息

```bash
# 生成完整诊断报告
cd /data/www/sendwalk
./diagnose-cors.sh > cors-report.txt

# 查看最近的错误
tail -100 backend/storage/logs/laravel-$(date +%Y-%m-%d).log >> cors-report.txt

# 查看 Nginx 错误
sudo tail -100 /var/log/nginx/sendwalk-api-error.log >> cors-report.txt

# 查看报告
cat cors-report.txt
```

### 临时调试模式

如果确实无法解决，可以临时启用更宽松的 CORS 设置进行调试：

```bash
# 编辑 config/cors.php
nano backend/config/cors.php
```

```php
// 临时调试配置（不要用于生产！）
return [
    'paths' => ['*'],  // 允许所有路径
    'allowed_methods' => ['*'],
    'allowed_origins' => ['*'],  // 临时允许所有来源
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,  // 注意：改为 false
];
```

然后测试是否工作。如果工作了，说明是配置问题；如果还不工作，说明是其他问题。

**记住**: 测试完后立即改回安全配置！

## 📞 联系支持

如果尝试了所有步骤仍然无法解决：

1. 提供完整的诊断报告 (`cors-report.txt`)
2. 提供浏览器 Console 的截图
3. 提供 Network 选项卡中失败请求的详细信息
4. 说明已经尝试过的步骤

---

**绝大多数 CORS 问题都是配置缓存未清除或 SESSION_DOMAIN 缺少点导致的！** ✅

