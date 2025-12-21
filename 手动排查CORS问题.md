# 手动排查 CORS 问题 - 逐步指南

## 🔴 如果脚本运行后问题仍然存在

按照以下步骤逐一检查，每一步都要确认通过。

## 📋 第一步：运行深度诊断

```bash
cd /data/www/sendwalk
chmod +x debug-cors-detailed.sh
./debug-cors-detailed.sh > cors-debug-report.txt 2>&1
cat cors-debug-report.txt
```

**仔细阅读报告**，特别关注标记为 ✗ 的项目。

## 📋 第二步：手动验证每个配置

### 2.1 检查后端 .env

```bash
cd /data/www/sendwalk/backend
cat .env | grep -E "APP_URL|FRONTEND_URL|SANCTUM|SESSION"
```

**必须完全一致**：
```bash
APP_URL=https://api.sendwalk.com
FRONTEND_URL=https://edm.sendwalk.com
SANCTUM_STATEFUL_DOMAINS=edm.sendwalk.com
SESSION_DOMAIN=.sendwalk.com
```

**特别检查**：`SESSION_DOMAIN` 前面有没有点？

```bash
# 这个命令会显示点
grep "^SESSION_DOMAIN=" .env | cat -A

# 应该看到: SESSION_DOMAIN=.sendwalk.com$
# 不应该是: SESSION_DOMAIN=sendwalk.com$
```

### 2.2 检查 Laravel 配置是否生效

```bash
cd /data/www/sendwalk/backend
php artisan tinker
```

在 tinker 中运行：

```php
// 1. 检查 CORS 配置
config('cors.allowed_origins')
// 期望: ["https://edm.sendwalk.com"]

config('cors.supports_credentials')
// 期望: true

config('cors.paths')
// 期望: ["api/*", "sanctum/csrf-cookie"]

// 2. 检查 Sanctum 配置
config('sanctum.stateful')
// 期望: ["edm.sendwalk.com"] 或包含 edm.sendwalk.com

// 3. 检查 Session 配置
config('session.domain')
// 期望: ".sendwalk.com" （注意有点）

config('session.driver')
// 期望: "redis" 或 "file"

// 4. 退出
exit
```

**如果输出不对**，说明配置缓存有问题。

### 2.3 强制清除配置缓存

```bash
cd /data/www/sendwalk/backend

# 删除缓存文件
rm -f bootstrap/cache/config.php
rm -f bootstrap/cache/routes.php
rm -f bootstrap/cache/services.php

# 清除 Laravel 缓存
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

# 如果使用 Redis
php artisan cache:clear --tags=config

# 重新生成缓存
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 验证配置文件已生成
ls -la bootstrap/cache/
```

### 2.4 检查前端配置

```bash
cd /data/www/sendwalk/frontend
cat .env
```

**必须是**：
```bash
VITE_API_URL=https://api.sendwalk.com
VITE_APP_NAME=SendWalk
```

**检查构建时间**：
```bash
ls -lh dist/index.html
```

如果构建时间太早（在修改 .env 之前），必须重新构建：

```bash
rm -rf dist
npm run build
```

**验证构建产物**：
```bash
# 检查 API URL 是否正确
grep -r "api\.sendwalk\.com" dist/assets/ | head -3

# 应该能找到 API URL
```

## 📋 第三步：重启所有服务

**按顺序执行，不要跳过**：

```bash
# 1. 重启 PHP-FPM（重要！）
sudo systemctl restart php8.3-fpm
sudo systemctl status php8.3-fpm

# 2. 重启 Nginx
sudo systemctl restart nginx
sudo systemctl status nginx

# 3. 如果使用 Redis
sudo systemctl restart redis-server
sudo systemctl status redis-server

# 4. 重启 Supervisor
sudo supervisorctl restart all
sudo supervisorctl status

# 等待 5 秒让服务完全启动
sleep 5
```

## 📋 第四步：测试 CORS

### 4.1 命令行测试

```bash
# 测试 OPTIONS 预检请求
curl -v -X OPTIONS \
  -H "Origin: https://edm.sendwalk.com" \
  -H "Access-Control-Request-Method: POST" \
  -H "Access-Control-Request-Headers: Content-Type, Authorization" \
  https://api.sendwalk.com/api/campaigns
```

