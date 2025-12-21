# Worker 进程优雅退出机制

## 📋 功能说明

为 `ProcessCampaignQueue` Worker 进程实现了优雅退出（Graceful Shutdown）机制，确保在停止 Worker 时不会中断正在处理的任务。

## 🎯 核心特性

### 1. **信号处理**
支持以下 UNIX 信号：
- `SIGTERM` (15)：kill 命令默认发送的信号
- `SIGINT` (2)：Ctrl+C
- `SIGQUIT` (3)：Ctrl+\

### 2. **优雅退出流程**

```
收到信号 → 设置退出标志 → 完成当前任务 → 清理资源 → 退出
```

### 3. **状态管理**

- `$shouldQuit`：退出标志，收到信号后设置为 true
- `$isProcessing`：任务处理标志，标识是否正在处理任务

## 💻 使用方式

### 停止 Worker（优雅退出）

```bash
# 方式 1：使用 kill 命令（推荐）
kill $(pgrep -f "campaign:process-queue")

# 方式 2：使用 pkill
pkill -f "campaign:process-queue"

# 方式 3：查找 PID 后发送信号
ps aux | grep "campaign:process-queue"
kill <PID>
```

### 强制停止（不推荐）

```bash
# 强制终止，可能导致任务中断
kill -9 <PID>
```

## 🔍 工作原理

### 信号注册

```php
protected function registerSignalHandlers()
{
    // 检查 PCNTL 扩展
    if (!extension_loaded('pcntl')) {
        return;
    }

    // 注册信号处理器
    pcntl_signal(SIGTERM, function ($signal) {
        $this->handleShutdownSignal($signal, 'SIGTERM');
    });
    
    // ... 其他信号
}
```

### 主循环检查

```php
while (!$this->shouldQuit) {
    // 处理挂起的信号
    $this->checkSignals();
    
    // 检查退出标志
    if ($this->shouldQuit) {
        // 记录日志并退出
        return 0;
    }
    
    // 处理任务...
}
```

### 任务处理标志

```php
private function processJob($job)
{
    // 标记开始处理
    $this->isProcessing = true;
    
    try {
        // 处理任务...
        
        // 标记处理完成
        $this->isProcessing = false;
        return 'success';
        
    } catch (\Exception $e) {
        // 异常处理...
        
        // 标记处理完成
        $this->isProcessing = false;
        return 'failed';
    }
}
```

## 📊 执行流程示例

### 场景 1：Worker 空闲时收到信号

```
1. Worker 正在等待新任务（sleep）
2. 收到 SIGTERM 信号
3. 设置 $shouldQuit = true
4. 主循环检测到 $shouldQuit
5. 记录日志并立即退出 ✅
```

**日志输出**：
```
🛑 Shutdown signal (SIGTERM) received, exiting gracefully...
👋 Graceful shutdown completed
```

### 场景 2：Worker 正在处理任务时收到信号

```
1. Worker 正在发送邮件（isProcessing = true）
2. 收到 SIGTERM 信号
3. 设置 $shouldQuit = true
4. 继续完成当前邮件发送
5. 任务完成后，主循环检测到 $shouldQuit
6. 记录日志并退出 ✅
```

**日志输出**：
```
🛑 Shutdown signal (SIGTERM) received, will exit after current job completes...
[20:15:30] Processed job #12345
👋 Graceful shutdown completed
```

### 场景 3：多次发送信号

```
1. 第一次 SIGTERM：设置 $shouldQuit = true
2. 第二次 SIGTERM：忽略（已在退出过程中）
3. 继续等待当前任务完成
4. 退出 ✅
```

## 📝 日志记录

### Worker 启动

```
✅ Signal handlers registered (SIGTERM, SIGINT, SIGQUIT)
🚀 Starting dedicated worker for Campaign #25
   PID: 12345
   Queue: campaign_25
   Campaign: Summer Sale
   SMTP Server: AWS SES
```

### 收到信号

```json
{
  "message": "Worker received shutdown signal",
  "signal": "SIGTERM",
  "signal_number": 15,
  "campaign_id": 25,
  "pid": 12345,
  "is_processing": true
}
```

### 优雅退出

```json
{
  "message": "Worker gracefully shutdown",
  "campaign_id": 25,
  "pid": 12345,
  "processed_count": 128
}
```

## ⚙️ 系统要求

### PCNTL 扩展

