<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanOldQueueJobs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'queue:clean {--days=7 : 清理多少天前的任务}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '清理已完成的旧队列任务';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $days = $this->option('days');
        $this->info("🧹 开始清理 {$days} 天前的旧任务...");
        
        try {
            $cutoffTime = time() - ($days * 86400);
            
            // MySQL 数据库队列会自动删除已处理的任务
            // 这里主要清理一些可能遗留的旧记录
            
            // 统计要删除的任务数（超时未处理的任务）
            $count = DB::table('jobs')
                ->where('created_at', '<', $cutoffTime)
                ->where(function($query) {
                    $query->whereNull('reserved_at')
                          ->orWhere('reserved_at', '<', time() - 86400); // 24小时前领取但未完成
                })
                ->count();
            
            if ($count == 0) {
                $this->info('✅ 没有需要清理的任务');
                
                // 清理失败任务
                $failedCount = DB::table('failed_jobs')
                    ->where('failed_at', '<', now()->subDays($days))
                    ->count();
                
                if ($failedCount > 0) {
                    $this->info("📊 找到 {$failedCount} 个失败任务");
                    if ($this->confirm("确定要删除这 {$failedCount} 个失败任务吗?", true)) {
                        $deleted = DB::table('failed_jobs')
                            ->where('failed_at', '<', now()->subDays($days))
                            ->delete();
                        $this->info("✅ 清理了 {$deleted} 个失败任务");
                    }
                }
                
                return 0;
            }
            
            $this->warn("📊 找到 {$count} 个异常旧任务（可能是僵尸任务）");
            
            // 确认删除
            if ($this->confirm("确定要删除这 {$count} 个任务吗?", true)) {
                // 删除旧任务
                $deleted = DB::table('jobs')
                    ->where('created_at', '<', $cutoffTime)
                    ->where(function($query) {
                        $query->whereNull('reserved_at')
                              ->orWhere('reserved_at', '<', time() - 86400);
                    })
                    ->delete();
                
                $this->info("✅ 成功清理了 {$deleted} 个旧任务");
                
                // 优化表
                DB::statement('OPTIMIZE TABLE jobs');
                DB::statement('OPTIMIZE TABLE failed_jobs');
                $this->info("✅ 表优化完成");
            } else {
                $this->info('❌ 取消清理操作');
            }
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error("❌ 清理失败: {$e->getMessage()}");
            return 1;
        }
    }
}

