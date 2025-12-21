# SendLog 数据库清理说明

## 📋 功能说明

系统已配置为每天自动清理 `send_logs` 数据库表中超过 30 天的旧记录，避免数据库无限增长影响性能。

## 🎯 清理策略

### **自动清理**

- **执行时间**：每天凌晨 4:00
- **保留天数**：30 天
- **批次大小**：1000 条/批次
- **执行方式**：后台运行

### **为什么需要清理 SendLog？**

1. **数据增长快**：每发送一封邮件就会产生一条记录
2. **查询变慢**：随着数据量增加，查询速度会显著下降
3. **存储成本**：占用大量数据库空间
4. **备份耗时**：数据库备份时间变长

### **清理策略对比**

假设每天发送 10,000 封邮件：

| 保留天数 | 数据量 | 大小估算 | 推荐场景 |
|---------|--------|---------|---------|
| 7 天 | ~70,000 | ~35MB | 测试环境 |
| 30 天 | ~300,000 | ~150MB | 生产环境（默认） |
| 90 天 | ~900,000 | ~450MB | 合规要求/审计需要 |
| 永久保留 | 数百万+ | GB级别 | ❌ 不推荐 |

## 💻 命令使用

### **1. 预览会删除的记录（Dry Run）**

```bash
# 查看会删除哪些记录（不实际删除）
php artisan sendlogs:cleanup --dry-run

# 示例输出
🗑️  Starting SendLog cleanup...
   Delete records older than: 2025-11-20 23:33:56
   Batch size: 1000
   Dry run: Yes

📊 Found 122 records to delete

Sample records that would be deleted:
  ID: 1, Campaign: test, Email: hi@dmoal.com, Status: sent, Date: 2025-12-14 10:17:54
  ... and 117 more records

✅ Dry run completed
```

### **2. 手动清理（默认保留 30 天）**

```bash
php artisan sendlogs:cleanup
```

### **3. 自定义保留天数**

```bash
# 保留 7 天的数据
php artisan sendlogs:cleanup --days=7

# 保留 90 天的数据
php artisan sendlogs:cleanup --days=90
```

### **4. 调整批次大小**

```bash
# 每次删除 5000 条记录（默认 1000）
php artisan sendlogs:cleanup --batch-size=5000
```

### **5. 组合使用**

```bash
# Dry run：查看删除 7 天前的数据
php artisan sendlogs:cleanup --days=7 --dry-run

# 实际执行：删除 7 天前的数据
php artisan sendlogs:cleanup --days=7
```

## 📊 命令参数说明

| 参数 | 默认值 | 说明 |
|------|--------|------|
| `--days` | 30 | 保留最近 N 天的数据 |
| `--batch-size` | 1000 | 每批删除的记录数 |
| `--dry-run` | false | 只显示会删除的记录，不实际删除 |

## 🕐 定时任务配置

在 `routes/console.php` 中已配置：

```php
// 清理旧发送日志（每天凌晨4点，保留30天）
Schedule::command('sendlogs:cleanup --days=30')
    ->dailyAt('04:00')
    ->runInBackground();
```

### **修改清理时间**

```php
// 每天中午12点清理
->dailyAt('12:00')

// 每周日凌晨2点清理
->weekly()->sundays()->at('02:00')

// 每月1号凌晨3点清理
->monthlyOn(1, '03:00')
```

### **修改保留天数**

```php
// 保留 7 天
Schedule::command('sendlogs:cleanup --days=7')

// 保留 90 天
Schedule::command('sendlogs:cleanup --days=90')
```

## 📈 性能优化

### **批量删除策略**

命令使用批量删除，避免长时间锁表：

```php
// 每批删除 1000 条
while (true) {
    $deleted = SendLog::where('created_at', '<', $cutoffDate)
        ->limit(1000)
        ->delete();
    
    if ($deleted === 0) break;
    
    // 每批之间暂停 10ms，避免过度占用资源
    usleep(10000);
}
```

### **表优化**

删除完成后自动优化表：

```sql
OPTIMIZE TABLE send_logs;
```

这会：
- 回收已删除记录占用的空间
- 重建索引，提高查询效率
- 整理表碎片

### **性能估算**

| 删除数量 | 预计耗时 | 数据库影响 |
|---------|---------|-----------|
| 1,000 | <1s | 几乎无影响 |
| 10,000 | ~5s | 轻微影响 |
| 100,000 | ~30s | 中等影响 |
| 1,000,000 | ~5min | 建议在低峰期执行 |

## 🔍 监控和日志

### **查看执行日志**

```bash
# 实时查看日志
tail -f storage/logs/laravel-$(date +%Y-%m-%d).log | grep "SendLog cleanup"

# 搜索历史记录
grep "SendLog cleanup" storage/logs/laravel-*.log
```

### **日志内容**

```json
{
  "message": "SendLog cleanup completed",
  "deleted_count": 12500,
  "cutoff_date": "2025-11-20 04:00:00",
  "duration_seconds": 15.23
}
```

### **查看数据库统计**

```bash
php artisan tinker --execute="
echo 'Total send logs: ' . App\Models\SendLog::count() . PHP_EOL;
echo 'Oldest log: ' . App\Models\SendLog::orderBy('created_at', 'asc')->first()->created_at . PHP_EOL;
echo 'Latest log: ' . App\Models\SendLog::orderBy('created_at', 'desc')->first()->created_at . PHP_EOL;
"
```

## ⚠️ 注意事项

### **1. 数据不可恢复**

删除的数据无法恢复，请谨慎操作：

