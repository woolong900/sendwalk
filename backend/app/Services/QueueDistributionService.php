<?php

namespace App\Services;

use App\Models\Campaign;
use App\Jobs\SendCampaignEmail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class QueueDistributionService
{
    /**
     * 智能分配任务到队列，确保多个活动齐头并进
     * 
     * 策略：使用 sort_order 字段实现交替执行
     * 
     * 核心思想：
     * - sort_order 是整数排序值，控制任务执行顺序
     * - Worker 按 sort_order 排序处理，实现齐头并进
     * - 简单、直接、高效
     */
    public function distributeEvenly(Campaign $campaign, $subscribers)
    {
        // 每个活动使用独立队列
        $queueName = "campaign_{$campaign->id}";
        
        // 1. 查询队列中最大的排序值
        $maxSortValue = DB::table('jobs')
            ->where('queue', $queueName)
            ->whereNull('reserved_at')
            ->max('sort_order');
        
        // 2. 确定起始排序值
        if ($maxSortValue) {
            // 队列不为空，从最大值+1开始
            $startSort = (int)$maxSortValue + 1;
        } else {
            // 队列为空，从1开始
            $startSort = 1;
        }
        
        // 3. 每个活动独立队列，不需要计算间隔
        $interval = 1;
        
        // 计算订阅者数量（兼容 Collection 和 Array）
        $subscriberCount = is_array($subscribers) ? count($subscribers) : $subscribers->count();
        
        Log::info('Distributing campaign tasks to dedicated queue', [
            'campaign_id' => $campaign->id,
            'campaign_name' => $campaign->name,
            'queue' => $queueName,
            'subscribers' => $subscriberCount,
            'start_sort' => $startSort,
            'max_sort_value' => $maxSortValue ?? 'N/A',
        ]);
        
        // 5. 批量创建任务（分批处理，避免内存溢出）
        $currentSort = $startSort;
        $now = time();
        $taskCount = 0;
        $batchSize = 1000; // 每批处理 1000 个（优化：只存储 ID，序列化更快）
        $currentBatch = [];
        $totalInserted = 0;
        
        // 为大批量任务设置更长的执行时间
        if ($subscriberCount > 10000) {
            set_time_limit(600); // 10分钟
            ini_set('memory_limit', '512M');
        }
        
        Log::info('Starting batch job creation (optimized)', [
            'campaign_id' => $campaign->id,
            'total_subscribers' => $subscriberCount,
            'batch_size' => $batchSize,
            'optimization' => 'ID-only serialization',
        ]);
        
        foreach ($subscribers as $index => $subscriberData) {
            // 支持两种格式：直接的订阅者对象 或 包含订阅者和列表ID的数组
            if (is_array($subscriberData)) {
                $subscriber = $subscriberData['subscriber'];
                $listId = $subscriberData['list_id'] ?? null;
            } else {
                $subscriber = $subscriberData;
                $listId = null;
            }
            
            try {
                // 创建 Job 实例以获取 payload（只传递 ID，不传递整个模型）
                $job = new SendCampaignEmail($campaign->id, $subscriber->id, $listId);
                
                // 准备 job 数据
                $currentBatch[] = [
                    'queue' => $queueName,
                    'payload' => json_encode([
                        'uuid' => \Illuminate\Support\Str::uuid()->toString(),
                        'displayName' => 'App\\Jobs\\SendCampaignEmail',
                        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                        'maxTries' => 1,  // 不重试
                        'maxExceptions' => null,
                        'failOnTimeout' => false,
                        'backoff' => null,
                        'timeout' => 120,
                        'retryUntil' => null,
                        'data' => [
                            'commandName' => 'App\\Jobs\\SendCampaignEmail',
                            'command' => serialize($job),
                        ],
                    ]),
                    'attempts' => 0,
                    'reserved_at' => null,
                    'available_at' => $now,  // 立即可用（当前时间）
                    'sort_order' => $currentSort,  // 控制执行顺序（齐头并进）
                    'created_at' => $now,
                ];
                
                $currentSort += $interval;
                $taskCount++;
                
                // 当达到批次大小或是最后一条记录时，执行插入
                if (count($currentBatch) >= $batchSize || $index === $subscriberCount - 1) {
                    try {
                        DB::table('jobs')->insert($currentBatch);
                        $totalInserted += count($currentBatch);
                        
                        // 每插入 10000 条记录打印一次进度（减少日志频率）
                        if ($totalInserted % 10000 === 0 || $index === $subscriberCount - 1) {
                            $elapsed = time() - $now;
                            $speed = $elapsed > 0 ? round($totalInserted / $elapsed, 2) : 0;
                            Log::info("Batch job creation progress", [
                                'campaign_id' => $campaign->id,
                                'inserted' => $totalInserted,
                                'total' => $subscriberCount,
                                'progress' => round($totalInserted / $subscriberCount * 100, 2) . '%',
                                'speed' => $speed . ' tasks/sec',
                                'elapsed' => $elapsed . 's',
                            ]);
                        }
                        
                        $currentBatch = []; // 清空当前批次
                    } catch (\Exception $e) {
                        Log::error('Failed to insert batch of campaign tasks', [
                            'campaign_id' => $campaign->id,
                            'batch_size' => count($currentBatch),
                            'error' => $e->getMessage(),
                        ]);
                        throw $e;
                    }
                }
            } catch (\Exception $e) {
                Log::error('Failed to create job for subscriber', [
                    'campaign_id' => $campaign->id,
                    'subscriber_id' => $subscriber->id,
                    'error' => $e->getMessage(),
                ]);
                // 继续处理下一个订阅者
                continue;
            }
        }
        
        Log::info('Campaign tasks distributed successfully', [
            'campaign_id' => $campaign->id,
            'total_tasks' => $taskCount,
            'queue' => $queueName,
            'start_sort' => $startSort,
            'end_sort' => $currentSort - $interval,
        ]);
        
        return [
            'queue' => $queueName,
            'tasks' => $taskCount,
            'distribution' => 'sort-order-interleaved',
            'start_sort' => $startSort,
            'end_sort' => $currentSort - $interval,
            'interval' => $interval,
        ];
    }
    
    /**
     * 获取活动使用的 SMTP 服务器ID
     */
    private function getSmtpServerId(Campaign $campaign)
    {
        if ($campaign->smtp_server_id) {
            return $campaign->smtp_server_id;
        }
        
        // 使用默认服务器
        $defaultServer = \App\Models\SmtpServer::where('user_id', $campaign->user_id)
            ->where('is_active', true)
            ->where('is_default', true)
            ->first();
        
        return $defaultServer?->id ?? 1;
    }
}

