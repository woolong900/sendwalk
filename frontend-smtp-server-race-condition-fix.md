# 修复活动编辑器 SMTP 服务器加载的竞态条件问题

## 问题现象

用户报告："偶尔能正确加载发件服务器，偶尔又不能加载了"

这是一个经典的 React **竞态条件（Race Condition）**问题。

## 问题原因

### 原因 1：useEffect 依赖项不完整导致闭包陷阱

**旧代码**：

```typescript
useEffect(() => {
  if (isEditing && campaignDataLoaded) {
    return
  }
  
  if (!isEditing && smtpServers && smtpServers.length > 0 && !formData.smtp_server_id) {
    const defaultServer = smtpServers.find(s => s.is_default && s.is_active)
    if (defaultServer) {
      setFormData(prev => ({ ...prev, smtp_server_id: defaultServer.id.toString() }))
    }
  }
}, [smtpServers, isEditing, campaignDataLoaded]) // ❌ 缺少 formData.smtp_server_id
```

**问题**：

1. useEffect 的条件判断中使用了 `formData.smtp_server_id`
2. 但依赖项中没有包含它
3. 导致 useEffect 使用的是旧的闭包值

**时序问题场景**：

```
时间线：
T1: 页面加载，smtpServers 加载完成
    - formData.smtp_server_id = '' (初始值)
    - useEffect 触发，自动选择默认服务器
    
T2: campaign 数据加载完成
    - 第一个 useEffect 设置 smtp_server_id = '3'
    - 但第二个 useEffect 不会重新运行（依赖项没有 formData.smtp_server_id）
    
T3: smtpServers 重新获取（如页面刷新或数据更新）
    - useEffect 再次触发
    - 但它使用的是闭包中的旧值 formData.smtp_server_id = ''
    - 又会自动选择默认服务器，覆盖了活动原有的设置！
```

### 原因 2：Label htmlFor 不匹配

```typescript
<Label htmlFor="smtp_server_id">发送服务器 *</Label>
<Select ...>
  <SelectTrigger> {/* ❌ 没有 id 属性 */}
```

浏览器警告：
```
Incorrect use of <label for=FORM_ELEMENT>
The label's for attribute doesn't match any element id.
```

虽然这不是主要问题，但可能影响辅助功能。

## 解决方案

### 方案 1：使用 useRef 追踪状态（已采用）

```typescript
const defaultServerSetRef = useRef(false) // 标记是否已设置默认服务器

// 在加载活动数据时设置标记
useEffect(() => {
  if (campaign) {
    const serverId = campaign.smtp_server_id ? campaign.smtp_server_id.toString() : ''
    
    setFormData({ ...formData, smtp_server_id: serverId })
    
    // 编辑模式下，标记已有服务器设置
    if (serverId) {
      defaultServerSetRef.current = true
    }
  }
}, [campaign])

// 自动选择默认服务器（仅在创建新活动且未设置过时）
useEffect(() => {
  if (isEditing) {
    return // 编辑模式下完全跳过
  }
  
  // 使用 ref 防止重复设置
  if (smtpServers && smtpServers.length > 0 && !defaultServerSetRef.current && !formData.smtp_server_id) {
    const defaultServer = smtpServers.find(s => s.is_default && s.is_active)
    if (defaultServer) {
      setFormData(prev => ({ ...prev, smtp_server_id: defaultServer.id.toString() }))
      defaultServerSetRef.current = true
    }
  }
}, [smtpServers, isEditing, formData.smtp_server_id])
```

**优势**：
- ✅ 使用 ref 追踪是否已设置，ref 的变化不会触发重新渲染
- ✅ 编辑模式下完全跳过自动选择逻辑
- ✅ 保留了 `formData.smtp_server_id` 在依赖项中，但通过 ref 防止重复设置
- ✅ 避免了闭包陷阱

### 方案 2：修复 Label htmlFor 警告

```typescript
<Label>发送服务器 *</Label> {/* ✅ 移除 htmlFor */}
<Select
  value={formData.smtp_server_id || ''}
  onValueChange={(value) => setFormData({ ...formData, smtp_server_id: value })}
>
  <SelectTrigger id="smtp_server_id"> {/* ✅ 添加 id */}
    <SelectValue placeholder="选择服务器" />
  </SelectTrigger>
  <SelectContent>
    ...
  </SelectContent>
</Select>
```

