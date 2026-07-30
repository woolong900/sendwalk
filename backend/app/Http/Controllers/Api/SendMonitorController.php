<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SendLog;
use App\Models\Campaign;
use App\Models\SmtpServer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SendMonitorController extends Controller
{
    /**
     * Apply time range filter to query
     */
    private function applyTimeRangeFilter($query, $timeRange)
    {
        // If no time range specified or 'all', don't filter
        if (!$timeRange || $timeRange === 'all') {
            return $query;
        }

        $now = now();
        
        switch ($timeRange) {
            case '1m':
                $query->where('created_at', '>=', $now->copy()->subMinute());
                break;
            case '10m':
                $query->where('created_at', '>=', $now->copy()->subMinutes(10));
                break;
            case '30m':
                $query->where('created_at', '>=', $now->copy()->subMinutes(30));
                break;
            case '1h':
                $query->where('created_at', '>=', $now->copy()->subHour());
                break;
            case '1d':
                $query->where('created_at', '>=', $now->copy()->subDay());
                break;
        }

        return $query;
    }

    /**
     * Get real-time send logs (tail-like order: oldest first, newest last)
     */
    public function getLogs(Request $request)
    {
        $query = SendLog::with(['campaign', 'subscriber', 'smtpServer']);

        // Filter by SMTP server
        if ($request->smtp_server_id) {
            $query->where('smtp_server_id', $request->smtp_server_id);
        }

        // Filter by status
        if ($request->status) {
            $query->where('status', $request->status);
        }

        // Filter by time range
        $query = $this->applyTimeRangeFilter($query, $request->time_range);

        // If after_id is provided, get logs created after that ID
        if ($request->after_id) {
            $query->where('id', '>', $request->after_id)
                ->orderBy('id', 'asc');
            
            $logs = $query->limit(100)->get();
        } else {
            // Get recent logs (last 100) in desc order, then reverse for tail effect
            $logs = $query->orderBy('created_at', 'desc')
                ->limit(100)
                ->get()
                ->reverse()
                ->values();
        }

        // 邮箱脱敏处理
        $maskedLogs = $logs->map(function($log) {
            $log->email = maskEmail($log->email);
            return $log;
        });

        return response()->json([
            'data' => $maskedLogs,
        ]);
    }

    /**
     * Get paginated logs for history view
     */
    public function getPaginatedLogs(Request $request)
    {
        $perPage = $request->get('per_page', 50);
        $page = $request->get('page', 1);

        $query = SendLog::with(['campaign', 'subscriber', 'smtpServer'])
            ->orderBy('created_at', 'desc');

        // Filter by SMTP server
        if ($request->smtp_server_id) {
            $query->where('smtp_server_id', $request->smtp_server_id);
        }

        // Filter by status
        if ($request->status) {
            $query->where('status', $request->status);
        }

        // Filter by time range
        $query = $this->applyTimeRangeFilter($query, $request->time_range);

        // Get paginated logs
        $logs = $query->paginate($perPage, ['*'], 'page', $page);

        // 邮箱脱敏处理
        $maskedItems = collect($logs->items())->map(function($log) {
            $log->email = maskEmail($log->email);
            return $log;
        })->all();

        return response()->json([
            'data' => $maskedItems,
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'from' => $logs->firstItem(),
                'to' => $logs->lastItem(),
            ],
        ]);
    }

    /**
     * Get sending statistics
     */
    public function getStats(Request $request)
    {
        $smtpServerId = $request->smtp_server_id;
        $status = $request->status;

        $query = SendLog::query();
        
        if ($smtpServerId) {
            $query->where('smtp_server_id', $smtpServerId);
        }

        if ($status) {
            $query->where('status', $status);
        }

        // Filter by time range
        $query = $this->applyTimeRangeFilter($query, $request->time_range);

        $stats = [
            'total' => $query->count(),
            'sent' => (clone $query)->where('status', 'sent')->count(),
            'failed' => (clone $query)->where('status', 'failed')->count(),
            'success_rate' => 0,
        ];

        if ($stats['total'] > 0) {
            $stats['success_rate'] = round(($stats['sent'] / $stats['total']) * 100, 2);
        }

        return response()->json([
            'data' => $stats,
        ]);
    }

    /**
     * Clear logs
     */
    public function clearLogs(Request $request)
    {
        $smtpServerId = $request->smtp_server_id;
        $status = $request->status;

        $query = SendLog::query();
        
        if ($smtpServerId) {
            // Clear logs for specific SMTP server
            $query->where('smtp_server_id', $smtpServerId);
        }

        if ($status) {
            // Clear logs for specific status
            $query->where('status', $status);
        }

        // Filter by time range
        $query = $this->applyTimeRangeFilter($query, $request->time_range);
        // If no smtp_server_id or status, clear all logs (user triggered)

        $deleted = $query->delete();

        return response()->json([
            'message' => "已清理 {$deleted} 条日志",
            'deleted' => $deleted,
        ]);
    }

    /**
     * Get queue status for active campaigns (each campaign has its own queue)
     */
    public function getQueueStatus(Request $request)
    {
        // Get all campaigns that are currently sending
        $sendingCampaigns = Campaign::where('status', 'sending')
            ->with('smtpServer')
            ->get();
        
        $queues = [];
        
        foreach ($sendingCampaigns as $campaign) {
            $queueName = "campaign_{$campaign->id}";
            
            try {
                // Get queue size from jobs table
                $pending = \DB::table('jobs')
                    ->where('queue', $queueName)
                    ->whereNull('reserved_at')
                    ->count();
                
                $queues[] = [
                    'campaign_id' => $campaign->id,
                    'campaign_name' => $campaign->name,
                    'queue_name' => $queueName,
                    'pending' => $pending,
                    'total' => $pending,
                    'smtp_server_id' => $campaign->smtp_server_id,
                    'smtp_server_name' => $campaign->smtpServer->name ?? 'Unknown',
                    'total_recipients' => $campaign->total_recipients,
                    'total_sent' => $campaign->total_sent,
                ];
            } catch (\Exception $e) {
                $queues[] = [
                    'campaign_id' => $campaign->id,
                    'campaign_name' => $campaign->name,
                    'queue_name' => $queueName,
                    'pending' => 0,
                    'total' => 0,
                    'error' => $e->getMessage(),
                ];
            }
        }
        
        return response()->json([
            'data' => $queues,
        ]);
    }
}

