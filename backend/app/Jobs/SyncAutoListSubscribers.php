<?php

namespace App\Jobs;

use App\Models\MailingList;
use App\Models\Subscriber;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncAutoListSubscribers implements ShouldQueue
{
    use Queueable;

    public $timeout = 1800; // 30 分钟超时
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
                return;
            }

            // 获取所有符合条件的订阅者 ID
            $subscriberIds = $query->pluck('id')->toArray();
            $totalCount = count($subscriberIds);

            Log::info('找到符合条件的订阅者', [
                'list_id' => $this->listId,
                'count' => $totalCount,
            ]);

            if ($totalCount === 0) {
                // 清空列表中的所有订阅者
                $list->subscribers()->detach();
                $list->update(['subscribers_count' => 0]);
                Log::info('自动列表同步完成，无符合条件的订阅者', ['list_id' => $this->listId]);
                return;
            }

            // 使用事务批量同步
            DB::transaction(function () use ($list, $subscriberIds, $totalCount) {
                // 获取当前列表中已有的订阅者 ID
                $existingIds = $list->subscribers()->pluck('subscribers.id')->toArray();
                
                // 计算需要添加和删除的订阅者
                $toAdd = array_diff($subscriberIds, $existingIds);
                $toRemove = array_diff($existingIds, $subscriberIds);

                Log::info('自动列表订阅者变更', [
                    'list_id' => $list->id,
                    'to_add' => count($toAdd),
                    'to_remove' => count($toRemove),
                    'unchanged' => count(array_intersect($subscriberIds, $existingIds)),
                ]);

                // 删除不再符合条件的订阅者
                if (!empty($toRemove)) {
                    $list->subscribers()->detach($toRemove);
                }

                // 批量添加新订阅者（分批处理，每批 1000 条）
                $chunks = array_chunk($toAdd, 1000);
                foreach ($chunks as $chunk) {
                    $attachData = [];
                    $now = now();
                    foreach ($chunk as $subscriberId) {
                        $attachData[$subscriberId] = [
                            'status' => 'active',
                            'subscribed_at' => $now,
                            'source' => 'auto_list',
                        ];
                    }
                    $list->subscribers()->attach($attachData);
                }
            });

            // 更新 subscribers_count 字段
            $finalCount = DB::table('list_subscriber')
                ->where('list_id', $list->id)
                ->where('status', 'active')
                ->count();
            
            $list->update(['subscribers_count' => $finalCount]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            Log::info('自动列表同步完成', [
                'list_id' => $this->listId,
                'total_subscribers' => $finalCount,
                'duration_ms' => $duration,
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
