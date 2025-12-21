# Nginx 配置说明

## 📂 配置文件位置

本项目的 Nginx 配置文件应放置在 `/etc/nginx/conf.d/` 目录下。

### 配置文件清单

| 配置文件 | 源文件 | 目标位置 |
|---------|-------|---------|
| API 配置 | `nginx/api.conf` | `/etc/nginx/conf.d/sendwalk-api.conf` |
| 前端配置 | `nginx/frontend.conf` | `/etc/nginx/conf.d/sendwalk-frontend.conf` |

## 🔧 部署配置文件

### 复制配置文件

```bash
# 复制 API 配置
sudo cp /data/www/sendwalk/nginx/api.conf /etc/nginx/conf.d/sendwalk-api.conf

# 复制前端配置
sudo cp /data/www/sendwalk/nginx/frontend.conf /etc/nginx/conf.d/sendwalk-frontend.conf
```

### 测试和重启

```bash
# 测试 Nginx 配置
sudo nginx -t

# 如果测试通过，重启 Nginx
sudo systemctl restart nginx

# 检查 Nginx 状态
sudo systemctl status nginx
```

## 📋 Nginx 配置目录说明

### `/etc/nginx/conf.d/` 方式（本项目使用）

**特点**:
- ✅ 简单直接，只需复制配置文件
- ✅ 不需要创建软链接
- ✅ 适用于大多数 Linux 发行版
- ✅ 配置文件以 `.conf` 结尾会被自动加载

**使用方式**:
```bash
# 将配置文件直接复制到 conf.d 目录
sudo cp nginx/api.conf /etc/nginx/conf.d/sendwalk-api.conf
```

**Nginx 主配置**:
```nginx
# /etc/nginx/nginx.conf 中应包含
http {
    include /etc/nginx/conf.d/*.conf;
}
```

### `/etc/nginx/sites-available/` + `/etc/nginx/sites-enabled/` 方式（Debian/Ubuntu 传统）

**特点**:
- available: 存放所有可用的站点配置
- enabled: 通过软链接启用特定站点
- 适合管理多个站点，可以方便地启用/禁用

**使用方式**:
```bash
# 复制配置到 sites-available
sudo cp nginx/api.conf /etc/nginx/sites-available/sendwalk-api

# 创建软链接到 sites-enabled
sudo ln -s /etc/nginx/sites-available/sendwalk-api /etc/nginx/sites-enabled/

# 禁用站点（删除软链接）
sudo rm /etc/nginx/sites-enabled/sendwalk-api
```

## ✅ 配置验证

### 1. 检查配置文件是否存在

```bash
# 列出 conf.d 目录下的配置
ls -lh /etc/nginx/conf.d/sendwalk-*.conf

# 应该看到：
# /etc/nginx/conf.d/sendwalk-api.conf
# /etc/nginx/conf.d/sendwalk-frontend.conf
```

### 2. 测试配置语法

```bash
# 测试所有 Nginx 配置
sudo nginx -t

# 期望输出：
# nginx: the configuration file /etc/nginx/nginx.conf syntax is ok
# nginx: configuration file /etc/nginx/nginx.conf test is successful
```

### 3. 检查配置是否生效

```bash
# 查看 Nginx 加载的配置
sudo nginx -T | grep -A 5 "sendwalk"

# 或者查看监听的端口
sudo netstat -tlnp | grep nginx
# 应该看到监听 80 和 443 端口
```

### 4. 查看配置文件内容

```bash
# 查看 API 配置
sudo cat /etc/nginx/conf.d/sendwalk-api.conf

# 查看前端配置
sudo cat /etc/nginx/conf.d/sendwalk-frontend.conf
```

## 🔄 更新配置

### 当配置文件有变更时

```bash
# 1. 从项目拉取最新代码
cd /data/www/sendwalk
git pull

# 2. 复制更新的配置文件（覆盖旧文件）
sudo cp nginx/api.conf /etc/nginx/conf.d/sendwalk-api.conf
sudo cp nginx/frontend.conf /etc/nginx/conf.d/sendwalk-frontend.conf

# 3. 测试配置
sudo nginx -t

# 4. 如果测试通过，重新加载配置（无缝更新）
sudo systemctl reload nginx

# 或者重启 Nginx
sudo systemctl restart nginx
```

## 🗑️ 删除配置

### 如果需要完全删除配置

```bash
# 删除配置文件
sudo rm /etc/nginx/conf.d/sendwalk-api.conf
sudo rm /etc/nginx/conf.d/sendwalk-frontend.conf

# 测试配置
sudo nginx -t

# 重启 Nginx
sudo systemctl restart nginx
```

## 🔍 故障排查

