<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ManageWorkers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'queue:manage-workers
                            {--min=1 : Minimum number of workers per queue}
                            {--max=20 : Maximum number of workers per queue}
                            {--check-interval=10 : Seconds between checks}
                            {--scale-up-threshold=50 : Jobs per worker to scale up}
                            {--scale-down-threshold=10 : Jobs per worker to scale down}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically manage worker count for each queue based on load';

    private $queueWorkers = []; // ['smtp_1' => [pid1, pid2], 'smtp_2' => [pid3]]
    private $logDir;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $minWorkers = $this->option('min');
        $maxWorkers = $this->option('max');
        $checkInterval = $this->option('check-interval');
        $scaleUpThreshold = $this->option('scale-up-threshold');
        $scaleDownThreshold = $this->option('scale-down-threshold');

        $this->logDir = storage_path('logs');
        
        $this->info('🎛️  Starting Per-Queue Worker Auto-Scaler...');
        $this->info("   Min Workers/Queue: {$minWorkers}");
        $this->info("   Max Workers/Queue: {$maxWorkers}");
        $this->info("   Check Interval: {$checkInterval}s");
        $this->info("   Scale Up Threshold: {$scaleUpThreshold} jobs/worker");
        $this->info("   Scale Down Threshold: {$scaleDownThreshold} jobs/worker");
        $this->info('');

        // Monitor loop
        while (true) {
            // 发现所有活跃的队列
            $activeQueues = $this->discoverActiveQueues();
            
            $this->line("\n[" . date('H:i:s') . "] " . str_repeat('=', 60));
            $this->info("Active Queues: " . count($activeQueues));
            
            // 为每个队列管理 Worker
            foreach ($activeQueues as $queueName => $queueInfo) {
                $this->manageQueueWorkers(
                    $queueName,
                    $queueInfo,
                    $minWorkers,
                    $maxWorkers,
                    $scaleUpThreshold,
                    $scaleDownThreshold
                );
            }
            
            // 清理不活跃队列的 Worker
            $this->cleanInactiveQueueWorkers($activeQueues);
            
            // 清理死掉的 Worker
            $this->cleanDeadWorkers();
            
            sleep($checkInterval);
        }
    }

    /**
     * 发现所有活跃的队列（每个活动一个队列）
     */
    private function discoverActiveQueues()
    {
        // 获取所有正在发送的活动
        $sendingCampaigns = \App\Models\Campaign::where('status', 'sending')
            ->with('smtpServer')
            ->get();
        
        $queues = [];
        
        foreach ($sendingCampaigns as $campaign) {
            // 每个活动使用独立队列
            $queueName = 'campaign_' . $campaign->id;
            
            // 获取该队列的任务数
            $jobCount = DB::table('jobs')
                ->where('queue', $queueName)
                ->whereNull('reserved_at')
                ->count();
            
            $queues[$queueName] = [
                'campaign_id' => $campaign->id,
                'campaign_name' => $campaign->name,
                'smtp_server_id' => $campaign->smtp_server_id,
                'smtp_server_name' => $campaign->smtpServer->name ?? 'Unknown',
                'jobs' => $jobCount,
            ];
        }
        
        return $queues;
    }
    
    /**
     * 管理单个队列的 Worker
     */
    private function manageQueueWorkers($queueName, $queueInfo, $minWorkers, $maxWorkers, $scaleUpThreshold, $scaleDownThreshold)
    {
        // 初始化队列的 Worker 数组
        if (!isset($this->queueWorkers[$queueName])) {
            $this->queueWorkers[$queueName] = [];
        }
        
        $currentWorkers = count($this->queueWorkers[$queueName]);
        $jobCount = $queueInfo['jobs'];
        $jobsPerWorker = $currentWorkers > 0 ? round($jobCount / $currentWorkers, 2) : $jobCount;
        
        $this->line("  [{$queueName}] Jobs: {$jobCount}, Workers: {$currentWorkers}, Load: {$jobsPerWorker} jobs/worker");
        
        // 决定是否需要扩缩容
        $targetWorkers = $currentWorkers;
        
        if ($jobCount > 0 && $jobsPerWorker > $scaleUpThreshold && $currentWorkers < $maxWorkers) {
            // 智能扩容：根据负载计算需要的 Worker 数量
            // 目标：每个 Worker 处理约 2000 个任务（更激进的扩容）
            $idealWorkers = max(1, ceil($jobCount / 2000));
            
            // 限制在最小值和最大值之间
            $targetWorkers = min(max($idealWorkers, $minWorkers), $maxWorkers);
            
            // 如果计算出的目标值和当前值相同，至少增加2个（更快扩容）
            if ($targetWorkers == $currentWorkers && $currentWorkers < $maxWorkers) {
                $targetWorkers = min($currentWorkers + 2, $maxWorkers);
            }
            
            $this->info("    📈 Scaling UP: {$currentWorkers} → {$targetWorkers} (load: {$jobsPerWorker} jobs/worker)");
        } elseif ($currentWorkers > 0 && ($jobCount == 0 || $jobsPerWorker < $scaleDownThreshold) && $currentWorkers > $minWorkers) {
            // 渐进式缩容：每次只减少1个（防止频繁波动）
            $targetWorkers = max($currentWorkers - 1, $minWorkers);
            $this->info("    📉 Scaling DOWN: {$currentWorkers} → {$targetWorkers} (load: {$jobsPerWorker} jobs/worker)");
        } elseif ($currentWorkers == 0 && $jobCount > 0) {
            // 队列有任务但没有 Worker，根据任务数智能启动
            // 目标：每个 Worker 处理约 2000 个任务（更激进的扩容）
            $idealWorkers = max(1, ceil($jobCount / 2000));
            $targetWorkers = min(max($idealWorkers, $minWorkers), $maxWorkers);
            $this->info("    🚀 Starting workers: 0 → {$targetWorkers} (jobs: {$jobCount})");
        }
        
        // 执行扩缩容
        if ($targetWorkers != $currentWorkers) {
            $this->scaleQueueWorkers($queueName, $targetWorkers);
        }
    }
    
    /**
     * 调整指定队列的 Worker 数量
     */
    private function scaleQueueWorkers($queueName, $targetCount)
    {
        $currentCount = count($this->queueWorkers[$queueName] ?? []);
        
        if ($targetCount > $currentCount) {
            // 启动新 Worker
            for ($i = $currentCount; $i < $targetCount; $i++) {
                $this->startQueueWorker($queueName, $i + 1);
            }
        } elseif ($targetCount < $currentCount) {
            // 停止多余的 Worker
            for ($i = $targetCount; $i < $currentCount; $i++) {
                $this->stopQueueWorker($queueName, $i);
            }
        }
    }
    
    /**
     * 启动单个队列的 Worker（专属于一个活动）
     */
    private function startQueueWorker($queueName, $workerId)
    {
        // 从队列名中提取 campaign_id
        // queueName 格式: campaign_123
        if (!preg_match('/campaign_(\d+)/', $queueName, $matches)) {
            $this->error("Invalid queue name: {$queueName}");
            return;
        }
        
        $campaignId = $matches[1];
        $logFile = $this->logDir . "/{$queueName}-worker-{$workerId}.log";
        
        // 使用新的专属 Worker 命令
        // 不需要 while true 循环，因为 Worker 会自动在活动暂停/完成时退出
        $cmd = sprintf(
            'nohup bash -c \'echo "[$(date +\"%%Y-%%m-%%d %%H:%%M:%%S\")] Starting dedicated worker for Campaign #%s"; cd %s && php artisan campaign:process-queue %s --sleep=3 --memory=128 2>&1; EXIT_CODE=$?; echo "[$(date +\"%%Y-%%m-%%d %%H:%%M:%%S\")] Worker exited with code $EXIT_CODE"\' > %s 2>&1 & echo $!',
            $campaignId,
            base_path(),
            $campaignId,
            $logFile
        );
        
        $output = trim(shell_exec($cmd));
        
        if ($output) {
            $this->queueWorkers[$queueName][] = (int)$output;
            $this->line("      Started dedicated Worker for Campaign #{$campaignId} (PID: {$output})");
        }
    }
    
    /**
     * 停止单个队列的 Worker
     */
    private function stopQueueWorker($queueName, $index)
    {
        if (isset($this->queueWorkers[$queueName][$index])) {
            $pid = $this->queueWorkers[$queueName][$index];
            shell_exec("kill {$pid} 2>/dev/null");
            unset($this->queueWorkers[$queueName][$index]);
            $this->queueWorkers[$queueName] = array_values($this->queueWorkers[$queueName]);
            $this->line("      Stopped Worker at index {$index} (PID: {$pid})");
        }
    }
    
    /**
     * 清理不活跃队列的 Worker
     */
    private function cleanInactiveQueueWorkers($activeQueues)
    {
        foreach ($this->queueWorkers as $queueName => $workers) {
            if (!isset($activeQueues[$queueName]) && count($workers) > 0) {
                $this->warn("  [{$queueName}] Queue is inactive, stopping all workers...");
                foreach ($workers as $pid) {
                    shell_exec("kill {$pid} 2>/dev/null");
                }
                unset($this->queueWorkers[$queueName]);
            }
        }
    }
    
    /**
     * Get current queue length (legacy, kept for compatibility)
     */
    private function getQueueLength()
    {
        try {
            return DB::table('jobs')
                ->whereNull('reserved_at')
                ->count();
        } catch (\Exception $e) {
            $this->error("Failed to get queue length: {$e->getMessage()}");
            
            Log::error('Failed to get queue length', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return 0;
        }
    }


    /**
     * Clean up dead workers
     */
    private function cleanDeadWorkers()
    {
        foreach ($this->queueWorkers as $queueName => $workers) {
            foreach ($workers as $index => $pid) {
                // Check if process is still running
                $result = shell_exec("ps -p {$pid} -o pid=");
                
                if (empty(trim($result))) {
                    $this->warn("  [{$queueName}] Worker at index {$index} (PID: {$pid}) has died, removing from tracking");
                    unset($this->queueWorkers[$queueName][$index]);
                    $this->queueWorkers[$queueName] = array_values($this->queueWorkers[$queueName]);
                }
            }
        }
    }
}

