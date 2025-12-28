# 活动编辑页面加载问题修复说明

## 问题描述

在编辑活动页面时，偶尔会出现以下两个字段未正确加载的情况：
1. **发送服务器**字段显示为"选择服务器"而不是实际的服务器名称
2. **邮件列表**字段的复选框未正确勾选已选择的列表

## 问题原因

**竞态条件（Race Condition）**：多个并行的 API 请求返回时间不确定，导致在设置表单数据时，相关的选项列表可能还未加载完成。

```
并行请求：
├─ Campaign API 返回（包含 smtp_server_id=3, list_ids=[1,2,3]）
├─ SMTP Servers API 加载中...
└─ Lists API 加载中...

时刻 1: Campaign 数据先返回
  └─ 设置 formData: smtp_server_id='3', list_ids=[1,2,3]
  └─ UI 渲染：
      ├─ Select 组件 value='3' 但 options=[] → 显示"选择服务器" ❌
      └─ Checkbox list_ids=[1,2,3] 但 lists=[] → 无法勾选 ❌

时刻 2: SMTP Servers 和 Lists 数据返回
  └─ 但 formData 已经设置，UI 不会自动更新
```

## 解决方案

在 `frontend/src/pages/campaigns/editor.tsx` 中添加等待逻辑，确保在编辑模式下，只有当所有必要数据都加载完成后，才设置表单数据。

### 修改内容：

```typescript
useEffect(() => {
  if (!campaign) {
    return
  }

  // 如果是编辑模式，等待必要的数据加载完成
  if (isEditing) {
    // 等待 smtpServers 加载（如果有 smtp_server_id）
    if (campaign.smtp_server_id && (!smtpServers || smtpServers.length === 0)) {
      return  // 等待...
    }
    
    // 等待 lists 加载（如果有 list_ids）
    const hasListIds = campaign.list_ids && campaign.list_ids.length > 0
    if (hasListIds && (!lists || lists.length === 0)) {
      return  // 等待...
    }
  }
  
  // 所有数据就绪，设置 formData
  setFormData({
    list_ids: campaign.list_ids || [],
    smtp_server_id: campaign.smtp_server_id ? campaign.smtp_server_id.toString() : '',
    // ... 其他字段
  })
  
}, [campaign, smtpServers, lists, isEditing])  // 添加必要依赖
```

### 修复原理：

1. **添加多个数据依赖**：
   - 当 `smtpServers` 从空变为有数据时，触发 `useEffect`
   - 当 `lists` 从空变为有数据时，触发 `useEffect`

2. **分别检查每个数据源**：
   - 如果 campaign 有 `smtp_server_id`，等待 `smtpServers` 加载
   - 如果 campaign 有 `list_ids`，等待 `lists` 加载
   - 只有所有必要数据都准备好，才设置 `formData`

3. **修复后的时序**：
   ```
   时刻 1: Campaign 数据返回
     └─ useEffect 触发，检测到 smtpServers/lists 为空
     └─ 等待... (不设置 formData)
   
   时刻 2: SMTP Servers 数据返回
     └─ useEffect 再次触发，检测到 lists 仍为空
     └─ 继续等待...
   
   时刻 3: Lists 数据返回
     └─ useEffect 再次触发，所有数据已就绪
     └─ 设置 formData
   
   时刻 4: UI 渲染
     ├─ Select: value='3', options=[1,2,3,4] ✅ 正确显示
     └─ Checkbox: list_ids=[1,2,3], lists=[...] ✅ 正确勾选
   ```

### 修复效果：

- ✅ 彻底解决竞态条件
- ✅ 不影响页面加载性能（仍然是并行请求）
- ✅ 代码简洁清晰
- ✅ 同时修复了两个字段的加载问题

## 测试

### 正常测试：
访问编辑页面多次，观察：
- 发送服务器字段应该始终正确显示服务器名称
- 邮件列表的复选框应该正确勾选已选择的列表

### 慢网络测试：
1. 打开 Chrome DevTools
2. Network 标签 → 设置为 "Slow 3G"
3. 访问编辑页面
4. 观察字段是否仍然正确加载

## 日期

2025-12-28

