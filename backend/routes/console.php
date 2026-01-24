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

// 检测 DOMAIN 标签中的域名状态（每10分钟）
// --auto-remove: 自动移除异常域名
// --notify: 发送通知（记录到日志）
Schedule::command('domains:check --auto-remove --notify')
    ->everyTenMinutes()
    ->runInBackground()
    ->withoutOverlapping();