**应该看到**：
```
< HTTP/2 204
< access-control-allow-origin: https://edm.sendwalk.com
< access-control-allow-credentials: true
< access-control-allow-methods: POST, GET, OPTIONS, ...
< access-control-allow-headers: Content-Type, Authorization, ...
```

**如果没有这些头**，说明 Laravel CORS 没有生效。

### 4.2 测试实际请求

```bash
# 测试 GET 请求
curl -v \
  -H "Origin: https://edm.sendwalk.com" \
  https://api.sendwalk.com/api/health
```

**应该在响应头中看到**：
```
< access-control-allow-origin: https://edm.sendwalk.com
< access-control-allow-credentials: true
```

## 📋 第五步：浏览器测试

### 5.1 清除浏览器缓存

**Chrome/Edge**:
- 按 `Ctrl+Shift+Delete`
- 选择 "全部时间"
- 勾选 "缓存的图片和文件"
- 点击 "清除数据"

**或者使用隐私模式**：
- `Ctrl+Shift+N` (Chrome)
- `Ctrl+Shift+P` (Firefox)

### 5.2 浏览器开发者工具测试

1. 打开 `https://edm.sendwalk.com`
2. 按 `F12` 打开开发者工具
3. 切换到 **Network** 选项卡
4. 勾选 "Preserve log"
5. 尝试登录或调用任何 API

**检查请求**：
- 找到对 `api.sendwalk.com` 的请求
- 点击查看详情
- 切换到 **Headers** 标签
- 查看 **Response Headers**

**应该看到**：
```
access-control-allow-origin: https://edm.sendwalk.com
access-control-allow-credentials: true
```

**如果看不到**，切换到 **Console** 标签，查看错误信息。

### 5.3 检查实际错误

在 Console 中，CORS 错误通常是：
```
Access to XMLHttpRequest at 'https://api.sendwalk.com/...' 
from origin 'https://edm.sendwalk.com' has been blocked by CORS policy
```

**但有时错误可能不是 CORS**：
- `net::ERR_CERT_AUTHORITY_INVALID` - SSL 证书问题
- `net::ERR_NAME_NOT_RESOLVED` - DNS 问题
- `401 Unauthorized` - 认证问题（不是 CORS）
- `500 Internal Server Error` - 服务器错误（不是 CORS）

## 📋 第六步：检查中间件

### 6.1 验证 CORS 中间件已加载

```bash
cd /data/www/sendwalk/backend
cat app/Http/Kernel.php | grep -A 20 "protected \$middleware"
```

**必须包含**：
```php
\Illuminate\Http\Middleware\HandleCors::class,
```

如果没有，添加它：

```bash
nano app/Http/Kernel.php
```

在 `$middleware` 数组中添加：
```php
protected $middleware = [
    // ...
    \Illuminate\Http\Middleware\HandleCors::class,
    // ...
];
```

### 6.2 检查 CORS 配置文件

```bash
cat config/cors.php
```

**确认内容**：
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
    
    'supports_credentials' => true,
];
```

**关键点**：
- `'allowed_origins' => [env('FRONTEND_URL', ...)]` 使用环境变量
- `'supports_credentials' => true` 必须为 true

## 📋 第七步：检查 Nginx

### 7.1 检查 Nginx 配置

```bash
cat /etc/nginx/conf.d/sendwalk-api.conf | grep -i "add_header"
```

**不应该有**：
```nginx
add_header 'Access-Control-Allow-Origin' ...
add_header 'Access-Control-Allow-Methods' ...
```

**如果有，删除它们**，让 Laravel 处理 CORS：

```bash
sudo nano /etc/nginx/conf.d/sendwalk-api.conf

# 删除所有 Access-Control 相关的 add_header

# 测试配置
sudo nginx -t