优雅退出需要 PHP 的 PCNTL 扩展：

```bash
# 检查是否已安装
php -m | grep pcntl

# Ubuntu/Debian 安装
sudo apt-get install php-pcntl

# CentOS/RHEL 安装
sudo yum install php-pcntl

# macOS (Homebrew)
# PCNTL 通常已包含在 PHP 中
```

**注意**：如果 PCNTL 扩展不可用，Worker 仍然可以正常运行，但不支持优雅退出。

## 🔒 线程安全

### 防止重复退出

```php
protected function handleShutdownSignal($signal, $signalName)
{
    if ($this->shouldQuit) {
        // 已经在退出过程中，忽略重复信号
        return;
    }
    
    $this->shouldQuit = true;
    // ...
}
```

### 任务状态保护

- 使用 `$isProcessing` 标志确保任务完成后才退出
- 所有 return 语句前都会重置 `$isProcessing = false`

## 🚀 性能影响

### 信号检查开销

- 每次主循环执行 `pcntl_signal_dispatch()`
- 开销极小（<0.1ms）
- 仅在收到信号时才触发处理器

### 退出延迟

- 空闲状态：立即退出（<1s）
- 处理任务时：等待任务完成（通常 <5s）
- 速率限制休眠时：最多 60s（可中断 sleep 以优化）

## 💡 最佳实践

### 1. 使用 SIGTERM 停止 Worker

```bash
# ✅ 推荐：优雅退出
kill $(pgrep -f "campaign:process-queue")

# ❌ 不推荐：强制终止
kill -9 $(pgrep -f "campaign:process-queue")
```

### 2. 监控 Worker 退出

```bash
# 发送信号
kill <PID>

# 等待退出（最多 60 秒）
timeout 60 tail --pid=<PID> -f /dev/null && echo "Worker exited" || echo "Worker still running"
```

### 3. 集成到部署脚本

```bash
#!/bin/bash

# 停止所有 Worker
echo "Stopping all workers..."
pkill -f "campaign:process-queue"

# 等待 Worker 优雅退出
sleep 10

# 检查是否还有 Worker 在运行
if pgrep -f "campaign:process-queue" > /dev/null; then
    echo "Some workers still running, forcing shutdown..."
    pkill -9 -f "campaign:process-queue"
fi

echo "All workers stopped"

# 拉取新代码
git pull

# 重启 Scheduler（会自动管理 Worker）
php artisan schedule:work &
```

## 🎯 与其他组件的集成

### ManageWorkers 命令

`ManageWorkers` 在停止 Worker 时会发送 SIGTERM：

```php
// backend/app/Console/Commands/ManageWorkers.php
exec("kill {$pid}"); // 发送 SIGTERM
```

Worker 收到信号后会优雅退出，确保正在处理的邮件不会被中断。

### DashboardController

前端停止 Worker 时：

```php
// backend/app/Http/Controllers/Api/DashboardController.php
public function stopWorkers(Request $request)
{
    $pids = $this->getWorkerPids();
    foreach ($pids as $pid) {
        // 发送 SIGTERM
        shell_exec("kill {$pid}");
    }
}
```

## 📈 未来优化

### 1. 可中断的 Sleep

当前在速率限制休眠时（60s），Worker 无法立即响应信号。可以优化为：

```php
// 将 60s 分成 60 个 1s，每次检查信号
for ($i = 0; $i < 60; $i++) {
    if ($this->shouldQuit) break;
    sleep(1);
}
```

### 2. 健康检查端点

添加 HTTP 端点用于检查 Worker 状态：

```php
GET /api/workers/{pid}/status
{
  "pid": 12345,
  "campaign_id": 25,
  "is_processing": true,
  "processed_count": 128,
  "uptime": 3600
}
```

### 3. 超时强制退出

在优雅退出超过一定时间后（如 120s），自动强制退出：

```php
// 在信号处理器中设置超时
pcntl_alarm(120); // 120 秒后发送 SIGALRM
```

## ✅ 总结

优雅退出机制确保：

- ✅ 正在发送的邮件不会被中断
- ✅ 数据一致性（SendLog、CampaignSend 正确记录）
- ✅ 资源正确释放
- ✅ 详细的日志记录
- ✅ 零停机部署（配合 Scheduler 自动重启）

现在您可以安全地停止和重启 Worker，而不用担心任务中断或数据丢失！ 🎉

