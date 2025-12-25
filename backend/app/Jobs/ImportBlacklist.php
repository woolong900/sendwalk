<?php

namespace App\Jobs;

use App\Models\Blacklist;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ImportBlacklist implements ShouldQueue
{
    use Queueable;

    public $timeout = 3600; // 1 小时超时
    public $tries = 1; // 不重试

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $filePath,
        public int $userId,
        public ?string $reason,
        public string $importId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $added = 0;
        $alreadyExists = 0;
        $invalid = 0;
        $totalRows = 0;

        try {
            // 设置初始进度
            $this->updateProgress(0, 0, 0, 0, 0, 'processing');

            // 读取文件（支持多种格式）
            if (($handle = fopen($this->filePath, 'r')) !== false) {
                // 检测文件格式
                $firstLine = fgets($handle);
                rewind($handle);
                
                $isCsv = strpos($firstLine, ',') !== false;
                
                Log::info('黑名单导入 - 检测文件格式', [
                    'import_id' => $this->importId,
                    'is_csv' => $isCsv,
                    'first_line' => substr($firstLine, 0, 100),
                    'file_path' => $this->filePath,
                ]);

                // 如果是 CSV，跳过表头（如果有）
                if ($isCsv) {
                    $header = fgetcsv($handle);
                    // 如果第一行看起来像表头，就跳过
                    if ($header && (in_array('email', array_map('strtolower', $header)) || 
                        in_array('邮箱', $header) || 
                        strtolower($header[0]) === 'email')) {
                        Log::info('黑名单导入 - 跳过CSV表头', [
                            'import_id' => $this->importId,
                            'header' => $header,
                        ]);
                    } else {
                        // 不是表头，回退到开头
                        rewind($handle);
                    }
                }

                // 先统计总行数
                while (fgets($handle) !== false) {
                    $totalRows++;
                }
                rewind($handle);
                
                // 如果跳过了表头，重新跳过
                if ($isCsv) {
                    $firstLine = fgetcsv($handle);
                    if ($firstLine && (in_array('email', array_map('strtolower', $firstLine)) || 
                        in_array('邮箱', $firstLine))) {
                        $totalRows--; // 不计算表头
                    } else {
                        rewind($handle);
                    }
                }

                Log::info('黑名单导入 - 文件信息', [
                    'import_id' => $this->importId,
                    'total_rows' => $totalRows,
                ]);

                // 批量处理
                $batch = [];
                $batchSize = 1000;
                $processed = 0;

                $sampleRows = [];
                $rowNumber = 0;
                
                while (($line = fgets($handle)) !== false) {
                    $rowNumber++;
                    
                    // 解析邮箱（支持 CSV 或纯文本）
                    $email = trim($line);
                    
                    // 如果是 CSV，取第一列
                    if ($isCsv) {
                        $row = str_getcsv($email);
                        $email = $row[0] ?? '';
                    }
                    
                    $email = strtolower(trim($email));
                    
                    // 记录前5行的样例数据
                    if ($rowNumber <= 5) {
                        $sampleRows[] = [
                            'row' => $rowNumber,
                            'raw_data' => substr($line, 0, 100),
                            'email' => $email,
                            'valid' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
                        ];
                    }
                    
                    // 验证邮箱
                    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $invalid++;
                        $processed++;
                        
                        // 每 1000 行更新一次进度
                        if ($processed % 1000 === 0) {
                            $progress = $totalRows > 0 ? round(($processed / $totalRows) * 100) : 0;
                            $this->updateProgress($progress, $added, $alreadyExists, $invalid, $processed, 'processing');
                        }
                        
                        continue;
                    }

                    $batch[] = $email;

                    // 达到批量大小时处理
                    if (count($batch) >= $batchSize) {
                        $result = $this->processBatch($batch);
                        $added += $result['added'];
                        $alreadyExists += $result['already_exists'];
                        $processed += count($batch);
                        
                        // 更新进度
                        $progress = $totalRows > 0 ? round(($processed / $totalRows) * 100) : 0;
                        $this->updateProgress($progress, $added, $alreadyExists, $invalid, $processed, 'processing');
                        
                        $batch = [];
                    }
                }

                // 处理剩余的批次
                if (!empty($batch)) {
                    $result = $this->processBatch($batch);
                    $added += $result['added'];
                    $alreadyExists += $result['already_exists'];
                    $processed += count($batch);
                }

                fclose($handle);
                
                // 记录样例数据
                Log::info('黑名单导入 - 数据样例', [
                    'import_id' => $this->importId,
                    'sample_rows' => $sampleRows,
                    'total_rows' => $totalRows,
                ]);
            }

            // 设置完成状态
            $this->updateProgress(100, $added, $alreadyExists, $invalid, $processed, 'completed');
            
            Log::info('黑名单导入完成', [
                'import_id' => $this->importId,
                'added' => $added,
                'already_exists' => $alreadyExists,
                'invalid' => $invalid,
                'total_rows' => $totalRows,
            ]);

            // 删除临时文件
            if (file_exists($this->filePath)) {
                unlink($this->filePath);
            }

        } catch (\Exception $e) {
            Log::error('黑名单导入失败', [
                'import_id' => $this->importId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->updateProgress(0, $added, $alreadyExists, $invalid, 0, 'failed', $e->getMessage());

            // 删除临时文件
            if (file_exists($this->filePath)) {
                unlink($this->filePath);
            }
        }
    }

    /**
     * 批量处理黑名单邮箱
     */
    private function processBatch(array $emails): array
    {
        $result = Blacklist::addBatch(
            $this->userId,
            $emails,
            $this->reason
        );

        return [
            'added' => $result['added'],
            'already_exists' => $result['already_exists'],
        ];
    }

    /**
     * 更新导入进度
     */
    private function updateProgress(
        int $progress,
        int $added,
        int $alreadyExists,
        int $invalid,
        int $processed,
        string $status,
        ?string $error = null
    ): void {
        $data = [
            'progress' => $progress,
            'added' => $added,
            'already_exists' => $alreadyExists,
            'invalid' => $invalid,
            'processed' => $processed,
            'status' => $status,
            'updated_at' => now()->toIso8601String(),
        ];

        if ($error) {
            $data['error'] = $error;
        }

        $cacheKey = "blacklist_import:{$this->importId}";
        
        try {
            Cache::put($cacheKey, $data, 3600); // 缓存 1 小时
            
            // 验证缓存是否写入成功
            $cached = Cache::get($cacheKey);
            if ($cached) {
                Log::info('黑名单进度缓存更新成功', [
                    'import_id' => $this->importId,
                    'cache_key' => $cacheKey,
                    'data' => $data,
                ]);
            } else {
                Log::error('黑名单进度缓存写入后无法读取', [
                    'import_id' => $this->importId,
                    'cache_key' => $cacheKey,
                    'data' => $data,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('黑名单进度缓存更新失败', [
                'import_id' => $this->importId,
                'cache_key' => $cacheKey,
                'error' => $e->getMessage(),
                'data' => $data,
            ]);
        }
    }
}

