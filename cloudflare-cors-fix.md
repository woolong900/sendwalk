# Cloudflare CORS 问题解决方案

## 🔥 Cloudflare 特定问题

如果使用了 Cloudflare CDN，有几个特殊的配置会导致 CORS 问题：

## 1. SSL/TLS 模式检查（最常见）

### ❌ 错误设置：Flexible
- 浏览器到 Cloudflare：HTTPS
- Cloudflare 到源服务器：HTTP
- **会导致 CORS 和各种问题！**

### ✅ 正确设置：Full (strict)
1. 登录 Cloudflare 控制台
2. 选择 `sendwalk.com` 域名
3. 左侧 **SSL/TLS** → **概述**
4. 选择 **Full (strict)** 或 **Full**
5. 保存并等待几分钟生效

## 2. 开启开发模式（临时测试）

开发模式会绕过所有 Cloudflare 缓存：

1. Cloudflare 控制台
2. 选择 `sendwalk.com`
3. 顶部快速操作
4. 点击 **开发模式**
5. 开启（3小时后自动关闭）
6. 立即测试

## 3. 缓存规则配置

### 为 API 域名禁用缓存

1. 路径：**缓存** → **配置** → **缓存规则**
2. 创建新规则：

**规则名称**: `API No Cache`

**如果传入请求匹配**:
```
主机名 等于 api.sendwalk.com
```

**那么**:
- 缓存级别：`绕过`
- Edge Cache TTL：`关闭`

3. 保存

## 4. 页面规则配置

如果没有缓存规则，使用页面规则：

1. 路径：**规则** → **页面规则**
2. 创建页面规则

**URL**: `api.sendwalk.com/*`

**设置**:
- 缓存级别：`绕过`
- 浏览器缓存 TTL：`关闭`

## 5. 防火墙检查

1. **安全性** → **WAF** → **受管规则**
2. 检查是否有规则阻止 OPTIONS 请求
3. **安全性** → **事件**
4. 查看是否有被阻止的请求

## 6. Transform Rules

Cloudflare 的 Transform Rules 可能会干扰 CORS 头：

1. **规则** → **Transform Rules**
2. 检查是否有修改 HTTP 响应头的规则
3. 确保没有移除或修改 CORS 相关头

## 7. 临时绕过 Cloudflare 测试

### 方法 1: 修改本地 hosts 文件

```bash
# 在本地电脑上编辑 hosts 文件
# Windows: C:\Windows\System32\drivers\etc\hosts
# Mac/Linux: /etc/hosts

# 添加（替换为你服务器的真实 IP）
YOUR_SERVER_IP api.sendwalk.com
YOUR_SERVER_IP edm.sendwalk.com
```

保存后测试，如果工作了，说明确实是 Cloudflare 的问题。

### 方法 2: 直接使用 IP 测试

如果服务器 IP 是 `1.2.3.4`：

```bash
curl -H "Host: api.sendwalk.com" \
     -H "Origin: https://edm.sendwalk.com" \
     -I https://1.2.3.4/api/health
```

## 8. Cloudflare 的其他可能问题

### (1) Rocket Loader
- 路径：**速度** → **优化**
- 关闭 **Rocket Loader**

### (2) Auto Minify
- 路径：**速度** → **优化**
- 关闭 **Auto Minify** for JavaScript

### (3) Brotli 压缩
- 路径：**速度** → **优化**
- 检查 **Brotli** 设置

## 9. 完全禁用 Cloudflare 的橙色云（测试用）

**警告：这会暴露你的真实服务器 IP**

1. Cloudflare DNS 设置
2. 找到 `api.sendwalk.com` 和 `edm.sendwalk.com`
3. 点击橙色云图标，变成灰色云
4. 等待 DNS 传播（几分钟）
5. 测试

如果这样可以工作，说明确实是 Cloudflare 的问题。
然后再开启橙色云，逐一检查上述配置。

## 10. Cloudflare API Shield

如果你使用了 Cloudflare 的企业版功能：

1. **安全性** → **API Shield**
2. 检查是否有规则影响 CORS
3. 可能需要添加 CORS 白名单

## 推荐配置顺序

1. ✅ SSL/TLS 模式改为 **Full (strict)**
2. ✅ 为 `api.sendwalk.com` 创建 **缓存规则：绕过**
3. ✅ 开启 **开发模式** 测试
4. ✅ 检查 **防火墙事件**
5. ✅ 如果还不行，临时变成灰色云测试

---

## 🔍 验证 Cloudflare 设置的命令

```bash
# 检查是否经过 Cloudflare
curl -I https://api.sendwalk.com/api/health | grep -i "cf-"

# 应该看到：
# cf-cache-status: DYNAMIC 或 BYPASS
# cf-ray: xxx
# server: cloudflare

# 检查 CORS 头
curl -I \
  -H "Origin: https://edm.sendwalk.com" \
  https://api.sendwalk.com/api/health | grep -i "access-control"

# 应该看到：
# access-control-allow-origin: https://edm.sendwalk.com
# access-control-allow-credentials: true
```

---

## 📞 如果问题仍然存在

请提供：

1. Cloudflare SSL/TLS 模式截图
2. 缓存规则截图
3. 页面规则截图
4. 浏览器 Console 的完整错误
5. Network 中失败请求的 Headers 截图

有了这些信息，我能准确找出问题所在。

