<?php

namespace App\Jobs;

use App\Models\Blacklist;
use App\Models\Subscriber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ImportBlacklistJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Job 超时时间（秒）
     */
    public $timeout = 3600; // 1小时

    /**
     * 最大尝试次数
     */
    public $tries = 3;

    /**
     * 用户ID
     */
    protected int $userId;

    /**
     * 邮箱数组（分批传入）
     */
    protected array $emails;

    /**
     * 黑名单原因
     */
    protected ?string $reason;

    /**
     * 任务ID（用于跟踪进度）
     */
    protected string $taskId;

    /**
     * 批次号
     */
    protected int $batchNumber;

    /**
     * 总批次数
     */
    protected int $totalBatches;

    /**
     * Create a new job instance.
     */
    public function __construct(
        int $userId,
        array $emails,
        ?string $reason,
        string $taskId,
        int $batchNumber,
        int $totalBatches
    ) {
        $this->userId = $userId;
        $this->emails = $emails;
        $this->reason = $reason;
        $this->taskId = $taskId;
        $this->batchNumber = $batchNumber;
        $this->totalBatches = $totalBatches;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info("黑名单导入任务开始", [
            'task_id' => $this->taskId,
            'batch' => "{$this->batchNumber}/{$this->totalBatches}",
            'count' => count($this->emails),
        ]);

        $added = 0;
        $alreadyExists = 0;
        $invalid = 0;
        $subscribersUpdated = 0;

        // 验证和清理邮箱地址
        $validEmails = [];
        foreach ($this->emails as $email) {
            $email = strtolower(trim($email));
            
            if (empty($email)) {
                $invalid++;
                continue;
            }
            
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $invalid++;
                continue;
            }
            
            $validEmails[] = $email;
        }

        if (empty($validEmails)) {
            $this->updateProgress($added, $alreadyExists, $invalid, $subscribersUpdated);
            return;
        }

        try {
            DB::beginTransaction();

            // 1. 查询已存在的黑名单邮箱
            $existingEmails = Blacklist::where('user_id', $this->userId)
                ->whereIn('email', $validEmails)
                ->pluck('email')
                ->toArray();

            $alreadyExists = count($existingEmails);

            // 2. 过滤出需要新增的邮箱
            $newEmails = array_diff($validEmails, $existingEmails);
            $added = count($newEmails);

            // 3. 批量插入新邮箱到黑名单
            if (!empty($newEmails)) {
                $insertData = array_map(function ($email) {
                    return [
                        'user_id' => $this->userId,
                        'email' => $email,
                        'reason' => $this->reason,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }, $newEmails);

                // 分块插入，每次500条
                foreach (array_chunk($insertData, 500) as $chunk) {
                    Blacklist::insert($chunk);
                }
            }

            // 4. 批量更新订阅者状态为 blacklisted
            // 获取需要更新的订阅者
            $subscriberIds = Subscriber::whereIn('email', $validEmails)
                ->where('status', '!=', 'blacklisted')
                ->pluck('id')
                ->toArray();

            if (!empty($subscriberIds)) {
                // 更新 subscribers 表
                $subscribersUpdated = Subscriber::whereIn('id', $subscriberIds)
                    ->update(['status' => 'blacklisted']);

                // 更新 list_subscriber 中间表
                DB::table('list_subscriber')
                    ->whereIn('subscriber_id', $subscriberIds)
                    ->where('status', '!=', 'blacklisted')
                    ->update(['status' => 'blacklisted']);
            }

            DB::commit();

            Log::info("黑名单导入批次完成", [
                'task_id' => $this->taskId,
                'batch' => "{$this->batchNumber}/{$this->totalBatches}",
                'added' => $added,
                'already_exists' => $alreadyExists,
                'invalid' => $invalid,
                'subscribers_updated' => $subscribersUpdated,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error("黑名单导入批次失败", [
                'task_id' => $this->taskId,
                'batch' => "{$this->batchNumber}/{$this->totalBatches}",
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }

        // 更新进度
        $this->updateProgress($added, $alreadyExists, $invalid, $subscribersUpdated);
    }

    /**
     * 更新导入进度到 Cache
     */
    protected function updateProgress(int $added, int $alreadyExists, int $invalid, int $subscribersUpdated): void
    {
        $cacheKey = "blacklist_import_{$this->taskId}";
        
        // 使用 Redis 的原子操作更新计数器
        Cache::lock("lock_{$cacheKey}", 10)->block(5, function () use ($cacheKey, $added, $alreadyExists, $invalid, $subscribersUpdated) {
            $progress = Cache::get($cacheKey, [
                'total_batches' => $this->totalBatches,
                'completed_batches' => 0,
                'added' => 0,
                'already_exists' => 0,
                'invalid' => 0,
                'subscribers_updated' => 0,
                'status' => 'processing',
                'started_at' => now()->toIso8601String(),
            ]);

            $progress['completed_batches']++;
            $progress['added'] += $added;
            $progress['already_exists'] += $alreadyExists;
            $progress['invalid'] += $invalid;
            $progress['subscribers_updated'] += $subscribersUpdated;

            // 检查是否全部完成
            if ($progress['completed_batches'] >= $progress['total_batches']) {
                $progress['status'] = 'completed';
                $progress['completed_at'] = now()->toIso8601String();
            }

            // 缓存24小时
            Cache::put($cacheKey, $progress, 86400);
        });
    }

    /**
     * 任务失败时的处理
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("黑名单导入任务失败", [
            'task_id' => $this->taskId,
            'batch' => "{$this->batchNumber}/{$this->totalBatches}",
            'error' => $exception->getMessage(),
        ]);

        $cacheKey = "blacklist_import_{$this->taskId}";
        
        Cache::lock("lock_{$cacheKey}", 10)->block(5, function () use ($cacheKey, $exception) {
            $progress = Cache::get($cacheKey, []);
            $progress['status'] = 'failed';
            $progress['error'] = $exception->getMessage();
            $progress['failed_at'] = now()->toIso8601String();
            
            Cache::put($cacheKey, $progress, 86400);
        });
    }
}

