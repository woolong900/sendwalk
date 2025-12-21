<?php

namespace App\Jobs;

use App\Models\Subscriber;
use App\Models\MailingList;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ImportSubscribers implements ShouldQueue
{
    use Queueable;

    public $timeout = 3600; // 1 小时超时
    public $tries = 1; // 不重试

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $filePath,
        public int $listId,
        public int $userId,
        public string $importId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $imported = 0;
        $skipped = 0;
        $errors = [];
        $totalRows = 0;

        try {
            // 设置初始进度
            $this->updateProgress(0, 0, 0, 0, 'processing');

            // 验证列表权限
            $list = MailingList::findOrFail($this->listId);
            if ($list->user_id !== $this->userId) {
                throw new \Exception('无权访问此列表');
            }

            // 读取CSV文件
            if (($handle = fopen($this->filePath, 'r')) !== false) {
                $header = fgetcsv($handle);
                
                if (!$header || !in_array('email', $header)) {
                    throw new \Exception('文件格式错误：必须包含 email 列');
                }

                $emailIndex = array_search('email', $header);
                $firstNameIndex = array_search('first_name', $header);
                $lastNameIndex = array_search('last_name', $header);

                // 先统计总行数
                while (fgetcsv($handle) !== false) {
                    $totalRows++;
                }
                rewind($handle);
                fgetcsv($handle); // 跳过表头

                // 批量处理
                $batch = [];
                $batchSize = 500;
                $processed = 0;

                while (($row = fgetcsv($handle)) !== false) {
                    $email = $row[$emailIndex] ?? '';
                    
                    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $skipped++;
                        $processed++;
                        continue;
                    }

                    $batch[] = [
                        'email' => $email,
                        'first_name' => $firstNameIndex !== false ? ($row[$firstNameIndex] ?? '') : '',
                        'last_name' => $lastNameIndex !== false ? ($row[$lastNameIndex] ?? '') : '',
                    ];

                    // 达到批量大小时处理
                    if (count($batch) >= $batchSize) {
                        $result = $this->processBatch($batch, $this->listId);
                        $imported += $result['imported'];
                        $skipped += $result['skipped'];
                        $processed += count($batch);
                        
                        // 更新进度
                        $progress = $totalRows > 0 ? round(($processed / $totalRows) * 100) : 0;
                        $this->updateProgress($progress, $imported, $skipped, $processed, 'processing');
                        
                        $batch = [];
                    }
                }

                // 处理剩余的批次
                if (!empty($batch)) {
                    $result = $this->processBatch($batch, $this->listId);
                    $imported += $result['imported'];
                    $skipped += $result['skipped'];
                    $processed += count($batch);
                }

                fclose($handle);
            }

            // 更新列表的订阅者计数
            $list->subscribers_count = $list->subscribers()->wherePivot('status', 'active')->count();
            $list->save();

            // 设置完成状态
            $this->updateProgress(100, $imported, $skipped, $processed, 'completed');

            // 删除临时文件
            if (file_exists($this->filePath)) {
                unlink($this->filePath);
            }

        } catch (\Exception $e) {
            Log::error('导入订阅者失败', [
                'import_id' => $this->importId,
                'error' => $e->getMessage(),
            ]);

            $this->updateProgress(0, $imported, $skipped, 0, 'failed', $e->getMessage());

            // 删除临时文件
            if (file_exists($this->filePath)) {
                unlink($this->filePath);
            }
        }
    }

    /**
     * 批量处理订阅者
     */
    private function processBatch(array $batch, int $listId): array
    {
        $imported = 0;
        $skipped = 0;

        foreach ($batch as $data) {
            try {
                // Check if email is blacklisted
                $isBlacklisted = \App\Models\Blacklist::isBlacklisted($this->userId, $data['email']);
                
                if ($isBlacklisted) {
                    $skipped++;
                    continue;
                }

                // 查找或创建订阅者（包括软删除的）
                $subscriber = Subscriber::withTrashed()
                    ->where('email', $data['email'])
                    ->first();

                if ($subscriber) {
                    // If subscriber is blacklisted, skip
                    if ($subscriber->status === 'blacklisted') {
                        $skipped++;
                        continue;
                    }
                    
                    if ($subscriber->trashed()) {
                        $subscriber->restore();
                    }
                    $subscriber->update([
                        'first_name' => $data['first_name'],
                        'last_name' => $data['last_name'],
                        'status' => 'active',
                        'subscribed_at' => now(),
                    ]);
                } else {
                    $subscriber = Subscriber::create([
                        'email' => $data['email'],
                        'first_name' => $data['first_name'],
                        'last_name' => $data['last_name'],
                        'status' => 'active',
                        'subscribed_at' => now(),
                        'source' => 'csv_import',
                    ]);
                }

                // 添加到列表（如果还未添加）
                $exists = $subscriber->lists()
                    ->wherePivot('list_id', $listId)
                    ->exists();

                if (!$exists) {
                    $subscriber->lists()->attach($listId, [
                        'status' => 'active',
                        'subscribed_at' => now(),
                    ]);
                    $imported++;
                } else {
                    // 如果已在列表中但状态是取消订阅，重新激活
                    $subscriber->lists()->updateExistingPivot($listId, [
                        'status' => 'active',
                        'subscribed_at' => now(),
                        'unsubscribed_at' => null,
                    ]);
                    $skipped++;
                }
            } catch (\Exception $e) {
                Log::warning('导入订阅者失败', [
                    'email' => $data['email'],
                    'error' => $e->getMessage(),
                ]);
                $skipped++;
            }
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
        ];
    }

    /**
     * 更新导入进度
     */
    private function updateProgress(
        int $progress,
        int $imported,
        int $skipped,
        int $processed,
        string $status,
        ?string $error = null
    ): void {
        $data = [
            'progress' => $progress,
            'imported' => $imported,
            'skipped' => $skipped,
            'processed' => $processed,
            'status' => $status,
            'updated_at' => now()->toIso8601String(),
        ];

        if ($error) {
            $data['error'] = $error;
        }

        Cache::put("import_progress:{$this->importId}", $data, 3600); // 缓存 1 小时
    }
}
