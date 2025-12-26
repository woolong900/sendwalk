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
        
        Log::info('Distributing campaign tasks to dedicated queue', [
            'campaign_id' => $campaign->id,
            'campaign_name' => $campaign->name,
            'queue' => $queueName,
            'subscribers' => $subscribers->count(),
            'start_sort' => $startSort,
            'max_sort_value' => $maxSortValue ?? 'N/A',
        ]);
        
        // 5. 批量创建任务（直接插入 jobs 表）
        $currentSort = $startSort;
        $jobsData = [];
        $now = time();
        
        foreach ($subscribers as $subscriberData) {
            // 支持两种格式：直接的订阅者对象 或 包含订阅者和列表ID的数组
            if (is_array($subscriberData)) {
                $subscriber = $subscriberData['subscriber'];
                $listId = $subscriberData['list_id'] ?? null;
            } else {
                $subscriber = $subscriberData;
                $listId = null;
            }
            
            // 创建 Job 实例以获取 payload
            $job = new SendCampaignEmail($campaign, $subscriber, $listId);
            
            // 准备 job 数据
            $jobsData[] = [
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
        }
        
        // 6. 批量插入（每100条一批，提升性能）
        $chunks = array_chunk($jobsData, 100);
        foreach ($chunks as $chunk) {
            DB::table('jobs')->insert($chunk);
        }
        
        $taskCount = count($jobsData);
        
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

