# 正式环境编辑活动无法加载发送服务器问题诊断

## 问题现象

- ✅ 本地环境：编辑活动能正确加载发送服务器
- ❌ 正式环境：编辑活动无法加载发送服务器
- ✅ 代码已确认同步

## 可能原因

### 1. 浏览器缓存了旧的前端代码
### 2. 前端代码没有重新构建/部署
### 3. API 返回的数据有问题

## 诊断步骤

### 步骤 1：检查浏览器控制台

在正式环境打开 `https://edm.sendwalk.com/campaigns/20/edit`，打开浏览器开发者工具（F12）：

#### 1.1 查看 Network 标签

找到 `/api/campaigns/20` 请求，检查：

```
请求 URL: https://api.sendwalk.com/api/campaigns/20
状态码: 应该是 200
响应内容: 
{
  "data": {
    "id": 20,
    "smtp_server_id": <数字>,  // ← 检查这个字段是否存在
    "name": "...",
    ...
  }
}
```

**如果 `smtp_server_id` 不存在或为 null**：后端问题
**如果 `smtp_server_id` 存在**：前端问题

#### 1.2 查看 Console 标签

查看是否有 JavaScript 错误：
```
Select is changing from uncontrolled to controlled
Uncaught TypeError: ...
```

### 步骤 2：强制刷新浏览器缓存

尝试以下方法清除缓存：

1. **硬刷新**：
   - Windows/Linux: `Ctrl + Shift + R` 或 `Ctrl + F5`
   - macOS: `Cmd + Shift + R`

2. **清空缓存并硬刷新**：
   - 打开开发者工具（F12）
   - 右键点击刷新按钮
   - 选择"清空缓存并硬刷新"

3. **无痕模式测试**：
   - 打开无痕/隐私窗口
   - 访问 `https://edm.sendwalk.com/campaigns/20/edit`
   - 如果正常，说明是缓存问题

### 步骤 3：检查前端部署

在正式环境服务器上：

```bash
# 1. 检查前端构建时间
cd /data/www/sendwalk/frontend
ls -la dist/index.html
# 查看修改时间是否是最近的

# 2. 查看前端代码版本
grep "campaignDataLoaded" dist/assets/*.js | head -5
# 如果找不到 campaignDataLoaded，说明前端代码是旧版本

# 3. 检查 editor.tsx 的构建产物
grep "smtp_server_id.*toString" dist/assets/*.js | head -5
# 应该能找到 campaign.smtp_server_id.toString() 相关代码
```

### 步骤 4：检查 API 响应

在正式环境使用 curl 测试：

```bash
# 获取 Bearer Token（从浏览器开发者工具 → Application → Cookies 复制）
TOKEN="your-token-here"

# 测试 API
curl -X GET "https://api.sendwalk.com/api/campaigns/20" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" | jq .

# 检查输出中是否有 smtp_server_id 字段
```

## 解决方案

### 方案 1：重新构建和部署前端（最可能）

```bash
# 1. 在本地构建
cd /Users/panlei/sendwalk/frontend
npm run build

# 2. 上传到正式环境
scp -r dist/* your-server:/data/www/sendwalk/frontend/dist/

# 或者在正式环境直接构建
ssh your-server
cd /data/www/sendwalk/frontend
git pull
npm install  # 如果 package.json 有变化
npm run build

# 3. 验证构建时间
ls -lh dist/index.html
```

### 方案 2：添加版本号防止缓存（长期方案）

在 `frontend/index.html` 中添加：

```html
<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
<meta http-equiv="Pragma" content="no-cache">
<meta http-equiv="Expires" content="0">
```

或者在 `vite.config.ts` 中配置：

```typescript
export default defineConfig({
  build: {
    rollupOptions: {
      output: {
        // 给文件名添加哈希，防止缓存
        entryFileNames: `assets/[name].[hash].js`,
        chunkFileNames: `assets/[name].[hash].js`,
        assetFileNames: `assets/[name].[hash].[ext]`
      }
    }
  }
})
```

### 方案 3：配置 Nginx 缓存策略

编辑 Nginx 配置（通常在 `/etc/nginx/sites-available/edm.sendwalk.com`）：

