<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CleanupOldLogs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'logs:cleanup 
                            {--days=30 : Number of days to keep logs}
                            {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up old log files that are older than specified days';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $days = (int) $this->option('days');
        $dryRun = $this->option('dry-run');
        
        $logPath = storage_path('logs');
        $cutoffDate = Carbon::now()->subDays($days);
        
        $this->info("🧹 Starting log cleanup...");
        $this->info("   Log directory: {$logPath}");
        $this->info("   Keep logs newer than: {$cutoffDate->format('Y-m-d')}");
        $this->info("   Dry run: " . ($dryRun ? 'Yes' : 'No'));
        $this->line('');
        
        if (!File::isDirectory($logPath)) {
            $this->error("Log directory does not exist: {$logPath}");
            return 1;
        }
        
        // 获取所有日志文件
        $logFiles = File::glob($logPath . '/*.log*');
        
        $deletedCount = 0;
        $deletedSize = 0;
        $keptCount = 0;
        
        foreach ($logFiles as $file) {
            $fileName = basename($file);
            
            // 跳过当前日志文件（没有日期后缀的）
            if (preg_match('/^[^-]+\.log$/', $fileName)) {
                $this->comment("⏭️  Skipping current log: {$fileName}");
                $keptCount++;
                continue;
            }
            
            // 获取文件修改时间
            $fileModifiedTime = Carbon::createFromTimestamp(File::lastModified($file));
            
            // 检查是否超过保留天数
            if ($fileModifiedTime->lt($cutoffDate)) {
                $fileSize = File::size($file);
                $fileSizeMB = round($fileSize / 1024 / 1024, 2);
                
                if ($dryRun) {
                    $this->warn("🗑️  Would delete: {$fileName} ({$fileSizeMB}MB, modified: {$fileModifiedTime->format('Y-m-d')})");
                } else {
                    try {
                        File::delete($file);
                        $this->info("✅ Deleted: {$fileName} ({$fileSizeMB}MB)");
                        
                        $deletedCount++;
                        $deletedSize += $fileSize;
                        
                        Log::info('Old log file deleted', [
                            'file' => $fileName,
                            'size' => $fileSize,
                            'modified_at' => $fileModifiedTime->toDateTimeString(),
                        ]);
                    } catch (\Exception $e) {
                        $this->error("❌ Failed to delete: {$fileName} - {$e->getMessage()}");
                        
                        Log::error('Failed to delete old log file', [
                            'file' => $fileName,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            } else {
                $keptCount++;
            }
        }
        
        $this->line('');
        $this->info("📊 Summary:");
        $this->info("   Files kept: {$keptCount}");
        
        if ($dryRun) {
            $this->warn("   Files that would be deleted: {$deletedCount}");
            $this->warn("   Space that would be freed: " . round($deletedSize / 1024 / 1024, 2) . " MB");
        } else {
            $this->info("   Files deleted: {$deletedCount}");
            $this->info("   Space freed: " . round($deletedSize / 1024 / 1024, 2) . " MB");
        }
        
        $this->line('');
        $this->info("✅ Log cleanup completed");
        
        return 0;
    }
}
