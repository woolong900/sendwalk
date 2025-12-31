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
    protected $signature = 'campaigns:fix-stuck';

    /**
     * The console command description.
     */
    protected $description = '检查并修复卡住的活动（队列为空但状态仍是sending）';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('检查卡住的活动...');
        
        // 查找所有 sending 状态的活动
        $campaigns = Campaign::where('status', 'sending')->get();
        
        if ($campaigns->isEmpty()) {
            $this->info('没有 sending 状态的活动');
            return 0;
        }
        
        $fixedCount = 0;
        
        foreach ($campaigns as $campaign) {
            $queueName = "campaign_{$campaign->id}";
            
            // 检查队列是否为空
            $remainingJobs = DB::table('jobs')
                ->where('queue', $queueName)
                ->count();
            
            if ($remainingJobs > 0) {
                // 检查是否有卡住超过1小时的任务
                $stuckJobs = DB::table('jobs')
                    ->where('queue', $queueName)
                    ->whereNotNull('reserved_at')
                    ->where('reserved_at', '<', time() - 3600)
                    ->count();
                
                if ($stuckJobs > 0) {
                    // 释放卡住的任务
                    DB::table('jobs')
                        ->where('queue', $queueName)
                        ->whereNotNull('reserved_at')
                        ->where('reserved_at', '<', time() - 3600)
                        ->update(['reserved_at' => null]);
                    
                    $this->warn("活动 #{$campaign->id}: 释放了 {$stuckJobs} 个卡住的任务");
                    Log::info("Released stuck jobs for campaign", [
                        'campaign_id' => $campaign->id,
                        'stuck_jobs' => $stuckJobs,
                    ]);
                }
                
                continue; // 队列不为空，跳过
            }
            
            // 队列为空，检查 campaign_sends 状态
            $totalProcessed = DB::table('campaign_sends')
                ->where('campaign_id', $campaign->id)
                ->whereIn('status', ['sent', 'failed'])
                ->count();
            
            $pendingCount = DB::table('campaign_sends')
                ->where('campaign_id', $campaign->id)
                ->where('status', 'pending')
                ->count();
            
            // 如果队列为空且没有 pending 记录，或已处理数达到预期
            if ($pendingCount === 0 || $totalProcessed >= $campaign->total_recipients) {
                $sentCount = DB::table('campaign_sends')
                    ->where('campaign_id', $campaign->id)
                    ->where('status', 'sent')
                    ->count();
                
                $campaign->update([
                    'status' => 'sent',
                    'sent_at' => now(),
                    'total_sent' => $totalProcessed,
                    'total_delivered' => $sentCount,
                ]);
                
                $fixedCount++;
                
                $this->info("✅ 活动 #{$campaign->id} ({$campaign->name}): 状态已更新为 sent");
                Log::info("Fixed stuck campaign", [
                    'campaign_id' => $campaign->id,
                    'campaign_name' => $campaign->name,
                    'total_processed' => $totalProcessed,
                    'total_delivered' => $sentCount,
                ]);
            } elseif ($pendingCount > 0) {
                $this->warn("⚠️ 活动 #{$campaign->id}: 队列为空但有 {$pendingCount} 条 pending 记录，需要手动处理");
                Log::warning("Campaign has pending records but empty queue", [
                    'campaign_id' => $campaign->id,
                    'pending_count' => $pendingCount,
                ]);
            }
        }
        
        if ($fixedCount > 0) {
            $this->info("修复了 {$fixedCount} 个活动");
        } else {
            $this->info('没有需要修复的活动');
        }
        
        return 0;
    }
}

