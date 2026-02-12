<?php

namespace App\Jobs;

use App\Models\MailingList;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncAutoListSubscribers implements ShouldQueue
{
    use Queueable;

    public $timeout = 3600; // 1 小时超时
    public $tries = 3; // 最多重试 3 次

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $listId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $startTime = microtime(true);
        
        Log::info('开始同步自动列表订阅者', [
            'list_id' => $this->listId,
        ]);

        try {
            $list = MailingList::find($this->listId);
            
            if (!$list) {
                Log::warning('自动列表不存在，跳过同步', ['list_id' => $this->listId]);
                return;
            }

            if (!$list->isAutoList()) {
                Log::warning('列表不是自动列表，跳过同步', ['list_id' => $this->listId]);
                return;
            }

            // 获取符合条件的订阅者查询
            $query = $list->getAutoSubscribersQuery();
            
            if (!$query) {
                Log::warning('自动列表条件无效，跳过同步', [
                    'list_id' => $this->listId,
                    'conditions' => $list->conditions,
                ]);
                // 清空列表
                DB::table('list_subscriber')->where('list_id', $this->listId)->delete();
                $list->update(['subscribers_count' => 0]);
                return;
            }

            // 第一步：清空列表（直接 SQL 删除，高效）
            $deletedCount = DB::table('list_subscriber')
                ->where('list_id', $this->listId)
                ->delete();
            
            Log::info('已清空自动列表', [
                'list_id' => $this->listId,
                'deleted_count' => $deletedCount,
            ]);

            // 第二步：使用游标分批插入，避免内存溢出
            $insertedCount = 0;
            $batchSize = 2000;
            $now = now();
            $batch = [];

            // 使用 cursor 逐条获取，避免一次性加载所有数据到内存
            foreach ($query->cursor() as $subscriber) {
                $batch[] = [
                    'list_id' => $this->listId,
                    'subscriber_id' => $subscriber->id,
                    'status' => 'active',
                    'subscribed_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (count($batch) >= $batchSize) {
                    DB::table('list_subscriber')->insert($batch);
                    $insertedCount += count($batch);
                    
                    // 每 10000 条记录日志
                    if ($insertedCount % 10000 === 0) {
                        Log::info('自动列表同步进度', [
                            'list_id' => $this->listId,
                            'inserted' => $insertedCount,
                        ]);
                    }
                    
                    $batch = [];
                }
            }

            // 插入剩余数据
            if (!empty($batch)) {
                DB::table('list_subscriber')->insert($batch);
                $insertedCount += count($batch);
            }

            // 更新 subscribers_count 字段
            $list->update(['subscribers_count' => $insertedCount]);

            $duration = round((microtime(true) - $startTime), 2);
            
            Log::info('自动列表同步完成', [
                'list_id' => $this->listId,
                'total_subscribers' => $insertedCount,
                'duration_seconds' => $duration,
            ]);

        } catch (\Exception $e) {
            Log::error('自动列表同步失败', [
                'list_id' => $this->listId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