```bash
# ✅ 始终先使用 dry-run 预览
php artisan sendlogs:cleanup --dry-run

# ✅ 确认无误后再实际执行
php artisan sendlogs:cleanup
```

### **2. 大量数据删除**

如果需要删除超过 10,000 条记录，命令会要求确认：

```
⚠️  About to delete 125,000 records!
 Do you want to continue? (yes/no) [no]:
```

在定时任务中使用时，不会有此确认（自动执行）。

### **3. 低峰期执行**

建议在业务低峰期执行清理：

```php
// 凌晨 4:00 执行（推荐）
->dailyAt('04:00')

// 避免在业务高峰期执行
// 如：上午 10:00 - 下午 6:00
```

### **4. 保留关键数据**

如果需要长期保留某些活动的发送记录，可以考虑：

- 导出重要数据到归档表
- 使用数据仓库存储历史数据
- 调整保留天数（如 90 天）

## 📦 数据归档方案

如果需要保留历史数据用于分析，可以先归档再删除：

### **方案 1：导出到 CSV**

```bash
# 导出 30 天前的数据
php artisan tinker --execute="
\$cutoffDate = now()->subDays(30);
\$logs = App\Models\SendLog::where('created_at', '<', \$cutoffDate)->get();
\$file = fopen('send_logs_archive_' . date('Y-m-d') . '.csv', 'w');
fputcsv(\$file, ['ID', 'Campaign', 'Email', 'Status', 'Created At']);
foreach (\$logs as \$log) {
    fputcsv(\$file, [\$log->id, \$log->campaign_name, \$log->email, \$log->status, \$log->created_at]);
}
fclose(\$file);
echo 'Exported ' . \$logs->count() . ' records' . PHP_EOL;
"
```

### **方案 2：归档到专用表**

```sql
-- 创建归档表
CREATE TABLE send_logs_archive LIKE send_logs;

-- 移动旧数据到归档表
INSERT INTO send_logs_archive 
SELECT * FROM send_logs 
WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY);

-- 删除已归档的数据
DELETE FROM send_logs 
WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
```

### **方案 3：备份到 S3 或其他存储**

```php
// 每月备份数据到 S3
Schedule::call(function () {
    $cutoffDate = now()->subDays(30);
    $logs = SendLog::where('created_at', '<', $cutoffDate)->get();
    
    Storage::disk('s3')->put(
        'send_logs_archive/' . date('Y-m') . '.json',
        $logs->toJson()
    );
    
    // 备份完成后删除
    SendLog::where('created_at', '<', $cutoffDate)->delete();
})->monthly();
```

## 🔧 故障排查

### **问题 1：清理命令没有执行**

**检查调度器是否运行**：
```bash
ps aux | grep "schedule:work"
```

**解决方案**：
```bash
# 启动调度器
php artisan schedule:work &
```

### **问题 2：删除速度太慢**

**可能原因**：
- 数据量太大
- 没有索引

**解决方案**：
```bash
# 增加批次大小
php artisan sendlogs:cleanup --batch-size=5000

# 检查索引
php artisan tinker --execute="
DB::select('SHOW INDEX FROM send_logs');
"
```

### **问题 3：表空间没有释放**

**原因**：MySQL InnoDB 表删除数据后，空间不会立即释放。

**解决方案**：
```sql
-- 手动优化表
OPTIMIZE TABLE send_logs;

-- 或使用命令（自动执行）
php artisan sendlogs:cleanup
```

### **问题 4：锁表导致其他查询阻塞**

**原因**：大批量删除时可能导致表锁定。

**解决方案**：
```bash
# 减小批次大小
php artisan sendlogs:cleanup --batch-size=500

# 或在低峰期执行
```

## 📊 数据分析建议

在清理数据前，可以先导出统计数据：

```sql
-- 按日期统计发送量
SELECT DATE(created_at) as date, 
       COUNT(*) as total,
       SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as success,
       SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
FROM send_logs
WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY DATE(created_at)
ORDER BY date DESC;

-- 按活动统计
SELECT campaign_name,
       COUNT(*) as total,
       AVG(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) * 100 as success_rate
FROM send_logs
WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY campaign_name
ORDER BY total DESC;
```

## ✅ 检查清单

部署后请确认：

- [ ] 命令可以正常执行：`php artisan sendlogs:cleanup --dry-run`
- [ ] 调度器正在运行：`ps aux | grep schedule:work`
- [ ] 定时任务已配置：`grep sendlogs:cleanup routes/console.php`
- [ ] 日志记录正常：`tail -f storage/logs/laravel-*.log`
- [ ] 数据库索引完整：已在前面优化
- [ ] 保留天数符合需求：默认 30 天

## 🎯 最佳实践

1. **定期监控**：
   ```bash
   # 每周检查一次数据量
   php artisan tinker --execute="echo 'Send logs: ' . App\Models\SendLog::count();"
   ```

2. **调整保留策略**：
   - 测试环境：7 天
   - 生产环境：30 天
   - 合规要求：90 天或更长

3. **备份重要数据**：
   - 在清理前导出关键活动的数据
   - 保留统计报告

4. **低峰期执行**：
   - 凌晨 3:00 - 5:00
   - 避免业务高峰期

5. **逐步调整**：
   - 先从小量测试开始
   - 观察系统性能影响
   - 逐步调整参数

## 🚀 总结

SendLog 自动清理功能已配置完成：

- ✅ 每天凌晨 4:00 自动清理
- ✅ 默认保留 30 天数据
- ✅ 批量删除避免锁表
- ✅ 自动优化表性能
- ✅ 详细日志记录
- ✅ 支持手动执行和 Dry Run

现在您的数据库会保持整洁，查询性能也会更好！ 🎉