# 重启 Nginx
sudo systemctl restart nginx
```

### 7.2 检查 Nginx 错误日志

```bash
sudo tail -50 /var/log/nginx/sendwalk-api-error.log
```

查看是否有：
- PHP 错误
- 权限错误
- 上游连接错误

## 📋 第八步：检查 Cloudflare（如果使用）

如果域名使用了 Cloudflare：

### 8.1 检查 SSL 模式

在 Cloudflare 控制台：
- SSL/TLS → 概述
- 确保选择 **Full (strict)** 模式

### 8.2 清除 Cloudflare 缓存

- 缓存 → 配置
- 点击 "清除所有内容"

### 8.3 检查 Cloudflare 规则

- 规则 → 页面规则
- 确保没有规则干扰 API 请求

## 📋 第九步：检查 Redis（如果使用）

```bash
# 检查 Redis 是否运行
sudo systemctl status redis-server

# 测试 Redis 连接
redis-cli ping
# 应该返回: PONG

# 测试 Laravel 连接 Redis
cd /data/www/sendwalk/backend
php artisan tinker
```

```php
use Illuminate\Support\Facades\Redis;
Redis::connection()->ping();
// 应该返回: "+PONG"

exit
```

**如果 Redis 有问题**，临时切换到文件缓存：

```bash
nano /data/www/sendwalk/backend/.env

# 修改
CACHE_DRIVER=file
SESSION_DRIVER=file

# 重启
sudo systemctl restart php8.3-fpm
```

## 📋 第十步：临时宽松 CORS（调试用）

**仅用于调试，找到问题后立即改回去！**

```bash
cd /data/www/sendwalk/backend
cp config/cors.php config/cors.php.backup

nano config/cors.php
```

临时改为：
```php
return [
    'paths' => ['*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => ['*'],  // 允许所有来源
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,  // 注意改为 false
];
```

清除缓存并测试：
```bash
php artisan config:clear
php artisan config:cache
sudo systemctl restart php8.3-fpm
```

**在浏览器中测试**：
- 如果工作了 → 说明是配置问题，恢复并正确配置
- 如果还不工作 → 说明不是 CORS 问题，是其他问题

**恢复配置**：
```bash
mv config/cors.php.backup config/cors.php
php artisan config:cache
sudo systemctl restart php8.3-fpm
```

## 📋 第十一步：查看实时日志

在一个终端运行：
```bash
tail -f /data/www/sendwalk/backend/storage/logs/laravel-$(date +%Y-%m-%d).log
```

在另一个终端运行：
```bash
sudo tail -f /var/log/nginx/sendwalk-api-error.log
```

然后在浏览器中触发请求，观察日志输出。

## 🆘 仍然无法解决？

### 收集完整信息

```bash
cd /data/www/sendwalk

# 生成完整报告
./debug-cors-detailed.sh > full-report.txt 2>&1

# 添加更多信息
echo "=== PHP-FPM Configuration ===" >> full-report.txt
cat /etc/php/8.3/fpm/pool.d/www.conf | grep -v "^;" | grep -v "^$" >> full-report.txt

echo "=== Nginx Configuration ===" >> full-report.txt
cat /etc/nginx/conf.d/sendwalk-api.conf >> full-report.txt

# 查看报告
cat full-report.txt
```

### 可能的根本原因

如果以上所有步骤都正确，但仍然报错，可能是：

1. **实际上不是 CORS 错误**
   - 仔细看浏览器 Console 的完整错误信息
   - 可能是 401/403/500 等其他错误

2. **浏览器扩展干扰**
   - 禁用所有浏览器扩展重试

3. **公司/学校网络限制**
   - 尝试使用手机热点测试

4. **DNS 劫持**
   - 检查 `/etc/hosts` 文件
   - 使用 `nslookup` 验证 DNS 解析

5. **防火墙阻止**
   - 检查服务器防火墙规则
   - 检查云服务商安全组规则

### 请提供以下信息

如果需要进一步帮助，请提供：

1. **完整的浏览器错误信息**（Console 截图）
2. **Network 选项卡中失败请求的详细信息**
3. **debug-cors-detailed.sh 的完整输出**
4. **Laravel 日志中的错误**
5. **Nginx 错误日志**

---

**90% 的 CORS 问题都是配置缓存或 SESSION_DOMAIN 的问题！** ✅

