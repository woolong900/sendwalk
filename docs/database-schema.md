# SendWalk 数据库设计

## 数据库表结构

### users - 用户表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | bigint | 主键 |
| name | varchar(255) | 用户名 |
| email | varchar(255) | 邮箱（唯一） |
| email_verified_at | timestamp | 邮箱验证时间 |
| password | varchar(255) | 密码哈希 |
| avatar | varchar(255) | 头像URL |
| remember_token | varchar(100) | 记住令牌 |
| created_at | timestamp | 创建时间 |
| updated_at | timestamp | 更新时间 |

### lists - 邮件列表表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | bigint | 主键 |
| user_id | bigint | 用户ID（外键） |
| name | varchar(255) | 列表名称 |
| description | text | 描述 |
| custom_fields | json | 自定义字段配置 |
| double_optin | boolean | 是否启用双重确认 |
| subscribers_count | int | 订阅者数量 |
| created_at | timestamp | 创建时间 |
| updated_at | timestamp | 更新时间 |
| deleted_at | timestamp | 软删除时间 |

### subscribers - 订阅者表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | bigint | 主键 |
| email | varchar(255) | 邮箱（唯一） |
| first_name | varchar(255) | 名字 |
| last_name | varchar(255) | 姓氏 |
| custom_fields | json | 自定义字段数据 |
| status | enum | 状态：active, unsubscribed, bounced, complained |
| subscribed_at | timestamp | 订阅时间 |
| unsubscribed_at | timestamp | 退订时间 |
| ip_address | varchar(45) | IP地址 |
| source | varchar(255) | 来源 |
| created_at | timestamp | 创建时间 |
| updated_at | timestamp | 更新时间 |
| deleted_at | timestamp | 软删除时间 |

### list_subscriber - 列表订阅者关联表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | bigint | 主键 |
| list_id | bigint | 列表ID（外键） |
| subscriber_id | bigint | 订阅者ID（外键） |
| status | enum | 状态：pending, active, unsubscribed |
| subscribed_at | timestamp | 订阅时间 |
| created_at | timestamp | 创建时间 |
| updated_at | timestamp | 更新时间 |

**索引**: unique(list_id, subscriber_id)

### campaigns - 邮件活动表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | bigint | 主键 |
| user_id | bigint | 用户ID（外键） |
| list_id | bigint | 列表ID（外键） |
| name | varchar(255) | 活动名称 |
| subject | varchar(255) | 邮件主题 |
| from_name | varchar(255) | 发件人名称 |
| from_email | varchar(255) | 发件人邮箱 |
| reply_to | varchar(255) | 回复邮箱 |
| html_content | longtext | HTML内容 |
| plain_content | longtext | 纯文本内容 |
| status | enum | 状态：draft, scheduled, sending, sent, paused, cancelled |
| scheduled_at | timestamp | 定时发送时间 |
| sent_at | timestamp | 实际发送时间 |
| total_recipients | int | 总收件人数 |
| total_sent | int | 已发送数 |
| total_delivered | int | 已送达数 |
| total_opened | int | 已打开数 |
| total_clicked | int | 已点击数 |
| total_bounced | int | 退信数 |
| total_complained | int | 投诉数 |
| total_unsubscribed | int | 退订数 |
| ab_test_config | json | A/B测试配置 |
| created_at | timestamp | 创建时间 |
| updated_at | timestamp | 更新时间 |
| deleted_at | timestamp | 软删除时间 |

### campaign_sends - 活动发送记录表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | bigint | 主键 |
| campaign_id | bigint | 活动ID（外键） |
| subscriber_id | bigint | 订阅者ID（外键） |
| status | enum | 状态：pending, sent, failed, bounced |
| sent_at | timestamp | 发送时间 |
| opened_at | timestamp | 首次打开时间 |
| open_count | int | 打开次数 |
| click_count | int | 点击次数 |
| error_message | text | 错误信息 |
| created_at | timestamp | 创建时间 |
| updated_at | timestamp | 更新时间 |

**索引**: (campaign_id, subscriber_id)

### email_templates - 邮件模板表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | bigint | 主键 |
| user_id | bigint | 用户ID（外键，可空） |
| name | varchar(255) | 模板名称 |
| description | text | 描述 |
| html_content | longtext | HTML内容 |
| plain_content | longtext | 纯文本内容 |
| thumbnail | varchar(255) | 缩略图URL |
| is_public | boolean | 是否公开 |
| usage_count | int | 使用次数 |
| created_at | timestamp | 创建时间 |
| updated_at | timestamp | 更新时间 |

