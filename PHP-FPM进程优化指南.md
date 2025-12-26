# PHP-FPM 进程优化指南

## 📋 问题背景

当PHP-FPM进程数不足时，会导致：
- ❌ 请求排队，响应延迟
- ❌ 前端请求长时间pending
- ❌ 并发能力差
- ❌ 用户体验差

---

## 🔍 第一步：检查当前配置

### 1. 查看当前进程数
```bash
ps aux | grep php-fpm | grep -v grep | wc -l
```

### 2. 查看进程详情
```bash
ps aux | grep php-fpm | grep -v grep
```

输出示例：
```
root      1234  0.0  2.1  php-fpm: master process
www-data  1235  0.1  3.2  php-fpm: pool www
www-data  1236  0.1  3.1  php-fpm: pool www
www-data  1237  0.1  3.2  php-fpm: pool www
```

### 3. 找到配置文件

**Ubuntu/Debian**:
```bash
# PHP 8.2
/etc/php/8.2/fpm/pool.d/www.conf

# PHP 8.1
/etc/php/8.1/fpm/pool.d/www.conf

# PHP 8.0
/etc/php/8.0/fpm/pool.d/www.conf
```

**查找配置文件**:
```bash
# 自动找到配置文件
find /etc/php -name "www.conf" 2>/dev/null
```

---

## ⚙️ 第二步：理解配置参数

打开配置文件：
```bash
sudo nano /etc/php/8.2/fpm/pool.d/www.conf
```

### 关键参数说明

#### 1. **pm (Process Manager)**
```ini
pm = dynamic
```

可选值：
- **static** - 固定数量的子进程
- **dynamic** - 动态调整子进程数量（推荐）
- **ondemand** - 按需创建进程

**推荐**：`dynamic`（灵活且高效）

---

#### 2. **pm.max_children**（最大子进程数）
```ini
pm.max_children = 20
```

**说明**：
- 同时运行的最大子进程数
- **重要**：这是最大并发请求数
- 如果所有子进程都在忙，新请求会排队

**如何计算**：
```
可用内存 / 每个进程的内存
```

**示例**：
```
服务器内存: 4GB
预留系统内存: 1GB
可用内存: 3GB = 3072MB
每个PHP-FPM进程: ~50MB

max_children = 3072 / 50 = 60
```

**推荐值**：
| 服务器内存 | 推荐值 |
|-----------|--------|
| 1GB | 10 |
| 2GB | 15 |
| 4GB | 20-30 |
| 8GB | 40-60 |
| 16GB+ | 80-100 |

---

#### 3. **pm.start_servers**（启动时子进程数）
```ini
pm.start_servers = 5
```

**说明**：
- PHP-FPM启动时创建的子进程数
- 应该在 `pm.min_spare_servers` 和 `pm.max_spare_servers` 之间

**推荐值**：
```
pm.start_servers = (pm.min_spare_servers + pm.max_spare_servers) / 2
```

---

#### 4. **pm.min_spare_servers**（最小空闲进程数）
```ini
pm.min_spare_servers = 3
```

**说明**：
- 保持的最小空闲进程数
- 如果空闲进程少于这个数，会自动创建新进程

**推荐值**：
- 小型应用：2-3
- 中型应用：5-10
- 大型应用：10-20

---

#### 5. **pm.max_spare_servers**（最大空闲进程数）
```ini
pm.max_spare_servers = 8
```

**说明**：
- 保持的最大空闲进程数
- 如果空闲进程多于这个数，会自动杀掉一些

**推荐值**：
```
pm.max_spare_servers = pm.max_children * 0.4
```

---

#### 6. **pm.max_requests**（每个子进程处理的最大请求数）
```ini
pm.max_requests = 500
```

**说明**：
- 每个子进程处理这么多请求后会重启
- 防止内存泄漏

**推荐值**：
- 默认：500
- 高流量：1000

---

## 🚀 第三步：推荐配置方案

### 方案A：默认配置（适合小型应用）
```ini
pm = dynamic
pm.max_children = 10
pm.start_servers = 3
pm.min_spare_servers = 2
pm.max_spare_servers = 5
pm.max_requests = 500
```

**适用场景**：
- 服务器内存 1-2GB
- 日均访问量 < 1万
- 并发用户 < 10人

---

