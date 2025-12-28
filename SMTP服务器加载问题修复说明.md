# SMTP 服务器加载问题修复说明

## 问题描述

在编辑活动页面时，偶尔会出现"发送服务器"字段未正确加载的情况，显示为"选择服务器"而不是实际的服务器名称。

## 问题原因

**竞态条件（Race Condition）**：两个并行的 API 请求返回时间不确定，导致在设置 `smtp_server_id` 时，`smtpServers` 列表可能还未加载完成。

```
Campaign API 返回 → 设置 smtp_server_id = '3' → Select 组件渲染
                                                    ↓
                                              找不到 value='3' 的选项
                                                    ↓
SMTP Servers API 返回 → 但为时已晚
```

## 解决方案

在 `frontend/src/pages/campaigns/editor.tsx` 中添加等待逻辑，确保在编辑模式下，只有当 `smtpServers` 加载完成后才设置 `smtp_server_id`。

### 修改内容：

```typescript
useEffect(() => {
  if (!campaign) {
    return
  }

  // 如果是编辑模式且有 smtp_server_id，等待 smtpServers 加载完成
  if (isEditing && campaign.smtp_server_id && (!smtpServers || smtpServers.length === 0)) {
    return  // 等待...
  }
  
  // 设置 formData
  setFormData({ ... })
  
}, [campaign, smtpServers, isEditing])  // 添加 smtpServers 依赖
```

### 修复效果：

- ✅ 彻底解决竞态条件
- ✅ 不影响页面加载性能（仍然是并行请求）
- ✅ 代码简洁清晰

## 测试

正常访问编辑页面，发送服务器字段应该始终正确显示。

## 日期

2025-12-28

