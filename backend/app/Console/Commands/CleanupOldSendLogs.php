<?php

namespace App\Console\Commands;

use App\Models\SendLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CleanupOldSendLogs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sendlogs:cleanup 
                            {--days=30 : Number of days to keep send logs}
                            {--batch-size=1000 : Number of records to delete per batch}
                            {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up old send logs from the database that are older than specified days';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $days = (int) $this->option('days');
        $batchSize = (int) $this->option('batch-size');
        $dryRun = $this->option('dry-run');
        
        $cutoffDate = Carbon::now()->subDays($days);
        
        $this->info("🗑️  Starting SendLog cleanup...");
        $this->info("   Delete records older than: {$cutoffDate->format('Y-m-d H:i:s')}");
        $this->info("   Batch size: {$batchSize}");
        $this->info("   Dry run: " . ($dryRun ? 'Yes' : 'No'));
        $this->line('');
        
        // 统计需要删除的记录数
        $totalToDelete = SendLog::where('created_at', '<', $cutoffDate)->count();
        
        if ($totalToDelete === 0) {
            $this->info("✅ No old send logs to delete");
            return 0;
        }
        
        $this->info("📊 Found {$totalToDelete} records to delete");
        $this->line('');
        
        if ($dryRun) {
            $this->warn("🔍 DRY RUN MODE - No data will be deleted");
            $this->line('');
            
            // 显示一些样本记录
            $samples = SendLog::where('created_at', '<', $cutoffDate)
                ->orderBy('created_at', 'asc')
                ->limit(5)
                ->get(['id', 'campaign_name', 'email', 'status', 'created_at']);
            
            if ($samples->isNotEmpty()) {
                $this->info("Sample records that would be deleted:");
                foreach ($samples as $sample) {
                    $this->line("  ID: {$sample->id}, Campaign: {$sample->campaign_name}, Email: {$sample->email}, Status: {$sample->status}, Date: {$sample->created_at}");
                }
                
                if ($totalToDelete > 5) {
                    $this->line("  ... and " . ($totalToDelete - 5) . " more records");
                }
            }
            
            $this->line('');
            $this->info("✅ Dry run completed");
            return 0;
        }
        
        // 确认删除
        if ($totalToDelete > 10000) {
            $this->warn("⚠️  About to delete {$totalToDelete} records!");
            if (!$this->confirm('Do you want to continue?', false)) {
                $this->info("Operation cancelled");
                return 1;
            }
        }
        
        // 批量删除
        $deletedCount = 0;
        $startTime = microtime(true);
        
        $this->info("🔄 Deleting records in batches...");
        
        $progressBar = $this->output->createProgressBar($totalToDelete);
        $progressBar->start();
        
        try {
            while (true) {
                // 批量删除
                $deleted = SendLog::where('created_at', '<', $cutoffDate)
                    ->limit($batchSize)
                    ->delete();
                
                if ($deleted === 0) {
                    break;
                }
                
                $deletedCount += $deleted;
                $progressBar->advance($deleted);
                
                // 避免长时间锁表，每批次之间稍微暂停
                usleep(10000); // 10ms
            }
            
            $progressBar->finish();
            $this->line('');
            $this->line('');
            
            $duration = round(microtime(true) - $startTime, 2);
            
            $this->info("✅ Successfully deleted {$deletedCount} records");
            $this->info("   Duration: {$duration} seconds");
            $this->info("   Average: " . round($deletedCount / max($duration, 0.01), 2) . " records/second");
            
            // 记录日志
            Log::info('SendLog cleanup completed', [
                'deleted_count' => $deletedCount,
                'cutoff_date' => $cutoffDate->toDateTimeString(),
                'duration_seconds' => $duration,
            ]);
            
            // 优化表
            $this->line('');
            $this->info("🔧 Optimizing table...");
            DB::statement('OPTIMIZE TABLE send_logs');
            $this->info("✅ Table optimized");
            
        } catch (\Exception $e) {
            $this->line('');
            $this->error("❌ Failed to delete records: {$e->getMessage()}");
            
            Log::error('SendLog cleanup failed', [
                'error' => $e->getMessage(),
                'cutoff_date' => $cutoffDate->toDateTimeString(),
                'deleted_count' => $deletedCount,
            ]);
            
            return 1;
        }
        
        $this->line('');
        $this->info("🎉 SendLog cleanup completed successfully");
        
        return 0;
    }
}
