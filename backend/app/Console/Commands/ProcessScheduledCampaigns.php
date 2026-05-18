<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Models\DomainBlacklist;
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
            ->with(['lists', 'smtpServer', 'user'])
            ->get();

        if ($campaigns->isEmpty()) {
            $this->info('没有需要处理的定时活动');
            return 0;
        }

        $this->info("找到 {$campaigns->count()} 个待发送的定时活动");

        foreach ($campaigns as $campaign) {
            $this->info("处理活动: {$campaign->name}");

            $campaignUser = $campaign->user;
            if (!$campaignUser || !$campaignUser->isActive()) {
                $campaign->update(['status' => 'failed']);
                $this->warn("  ⚠️  用户不可用或已封禁，活动已标记为失败");
                continue;
            }

            if (!$campaignUser->hasSendQuota()) {
                $campaign->update(['status' => 'failed']);
                $this->warn("  ⚠️  用户发送额度不足，活动已标记为失败");
                continue;
            }

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
            
            try {
                // 分批处理：每次处理一个列表的 5000 个订阅者
                $batchSize = 5000;
                $totalTasksCreated = 0;
                $totalRecipients = 0;
                $distributionService = new QueueDistributionService();
                $remainingQuota = $campaignUser->remaining_quota;
                $stopForQuota = false;

                // 获取该用户的域名黑名单（一次性加载，避免重复查询）
                $blacklistedDomains = DomainBlacklist::getBlacklistedDomains($campaign->user_id);
                if (!empty($blacklistedDomains)) {
                    $this->info("  🚫 已屏蔽域名: " . implode(', ', $blacklistedDomains));
                }

                foreach ($listIds as $listIndex => $listId) {
                    if ($stopForQuota) {
                        break;
                    }

                    $this->info("  📝 处理列表 #{$listId} (" . ($listIndex + 1) . "/" . count($listIds) . ")");
                    
                    $listTasksCreated = 0;
                    $lastId = 0; // 使用游标分页，避免 offset 导致的数据混乱
                    $batchNumber = 0;
                    
                    while (true) {
                        // 使用游标分页查询活跃订阅者（基于 ID）
                        // 优势：即使处理过程中有数据变化，也不会漏掉或重复处理记录
                        $query = Subscriber::select(['id', 'email', 'first_name', 'last_name', 'custom_fields'])
                            ->whereHas('lists', function ($query) use ($listId) {
                                $query->where('lists.id', $listId)
                                    ->where('list_subscriber.status', 'active');
                            })
                            ->where('subscribers.id', '>', $lastId);

                        // 排除域名黑名单中的邮箱（域名黑名单作为发送过滤器，不修改订阅者数据）
                        if (!empty($blacklistedDomains)) {
                            $query->where(function ($q) use ($blacklistedDomains) {
                                foreach ($blacklistedDomains as $domain) {
                                    $q->where('subscribers.email', 'not like', "%@{$domain}");
                                }
                            });
                        }

                        $listSubscribers = $query
                            ->orderBy('subscribers.id', 'asc')
                            ->take($batchSize)
                            ->get();

                        if ($listSubscribers->isEmpty()) {
                            break; // 该列表处理完毕
                        }

                        if ($remainingQuota !== null) {
                            if ($remainingQuota <= 0) {
                                $stopForQuota = true;
                                break;
                            }

                            if ($listSubscribers->count() > $remainingQuota) {
                                $listSubscribers = $listSubscribers->take($remainingQuota);
                                $stopForQuota = true;
                            }
                        }
                        
                        // 更新游标位置
                        $lastId = $listSubscribers->last()->id;
                        $batchNumber++;
                        
                        // 构建待发送的订阅者列表
                        $subscribersWithList = [];
                        foreach ($listSubscribers as $subscriber) {
                            $subscribersWithList[] = [
                                'subscriber' => $subscriber,
                                'list_id' => $listId,
                            ];
                        }
                        
                        // 创建发送任务
                        $result = $distributionService->distributeEvenly($campaign, $subscribersWithList);
                        $listTasksCreated += count($subscribersWithList);
                        $totalTasksCreated += count($subscribersWithList);
                        if ($remainingQuota !== null) {
                            $remainingQuota -= count($subscribersWithList);
                        }
                        
                        $this->info("     ✓ 批次 {$batchNumber}: 创建 " . count($subscribersWithList) . " 个任务 (游标: ID > {$lastId})");
                        
                        // 清理内存
                        unset($subscribersWithList, $listSubscribers);
                        gc_collect_cycles();
                    }
                    
                    $this->info("     ✅ 列表 #{$listId} 完成: 共创建 {$listTasksCreated} 个任务");
                }
                
                if ($totalTasksCreated === 0) {
                    $this->warn("  ⚠️  活动 {$campaign->name} 没有待发送的订阅者，跳过");
                    continue;
                }
                
                // 🔥 关键修复：直接使用创建的任务数作为总收件人数
                // 不要查询 campaign_sends，因为在创建过程中部分任务可能已经执行了
                // 这会导致 total_recipients 被错误设置为一个很小的数字
                $campaign->update([
                    'total_recipients' => $totalTasksCreated,
                ]);

                $this->info("  🎉 活动 {$campaign->name} 任务创建完成");
                $this->info("     总任务数: {$totalTasksCreated}");
                $this->info("     总收件人: {$totalTasksCreated}");
                $this->info("     队列: campaign_{$campaign->id}");
            } catch (\Exception $e) {
                $this->error("  ❌ 创建任务失败: {$e->getMessage()}");
                \Log::error('Failed to create campaign tasks', [
                    'campaign_id' => $campaign->id,
                    'campaign_name' => $campaign->name,
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

