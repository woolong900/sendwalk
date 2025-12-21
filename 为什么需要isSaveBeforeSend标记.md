# 为什么需要 isSaveBeforeSend 标记？

## 🤔 核心问题

### React Query Mutation 的特性

在我们的代码中，`saveMutation` 是一个 React Query 的 mutation：

```typescript
const saveMutation = useMutation({
  mutationFn: async (data) => {
    if (isEditing) {
      return api.put(`/campaigns/${id}`, data)
    }
    return api.post('/campaigns', data)
  },
  onSuccess: (response) => {
    // ✅ 这个回调会在保存成功后自动触发
    toast.success(isEditing ? '活动已更新' : '活动已保存为草稿')
    navigate('/campaigns')
  },
})
```

**关键特性**：`onSuccess` 回调会在**每次保存成功后自动触发**，无论是谁调用的保存。

## 📊 问题场景分析

### 场景 1: 仅保存活动（期望显示提示）

```typescript
// 用户点击"保存为草稿"按钮
const handleSubmit = (e: React.FormEvent) => {
  e.preventDefault()
  saveMutation.mutate(formData) // 调用保存
}

// 流程：
saveMutation.mutate(formData)
    ↓
API 保存成功
    ↓
saveMutation.onSuccess 触发
    ↓
toast.success('活动已更新') ✅ 期望显示
    ↓
navigate('/campaigns')
```

✅ **结果**：显示"活动已更新" - 符合预期

### 场景 2: 编辑活动 + 立即发送（期望只显示发送提示）

```typescript
// 用户点击"立即发送"按钮
const handleSendNow = async () => {
  if (isEditing) {
    await saveMutation.mutateAsync(formData) // 先保存
  }
  sendMutation.mutate({ campaignId: parseInt(id) }) // 再发送
}

// 流程：
saveMutation.mutateAsync(formData)
    ↓
API 保存成功
    ↓
saveMutation.onSuccess 触发
    ↓
toast.success('活动已更新') ❌ 不想显示！
    ↓
sendMutation.mutate(...)
    ↓
API 发送成功
    ↓
sendMutation.onSuccess 触发
    ↓
toast.success('活动已加入发送队列') ✅ 只想显示这个
```

❌ **问题**：显示了两个提示
- "活动已更新"（来自 saveMutation.onSuccess）
- "活动已加入发送队列"（来自 sendMutation.onSuccess）

## 🎯 为什么需要标记？

### 问题的本质

**`saveMutation.onSuccess` 无法自动知道它是在什么场景下被调用的：**

1. 是用户单独点击"保存"按钮？→ 应该显示提示
2. 还是在"立即发送"流程中作为准备步骤？→ 不应该显示提示

**React Query 的 mutation 不会自动区分调用上下文**，它只知道"保存成功了"，然后触发 `onSuccess` 回调。

### 如果没有标记会怎样？

#### 方案 A: 总是显示保存提示 ❌

```typescript
onSuccess: (response) => {
  toast.success(isEditing ? '活动已更新' : '活动已保存为草稿')
  // 总是显示
}
```

**问题**：
- ✅ 单独保存：显示"活动已更新" - 正常
- ❌ 保存后发送：显示"活动已更新" + "活动已加入发送队列" - 重复提示

#### 方案 B: 从不显示保存提示 ❌

```typescript
onSuccess: (response) => {
  // 不显示任何提示
}
```

**问题**：
- ❌ 单独保存：不显示任何提示 - 用户不知道是否保存成功
- ✅ 保存后发送：只显示"活动已加入发送队列" - 正常

#### 方案 C: 使用标记区分上下文 ✅

```typescript
const [isSaveBeforeSend, setIsSaveBeforeSend] = useState(false)

onSuccess: (response) => {
  if (isSaveBeforeSend) {
    setIsSaveBeforeSend(false)
    return // 跳过提示
  }
  toast.success(isEditing ? '活动已更新' : '活动已保存为草稿')
}
```

**效果**：
- ✅ 单独保存（`isSaveBeforeSend = false`）：显示"活动已更新"
- ✅ 保存后发送（`isSaveBeforeSend = true`）：跳过保存提示，只显示发送提示

## 🔍 详细代码分析

### 完整流程对比

#### 没有标记的流程（问题版本）

```typescript
// 用户点击"立即发送"
const handleSendNow = async () => {
  // 1. 保存活动
  await saveMutation.mutateAsync(formData)
  
  // 2. 发送活动
  sendMutation.mutate({ campaignId: parseInt(id) })
}

// saveMutation 的 onSuccess
onSuccess: (response) => {
  toast.success('活动已更新') // ❌ 总是显示
  // 问题：不知道后面还要发送
}

// sendMutation 的 onSuccess
onSuccess: () => {
  toast.success('活动已加入发送队列') // ✅ 显示
}

// 结果：两个提示 ❌
```

#### 有标记的流程（解决方案）

```typescript
// 用户点击"立即发送"
const handleSendNow = async () => {
  // 1. 设置标记：告诉 saveMutation "这是保存后发送操作"
  setIsSaveBeforeSend(true)
  
  // 2. 保存活动
  try {
    await saveMutation.mutateAsync(formData)
  } catch (error) {
    setIsSaveBeforeSend(false) // 保存失败，重置标记
    return
  }
  
  // 3. 发送活动
  sendMutation.mutate({ campaignId: parseInt(id) })
}

// saveMutation 的 onSuccess
onSuccess: (response) => {
  if (isSaveBeforeSend) {
    // ✅ 检测到标记：这是"保存后发送"操作
    setIsSaveBeforeSend(false) // 重置标记
    return // 跳过提示
  }
  
  // ✅ 没有标记：这是单独保存操作
  toast.success('活动已更新')
}

// sendMutation 的 onSuccess
onSuccess: () => {
  toast.success('活动已加入发送队列') // ✅ 显示
}

// 结果：只有一个提示 ✅
```

