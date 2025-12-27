<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Models\Subscriber;
use App\Services\QueueDistributionService;
use Illuminate\Console\Command;

class ProcessScheduledCampaigns extends Command
{
    protected $signature = 'campaigns:process-scheduled';
    protected $description = 'Process scheduled campaigns that are ready to send';

    public function handle()
    {
        // 查找到时间的定时活动
        $campaigns = Campaign::where('status', 'scheduled')
            ->where('scheduled_at', '<=', now())
            ->with(['lists', 'smtpServer'])
            ->get();

        if ($campaigns->isEmpty()) {
            $this->info('没有需要处理的定时活动');
            return 0;
        }

        $this->info("找到 {$campaigns->count()} 个待发送的定时活动");

        foreach ($campaigns as $campaign) {
            $this->info("处理活动: {$campaign->name}");

            // ✅ 使用原子性更新防止并发：只有成功将 scheduled 改为 sending 的进程才能继续
            $affected = \DB::table('campaigns')
                ->where('id', $campaign->id)
                ->where('status', 'scheduled')  // 关键：只更新状态仍为 scheduled 的
                ->update([
                    'status' => 'sending',
                    'updated_at' => now(),
                ]);

            if ($affected === 0) {
                // 状态已被其他进程更新，跳过
                $this->warn("  ⚠️  活动 {$campaign->name} 已被其他进程处理，跳过");
                continue;
            }

            // 重新加载活动（获取最新状态）
            $campaign->refresh();

            // 获取所有列表的订阅者（兼容单列表和多列表）
            $listIds = [];
            
            // 优先使用多列表关系（新版）
            if ($campaign->lists()->exists()) {
                $listIds = $campaign->lists->pluck('id')->toArray();
            }
            // 回退到单列表字段（旧版）
            elseif ($campaign->list_id) {
                $listIds = [$campaign->list_id];
            }
            
            if (empty($listIds)) {
                $this->warn("  ⚠️  活动 {$campaign->name} 没有关联的邮件列表，跳过");
                continue;
            }
            
            $this->info("  📋 活动关联的列表: " . implode(', ', $listIds));
            
            // 获取所有列表中的活跃订阅者（去重）
            // 为每个列表获取订阅者，保留列表关系信息
            $subscribersWithList = [];
            $uniqueSubscriberIds = [];
            
            foreach ($listIds as $listId) {
                // 只查询必要的字段，减少内存占用和查询时间
                $listSubscribers = Subscriber::select(['id', 'email', 'first_name', 'last_name', 'custom_fields'])
                    ->whereHas('lists', function ($query) use ($listId) {
                        $query->where('lists.id', $listId)
                              ->where('list_subscriber.status', 'active');
                    })->get();
                
                foreach ($listSubscribers as $subscriber) {
                    // 使用订阅者ID去重，确保每个订阅者只发送一次
                    if (!in_array($subscriber->id, $uniqueSubscriberIds)) {
                        $subscribersWithList[] = [
                            'subscriber' => $subscriber,
                            'list_id' => $listId,
                        ];
                        $uniqueSubscriberIds[] = $subscriber->id;
                    }
                }
            }

            if (empty($subscribersWithList)) {
                $this->warn("  ⚠️  活动 {$campaign->name} 没有订阅者，跳过");
                continue;
            }

            // 更新总收件人数
            $campaign->update([
                'total_recipients' => count($subscribersWithList),
            ]);

            // ✅ 现在才创建 jobs！使用智能分配服务
            try {
                $distributionService = new QueueDistributionService();
                $result = $distributionService->distributeEvenly($campaign, $subscribersWithList);

                $this->info("  ✅ 已创建 {$result['tasks']} 个发送任务");
                $this->info("     队列: {$result['queue']}");
                $this->info("     分配策略: {$result['distribution']}");
            } catch (\Exception $e) {
                $this->error("  ❌ 创建任务失败: {$e->getMessage()}");
                \Log::error('Failed to create campaign tasks', [
                    'campaign_id' => $campaign->id,
                    'campaign_name' => $campaign->name,
                    'subscriber_count' => count($subscribersWithList),
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                
                // 将活动状态改回 scheduled，以便下次重试
                $campaign->update(['status' => 'scheduled']);
                $this->warn("  ⚠️  活动状态已重置为 scheduled，将在下次调度时重试");
                continue;
            }
        }

        $this->info("\n✅ 所有定时活动处理完成");
        return 0;
    }
}