### 方案B：中型应用配置（推荐 SendWalk）⭐
```ini
pm = dynamic
pm.max_children = 20
pm.start_servers = 5
pm.min_spare_servers = 3
pm.max_spare_servers = 8
pm.max_requests = 500
```

**适用场景**：
- 服务器内存 2-4GB
- 日均访问量 1万-10万
- 并发用户 10-50人
- 有后台任务（队列、定时任务）

---

### 方案C：大型应用配置
```ini
pm = dynamic
pm.max_children = 50
pm.start_servers = 10
pm.min_spare_servers = 5
pm.max_spare_servers = 20
pm.max_requests = 1000
```

**适用场景**：
- 服务器内存 8GB+
- 日均访问量 > 10万
- 并发用户 > 50人

---

### 方案D：高并发配置（生产环境）
```ini
pm = dynamic
pm.max_children = 100
pm.start_servers = 20
pm.min_spare_servers = 10
pm.max_spare_servers = 40
pm.max_requests = 1000
pm.process_idle_timeout = 10s
```

**适用场景**：
- 服务器内存 16GB+
- 高并发场景
- 有负载均衡

---

## 📝 第四步：修改配置

### 1. 备份原配置
```bash
sudo cp /etc/php/8.2/fpm/pool.d/www.conf /etc/php/8.2/fpm/pool.d/www.conf.backup
```

### 2. 编辑配置文件
```bash
sudo nano /etc/php/8.2/fpm/pool.d/www.conf
```

### 3. 找到并修改以下参数

在文件中搜索（Ctrl+W）`pm =`，然后修改：

```ini
; 修改前（默认）
pm = dynamic
pm.max_children = 5
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3

; 修改后（推荐 SendWalk）
pm = dynamic
pm.max_children = 20
pm.start_servers = 5
pm.min_spare_servers = 3
pm.max_spare_servers = 8
pm.max_requests = 500
```

### 4. 保存并退出
- 按 `Ctrl+O` 保存
- 按 `Enter` 确认
- 按 `Ctrl+X` 退出

---

## 🔄 第五步：重启PHP-FPM

### 重启服务
```bash
# PHP 8.2
sudo systemctl restart php8.2-fpm

# PHP 8.1
sudo systemctl restart php8.1-fpm

# 或者使用service命令
sudo service php8.2-fpm restart
```

### 检查状态
```bash
sudo systemctl status php8.2-fpm
```

应该看到：
```
● php8.2-fpm.service - The PHP 8.2 FastCGI Process Manager
   Loaded: loaded
   Active: active (running)
```

---

## ✅ 第六步：验证配置

### 1. 查看新的进程数
```bash
ps aux | grep php-fpm | grep -v grep
```

应该看到更多的进程（根据 `pm.start_servers` 的值）

### 2. 实时监控进程变化
```bash
watch -n 1 'ps aux | grep php-fpm | grep -v grep | wc -l'
```

### 3. 测试并发能力

在浏览器中：
1. 打开多个标签页
2. 同时加载黑名单页面
3. 观察响应速度

在服务器上：
```bash
cd /data/www/sendwalk
./快速诊断黑名单性能.sh
```

观察是否还有请求排队的情况。

---

## 📊 监控和调优

### 查看PHP-FPM状态

启用状态页（可选）：
```bash
sudo nano /etc/php/8.2/fpm/pool.d/www.conf
```

添加或取消注释：
```ini
pm.status_path = /php-fpm-status
```

在Nginx配置中添加：
```nginx
location ~ ^/php-fpm-status$ {
    access_log off;
    allow 127.0.0.1;
    deny all;
    fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
}
```

访问状态：
```bash
curl http://localhost/php-fpm-status
```

---

## 🚨 常见问题

### 问题1：重启后进程数没变化

**检查配置文件是否正确**：
```bash
sudo php-fpm8.2 -t
```

应该显示：`configuration file test is successful`

**检查是否修改了正确的文件**：
```bash
sudo grep "pm.max_children" /etc/php/8.2/fpm/pool.d/www.conf
```

---

### 问题2：内存不足

**症状**：
```
Cannot allocate memory
```

**原因**：`pm.max_children` 设置太大

**解决**：
1. 查看每个进程的内存使用：
```bash
ps aux | grep php-fpm | awk '{sum+=$6} END {print "Total: " sum/1024 " MB"}'
```

