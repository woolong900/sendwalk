<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Subscriber;
use App\Models\MailingList;
use App\Models\SendLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function stats(Request $request)
    {
        $userId = $request->user()->id;

        // 使用缓存（60秒过期，减轻数据库压力）
        $cacheKey = "dashboard_stats_{$userId}";
        
        return response()->json([
            'data' => \Cache::remember($cacheKey, 60, function () use ($userId) {
                // 只查询活动状态统计（用于显示活动状态卡片）
                $campaignStats = Campaign::where('user_id', $userId)
                    ->selectRaw('
                        SUM(CASE WHEN status = "sending" THEN 1 ELSE 0 END) as sending_count,
                        SUM(CASE WHEN status = "scheduled" THEN 1 ELSE 0 END) as scheduled_count,
                        SUM(CASE WHEN status = "sent" THEN 1 ELSE 0 END) as completed_count,
                        SUM(CASE WHEN status = "draft" THEN 1 ELSE 0 END) as draft_count
                    ')
                    ->first();

                return [
                    // 前端不再需要这些字段，但为了兼容性保留（返回0）
                    'total_subscribers' => 0,
                    'total_campaigns' => 0,
                    'total_sent' => 0,
                    'avg_open_rate' => 0,
                    // 以下是仍然需要的数据
                    'send_stats' => $this->getSendStatsOptimized($userId),
                    'queue_length' => $this->getQueueLength(),
                    'campaign_status_stats' => [
                        'sending' => $campaignStats->sending_count ?? 0,
                        'scheduled' => $campaignStats->scheduled_count ?? 0,
                        'completed' => $campaignStats->completed_count ?? 0,
                        'draft' => $campaignStats->draft_count ?? 0,
                    ],
                    'smtp_server_stats' => $this->getSmtpServerStatsOptimized($userId),
                    'worker_count' => $this->getWorkerCount(),
                    'scheduler_running' => $this->getSchedulerStatus(),
                ];
            }),
        ]);
    }
    
    // 此方法已被优化，合并到主查询中
    private function getCampaignStatusStats($userId)
    {
        // 已废弃 - 现在在 stats() 方法中直接查询
        $result = Campaign::where('user_id', $userId)
            ->selectRaw('
                SUM(CASE WHEN status = "sending" THEN 1 ELSE 0 END) as sending,
                SUM(CASE WHEN status = "scheduled" THEN 1 ELSE 0 END) as scheduled,
                SUM(CASE WHEN status = "sent" THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = "draft" THEN 1 ELSE 0 END) as draft
            ')
            ->first();
            
        return [
            'sending' => $result->sending ?? 0,
            'scheduled' => $result->scheduled ?? 0,
            'completed' => $result->completed ?? 0,
            'draft' => $result->draft ?? 0,
        ];
    }
    
    private function getSmtpServerStats($userId)
    {
        $servers = \App\Models\SmtpServer::where('user_id', $userId)->get();
        
        return [
            'total' => $servers->count(),
            'active' => $servers->where('is_active', true)->count(),
            'inactive' => $servers->where('is_active', false)->count(),
        ];
    }
    
    // 优化版本：使用单次查询
    private function getSmtpServerStatsOptimized($userId)
    {
        $result = \App\Models\SmtpServer::where('user_id', $userId)
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive
            ')
            ->first();
        
        return [
            'total' => $result->total ?? 0,
            'active' => $result->active ?? 0,
            'inactive' => $result->inactive ?? 0,
        ];
    }
    
    private function getSendStats($userId)
    {
        // 获取用户的活动ID
        $campaignIds = Campaign::where('user_id', $userId)->pluck('id');

        $timeRanges = [
            '1min' => now()->subMinute(),
            '10min' => now()->subMinutes(10),
            '30min' => now()->subMinutes(30),
            '1hour' => now()->subHour(),
            '1day' => now()->subDay(),
        ];

        $stats = [];

        foreach ($timeRanges as $key => $startTime) {
            $sent = SendLog::whereIn('campaign_id', $campaignIds)
                ->where('status', 'sent')
                ->where('created_at', '>=', $startTime)
                ->count();

            $failed = SendLog::whereIn('campaign_id', $campaignIds)
                ->where('status', 'failed')
                ->where('created_at', '>=', $startTime)
                ->count();

            $stats[$key] = [
                'sent' => $sent,
                'failed' => $failed,
                'total' => $sent + $failed,
            ];
        }

        return $stats;
    }
    
    // 优化版本：避免 JOIN，直接使用 campaign_id IN (...) 
    // 🔥 关键优化：
    // 1. 先查 campaign_ids，再用 IN 子句查 send_logs
    // 2. 在 WHERE 中限制 created_at，利用索引 idx_campaign_status_created
    private function getSendStatsOptimized($userId)
    {
        // 先获取用户的活动ID列表（通常很小，几十到几百个）
        $campaignIds = Campaign::where('user_id', $userId)->pluck('id')->toArray();
        
        // 如果没有活动，直接返回空结果
        if (empty($campaignIds)) {
            return [
                '1min' => ['sent' => 0, 'failed' => 0, 'total' => 0],
                '10min' => ['sent' => 0, 'failed' => 0, 'total' => 0],
                '30min' => ['sent' => 0, 'failed' => 0, 'total' => 0],
                '1hour' => ['sent' => 0, 'failed' => 0, 'total' => 0],
                '1day' => ['sent' => 0, 'failed' => 0, 'total' => 0],
            ];
        }
        
        $timeRanges = [
            '1min' => now()->subMinute(),
            '10min' => now()->subMinutes(10),
            '30min' => now()->subMinutes(30),
            '1hour' => now()->subHour(),
            '1day' => now()->subDay(),
        ];

        // 🔥 优化：
        // - 使用 whereIn(campaign_id) 代替 JOIN
        // - 在 WHERE 中限制 created_at >= 1天前，大幅减少扫描范围
        // - 可以利用索引 idx_campaign_status_created (campaign_id, status, created_at)
        $result = DB::table('send_logs')
            ->whereIn('campaign_id', $campaignIds)
            ->where('created_at', '>=', $timeRanges['1day']) // 只扫描最近1天
            ->selectRaw("
                SUM(CASE WHEN status = 'sent' AND created_at >= ? THEN 1 ELSE 0 END) as sent_1min,
                SUM(CASE WHEN status = 'failed' AND created_at >= ? THEN 1 ELSE 0 END) as failed_1min,
                SUM(CASE WHEN status = 'sent' AND created_at >= ? THEN 1 ELSE 0 END) as sent_10min,
                SUM(CASE WHEN status = 'failed' AND created_at >= ? THEN 1 ELSE 0 END) as failed_10min,
                SUM(CASE WHEN status = 'sent' AND created_at >= ? THEN 1 ELSE 0 END) as sent_30min,
                SUM(CASE WHEN status = 'failed' AND created_at >= ? THEN 1 ELSE 0 END) as failed_30min,
                SUM(CASE WHEN status = 'sent' AND created_at >= ? THEN 1 ELSE 0 END) as sent_1hour,
                SUM(CASE WHEN status = 'failed' AND created_at >= ? THEN 1 ELSE 0 END) as failed_1hour,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent_1day,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_1day
            ", [
                $timeRanges['1min'], $timeRanges['1min'],
                $timeRanges['10min'], $timeRanges['10min'],
                $timeRanges['30min'], $timeRanges['30min'],
                $timeRanges['1hour'], $timeRanges['1hour'],
            ])
            ->first();

        return [
            '1min' => [
                'sent' => $result->sent_1min ?? 0,
                'failed' => $result->failed_1min ?? 0,
                'total' => ($result->sent_1min ?? 0) + ($result->failed_1min ?? 0),
            ],
            '10min' => [
                'sent' => $result->sent_10min ?? 0,
                'failed' => $result->failed_10min ?? 0,
                'total' => ($result->sent_10min ?? 0) + ($result->failed_10min ?? 0),
            ],
            '30min' => [
                'sent' => $result->sent_30min ?? 0,
                'failed' => $result->failed_30min ?? 0,
                'total' => ($result->sent_30min ?? 0) + ($result->failed_30min ?? 0),
            ],
            '1hour' => [
                'sent' => $result->sent_1hour ?? 0,
                'failed' => $result->failed_1hour ?? 0,
                'total' => ($result->sent_1hour ?? 0) + ($result->failed_1hour ?? 0),
            ],
            '1day' => [
                'sent' => $result->sent_1day ?? 0,
                'failed' => $result->failed_1day ?? 0,
                'total' => ($result->sent_1day ?? 0) + ($result->failed_1day ?? 0),
            ],
        ];
    }

    private function getQueueLength()
    {
        try {
            // 从 MySQL jobs 表直接统计
            // 包括所有未处理的任务（reserved_at = NULL）
            $total = DB::table('jobs')
                ->whereNull('reserved_at')
                ->count();
            
            return $total;
        } catch (\Exception $e) {
            // 如果获取失败，返回 0
            \Illuminate\Support\Facades\Log::warning('Failed to get queue length', [
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }
    
    private function getWorkerCount()
    {
        try {
            // 统计正在运行的 PHP Worker 进程数量（排除 bash 包装器）
            // 新架构使用 campaign:process-queue 命令
            $output = shell_exec("ps aux | grep 'campaign:process-queue' | grep -v grep | grep -v bash | wc -l");
            return (int)trim($output);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to get worker count', [
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }
    
    private function getSchedulerStatus()
    {
        try {
            // 检查调度器进程是否在运行
            $output = shell_exec("ps aux | grep 'artisan schedule:work' | grep -v grep | wc -l");
            return (int)trim($output) > 0;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to get scheduler status', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
    
    /**
     * 启动调度器
     */
    public function startScheduler(Request $request)
    {
        try {
            // 检查调度器是否已在运行
            if ($this->getSchedulerStatus()) {
                // 如果已在运行，直接返回成功（而不是错误）
                return response()->json([
                    'message' => '调度器已在运行中',
                    'running' => true,
                ]);
            }
            
            // 启动调度器（使用后台脚本）
            $logFile = base_path('storage/logs/scheduler.log');
            
            // 确保日志目录存在
            $logDir = dirname($logFile);
            if (!file_exists($logDir)) {
                mkdir($logDir, 0755, true);
            }
            
            // 使用简单的后台启动脚本（立即返回）
            $scriptPath = base_path('start_scheduler.sh');
            
            // 如果脚本不存在，创建它
            if (!file_exists($scriptPath)) {
                $scriptContent = "#!/bin/bash\n";
                $scriptContent .= "cd " . base_path() . "\n";
                $scriptContent .= "nohup php artisan schedule:work > {$logFile} 2>&1 &\n";
                $scriptContent .= "echo $!\n";
                file_put_contents($scriptPath, $scriptContent);
                chmod($scriptPath, 0755);
            }
            
            // 执行脚本（立即返回）
            $output = shell_exec("bash {$scriptPath}");
            $pid = $output ? (int)trim($output) : null;
            
            \Log::info('Scheduler start script executed', [
                'user_id' => $request->user()->id,
                'pid' => $pid,
            ]);
            
            // 短暂等待
            usleep(500000); // 0.5 秒
            
            // 验证进程
            $running = $this->getSchedulerStatus();
            
            return response()->json([
                'message' => $running ? '调度器启动成功' : '调度器启动命令已执行',
                'pid' => $pid,
                'running' => $running,
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to start scheduler', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => '启动失败: ' . $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * 停止调度器
     */
    public function stopScheduler(Request $request)
    {
        try {
            // 检查调度器是否在运行
            if (!$this->getSchedulerStatus()) {
                // 如果未运行，直接返回成功（而不是错误）
                return response()->json([
                    'message' => '调度器未在运行',
                    'running' => false,
                ]);
            }
            
            // 获取调度器进程ID
            $pids = shell_exec("ps aux | grep 'artisan schedule:work' | grep -v grep | awk '{print $2}'");
            
            if ($pids) {
                $pidArray = array_filter(explode("\n", trim($pids)));
                
                foreach ($pidArray as $pid) {
                    $pid = trim($pid);
                    if ($pid) {
                        // 优雅停止
                        shell_exec("kill {$pid} 2>/dev/null");
                    }
                }
                
                // 等待进程退出
                sleep(2);
                
                // 检查是否还有进程，如果有则强制终止
                $remaining = shell_exec("ps aux | grep 'artisan schedule:work' | grep -v grep | awk '{print $2}'");
                if ($remaining) {
                    $remainingArray = array_filter(explode("\n", trim($remaining)));
                    foreach ($remainingArray as $pid) {
                        $pid = trim($pid);
                        if ($pid) {
                            shell_exec("kill -9 {$pid} 2>/dev/null");
                        }
                    }
                }
                
                \Log::info('Scheduler stopped', [
                    'user_id' => $request->user()->id,
                    'pids' => $pidArray,
                ]);
                
                sleep(1);
                $running = $this->getSchedulerStatus();
                
                return response()->json([
                    'message' => '调度器已停止',
                    'running' => $running,
                ]);
            } else {
                return response()->json([
                    'message' => '未找到调度器进程',
                ], 404);
            }
        } catch (\Exception $e) {
            \Log::error('Failed to stop scheduler', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => '停止失败: ' . $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * 清空所有队列
     */
    public function clearQueue(Request $request)
    {
        try {
            // 获取所有未处理的任务
            $pendingJobs = DB::table('jobs')
                ->whereNull('reserved_at')
                ->get();
            
            if ($pendingJobs->isEmpty()) {
                return response()->json([
                    'message' => '队列已经是空的',
                    'deleted_count' => 0,
                    'cancelled_campaigns' => 0,
                ], 200);
            }
            
            // 提取所有受影响的活动ID
            $campaignIds = [];
            foreach ($pendingJobs as $job) {
                // 队列名称格式: campaign_{campaign_id}
                if (preg_match('/campaign_(\d+)/', $job->queue, $matches)) {
                    $campaignIds[] = (int)$matches[1];
                }
            }
            
            // 去重
            $campaignIds = array_unique($campaignIds);
            
            // 将这些活动的状态改为 cancelled
            $cancelledCount = 0;
            if (!empty($campaignIds)) {
                $cancelledCount = Campaign::whereIn('id', $campaignIds)
                    ->whereIn('status', ['sending', 'scheduled', 'paused']) // 只取消这些状态的活动
                    ->update([
                        'status' => 'cancelled',
                        'scheduled_at' => null,
                    ]);
            }
            
            // 删除所有未处理的任务
            $deletedCount = DB::table('jobs')
                ->whereNull('reserved_at')
                ->delete();
            
            // 记录日志
            \Log::info('Queue cleared by user', [
                'user_id' => $request->user()->id,
                'deleted_count' => $deletedCount,
                'cancelled_campaigns' => $cancelledCount,
                'campaign_ids' => $campaignIds,
            ]);
            
            return response()->json([
                'message' => "已清空 {$deletedCount} 个队列任务，取消了 {$cancelledCount} 个活动",
                'deleted_count' => $deletedCount,
                'cancelled_campaigns' => $cancelledCount,
                'campaign_ids' => $campaignIds,
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to clear queue', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => '清空队列失败: ' . $e->getMessage(),
            ], 500);
        }
    }
}

