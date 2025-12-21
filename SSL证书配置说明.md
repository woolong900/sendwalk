# SSL 证书配置说明

## 📜 证书信息

本项目使用 **Cloudflare 生成的 SSL 证书**。

### 证书文件位置

| 文件类型 | 路径 |
|---------|------|
| 证书文件 (PEM) | `/data/www/ca/sendwalk.pem` |
| 私钥文件 (KEY) | `/data/www/ca/sendwalk.key` |

## ✅ 已配置的文件

### 1. **Nginx 前端配置** (`nginx/frontend.conf`)

```nginx
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name edm.sendwalk.com;
    
    # SSL 证书配置
    ssl_certificate /data/www/ca/sendwalk.pem;
    ssl_certificate_key /data/www/ca/sendwalk.key;
    
    # SSL 安全配置
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers 'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256...';
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 10m;
    
    # ... 其他配置
}

# HTTP 自动重定向到 HTTPS
server {
    listen 80;
    server_name edm.sendwalk.com;
    return 301 https://$server_name$request_uri;
}
```

### 2. **Nginx API 配置** (`nginx/api.conf`)

```nginx
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name api.edm.sendwalk.com;
    
    # SSL 证书配置
    ssl_certificate /data/www/ca/sendwalk.pem;
    ssl_certificate_key /data/www/ca/sendwalk.key;
    
    # ... SSL 安全配置
}

# HTTP 自动重定向到 HTTPS
server {
    listen 80;
    server_name api.edm.sendwalk.com;
    return 301 https://$server_name$request_uri;
}
```

## 🚀 部署步骤

### 1. 确认证书文件已上传

```bash
# 检查证书文件是否存在
ls -lh /data/www/ca/sendwalk.pem
ls -lh /data/www/ca/sendwalk.key

# 检查证书文件权限（应该是 600 或 644）
stat /data/www/ca/sendwalk.pem
stat /data/www/ca/sendwalk.key
```

### 2. 设置正确的权限

```bash
# 创建证书目录（如果不存在）
sudo mkdir -p /data/www/ca

# 设置所有者为 root
sudo chown root:root /data/www/ca/sendwalk.pem
sudo chown root:root /data/www/ca/sendwalk.key

# 设置证书文件权限
sudo chmod 644 /data/www/ca/sendwalk.pem
sudo chmod 600 /data/www/ca/sendwalk.key  # 私钥必须是 600
```

### 3. 验证证书内容

```bash
# 查看证书信息
openssl x509 -in /data/www/ca/sendwalk.pem -text -noout

# 查看证书有效期
openssl x509 -in /data/www/ca/sendwalk.pem -noout -dates

# 查看证书支持的域名
openssl x509 -in /data/www/ca/sendwalk.pem -noout -text | grep -A1 "Subject Alternative Name"

# 验证私钥和证书是否匹配
openssl x509 -noout -modulus -in /data/www/ca/sendwalk.pem | openssl md5
openssl rsa -noout -modulus -in /data/www/ca/sendwalk.key | openssl md5
# 两个命令的输出应该完全相同
```

### 4. 配置 Nginx

```bash
# 复制配置文件到 conf.d 目录
sudo cp /data/www/sendwalk/nginx/api.conf /etc/nginx/conf.d/sendwalk-api.conf
sudo cp /data/www/sendwalk/nginx/frontend.conf /etc/nginx/conf.d/sendwalk-frontend.conf

# 测试 Nginx 配置
sudo nginx -t
```

### 5. 重启 Nginx

```bash
# 如果配置测试通过，重启 Nginx
sudo systemctl restart nginx

# 检查 Nginx 状态
sudo systemctl status nginx
```

### 6. 测试 HTTPS 访问

```bash
# 测试前端 HTTPS
curl -I https://edm.sendwalk.com

# 测试 API HTTPS
curl -I https://api.edm.sendwalk.com/api/health

# 测试 HTTP 自动重定向
curl -I http://edm.sendwalk.com
# 应该看到 301 重定向到 https://

# 测试 SSL 证书
echo | openssl s_client -connect edm.sendwalk.com:443 -servername edm.sendwalk.com 2>/dev/null | openssl x509 -noout -dates
```

## 🔍 验证 SSL 配置

### 使用浏览器测试

1. 访问 `https://edm.sendwalk.com`
2. 点击地址栏的锁图标
3. 查看证书信息，确认：
   - 证书颁发者是否正确
   - 证书有效期
   - 证书支持的域名

### 使用在线工具

- **SSL Labs**: https://www.ssllabs.com/ssltest/
  - 输入您的域名进行全面的 SSL 测试
  - 建议评分达到 A 或 A+

- **SSL Checker**: https://www.sslshopper.com/ssl-checker.html
  - 快速检查证书安装是否正确

### 使用命令行工具

```bash
# 测试 SSL 连接
openssl s_client -connect edm.sendwalk.com:443 -servername edm.sendwalk.com

# 检查支持的协议
nmap --script ssl-enum-ciphers -p 443 edm.sendwalk.com

# 使用 testssl.sh（如果已安装）
testssl.sh https://edm.sendwalk.com
```

