<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// 定时任务
Schedule::command('campaigns:process-scheduled')->everyMinute();
Schedule::command('automations:process')->everyMinute();

// 修复卡住的活动（每5分钟，超时阈值5分钟）
Schedule::command('campaigns:fix-stuck --timeout=300')
    ->everyFiveMinutes()
    ->runInBackground()
    ->withoutOverlapping();

// 清理已完成的旧任务（每天凌晨2点）
Schedule::command('queue:clean')
    ->dailyAt('02:00')
    ->runInBackground();

// 清理旧日志文件（每天凌晨3点，保留30天）
Schedule::command('logs:cleanup --days=30')
    ->dailyAt('03:00')
    ->runInBackground();

// 清理旧发送日志（每天凌晨4点，保留30天）
Schedule::command('sendlogs:cleanup --days=30')
    ->dailyAt('04:00')
    ->runInBackground();

// Laravel 数据库队列会自动处理失败重试，不需要 queue:work

// 检测域名状态（每10分钟）
// 检测内容：
//   1. Tag (DOMAIN) 中的网站域名 - HTTP/HTTPS 可达性
//   2. SMTP 服务器发件人域名 - DNS 记录（MX/SPF/DMARC）
// 选项：
//   --auto-remove: 自动从 Tag 中移除异常域名
//   --notify: 发送通知（记录到日志）
Schedule::command('domains:check --auto-remove --notify')
    ->everyTenMinutes()
    ->runInBackground()
    ->withoutOverlapping();

// 同步所有自动列表的订阅者（每天中午12点）
Schedule::command('lists:sync-auto')
    ->dailyAt('12:00')
    ->runInBackground()
    ->withoutOverlapping();

// 同步订单数据（每天下午4点，同步最近2天的订单）
Schedule::command('orders:sync --days=2')
    ->dailyAt('16:00')
    ->runInBackground()
    ->withoutOverlapping();