### 问题 1: 配置文件未被加载

**检查**:
```bash
# 查看主配置文件
sudo cat /etc/nginx/nginx.conf | grep "conf.d"

# 应该包含：
# include /etc/nginx/conf.d/*.conf;
```

**解决方案**:
如果没有包含该行，编辑 `/etc/nginx/nginx.conf`：

```nginx
http {
    # 其他配置...
    
    # 添加这一行
    include /etc/nginx/conf.d/*.conf;
}
```

### 问题 2: 端口冲突

**检查**:
```bash
# 查看 80 和 443 端口占用情况
sudo netstat -tlnp | grep :80
sudo netstat -tlnp | grep :443

# 或使用 lsof
sudo lsof -i :80
sudo lsof -i :443
```

**解决方案**:
- 确保没有其他服务占用 80/443 端口
- 检查是否有默认的 Nginx 配置冲突：
```bash
# 检查 default 配置
ls -lh /etc/nginx/conf.d/default.conf
ls -lh /etc/nginx/sites-enabled/default

# 如果存在且冲突，可以禁用或删除
sudo mv /etc/nginx/conf.d/default.conf /etc/nginx/conf.d/default.conf.bak
```

### 问题 3: Nginx 启动失败

**查看错误日志**:
```bash
# 查看 Nginx 错误日志
sudo tail -50 /var/log/nginx/error.log

# 查看 Nginx 服务状态
sudo systemctl status nginx

# 查看详细的启动日志
sudo journalctl -u nginx -n 50
```

**常见错误**:
- SSL 证书文件不存在或权限不对
- 配置文件语法错误
- 端口被占用
- 上游服务（PHP-FPM）未运行

### 问题 4: 配置更新后未生效

**解决方案**:
```bash
# 1. 确认配置文件已更新
sudo ls -lh /etc/nginx/conf.d/sendwalk-*.conf

# 2. 测试配置
sudo nginx -t

# 3. 强制重启 Nginx（不是 reload）
sudo systemctl restart nginx

# 4. 清除浏览器缓存或使用隐私模式测试

# 5. 检查是否有缓存层（如 Cloudflare）
# 在 Cloudflare 控制台清除缓存
```

## 📊 配置文件说明

### API 配置 (`sendwalk-api.conf`)

- **域名**: `api.sendwalk.com`
- **端口**: 80 (HTTP), 443 (HTTPS)
- **Root**: `/data/www/sendwalk/backend/public`
- **PHP**: FastCGI 连接到 `php8.3-fpm.sock`
- **SSL**: 使用 Cloudflare 证书
- **特性**:
  - 支持大文件上传（100MB）
  - PHP 超时时间 300 秒
  - HTTP 自动重定向到 HTTPS

### 前端配置 (`sendwalk-frontend.conf`)

- **域名**: `edm.sendwalk.com`
- **端口**: 80 (HTTP), 443 (HTTPS)
- **Root**: `/data/www/sendwalk/frontend/dist`
- **SSL**: 使用 Cloudflare 证书
- **特性**:
  - SPA 路由支持（try_files）
  - Gzip 压缩
  - 静态文件缓存（1年）
  - HTTP 自动重定向到 HTTPS

## 🔐 SSL 配置

两个配置文件都包含完整的 SSL 设置：

- **证书文件**: `/data/www/ca/sendwalk.pem`
- **私钥文件**: `/data/www/ca/sendwalk.key`
- **TLS 协议**: 1.2, 1.3
- **安全头**: HSTS, X-Frame-Options, X-Content-Type-Options 等

详细说明请查看：[SSL证书配置说明.md](./SSL证书配置说明.md)

## ✅ 快速检查清单

部署完成后确认：

- [ ] 配置文件已复制到 `/etc/nginx/conf.d/`
- [ ] 配置文件以 `.conf` 结尾
- [ ] 配置文件权限正确（644）
- [ ] Nginx 配置测试通过（`nginx -t`）
- [ ] Nginx 已重启并运行正常
- [ ] 可以访问前端（https://edm.sendwalk.com）
- [ ] 可以访问 API（https://api.sendwalk.com/api/health）
- [ ] HTTP 自动重定向到 HTTPS
- [ ] SSL 证书正常工作

## 🔗 相关文档

- **SSL 证书配置**: [SSL证书配置说明.md](./SSL证书配置说明.md)
- **域名配置**: [域名配置说明.md](./域名配置说明.md)
- **部署路径**: [部署路径说明.md](./部署路径说明.md)
- **快速部署**: [快速部署命令.sh](./快速部署命令.sh)

---

**使用 `/etc/nginx/conf.d/` 目录简化了配置管理，无需软链接操作。** ✅