## 📋 SSL 安全配置详解

### 已配置的安全特性

#### 1. **TLS 协议版本**
```nginx
ssl_protocols TLSv1.2 TLSv1.3;
```
- 禁用了不安全的 TLS 1.0 和 1.1
- 只支持安全的 TLS 1.2 和 1.3

#### 2. **加密套件**
```nginx
ssl_ciphers 'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:...';
```
- 使用强加密算法
- 支持前向保密（Forward Secrecy）

#### 3. **HSTS（HTTP 严格传输安全）**
```nginx
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
```
- 强制浏览器使用 HTTPS
- 有效期 1 年（31536000 秒）
- 包含所有子域名

#### 4. **其他安全头**
```nginx
add_header X-Frame-Options "SAMEORIGIN" always;
add_header X-Content-Type-Options "nosniff" always;
add_header X-XSS-Protection "1; mode=block" always;
add_header Referrer-Policy "no-referrer-when-downgrade" always;
```
- 防止点击劫持
- 防止 MIME 类型嗅探
- 启用 XSS 过滤
- 合理的 Referrer 策略

#### 5. **会话缓存**
```nginx
ssl_session_cache shared:SSL:10m;
ssl_session_timeout 10m;
```
- 缓存 SSL 会话，提高性能
- 减少 TLS 握手开销

## 🔄 证书更新

### Cloudflare 证书有效期

Cloudflare 证书通常有以下有效期：
- **免费证书**: 15 年（origin certificate）
- **高级证书**: 根据订阅计划

### 更新证书的步骤

当证书即将到期时：

#### 1. 从 Cloudflare 获取新证书

1. 登录 Cloudflare 控制台
2. 选择您的域名
3. 进入 SSL/TLS → Origin Server
4. 创建新的 Origin Certificate
5. 下载新的证书文件

#### 2. 备份旧证书

```bash
# 备份旧证书
sudo cp /data/www/ca/sendwalk.pem /data/www/ca/sendwalk.pem.old
sudo cp /data/www/ca/sendwalk.key /data/www/ca/sendwalk.key.old
```

#### 3. 上传新证书

```bash
# 上传新证书到服务器
# 使用 scp、sftp 或其他方式

# 设置正确的权限
sudo chown root:root /data/www/ca/sendwalk.pem
sudo chown root:root /data/www/ca/sendwalk.key
sudo chmod 644 /data/www/ca/sendwalk.pem
sudo chmod 600 /data/www/ca/sendwalk.key
```

#### 4. 验证新证书

```bash
# 验证证书内容
openssl x509 -in /data/www/ca/sendwalk.pem -text -noout

# 验证私钥和证书匹配
openssl x509 -noout -modulus -in /data/www/ca/sendwalk.pem | openssl md5
openssl rsa -noout -modulus -in /data/www/ca/sendwalk.key | openssl md5
```

#### 5. 测试并重启 Nginx

```bash
# 测试配置
sudo nginx -t

# 重新加载 Nginx（无缝更新）
sudo systemctl reload nginx

# 如果需要，重启 Nginx
sudo systemctl restart nginx
```

#### 6. 验证新证书生效

```bash
# 检查证书有效期
echo | openssl s_client -connect edm.sendwalk.com:443 -servername edm.sendwalk.com 2>/dev/null | openssl x509 -noout -dates

# 浏览器访问并查看证书信息
```

## 🔐 Cloudflare SSL 模式

确保在 Cloudflare 控制台中选择正确的 SSL/TLS 加密模式：

### 推荐模式：**Full (strict)**

```
浏览器 --[HTTPS]--> Cloudflare --[HTTPS with valid cert]--> 源服务器
```

配置步骤：
1. 登录 Cloudflare
2. 选择您的域名
3. 进入 SSL/TLS 设置
4. 选择加密模式: **Full (strict)**

### 其他模式说明

| 模式 | 说明 | 安全性 | 推荐 |
|------|------|--------|------|
| Off | 不使用 HTTPS | ❌ 不安全 | ❌ |
| Flexible | Cloudflare 到浏览器使用 HTTPS，到源服务器使用 HTTP | ⚠️ 低 | ❌ |
| Full | Cloudflare 到源服务器使用 HTTPS（不验证证书） | ⚠️ 中 | ⚠️ |
| **Full (strict)** | 全程 HTTPS，验证源服务器证书 | ✅ 高 | ✅ |

## 🛡️ 安全最佳实践

### 1. 私钥保护

```bash
# 私钥必须设置为 600 权限
sudo chmod 600 /data/www/ca/sendwalk.key

# 只有 root 可以读取
sudo chown root:root /data/www/ca/sendwalk.key

# 不要提交私钥到 Git
echo "/data/www/ca/*.key" >> .gitignore
```

### 2. 定期检查证书有效期

创建监控脚本 `/data/www/sendwalk/check-ssl.sh`:

```bash
#!/bin/bash

CERT_FILE="/data/www/ca/sendwalk.pem"
DAYS_WARNING=30

# 获取证书到期时间
EXPIRY_DATE=$(openssl x509 -enddate -noout -in "$CERT_FILE" | cut -d= -f2)
EXPIRY_EPOCH=$(date -d "$EXPIRY_DATE" +%s)
NOW_EPOCH=$(date +%s)
DAYS_UNTIL_EXPIRY=$(( ($EXPIRY_EPOCH - $NOW_EPOCH) / 86400 ))

echo "证书到期时间: $EXPIRY_DATE"
echo "距离到期还有: $DAYS_UNTIL_EXPIRY 天"

if [ $DAYS_UNTIL_EXPIRY -lt $DAYS_WARNING ]; then
    echo "⚠️ 警告: 证书将在 $DAYS_UNTIL_EXPIRY 天后到期！"
    echo "请及时更新证书！"
    exit 1
fi

echo "✅ 证书有效"
exit 0
```

设置定时任务：

```bash
# 每周检查一次证书
sudo crontab -e

# 添加以下行
0 9 * * 1 /data/www/sendwalk/check-ssl.sh
```

### 3. 备份证书

```bash
# 定期备份证书（不包括私钥的公开备份）
sudo cp /data/www/ca/sendwalk.pem /backup/ssl/sendwalk-$(date +%Y%m%d).pem

# 私钥备份（加密存储）
sudo tar czf - /data/www/ca/sendwalk.key | \
    openssl enc -aes-256-cbc -salt -out /backup/ssl/sendwalk-key-$(date +%Y%m%d).tar.gz.enc
```

### 4. 防火墙配置

```bash
# 确保 HTTPS 端口开放
sudo ufw allow 443/tcp

# HTTP 端口也需要开放（用于重定向）
sudo ufw allow 80/tcp

# 查看规则
sudo ufw status
```

## 📊 常见问题排查

### 问题 1: "证书不受信任" 错误

**可能原因**:
- Cloudflare SSL 模式设置不正确
- 证书链不完整

**解决方案**:
```bash
# 确认 Cloudflare 设置为 Full (strict) 模式
# 检查证书文件是否包含完整的证书链
openssl x509 -in /data/www/ca/sendwalk.pem -text -noout
```

### 问题 2: "NET::ERR_CERT_COMMON_NAME_INVALID" 错误

**可能原因**:
- 证书不支持当前访问的域名

**解决方案**:
```bash
# 查看证书支持的域名
openssl x509 -in /data/www/ca/sendwalk.pem -noout -text | grep -A1 "Subject Alternative Name"

# 确认证书包含 edm.sendwalk.com 和 api.edm.sendwalk.com
```

### 问题 3: Nginx 启动失败

**可能原因**:
- 证书文件路径错误
- 证书文件权限问题
- 私钥和证书不匹配

**解决方案**:
```bash
# 检查 Nginx 错误日志
sudo tail -50 /var/log/nginx/error.log

# 测试配置
sudo nginx -t

# 检查文件权限
ls -lh /data/www/ca/

# 验证证书和私钥匹配
openssl x509 -noout -modulus -in /data/www/ca/sendwalk.pem | openssl md5
openssl rsa -noout -modulus -in /data/www/ca/sendwalk.key | openssl md5
```

### 问题 4: 浏览器显示 "不安全的连接"

**可能原因**:
- 混合内容（HTTPS 页面加载 HTTP 资源）
- HSTS 预加载问题

**解决方案**:
```bash
# 检查前端是否正确配置 API URL
cat /data/www/sendwalk/frontend/.env

# 确认使用 HTTPS
grep -r "http://" /data/www/sendwalk/frontend/dist/assets/

# 清除浏览器 HSTS 缓存
# Chrome: chrome://net-internals/#hsts
```

## ✅ 部署检查清单

完成 SSL 配置后，确认以下项目：

- [ ] 证书文件存在于 `/data/www/ca/sendwalk.pem`
- [ ] 私钥文件存在于 `/data/www/ca/sendwalk.key`
- [ ] 证书文件权限正确（644）
- [ ] 私钥文件权限正确（600）
- [ ] 证书和私钥匹配
- [ ] 证书包含正确的域名（edm.sendwalk.com, api.edm.sendwalk.com）
- [ ] 证书未过期，有效期充足
- [ ] Nginx 配置已更新
- [ ] Nginx 配置测试通过（nginx -t）
- [ ] Nginx 已重启
- [ ] HTTPS 前端可以访问
- [ ] HTTPS API 可以访问
- [ ] HTTP 自动重定向到 HTTPS
- [ ] 浏览器显示安全锁图标
- [ ] SSL Labs 评分达到 A 或 A+
- [ ] Cloudflare SSL 模式设置为 Full (strict)
- [ ] 前端可以正常调用 API（无混合内容警告）

## 🔗 相关文档

- **域名配置说明**: [域名配置说明.md](./域名配置说明.md)
- **部署路径说明**: [部署路径说明.md](./部署路径说明.md)
- **快速部署命令**: [快速部署命令.sh](./快速部署命令.sh)

---

**SSL 证书配置完成！现在您的应用已启用 HTTPS 加密访问。** 🔐✅

