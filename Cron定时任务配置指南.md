# Laravel Scheduler Cron 定时任务配置指南

## 📋 项目中的定时任务

你的项目配置了以下定时任务（在 `backend/routes/console.php` 中）：

| 任务 | 频率 | 说明 |
|------|------|------|
| `campaigns:process-scheduled` | 每分钟 | 处理到时间的定时活动 |
| `automations:process` | 每分钟 | 处理自动化邮件 |
| `queue:clean` | 每天 02:00 | 清理已完成的旧队列任务（7天前） |
| `logs:cleanup` | 每天 03:00 | 清理旧日志文件（保留30天） |
| `sendlogs:cleanup` | 每天 04:00 | 清理旧发送日志（保留30天） |

## ⚠️ 为什么必须配置 Cron

如果不配置 cron：

1. ❌ **定时活动不会自动发送**
   - 用户设置的定时发送将不会执行
   - 活动会一直停留在 "scheduled" 状态

2. ❌ **自动化邮件不会触发**
   - 自动化工作流不会运行

3. ❌ **旧数据不会清理**
   - 日志文件会越来越大
   - 数据库会越来越臃肿

## 🚀 快速配置（推荐）

### 方法 1: 使用自动化脚本

```bash
cd /data/www/sendwalk
chmod +x setup-cron.sh
sudo ./setup-cron.sh
```

### 方法 2: 手动配置

```bash
# 1. 编辑 crontab
crontab -e

# 2. 添加以下行（复制粘贴）
* * * * * cd /data/www/sendwalk/backend && /usr/bin/php artisan schedule:run >> /dev/null 2>&1

# 3. 保存并退出（:wq）
```

## 🔍 验证配置

### 检查 cron 是否已添加

```bash
crontab -l
```

应该看到：
```
* * * * * cd /data/www/sendwalk/backend && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

### 查看定时任务列表

```bash
cd /data/www/sendwalk/backend
php artisan schedule:list
```

应该看到：
```
  0 * * * * php artisan inspire ................ Next Due: 1 hour from now
  * * * * * php artisan campaigns:process-scheduled .. Next Due: 1 minute from now
  * * * * * php artisan automations:process ...... Next Due: 1 minute from now
  0 2 * * * php artisan queue:clean ............. Next Due: 14 hours from now
  0 3 * * * php artisan logs:cleanup --days=30 .. Next Due: 15 hours from now
  0 4 * * * php artisan sendlogs:cleanup --days=30 Next Due: 16 hours from now
```

### 手动测试 scheduler

```bash
cd /data/www/sendwalk/backend
php artisan schedule:run
```

应该看到：
```
  Running [php artisan campaigns:process-scheduled]  DONE
  Running [php artisan automations:process]  DONE
```

### 查看 cron 执行日志

```bash
# Ubuntu/Debian
grep CRON /var/log/syslog | tail -20

# 或者
sudo tail -f /var/log/cron
```

## 🔧 Laravel Scheduler 工作原理

```
┌─────────────┐
│   Cron      │  每分钟触发一次
│ (* * * * *) │
└──────┬──────┘
       │
       ▼
┌─────────────────────────┐
│  artisan schedule:run   │  检查所有定时任务
└──────┬──────────────────┘
       │
       ▼
┌─────────────────────────┐
│  检查任务执行时间       │
│  - everyMinute()        │
│  - hourly()             │
│  - daily()              │
│  - etc.                 │
└──────┬──────────────────┘
       │
       ▼
┌─────────────────────────┐
│  执行到时间的任务       │
│  - campaigns:process-   │
│    scheduled            │
│  - automations:process  │
│  - queue:clean          │
│  - etc.                 │
└─────────────────────────┘
```

**关键点**：
- Cron 只需要配置一次（每分钟运行）
- Laravel 负责判断哪些任务该执行
- 你只需要在 `routes/console.php` 中配置任务频率

## 📝 不同用户的 Cron 配置

### Root 用户（推荐用于系统级任务）

```bash
sudo crontab -e

# 添加
* * * * * cd /data/www/sendwalk/backend && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

### www-data 用户（Web 服务器用户）

```bash
sudo crontab -u www-data -e

# 添加
* * * * * cd /data/www/sendwalk/backend && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

### 特定用户

```bash
crontab -e

# 添加
* * * * * cd /data/www/sendwalk/backend && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

## 🐛 常见问题排查

### 问题 1: Cron 似乎没有运行