2. 计算合理的 `pm.max_children`：
```bash
free -m
# 可用内存 / 每个进程的内存 = max_children
```

3. 降低 `pm.max_children` 的值

---

### 问题3：进程数不增长

**原因**：可能达到了 `pm.max_children` 的限制

**检查**：
```bash
# 查看日志
sudo tail -f /var/log/php8.2-fpm.log
```

如果看到：
```
WARNING: [pool www] server reached pm.max_children setting
```

说明需要增加 `pm.max_children`。

---

## 🎯 SendWalk 推荐配置

基于 SendWalk 的实际情况（导入任务、黑名单200万数据），推荐配置：

```bash
sudo nano /etc/php/8.2/fpm/pool.d/www.conf
```

修改为：
```ini
[www]
user = www-data
group = www-data
listen = /run/php/php8.2-fpm.sock
listen.owner = www-data
listen.group = www-data

; 进程管理
pm = dynamic
pm.max_children = 20           # 最大进程数（增加）
pm.start_servers = 5           # 启动时进程数
pm.min_spare_servers = 3       # 最小空闲进程
pm.max_spare_servers = 8       # 最大空闲进程
pm.max_requests = 500          # 每个进程处理的最大请求数

; 进程超时
pm.process_idle_timeout = 10s  # 空闲进程超时时间
request_terminate_timeout = 300 # 请求超时时间（5分钟，适合长时间导入）

; 日志
slowlog = /var/log/php-fpm-slow.log
request_slowlog_timeout = 5s
```

保存后重启：
```bash
sudo systemctl restart php8.2-fpm
```

---

## 📈 效果对比

### 优化前
```
PHP-FPM进程数: 5
最大并发请求: 5
导入时请求排队: 是
前端pending时间: 10-30秒
```

### 优化后
```
PHP-FPM进程数: 8-20（动态）
最大并发请求: 20
导入时请求排队: 否
前端pending时间: <1秒
```

---

## 🛠️ 一键优化脚本

创建快速优化脚本：

```bash
#!/bin/bash
# optimize-php-fpm.sh

echo "优化 PHP-FPM 配置..."

# 查找配置文件
CONF_FILE=$(find /etc/php -name "www.conf" | head -1)

if [ -z "$CONF_FILE" ]; then
    echo "错误: 找不到 PHP-FPM 配置文件"
    exit 1
fi

echo "找到配置文件: $CONF_FILE"

# 备份
sudo cp $CONF_FILE ${CONF_FILE}.backup-$(date +%Y%m%d-%H%M%S)
echo "已备份到: ${CONF_FILE}.backup-$(date +%Y%m%d-%H%M%S)"

# 修改配置
sudo sed -i 's/^pm.max_children = .*/pm.max_children = 20/' $CONF_FILE
sudo sed -i 's/^pm.start_servers = .*/pm.start_servers = 5/' $CONF_FILE
sudo sed -i 's/^pm.min_spare_servers = .*/pm.min_spare_servers = 3/' $CONF_FILE
sudo sed -i 's/^pm.max_spare_servers = .*/pm.max_spare_servers = 8/' $CONF_FILE

echo "配置已更新"

# 重启
PHP_VERSION=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")
sudo systemctl restart php${PHP_VERSION}-fpm

echo "PHP-FPM 已重启"

# 验证
sleep 2
PROCESS_COUNT=$(ps aux | grep php-fpm | grep -v grep | wc -l)
echo "当前 PHP-FPM 进程数: $PROCESS_COUNT"

echo "优化完成！"
```

---

## 💡 最佳实践

1. **监控内存使用**
   - 定期检查内存使用情况
   - 避免 `pm.max_children` 设置过大

2. **逐步调整**
   - 不要一次性改动太大
   - 每次调整后观察效果

3. **日志监控**
   - 定期查看 PHP-FPM 日志
   - 关注警告和错误信息

4. **压力测试**
   - 在生产环境应用前先测试
   - 使用工具如 Apache Bench (ab) 测试

5. **根据实际情况调整**
   - 观察实际并发请求数
   - 根据服务器负载调整

---

**创建日期**: 2025-12-26  
**版本**: v1.0  
**适用于**: Ubuntu/Debian + PHP-FPM 8.x

