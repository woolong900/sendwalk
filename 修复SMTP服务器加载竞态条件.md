# 修复 SMTP 服务器加载竞态条件

## 问题根本原因

通过详细的日志分析，我们发现了问题的根本原因：**竞态条件（Race Condition）**

### 问题的时序：

```
时刻 1: 页面加载，开始并行获取数据
  ├─ useQuery 1: 获取 campaign 数据 (GET /api/campaigns/20)
  └─ useQuery 2: 获取 smtpServers 数据 (GET /api/smtp-servers)

时刻 2: Campaign 数据先返回
  ├─ campaign.smtp_server_id = 3
  └─ useEffect 触发，设置 formData.smtp_server_id = '3'
  └─ 此时 smtpServers 还是 undefined 或 []

时刻 3: Select 组件渲染
  ├─ value="3"
  ├─ options=[] （因为 smtpServers 还没加载完）
  └─ 结果：显示 "选择服务器"（因为找不到 value="3" 的选项）

时刻 4: SMTP Servers 数据返回
  ├─ smtpServers = [{id: 1, ...}, {id: 2, ...}, {id: 3, ...}]
  └─ Select 组件重新渲染
  └─ 但 value="3" 已经被设置，且没有触发 useEffect 重新设置
  └─ 结果：可能仍然显示 "选择服务器"（取决于 React Select 的内部实现）
```

### 实际日志证据：

**前端日志**：
```
1. [SMTP Servers] ========== Fetching ========== (开始获取)
2. [smtpServers changed] isEmpty: true (初始状态为空)
3. [Campaign useEffect] Setting formData.smtp_server_id = '3' (设置值，但 smtpServers 为空)
4. [SMTP Servers] Response received (API 响应)
5. [smtpServers changed] isEmpty: false (列表已更新，但为时已晚)
```

**后端日志**：
```
[2025-12-28 08:37:22] Request started
[2025-12-28 08:37:22] Query completed: count=3, server_ids=[3,2,1]
[2025-12-28 08:37:22] Request completed: total_time_ms=4.74
```

后端响应很快（4.74ms），但由于网络延迟，前端可能在 campaign 数据返回后 100-200ms 才收到 smtpServers 数据。

## 解决方案

### 修改内容：`frontend/src/pages/campaigns/editor.tsx`

**关键修改**：在 `useEffect` 中添加了等待逻辑

```typescript
useEffect(() => {
  if (!campaign) {
    return
  }

  // 🔥 关键修复：如果是编辑模式且有 smtp_server_id，等待 smtpServers 加载完成
  if (isEditing && campaign.smtp_server_id && (!smtpServers || smtpServers.length === 0)) {
    console.log('[Campaign Editor] Waiting for smtpServers to load before setting formData')
    return  // 等待 smtpServers 加载完成后再设置 formData
  }
  
  // ... 设置 formData
  
}, [campaign, smtpServers, isEditing])  // 添加 smtpServers 和 isEditing 作为依赖
```

### 修复原理：

1. **添加 `smtpServers` 依赖**：
   - 当 `smtpServers` 从 `undefined` 变为数组时，会重新触发 `useEffect`

2. **添加等待逻辑**：
   - 如果是编辑模式且有 `smtp_server_id`，但 `smtpServers` 还没加载完，就先返回（等待）
   - 当 `smtpServers` 加载完成后，`useEffect` 会再次触发，此时才设置 `formData`

3. **时序修复后**：
   ```
   时刻 1: 页面加载，开始并行获取数据
   
   时刻 2: Campaign 数据先返回
     └─ useEffect 触发，检测到 smtpServers 为空
     └─ 等待... (不设置 formData)
   
   时刻 3: SMTP Servers 数据返回
     └─ smtpServers 更新
     └─ useEffect 再次触发（因为依赖变化）
     └─ 此时 smtpServers 已有数据
     └─ 设置 formData.smtp_server_id = '3'
   
   时刻 4: Select 组件渲染
     ├─ value="3"
     ├─ options=[{id: 1, ...}, {id: 2, ...}, {id: 3, ...}]
     └─ 结果：正确显示 "azure/postal@wdbug.com"
   ```