**为什么这样做**：
- Select 组件是复合组件，真正的表单元素是内部的 hidden input
- 无法直接给 Select 添加 id
- 可以给 SelectTrigger 添加 id，但 Label 的 htmlFor 不会起作用
- 最简单的方案是移除 Label 的 htmlFor

## 其他尝试过的方案（不推荐）

### ❌ 方案 A：添加 formData.smtp_server_id 到依赖项

```typescript
useEffect(() => {
  if (!isEditing && smtpServers && !formData.smtp_server_id) {
    // 自动选择默认服务器
  }
}, [smtpServers, isEditing, formData.smtp_server_id])
```

**问题**：会导致无限循环！
- useEffect 内部修改 `formData.smtp_server_id`
- 触发 useEffect 再次运行
- 再次修改 `formData.smtp_server_id`
- 无限循环...

### ❌ 方案 B：移除 formData.smtp_server_id 检查

```typescript
useEffect(() => {
  if (isEditing && campaignDataLoaded) return
  
  if (!isEditing && smtpServers && smtpServers.length > 0) {
    // 不检查 formData.smtp_server_id
    const defaultServer = smtpServers.find(s => s.is_default && s.is_active)
    if (defaultServer) {
      setFormData(prev => ({ ...prev, smtp_server_id: defaultServer.id.toString() }))
    }
  }
}, [smtpServers, isEditing, campaignDataLoaded])
```

**问题**：会重复设置，覆盖用户的选择！

### ❌ 方案 C：使用 useState 标记

```typescript
const [defaultServerSet, setDefaultServerSet] = useState(false)

useEffect(() => {
  if (!defaultServerSet && smtpServers && !formData.smtp_server_id) {
    // ...
    setDefaultServerSet(true)
  }
}, [smtpServers, defaultServerSet, formData.smtp_server_id])
```

**问题**：useState 的变化会触发重新渲染，增加性能开销，useRef 更合适。

## 技术要点

### 1. useRef vs useState

| 特性 | useRef | useState |
|------|--------|----------|
| 改变触发重新渲染 | ❌ 否 | ✅ 是 |
| 保持引用稳定 | ✅ 是 | ❌ 否 |
| 跨渲染保留值 | ✅ 是 | ✅ 是 |
| 适用场景 | 标记/缓存/DOM引用 | UI状态 |

**结论**：用 useRef 标记是否已设置默认服务器，避免不必要的重新渲染。

### 2. 闭包陷阱

```typescript
// ❌ 错误示例
useEffect(() => {
  console.log(count) // 永远是初始值 0
}, []) // 空依赖项，只运行一次

// ✅ 正确示例
useEffect(() => {
  console.log(count) // 每次 count 变化都会更新
}, [count]) // 包含 count 在依赖项中
```

**规则**：useEffect 内部使用的所有外部变量都应该在依赖项中声明。

### 3. Select 组件的 controlled vs uncontrolled

```typescript
// ❌ 错误：值可能变成 undefined
<Select value={formData.smtp_server_id}>

// ✅ 正确：始终是字符串
<Select value={formData.smtp_server_id || ''}>
```

**原因**：
- React Select 组件必须保持 controlled 或 uncontrolled，不能切换
- `undefined` → `"3"` 会被视为从 uncontrolled 切换到 controlled
- `""` → `"3"` 始终是 controlled

## 测试验证

### 测试场景 1：创建新活动

**预期行为**：
1. 页面加载
2. 如果有默认服务器，自动选择
3. 用户可以手动更改
4. 不会自动覆盖用户的选择

### 测试场景 2：编辑现有活动

**预期行为**：
1. 页面加载
2. 显示活动原有的服务器设置
3. 不会自动选择默认服务器
4. 用户可以手动更改

### 测试场景 3：服务器列表重新加载

**预期行为**：
1. 页面已经有选中的服务器
2. 服务器列表重新获取（如刷新）
3. 保持原有的选择，不会切换到默认服务器

## 相关文件

- `frontend/src/pages/campaigns/editor.tsx` - 活动编辑器主文件

## 总结

这次修复解决了两个问题：

1. **竞态条件**：使用 useRef 追踪状态，避免闭包陷阱
2. **Label 警告**：移除 htmlFor 或给 SelectTrigger 添加 id

修复后，无论服务器列表何时加载，活动编辑器都能正确显示和保持 SMTP 服务器的选择。

