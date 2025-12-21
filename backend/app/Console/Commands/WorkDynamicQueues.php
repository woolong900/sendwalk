<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Campaign;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;

class WorkDynamicQueues extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'queue:work-dynamic
                            {--sleep=3 : Number of seconds to sleep when no job is available}
                            {--tries=1 : Number of times to attempt a job before logging it failed}
                            {--timeout=60 : The number of seconds a child process can run}
                            {--memory=128 : The memory limit in megabytes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process queue jobs from dynamically discovered queues based on sending campaigns';

    private $lastQueueRefresh = 0;
    private $queueRefreshInterval = 30; // Refresh queue list every 30 seconds
    private $currentQueues = [];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🚀 Starting Dynamic Queue Worker...');
        $this->info('💡 Worker will run continuously until stopped or memory limit reached');
        $this->info('');
        
        $processedJobs = 0;
        $sleep = $this->option('sleep');
        $tries = $this->option('tries');
        $timeout = $this->option('timeout');
        $memoryLimit = $this->option('memory') * 1024 * 1024; // Convert MB to bytes

        // Run continuously
        while (true) {
            // Refresh queue list periodically
            if (time() - $this->lastQueueRefresh >= $this->queueRefreshInterval) {
                $this->refreshQueues();
            }

            // If no queues to monitor, sleep and continue
            if (empty($this->currentQueues)) {
                $this->comment('⏸️  No active campaigns, sleeping...');
                sleep($sleep);
                continue;
            }

            // Try to get a job from any of the active queues
            $job = $this->getNextJob();

            if ($job) {
                try {
                    // Process the job
                    $this->processJob($job, $timeout);
                    $processedJobs++;
                    
                    $this->info("✅ Processed job #{$processedJobs} from queue: {$job->queue}");
                } catch (\Exception $e) {
                    $this->error("❌ Job failed: {$e->getMessage()}");
                    
                    // Mark job as failed if tries exceeded
                    if ($job->attempts >= $tries) {
                        $this->failJob($job, $e);
                        DB::table('jobs')->where('id', $job->id)->delete();
                    } else {
                        // Increment attempts
                        DB::table('jobs')
                            ->where('id', $job->id)
                            ->update([
                                'attempts' => $job->attempts + 1,
                                'reserved_at' => null,
                            ]);
                    }
                }
            } else {
                // No jobs available, sleep
                sleep($sleep);
            }

            // Check for memory leaks
            $currentMemory = memory_get_usage(true);
            if ($currentMemory > $memoryLimit) {
                $memoryMB = round($currentMemory / 1024 / 1024, 2);
                $this->warn("⚠️  Memory limit exceeded ({$memoryMB}MB), restarting worker...");
                break;
            }

            // Log memory usage every 100 jobs
            if ($processedJobs > 0 && $processedJobs % 100 === 0) {
                $memoryMB = round($currentMemory / 1024 / 1024, 2);
                $this->comment("📊 Processed {$processedJobs} jobs, Memory: {$memoryMB}MB");
            }
        }

        $this->info('');
        $this->info("🏁 Worker stopped after processing {$processedJobs} jobs");
    }

    /**
     * Refresh the list of queues to monitor
     */
    private function refreshQueues()
    {
        // Get SMTP server IDs from campaigns that are currently sending
        $sendingCampaignServerIds = Campaign::where('status', 'sending')
            ->whereNotNull('smtp_server_id')
            ->pluck('smtp_server_id')
            ->unique()
            ->toArray();

        $newQueues = array_map(fn($id) => "smtp_{$id}", $sendingCampaignServerIds);
        $newQueues[] = 'default'; // Always include default queue

        // Only log if queues changed
        if ($newQueues !== $this->currentQueues) {
            $this->currentQueues = $newQueues;
            $this->lastQueueRefresh = time();
            
            $queueList = implode(', ', $this->currentQueues);
            $this->info("🔄 Updated queue list: [{$queueList}]");
        } else {
            $this->lastQueueRefresh = time();
        }
    }

    /**
     * Get the next available job from any active queue
     */
    private function getNextJob()
    {
        // Get the next job from any of the active queues
        // Ordered by sort_order for interleaved execution
        $job = DB::table('jobs')
            ->whereIn('queue', $this->currentQueues)
            ->whereNull('reserved_at')
            ->where('available_at', '<=', time())
            ->orderBy('sort_order', 'asc')
            ->first();

        if ($job) {
            // Reserve the job
            DB::table('jobs')
                ->where('id', $job->id)
                ->update([
                    'reserved_at' => time(),
                    'attempts' => $job->attempts + 1,
                ]);

            return $job;
        }

        return null;
    }

    /**
     * Process a job
     */
    private function processJob($job, $timeout)
    {
        $payload = json_decode($job->payload, true);
        $command = unserialize($payload['data']['command']);

        try {
            // Create a proper DatabaseJob instance for the command
            // This allows the command to call $this->release()
            $connection = app('queue')->connection('database');
            $jobInstance = new \Illuminate\Queue\Jobs\DatabaseJob(
                app(),
                $connection,
                $job,
                'database',
                $job->queue
            );
            
            // Set the job instance on the command
            if (method_exists($command, 'setJob')) {
                $command->setJob($jobInstance);
            }
            
            // Execute the job with dependency injection
            app()->call([$command, 'handle']);

            // Check if job was released or deleted
            $updatedJob = DB::table('jobs')->where('id', $job->id)->first();
            
            if (!$updatedJob) {
                // Job was deleted (completed successfully)
                return;
            }
            
            if ($updatedJob->reserved_at === null) {
                // Job was released, will be picked up again later
                $this->info("⏸️  Job #{$job->id} was released");
                return;
            }
            
            // Job completed successfully, delete it
            DB::table('jobs')->where('id', $job->id)->delete();
            
        } catch (\Exception $e) {
            // Job failed, mark as failed
            $this->error("❌ Job #{$job->id} failed: " . $e->getMessage());
            $this->failJob($job, $e);
            
            // Delete the failed job from queue
            DB::table('jobs')->where('id', $job->id)->delete();
        }
    }

    /**
     * Mark a job as failed
     */
    private function failJob($job, \Exception $exception)
    {
        DB::table('failed_jobs')->insert([
            'uuid' => \Illuminate\Support\Str::uuid(),
            'connection' => 'database',
            'queue' => $job->queue,
            'payload' => $job->payload,
            'exception' => (string) $exception,
            'failed_at' => now(),
        ]);
    }
}

