<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessCampaignQueue extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'campaign:process-queue 
                            {campaign_id : The ID of the campaign to process}
                            {--sleep=3 : Number of seconds to sleep when no job is available}
                            {--memory=128 : The memory limit in megabytes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process queue for a specific campaign (dedicated worker)';

    /**
     * Flag to indicate if worker should shutdown gracefully
     *
     * @var bool
     */
    protected $shouldQuit = false;

    /**
     * Flag to indicate if worker is processing a job
     *
     * @var bool
     */
    protected $isProcessing = false;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $campaignId = $this->argument('campaign_id');
        $sleepSeconds = $this->option('sleep');
        $memoryLimit = $this->option('memory');
        
        // 注册信号处理器
        $this->registerSignalHandlers();
        
        $this->info("🚀 Starting dedicated worker for Campaign #{$campaignId}");
        $this->info("   PID: " . getmypid());
        
        // 加载活动
        $campaign = Campaign::find($campaignId);
        if (!$campaign) {
            $this->error("Campaign #{$campaignId} not found");
            return 1;
        }
        
        $queueName = "campaign_{$campaignId}";
        $this->info("   Queue: {$queueName}");
        $this->info("   Campaign: {$campaign->name}");
        $this->info("   SMTP Server: {$campaign->smtpServer->name}");
        $this->line('');
        
        $processedCount = 0;
        $lastCheck = time();
        $isRateLimited = false; // 标记是否处于限流状态
        $rateLimitBlockedBy = null; // 记录被哪种限制阻塞
        $smtpServerId = $campaign->smtp_server_id;
        
        // 主循环
        while (!$this->shouldQuit) {
            // 处理挂起的信号
            $this->checkSignals();
            
            // 如果收到退出信号，立即退出循环
            if ($this->shouldQuit) {
                $this->info("👋 Graceful shutdown completed");
                Log::info('Worker gracefully shutdown', [
                    'campaign_id' => $campaignId,
                    'pid' => getmypid(),
                    'processed_count' => $processedCount,
                ]);
                return 0;
            }
            
            // 每 10 秒检查一次活动状态
            if (time() - $lastCheck >= 10) {
                try {
                    // 尝试重新加载活动
                    $campaign = Campaign::find($campaignId);
                    
                    // 检查活动是否已被删除
                    if (!$campaign) {
                        $this->warn("🗑️  Campaign has been deleted, exiting worker");
                        return 0;
                    }
                    
                    // 检查活动状态
                    if (in_array($campaign->status, ['paused', 'cancelled', 'draft'])) {
                        $this->warn("⏸️  Campaign status changed to '{$campaign->status}', exiting worker");
                        return 0;
                    }
                    
                    if ($campaign->status === 'sent') {
                        $this->info("✅ Campaign completed, exiting worker");
                        return 0;
                    }
                } catch (\Exception $e) {
                    // 如果活动被删除或其他异常，退出 Worker
                    $this->error("❌ Failed to check campaign status: {$e->getMessage()}");
                    $this->warn("🗑️  Campaign may have been deleted, exiting worker");
                    
                    Log::error('Worker failed to check campaign status', [
                        'campaign_id' => $campaignId,
                        'error' => $e->getMessage(),
                        'exception' => get_class($e),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    
                    return 0;
                }
                
                // 检查内存限制
                $memoryUsage = memory_get_usage(true) / 1024 / 1024;
                if ($memoryUsage > $memoryLimit) {
                    $this->warn("⚠️  Memory limit exceeded ({$memoryUsage}MB), exiting worker");
                    return 0;
                }
                
                $lastCheck = time();
            }
            
            // 如果处于限流状态，根据限制类型决定等待时间
            if ($isRateLimited) {
                // 根据上次被阻塞的类型决定睡眠时间
                // 秒级限制：只睡1秒；分钟级：睡5秒；小时级：睡30秒；天级：睡60秒
                $sleepMap = [
                    'second' => 1,
                    'minute' => 5,
                    'hour' => 30,
                    'day' => 60,
                ];
                $rateLimitSleep = $sleepMap[$rateLimitBlockedBy] ?? 1;
                
                $smtpServer = \App\Models\SmtpServer::find($smtpServerId);
                if ($smtpServer) {
                    $rateLimitStatus = $smtpServer->checkRateLimits();
                    if (!$rateLimitStatus['can_send']) {
                        // 仍然处于限流状态，根据类型智能休眠
                        $rateLimitBlockedBy = $rateLimitStatus['blocked_by'];
                        $rateLimitSleep = $sleepMap[$rateLimitBlockedBy] ?? 1;
                        $this->comment("[" . date('H:i:s') . "] Rate limited (blocked by: {$rateLimitBlockedBy}), sleeping {$rateLimitSleep}s");
                        sleep($rateLimitSleep);
                        continue;
                    }
                    // 限流解除，可以继续获取任务
                    $this->info("[" . date('H:i:s') . "] Rate limit cleared, resuming job processing");
                    $isRateLimited = false;
                    $rateLimitBlockedBy = null;
                }
            }
            
            // 获取下一个任务
            $job = $this->getNextJob($queueName);
            
            if (!$job) {
                // 队列为空，尝试标记活动为完成（原子操作，防止状态卡住）
                $this->tryMarkCampaignComplete($campaignId, $queueName);
                
                // 休眠后继续
                $this->comment("[" . date('H:i:s') . "] No jobs available, sleeping {$sleepSeconds}s");
                sleep($sleepSeconds);
                continue;
            }
            
            // 处理任务
            $result = $this->processJob($job);
            
            if ($result === 'rate_limited') {
                // 服务器超限，标记限流状态，并获取具体限制类型
                $isRateLimited = true;
                $smtpServer = \App\Models\SmtpServer::find($smtpServerId);
                if ($smtpServer) {
                    $rateLimitStatus = $smtpServer->checkRateLimits();
                    $rateLimitBlockedBy = $rateLimitStatus['blocked_by'] ?? 'second';
                }
                continue;
            }
            
            $processedCount++;
            
            if ($processedCount % 100 === 0) {
                $this->info("Processed {$processedCount} jobs");
            }
        }
    }
    
    /**
     * 最大重试次数
     */
    private const MAX_ATTEMPTS = 5;

    /**
     * 获取下一个任务（原子性操作，防止竞态条件）
     */
    private function getNextJob($queueName)
    {
        $now = time();
        
        try {
            // 方案1：使用 lockForUpdate() 锁定行
            // 在事务中执行，确保原子性
            return DB::transaction(function () use ($queueName, $now) {
                // SELECT ... FOR UPDATE 锁定行，其他 Worker 会等待
                $job = DB::table('jobs')
                    ->where('queue', $queueName)
                    ->whereNull('reserved_at')
                    ->where('available_at', '<=', $now)
                    ->orderBy('sort_order', 'asc')
                    ->lockForUpdate()  // 关键：锁定行
                    ->first();
                
                if ($job) {
                    // 检查是否超过最大重试次数
                    if ($job->attempts >= self::MAX_ATTEMPTS) {
                        $this->warn("⚠️  Job #{$job->id} exceeded max attempts ({$job->attempts}), marking as failed");
                        $this->moveJobToFailed($job, new \Exception("Job exceeded maximum attempts ({$job->attempts})"));
                        DB::table('jobs')->where('id', $job->id)->delete();
                        return null;
                    }
                    
                    // 更新任务状态（仍在事务中）
                    DB::table('jobs')
                        ->where('id', $job->id)
                        ->update([
                            'reserved_at' => $now,
                            'attempts' => $job->attempts + 1,
                        ]);
                }
                
                return $job;
            });
        } catch (\Exception $e) {
            Log::error('Failed to get next job from queue', [
                'queue' => $queueName,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return null;
        }
    }
    
    /**
     * 将任务移动到失败队列
     */
    private function moveJobToFailed($job, \Exception $exception)
    {
        DB::table('failed_jobs')->insert([
            'uuid' => \Illuminate\Support\Str::uuid(),
            'connection' => 'database',
            'queue' => $job->queue,
            'payload' => $job->payload,
            'exception' => $exception->getMessage() . "\n" . $exception->getTraceAsString(),
            'failed_at' => now(),
        ]);
        
        Log::warning('Job moved to failed_jobs due to max attempts', [
            'job_id' => $job->id,
            'queue' => $job->queue,
            'attempts' => $job->attempts,
        ]);
    }
    
    /**
     * 处理任务
     */
    private function processJob($job)
    {
        // 标记正在处理任务
        $this->isProcessing = true;
        
        try {
            Log::debug('Processing job', [
                'job_id' => $job->id,
                'queue' => $job->queue,
                'attempts' => $job->attempts,
            ]);
            
            $payload = json_decode($job->payload, true);
            
            if (!$payload) {
                throw new \Exception('Invalid job payload: failed to decode JSON');
            }
            
            if (!isset($payload['data']['command'])) {
                throw new \Exception('Invalid job payload: missing command data');
            }
            
            $command = unserialize($payload['data']['command']);
            
            if (!$command) {
                throw new \Exception('Invalid job payload: failed to unserialize command');
            }
            
            // 创建 DatabaseJob 实例
            $connection = app('queue')->connection('database');
            $jobInstance = new \Illuminate\Queue\Jobs\DatabaseJob(
                app(),
                $connection,
                $job,
                'database',
                $job->queue
            );
            
            // 设置 job 实例到 command
            if (method_exists($command, 'setJob')) {
                $command->setJob($jobInstance);
            }
            
            // 执行任务（使用容器解析依赖）
            app()->call([$command, 'handle']);
            
            // 删除已完成的任务
            DB::table('jobs')->where('id', $job->id)->delete();
            
            $this->line("[" . date('H:i:s') . "] Processed job #{$job->id}");
            
            // 任务处理完成
            $this->isProcessing = false;
            
            return 'success';
            
        } catch (\App\Exceptions\RateLimitException $e) {
            // 服务器超限，将任务放回队列
            $waitSeconds = $e->getWaitSeconds();
            $this->warn("[" . date('H:i:s') . "] Rate limit reached, estimated wait: {$waitSeconds}s");
            
            Log::warning('Worker paused due to rate limit', [
                'reason' => 'rate_limit_worker_pause',
                'job_id' => $job->id,
                'queue' => $job->queue,
                'attempts' => $job->attempts,
                'wait_seconds' => $waitSeconds,
                'message' => $e->getMessage(),
            ]);
            
            // 将任务放回队列（不延迟 available_at）
            // 重要：同时将 attempts 减 1，因为这次不算真正的失败
            DB::table('jobs')
                ->where('id', $job->id)
                ->update([
                    'reserved_at' => null,
                    'attempts' => DB::raw('GREATEST(attempts - 1, 0)'), // 回退 attempts，最小为 0
                ]);
            
            // 任务处理完成（放回队列）
            // 不在这里 sleep，由主循环处理限流状态
            $this->isProcessing = false;
            
            return 'rate_limited';
            
        } catch (\Exception $e) {
            // 任务失败，删除任务
            DB::table('jobs')->where('id', $job->id)->delete();
            
            $this->error("[" . date('H:i:s') . "] Job #{$job->id} failed: {$e->getMessage()}");
            
            // 记录详细的错误日志
            Log::error('Worker job processing failed', [
                'job_id' => $job->id,
                'queue' => $job->queue,
                'attempts' => $job->attempts,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // 任务处理完成（失败）
            $this->isProcessing = false;
            
            return 'failed';
        }
    }

    /**
     * 注册信号处理器，实现优雅退出
     */
    protected function registerSignalHandlers()
    {
        // 检查 PCNTL 扩展是否可用
        if (!extension_loaded('pcntl')) {
            $this->comment("⚠️  PCNTL extension not loaded, graceful shutdown will not work");
            return;
        }

        // 注册 SIGTERM 信号处理器（kill 命令默认发送的信号）
        pcntl_signal(SIGTERM, function ($signal) {
            $this->handleShutdownSignal($signal, 'SIGTERM');
        });

        // 注册 SIGINT 信号处理器（Ctrl+C）
        pcntl_signal(SIGINT, function ($signal) {
            $this->handleShutdownSignal($signal, 'SIGINT');
        });

        // 注册 SIGQUIT 信号处理器
        pcntl_signal(SIGQUIT, function ($signal) {
            $this->handleShutdownSignal($signal, 'SIGQUIT');
        });

        $this->comment("✅ Signal handlers registered (SIGTERM, SIGINT, SIGQUIT)");
    }

    /**
     * 处理关闭信号
     */
    protected function handleShutdownSignal($signal, $signalName)
    {
        if ($this->shouldQuit) {
            // 已经在退出过程中，忽略重复信号
            return;
        }

        $this->shouldQuit = true;
        
        $campaignId = $this->argument('campaign_id');
        
        Log::info("Worker received shutdown signal", [
            'signal' => $signalName,
            'signal_number' => $signal,
            'campaign_id' => $campaignId,
            'pid' => getmypid(),
            'is_processing' => $this->isProcessing,
        ]);

        if ($this->isProcessing) {
            $this->warn("\n🛑 Shutdown signal ({$signalName}) received, will exit after current job completes...");
        } else {
            $this->warn("\n🛑 Shutdown signal ({$signalName}) received, exiting gracefully...");
        }
    }

    /**
     * 在主循环中调用，处理挂起的信号
     */
    protected function checkSignals()
    {
        if (!extension_loaded('pcntl')) {
            return;
        }

        // 调度挂起的信号处理器
        pcntl_signal_dispatch();
    }
    
    /**
     * 尝试将活动标记为完成（原子操作）
     * 
     * 当 Worker 检测到队列为空时调用，作为备用机制
     * 使用原子性 SQL 避免竞态条件
     */
    protected function tryMarkCampaignComplete(int $campaignId, string $queueName): void
    {
        // 原子性更新：单条 SQL 同时检查所有条件
        $affected = DB::update("
            UPDATE campaigns 
            SET status = 'sent', 
                sent_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
            AND status = 'sending'
            AND NOT EXISTS (
                SELECT 1 FROM jobs WHERE queue = ?
            )
            AND (
                SELECT COUNT(*) FROM campaign_sends 
                WHERE campaign_id = ? AND status IN ('sent', 'failed')
            ) >= total_recipients
        ", [$campaignId, $queueName, $campaignId]);
        
        if ($affected > 0) {
            $this->info("✅ Campaign #{$campaignId} marked as completed");
            Log::info('Campaign marked as completed by worker', [
                'campaign_id' => $campaignId,
                'queue' => $queueName,
            ]);
        }
    }
}