### automations - 自动化流程表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | bigint | 主键 |
| user_id | bigint | 用户ID（外键） |
| list_id | bigint | 列表ID（外键，可空） |
| name | varchar(255) | 流程名称 |
| description | text | 描述 |
| workflow_data | json | 流程数据（节点和连接） |
| trigger_type | enum | 触发类型：subscribe, unsubscribe, click, open, date, custom |
| trigger_config | json | 触发器配置 |
| is_active | boolean | 是否激活 |
| total_entered | int | 进入总数 |
| total_completed | int | 完成总数 |
| created_at | timestamp | 创建时间 |
| updated_at | timestamp | 更新时间 |
| deleted_at | timestamp | 软删除时间 |

### automation_subscribers - 自动化订阅者表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | bigint | 主键 |
| automation_id | bigint | 自动化ID（外键） |
| subscriber_id | bigint | 订阅者ID（外键） |
| status | enum | 状态：active, completed, stopped |
| current_step | varchar(255) | 当前步骤ID |
| entered_at | timestamp | 进入时间 |
| completed_at | timestamp | 完成时间 |
| created_at | timestamp | 创建时间 |
| updated_at | timestamp | 更新时间 |

**索引**: (automation_id, subscriber_id)

### links - 链接表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | bigint | 主键 |
| campaign_id | bigint | 活动ID（外键） |
| original_url | varchar(2048) | 原始URL |
| hash | varchar(32) | 哈希值（唯一） |
| click_count | int | 总点击次数 |
| unique_click_count | int | 唯一点击次数 |
| created_at | timestamp | 创建时间 |
| updated_at | timestamp | 更新时间 |

### link_clicks - 链接点击记录表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | bigint | 主键 |
| link_id | bigint | 链接ID（外键） |
| subscriber_id | bigint | 订阅者ID（外键） |
| ip_address | varchar(45) | IP地址 |
| user_agent | varchar(255) | User Agent |
| country | varchar(2) | 国家代码 |
| city | varchar(255) | 城市 |
| clicked_at | timestamp | 点击时间 |

**索引**: (link_id, subscriber_id)

### smtp_servers - SMTP服务器表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | bigint | 主键 |
| user_id | bigint | 用户ID（外键） |
| name | varchar(255) | 服务器名称 |
| type | enum | 类型：smtp, ses, sendgrid, mailgun, postmark |
| host | varchar(255) | 主机地址 |
| port | int | 端口 |
| username | varchar(255) | 用户名 |
| password | varchar(255) | 密码 |
| encryption | varchar(255) | 加密方式 |
| credentials | json | 凭证信息（JSON） |
| is_default | boolean | 是否默认 |
| is_active | boolean | 是否启用 |
| rate_limit_second | int | 每秒限制 |
| rate_limit_minute | int | 每分钟限制 |
| rate_limit_hour | int | 每小时限制 |
| rate_limit_day | int | 每天限制 |
| emails_sent_today | int | 今日已发送数 |
| last_reset_date | date | 上次重置日期 |
| created_at | timestamp | 创建时间 |
| updated_at | timestamp | 更新时间 |

## 数据库关系图

```
users
  ├─ lists (1:N)
  ├─ campaigns (1:N)
  ├─ automations (1:N)
  └─ smtp_servers (1:N)

lists
  ├─ subscribers (N:M through list_subscriber)
  ├─ campaigns (1:N)
  └─ automations (1:N)

subscribers
  ├─ lists (N:M through list_subscriber)
  ├─ campaign_sends (1:N)
  └─ link_clicks (1:N)

campaigns
  ├─ campaign_sends (1:N)
  └─ links (1:N)

links
  └─ link_clicks (1:N)

automations
  └─ subscribers (N:M through automation_subscribers)
```

## 索引策略

### 主要索引

1. **外键索引**: 所有外键字段都有索引
2. **唯一索引**: 
   - users.email
   - subscribers.email
   - links.hash
   - list_subscriber(list_id, subscriber_id)
3. **复合索引**:
   - campaign_sends(campaign_id, subscriber_id)
   - link_clicks(link_id, subscriber_id)
   - automation_subscribers(automation_id, subscriber_id)

### 查询优化索引

```sql
-- 活动状态查询
CREATE INDEX idx_campaigns_status ON campaigns(status);

-- 订阅者状态查询
CREATE INDEX idx_subscribers_status ON subscribers(status);

-- 时间范围查询
CREATE INDEX idx_campaigns_created_at ON campaigns(created_at);
CREATE INDEX idx_campaign_sends_sent_at ON campaign_sends(sent_at);
```

## 数据库优化建议

1. **分区策略**: 对大表（如 campaign_sends, link_clicks）按时间分区
2. **归档策略**: 定期归档历史数据（超过1年的数据）
3. **读写分离**: 使用主从复制，读操作使用从库
4. **缓存策略**: 使用 Redis 缓存热数据
5. **定期维护**: 定期优化表、更新统计信息

