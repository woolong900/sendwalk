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
                
                // 记录表头信息
                Log::info('导入CSV - 读取表头', [
                    'import_id' => $this->importId,
                    'header' => $header,
                    'file_path' => $this->filePath,
                ]);
                
                if (!$header || !in_array('email', $header)) {
                    // 尝试将表头转换为小写再检查
                    $headerLower = array_map('strtolower', $header ?? []);
                    if (!in_array('email', $headerLower)) {
                        Log::error('CSV格式错误', [
                            'import_id' => $this->importId,
                            'header' => $header,
                            'header_lower' => $headerLower,
                        ]);
                        throw new \Exception('文件格式错误：必须包含 email 列（当前表头：' . implode(', ', $header ?? []) . '）');
                    }
                    // 如果找到了小写的email，使用小写表头
                    $header = $headerLower;
                }

                $emailIndex = array_search('email', $header);
                $firstNameIndex = array_search('first_name', $header);
                $lastNameIndex = array_search('last_name', $header);
                
                Log::info('CSV列索引', [
                    'import_id' => $this->importId,
                    'email_index' => $emailIndex,
                    'first_name_index' => $firstNameIndex,
                    'last_name_index' => $lastNameIndex,
                ]);

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

                $sampleRows = [];
                $rowNumber = 0;
                
                while (($row = fgetcsv($handle)) !== false) {
                    $rowNumber++;
                    $email = $row[$emailIndex] ?? '';
                    
                    // 记录前5行的样例数据
                    if ($rowNumber <= 5) {
                        $sampleRows[] = [
                            'row' => $rowNumber,
                            'raw_data' => $row,
                            'email' => $email,
                            'valid' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
                        ];
                    }
                    
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
                
                // 记录样例数据
                Log::info('CSV数据样例', [
                    'import_id' => $this->importId,
                    'sample_rows' => $sampleRows,
                    'total_rows' => $totalRows,
                ]);
            }

            // 更新列表的订阅者计数
            $list->subscribers_count = $list->subscribers()->wherePivot('status', 'active')->count();
            $list->save();

            // 设置完成状态
            $this->updateProgress(100, $imported, $skipped, $processed, 'completed');
            
            Log::info('导入完成', [
                'import_id' => $this->importId,
                'imported' => $imported,
                'skipped' => $skipped,
                'total_rows' => $totalRows,
            ]);

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
        $skipReasons = [];

        foreach ($batch as $data) {
            try {
                // Check if email is blacklisted
                $isBlacklisted = \App\Models\Blacklist::isBlacklisted($this->userId, $data['email']);
                
                if ($isBlacklisted) {
                    $skipped++;
                    if (count($skipReasons) < 5) {
                        $skipReasons[] = ['email' => $data['email'], 'reason' => '在黑名单中'];
                    }
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
                        if (count($skipReasons) < 5) {
                            $skipReasons[] = ['email' => $data['email'], 'reason' => '订阅者状态为黑名单'];
                        }
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
                    if (count($skipReasons) < 5) {
                        $skipReasons[] = ['email' => $data['email'], 'reason' => '已在列表中'];
                    }
                }
            } catch (\Exception $e) {
                Log::warning('导入订阅者失败', [
                    'email' => $data['email'],
                    'error' => $e->getMessage(),
                ]);
                $skipped++;
                if (count($skipReasons) < 5) {
                    $skipReasons[] = ['email' => $data['email'], 'reason' => '异常: ' . $e->getMessage()];
                }
            }
        }
        
        // 记录跳过原因样例
        if (!empty($skipReasons)) {
            Log::info('批次处理 - 跳过原因样例', [
                'import_id' => $this->importId,
                'skip_reasons' => $skipReasons,
                'imported' => $imported,
                'skipped' => $skipped,
            ]);
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

        $cacheKey = "import_progress:{$this->importId}";
        
        try {
            Cache::put($cacheKey, $data, 3600); // 缓存 1 小时
            
            // 验证缓存是否写入成功
            $cached = Cache::get($cacheKey);
            if ($cached) {
                Log::info('进度缓存更新成功', [
                    'import_id' => $this->importId,
                    'cache_key' => $cacheKey,
                    'data' => $data,
                ]);
            } else {
                Log::error('进度缓存写入后无法读取', [
                    'import_id' => $this->importId,
                    'cache_key' => $cacheKey,
                    'data' => $data,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('进度缓存更新失败', [
                'import_id' => $this->importId,
                'cache_key' => $cacheKey,
                'error' => $e->getMessage(),
                'data' => $data,
            ]);
        }
    }
}