## 为什么之前的方案不够完善？

### 之前的尝试：
1. **使用 `useRef`**：试图追踪是否已经设置过默认服务器
   - 问题：无法解决竞态条件，只是在重复设置上做了限制
   
2. **添加 `campaignDataLoaded` 状态**：试图标记 campaign 数据是否已加载
   - 问题：没有考虑 `smtpServers` 的加载状态

3. **简化逻辑，去掉复杂的 ref 和状态**：
   - 问题：虽然简化了代码，但没有解决根本的竞态问题

### 本次方案的优势：

✅ **直接解决根本问题**：等待 `smtpServers` 加载完成
✅ **代码简洁**：只需添加一个条件判断和一个依赖
✅ **逻辑清晰**：明确表达了"需要等待 smtpServers"的意图
✅ **不影响创建模式**：只在编辑模式下才等待

## 测试方法

### 1. 正常测试：
```bash
# 访问编辑页面
https://edm.sendwalk.com/campaigns/20/edit

# 预期：发送服务器字段正确显示 "azure/postal@wdbug.com"
```

### 2. 慢网络测试（模拟竞态条件）：
```
1. 打开 Chrome DevTools
2. 切换到 Network 标签
3. 设置网络限速：Slow 3G
4. 刷新页面
5. 观察：发送服务器字段应该仍然正确显示
```

### 3. 查看日志：
```javascript
// 控制台日志应该显示：
[Campaign Editor] Campaign useEffect triggered
  hasSmtpServers: false  // 第一次触发，smtpServers 还没加载
[Campaign Editor] Waiting for smtpServers to load before setting formData

// 然后：
[SMTP Servers] Response received

// 然后：
[Campaign Editor] Campaign useEffect triggered
  hasSmtpServers: true  // 第二次触发，smtpServers 已加载
  smtpServersLength: 3
[Campaign Editor] Setting formData
  newSmtpServerId: '3'
  smtpServersAvailable: 3
```

## 额外的调试信息

我们保留了详细的调试日志，方便未来排查问题：

### 前端日志：
- ✅ SMTP Servers 获取全过程
- ✅ smtpServers 状态变化
- ✅ Campaign 数据加载
- ✅ formData 设置时机
- ✅ 等待状态日志

### 后端日志：
- ✅ 唯一请求 ID
- ✅ SQL 查询和结果
- ✅ 响应数据结构
- ✅ 执行时间统计

## 总结

这个问题的根本原因是：
1. **两个独立的 API 请求并行执行**
2. **返回时间不可控**（取决于网络、服务器负载等）
3. **React Select 组件需要同时有 `value` 和 `options` 才能正确显示**

解决方案的核心思想是：
**在设置 value 之前，确保 options 已经加载完成**

这是一个典型的前端竞态条件问题，在处理多个异步数据加载时需要特别注意时序和依赖关系。

## 后续优化建议

如果未来还想进一步优化，可以考虑：

1. **合并 API 请求**：
   ```typescript
   // 在 campaign API 中直接返回 smtp_server 完整信息
   GET /api/campaigns/20
   {
     "data": {
       "id": 20,
       "smtp_server_id": 3,
       "smtp_server": {  // 直接包含完整的 server 信息
         "id": 3,
         "name": "azure/postal@wdbug.com",
         ...
       },
       ...
     }
   }
   ```

2. **使用 React Suspense**：
   ```typescript
   // 使用 Suspense 确保所有数据都加载完才渲染
   <Suspense fallback={<Loading />}>
     <CampaignEditor />
   </Suspense>
   ```

3. **使用串行加载**：
   ```typescript
   // 先加载 campaign，再加载 smtpServers
   // 但这会降低页面加载速度
   ```

但目前的方案已经足够好了，既保持了并行加载的性能优势，又解决了竞态条件问题。

