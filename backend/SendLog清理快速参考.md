# SendLog 清理快速参考

## ✅ 已配置

- **自动清理**：每天凌晨 4:00
- **保留天数**：30 天
- **状态**：已启用

## 💻 常用命令

```bash
# 预览会删除的记录（推荐先执行）
php artisan sendlogs:cleanup --dry-run

# 执行清理（保留 30 天）
php artisan sendlogs:cleanup

# 保留 7 天
php artisan sendlogs:cleanup --days=7

# 保留 90 天
php artisan sendlogs:cleanup --days=90
```

## 📊 查看统计

```bash
# 查看总记录数
php artisan tinker --execute="echo App\Models\SendLog::count();"

# 查看日期范围
php artisan tinker --execute="
\$oldest = App\Models\SendLog::orderBy('created_at', 'asc')->first();
\$latest = App\Models\SendLog::orderBy('created_at', 'desc')->first();
echo 'Oldest: ' . \$oldest->created_at . PHP_EOL;
echo 'Latest: ' . \$latest->created_at . PHP_EOL;
"
```

## 🔧 修改配置

修改 `routes/console.php`：

```php
// 修改保留天数
Schedule::command('sendlogs:cleanup --days=90')

// 修改执行时间
->dailyAt('03:00')  // 凌晨 3:00
```

## 📖 详细文档

查看完整文档：
```bash
cat SendLog清理说明.md
```

运行测试：
```bash
./test_sendlog_cleanup.sh
```

## ⚠️ 注意

- 删除的数据**无法恢复**
- 始终先用 `--dry-run` 预览
- 大量数据删除会要求确认

## 📈 当前数据

- 总记录数：9,037
- 最早记录：2025-12-14
- 最新记录：2025-12-20
- 成功：7,094
- 失败：1,943

