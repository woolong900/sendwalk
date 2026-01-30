<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FixStuckCampaigns extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'campaigns:fix-stuck 
                            {--timeout=300 : 任务卡住超过多少秒后释放（默认5分钟）}
                            {--force : 保留选项，向后兼容（现在队列为空即自动完成）}';

    /**
     * The console command description.
     */
    protected $description = '检查并修复卡住的活动（释放超时任务，标记队列为空的活动为完成）';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $timeout = (int) $this->option('timeout');
        
        $this->info("检查卡住的活动... (超时阈值: {$timeout}秒)");
        
        // 查找所有 sending 状态的活动
        $campaigns = Campaign::where('status', 'sending')->get();
        
        if ($campaigns->isEmpty()) {
            $this->info('没有 sending 状态的活动');
            return 0;
        }
        
        $fixedCount = 0;
        $releasedCount = 0;
        
        foreach ($campaigns as $campaign) {
            $queueName = "campaign_{$campaign->id}";
            
            // 1. 释放卡住的任务（reserved 超时）
            $stuckJobs = DB::table('jobs')
                ->where('queue', $queueName)
                ->whereNotNull('reserved_at')
                ->where('reserved_at', '<', time() - $timeout)
                ->count();
            
            if ($stuckJobs > 0) {
                $released = DB::table('jobs')
                    ->where('queue', $queueName)
                    ->whereNotNull('reserved_at')
                    ->where('reserved_at', '<', time() - $timeout)
                    ->update([
                        'reserved_at' => null,
                        'attempts' => DB::raw('attempts + 1'),
                    ]);
                
                $releasedCount += $released;
                
                $this->warn("  活动 #{$campaign->id} ({$campaign->name}): 释放了 {$released} 个卡住的任务");
                Log::info("Released stuck jobs for campaign", [
                    'campaign_id' => $campaign->id,
                    'stuck_jobs' => $released,
                ]);
            }
            
            // 2. 检查队列是否为空
            $remainingJobs = DB::table('jobs')
                ->where('queue', $queueName)
                ->count();
            
            if ($remainingJobs > 0) {
                $this->line("  活动 #{$campaign->id} ({$campaign->name}): 队列中还有 {$remainingJobs} 个任务");
                continue;
            }
            
            // 3. 队列为空 = 活动完成
            $campaign->update([
                'status' => 'sent',
                'sent_at' => now(),
            ]);
            
            $fixedCount++;
            
            $this->info("  ✅ 活动 #{$campaign->id} ({$campaign->name}): 队列为空，已标记为完成");
            Log::info("Fixed stuck campaign (queue empty)", [
                'campaign_id' => $campaign->id,
                'campaign_name' => $campaign->name,
                'total_recipients' => $campaign->total_recipients,
                'total_sent' => $campaign->total_sent,
            ]);
        }
        
        $this->newLine();
        $this->info("📊 处理结果:");
        $this->line("   释放卡住的任务: {$releasedCount}");
        $this->line("   修复的活动: {$fixedCount}");
        
        return 0;
    }
}