**检查 cron 服务状态**:
```bash
sudo systemctl status cron
```

**启动 cron 服务**:
```bash
sudo systemctl start cron
sudo systemctl enable cron
```

### 问题 2: 任务没有执行

**检查 Laravel 日志**:
```bash
tail -f /data/www/sendwalk/backend/storage/logs/laravel-$(date +%Y-%m-%d).log
```

**检查文件权限**:
```bash
ls -la /data/www/sendwalk/backend/storage/
ls -la /data/www/sendwalk/backend/bootstrap/cache/
```

确保目录可写：
```bash
sudo chown -R www-data:www-data /data/www/sendwalk/backend/storage
sudo chown -R www-data:www-data /data/www/sendwalk/backend/bootstrap/cache
```

### 问题 3: PHP 路径不对

**查找 PHP 路径**:
```bash
which php
```

如果不是 `/usr/bin/php`，修改 cron 命令：
```bash
* * * * * cd /data/www/sendwalk/backend && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```

### 问题 4: 想看 cron 的输出

**添加日志输出**:
```bash
# 编辑 crontab
crontab -e

# 修改为（输出到日志文件）
* * * * * cd /data/www/sendwalk/backend && /usr/bin/php artisan schedule:run >> /data/www/sendwalk/backend/storage/logs/cron.log 2>&1
```

**查看日志**:
```bash
tail -f /data/www/sendwalk/backend/storage/logs/cron.log
```

## 🔧 高级配置

### 只在工作日执行

```bash
# 周一到周五执行（1-5）
* * * * 1-5 cd /data/www/sendwalk/backend && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

### 使用绝对路径的环境变量

```bash
# 编辑 crontab
crontab -e

# 在文件开头添加
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

# 然后添加任务
* * * * * cd /data/www/sendwalk/backend && php artisan schedule:run >> /dev/null 2>&1
```

### 防止任务重叠执行

Laravel Scheduler 自动处理任务重叠，但如果需要额外保护：

```bash
# 使用 flock 防止并发
* * * * * flock -n /tmp/scheduler.lock -c 'cd /data/www/sendwalk/backend && /usr/bin/php artisan schedule:run >> /dev/null 2>&1'
```

## 📊 监控定时任务

### 创建监控脚本

创建 `monitor-scheduler.sh`:

```bash
#!/bin/bash

echo "=== Laravel Scheduler 状态 ==="
echo ""

# 检查 cron 服务
echo "Cron 服务状态:"
systemctl status cron --no-pager | grep "Active:"
echo ""

# 检查 crontab 配置
echo "Crontab 配置:"
crontab -l | grep artisan
echo ""

# 检查最近的执行
echo "最近的 scheduler 执行 (cron 日志):"
grep CRON /var/log/syslog | grep "artisan" | tail -5
echo ""

# 检查 Laravel 日志中的任务执行
echo "Laravel 日志中的任务执行:"
tail -20 /data/www/sendwalk/backend/storage/logs/laravel-$(date +%Y-%m-%d).log | grep -E "campaigns:process|automations:process|queue:clean|logs:cleanup|sendlogs:cleanup"
echo ""

# 手动运行一次
echo "手动测试 scheduler:"
cd /data/www/sendwalk/backend
php artisan schedule:run
```

使用：
```bash
chmod +x monitor-scheduler.sh
./monitor-scheduler.sh
```

## ✅ 配置完成检查清单

- [ ] Cron 任务已添加（`crontab -l`）
- [ ] Cron 服务正在运行（`systemctl status cron`）
- [ ] PHP 路径正确（`which php`）
- [ ] 文件权限正确（storage 和 bootstrap/cache 可写）
- [ ] 手动测试成功（`php artisan schedule:run`）
- [ ] 可以看到任务列表（`php artisan schedule:list`）
- [ ] Cron 日志显示任务在执行（`grep CRON /var/log/syslog`）
- [ ] Laravel 日志没有错误（`tail storage/logs/laravel.log`）

## 📞 如果还有问题

提供以下信息：

1. Crontab 配置：`crontab -l`
2. Cron 服务状态：`systemctl status cron`
3. PHP 路径：`which php`
4. 手动运行结果：`php artisan schedule:run`
5. Laravel 日志：最近的错误
6. 系统日志：`grep CRON /var/log/syslog | tail -20`

---

**配置 cron 是 Laravel 项目部署的必要步骤！** 🚀