```nginx
server {
    listen 443 ssl http2;
    server_name edm.sendwalk.com;
    
    root /data/www/sendwalk/frontend/dist;
    
    # HTML 不缓存
    location / {
        try_files $uri $uri/ /index.html;
        add_header Cache-Control "no-cache, no-store, must-revalidate";
        add_header Pragma "no-cache";
        add_header Expires "0";
    }
    
    # JS/CSS 文件缓存（但使用哈希名称）
    location ~* \.(js|css)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
    
    # 其他静态资源
    location ~* \.(jpg|jpeg|png|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
}
```

重启 Nginx：
```bash
sudo nginx -t  # 测试配置
sudo systemctl reload nginx
```

## 快速验证脚本

创建验证脚本：

```bash
#!/bin/bash
# verify-deployment.sh

echo "=== 验证前端部署 ==="
echo ""

# 1. 检查文件修改时间
echo "1. 检查 index.html 修改时间："
stat -c '%y %n' /data/www/sendwalk/frontend/dist/index.html 2>/dev/null || \
stat -f '%Sm %N' /data/www/sendwalk/frontend/dist/index.html 2>/dev/null
echo ""

# 2. 检查关键代码
echo "2. 检查是否包含 campaignDataLoaded 代码："
if grep -r "campaignDataLoaded" /data/www/sendwalk/frontend/dist/assets/*.js >/dev/null 2>&1; then
    echo "   ✅ 找到 campaignDataLoaded（新版本代码）"
else
    echo "   ❌ 未找到 campaignDataLoaded（可能是旧版本）"
fi
echo ""

# 3. 检查关键修复代码
echo "3. 检查 smtp_server_id toString 处理："
if grep -r "smtp_server_id.*toString" /data/www/sendwalk/frontend/dist/assets/*.js >/dev/null 2>&1; then
    echo "   ✅ 找到 toString 处理（新版本代码）"
else
    echo "   ❌ 未找到 toString 处理（可能是旧版本）"
fi
echo ""

# 4. 检查构建产物大小
echo "4. JS 文件大小："
ls -lh /data/www/sendwalk/frontend/dist/assets/*.js | awk '{print "   " $9 ": " $5}'
echo ""

echo "=== 验证完成 ==="
```

## 调试技巧

### 在浏览器控制台手动测试

```javascript
// 1. 查看当前页面加载的 JS 文件
performance.getEntriesByType('resource')
  .filter(r => r.name.includes('.js'))
  .forEach(r => console.log(r.name, new Date(r.startTime)))

// 2. 检查 React Query 缓存
// （需要安装 React Query Devtools）

// 3. 手动触发 API 请求
fetch('https://api.sendwalk.com/api/campaigns/20', {
  headers: {
    'Authorization': 'Bearer ' + document.cookie.match(/token=([^;]+)/)[1],
    'Accept': 'application/json'
  }
})
.then(r => r.json())
.then(data => {
  console.log('Campaign data:', data)
  console.log('smtp_server_id:', data.data.smtp_server_id)
  console.log('Type:', typeof data.data.smtp_server_id)
})
```

## 预防措施

### 1. 设置 CI/CD 自动部署

在 `.github/workflows/deploy.yml` 中：

```yaml
name: Deploy Frontend
on:
  push:
    branches: [main]
    paths:
      - 'frontend/**'
      
jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      - name: Setup Node
        uses: actions/setup-node@v2
        with:
          node-version: '18'
      - name: Install and Build
        run: |
          cd frontend
          npm install
          npm run build
      - name: Deploy
        run: |
          # 部署到服务器
          rsync -avz --delete frontend/dist/ ${{ secrets.SERVER }}:/data/www/sendwalk/frontend/dist/
```

### 2. 添加版本号检查

在 `frontend/package.json` 中添加版本号，在页面底部显示：

```typescript
// App.tsx
import packageJson from '../package.json'

// 在页面底部显示
<footer>
  版本: {packageJson.version} - 构建时间: {import.meta.env.BUILD_TIME}
</footer>
```

## 总结

最可能的原因是**前端代码没有重新构建/部署**或**浏览器缓存了旧代码**。

立即执行：

1. **在正式环境重新构建前端**
2. **强制刷新浏览器**（Ctrl+Shift+R）
3. **清空浏览器缓存**

如果问题依然存在，请在浏览器控制台截图以下信息：
- Network 标签中 `/api/campaigns/20` 的完整响应
- Console 标签中的所有错误

