<?php

namespace App\Console\Commands;

use App\Models\SendLog;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CleanupSendLogs extends Command
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
    protected $description = 'Clean up old send logs that are older than specified days';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $days = (int) $this->option('days');
        $batchSize = (int) $this->option('batch-size');
        $dryRun = $this->option('dry-run');
        
        $cutoffDate = Carbon::now()->subDays($days);
        
        $this->info("🧹 Starting send logs cleanup...");
        $this->info("   Keep logs newer than: {$cutoffDate->format('Y-m-d H:i:s')}");
        $this->info("   Batch size: {$batchSize}");
        $this->info("   Dry run: " . ($dryRun ? 'Yes' : 'No'));
        $this->line('');
        
        // 统计要删除的记录数
        $totalCount = SendLog::where('created_at', '<', $cutoffDate)->count();
        
        if ($totalCount === 0) {
            $this->info("✅ No old send logs to clean up");
            return 0;
        }
        
        $this->info("📊 Found {$totalCount} records older than {$days} days");
        $this->line('');
        
        // 获取一些统计信息
        $oldestLog = SendLog::where('created_at', '<', $cutoffDate)
            ->orderBy('created_at', 'asc')
            ->first();
        
        if ($oldestLog) {
            $oldestDate = $oldestLog->created_at->format('Y-m-d H:i:s');
            $this->comment("   Oldest log: {$oldestDate}");
            $this->line('');
        }
        
        if ($dryRun) {
            $this->warn("🔍 DRY RUN MODE - No records will be deleted");
            $this->warn("   Would delete {$totalCount} records");
            
            // 显示按状态统计
            $stats = SendLog::where('created_at', '<', $cutoffDate)
                ->select('status', DB::raw('count(*) as count'))
                ->groupBy('status')
                ->get();
            
            $this->line('');
            $this->info("   Records by status:");
            foreach ($stats as $stat) {
                $this->info("     - {$stat->status}: {$stat->count}");
            }
            
            return 0;
        }
        
        // 确认删除
        if (!$this->confirm("Are you sure you want to delete {$totalCount} records?", false)) {
            $this->comment("Operation cancelled");
            return 0;
        }
        
        $this->line('');
        $this->info("🗑️  Starting deletion in batches...");
        
        $deletedCount = 0;
        $startTime = microtime(true);
        
        // 分批删除
        $bar = $this->output->createProgressBar($totalCount);
        $bar->start();
        
        while (true) {
            // 每批删除指定数量
            $deleted = SendLog::where('created_at', '<', $cutoffDate)
                ->limit($batchSize)
                ->delete();
            
            if ($deleted === 0) {
                break;
            }
            
            $deletedCount += $deleted;
            $bar->advance($deleted);
            
            // 记录日志
            Log::info('Send logs batch deleted', [
                'batch_size' => $deleted,
                'total_deleted' => $deletedCount,
                'cutoff_date' => $cutoffDate->toDateTimeString(),
            ]);
            
            // 避免数据库负载过高，短暂休眠
            usleep(100000); // 0.1 秒
        }
        
        $bar->finish();
        
        $duration = round(microtime(true) - $startTime, 2);
        
        $this->line('');
        $this->line('');
        $this->info("📊 Cleanup Summary:");
        $this->info("   Records deleted: {$deletedCount}");
        $this->info("   Duration: {$duration} seconds");
        $this->info("   Average speed: " . round($deletedCount / max($duration, 1)) . " records/second");
        
        $this->line('');
        
        // 优化表（可选）
        if ($deletedCount > 1000) {
            $this->comment("💡 Consider optimizing the table:");
            $this->comment("   php artisan db:statement 'OPTIMIZE TABLE send_logs'");
        }
        
        $this->line('');
        $this->info("✅ Send logs cleanup completed");
        
        // 记录完成日志
        Log::info('Send logs cleanup completed', [
            'deleted_count' => $deletedCount,
            'cutoff_date' => $cutoffDate->toDateTimeString(),
            'days' => $days,
            'duration' => $duration,
        ]);
        
        return 0;
    }
}