## 💡 标记的作用

### 1. 传递上下文信息

```typescript
setIsSaveBeforeSend(true) // "嘿，saveMutation，接下来的保存是为了发送准备的"
```

标记就像是在调用者和回调之间传递一个"暗号"：
- 调用者说："我接下来要保存，但这是为了发送，不要显示保存提示"
- 回调收到："好的，我知道了，保存成功后我不显示提示"

### 2. 控制副作用

```typescript
onSuccess: (response) => {
  if (isSaveBeforeSend) {
    return // 控制副作用：跳过 toast.success
  }
  toast.success('活动已更新') // 正常的副作用
}
```

标记让我们能够**根据调用上下文控制副作用**（toast 提示）。

### 3. 状态同步

```typescript
// 设置标记
setIsSaveBeforeSend(true)

// 保存成功后重置
onSuccess: (response) => {
  if (isSaveBeforeSend) {
    setIsSaveBeforeSend(false) // 重置，不影响下次操作
    return
  }
}

// 保存失败也重置
catch (error) {
  setIsSaveBeforeSend(false) // 重置，不影响下次操作
}
```

标记确保每次操作的状态是独立的，不会互相影响。

## 🤷 为什么不用其他方案？

### 方案 1: 在 `handleSendNow` 中直接调用 API，不通过 mutation ❌

```typescript
const handleSendNow = async () => {
  // 直接调用 API
  await api.put(`/campaigns/${id}`, formData)
  // 不触发 saveMutation.onSuccess
  
  sendMutation.mutate({ campaignId: parseInt(id) })
}
```

**问题**：
- ❌ 失去 React Query 的缓存管理
- ❌ 失去自动重试、错误处理等功能
- ❌ 需要手动更新缓存
- ❌ 代码重复（其他地方也需要保存）

### 方案 2: 给 mutation 传递参数控制是否显示提示 ❌

```typescript
const saveMutation = useMutation({
  mutationFn: async ({ data, silent }) => {
    return api.put(`/campaigns/${id}`, data)
  },
  onSuccess: (response, { silent }) => {
    if (!silent) {
      toast.success('活动已更新')
    }
  },
})

// 调用
saveMutation.mutate({ data: formData, silent: true })
```

**问题**：
- ❌ 需要修改 mutation 的函数签名
- ❌ 所有调用的地方都需要传递 `silent` 参数
- ❌ 如果忘记传递参数，行为会不一致
- ⚠️ 但这也是一个可行的方案，只是不如用标记简洁

### 方案 3: 使用 useRef 而不是 useState ⚠️

```typescript
const isSaveBeforeSendRef = useRef(false)

const handleSendNow = async () => {
  isSaveBeforeSendRef.current = true
  await saveMutation.mutateAsync(formData)
  sendMutation.mutate({ campaignId: parseInt(id) })
}

onSuccess: (response) => {
  if (isSaveBeforeSendRef.current) {
    isSaveBeforeSendRef.current = false
    return
  }
  toast.success('活动已更新')
}
```

**为什么 useState 更好？**
- ✅ useState 更符合 React 的状态管理理念
- ✅ 方便调试（可以在 React DevTools 中看到）
- ✅ 如果将来需要根据这个状态渲染 UI，useState 更方便
- ⚠️ useRef 也能工作，但不是最佳实践

## 📊 对比总结

| 方案 | 优点 | 缺点 | 推荐度 |
|------|------|------|--------|
| 总是显示提示 | 简单 | 重复提示 | ❌ |
| 从不显示提示 | 简单 | 单独保存没有反馈 | ❌ |
| **使用标记** | 灵活、清晰、符合 React 最佳实践 | 需要额外的状态 | ✅ |
| 直接调用 API | 避免标记 | 失去 React Query 优势 | ❌ |
| mutation 传参 | 明确 | 需要修改函数签名 | ⚠️ |
| 使用 useRef | 能工作 | 不符合 React 最佳实践 | ⚠️ |

## 🎯 结论

`isSaveBeforeSend` 标记是**必需的**，因为：

1. **React Query 的 `onSuccess` 回调无法自动知道调用上下文**
   - 它不知道是"单独保存"还是"保存后发送"

2. **我们需要根据不同的上下文控制不同的行为**
   - "单独保存"：显示"活动已更新"
   - "保存后发送"：不显示保存提示，只显示发送提示

3. **标记是最简洁、最符合 React 最佳实践的解决方案**
   - 不需要修改 mutation 的函数签名
   - 不需要放弃 React Query 的优势
   - 符合 React 的状态管理理念

**简单来说**：标记就像是在调用者和回调之间传递的一个"暗号"，告诉回调："这次保存是为了后续操作准备的，不要显示提示"。

## 🔗 类比

想象你去餐厅点餐：

### 没有标记的情况

```
你："我要一份汉堡"
服务员："好的，汉堡来了！" （提示 1）
你："请把它打包带走"
服务员："好的，已打包！" （提示 2）
```

结果：两个提示 ❌

### 有标记的情况

```
你："我要一份汉堡，打包带走" （设置标记）
服务员：（准备汉堡，不说话）
服务员："好的，已打包好了！" （只有最终提示）
```

结果：只有一个提示 ✅

标记就是你说的"打包带走"这个额外信息，告诉服务员这是一个组合操作，不需要中间提示。

