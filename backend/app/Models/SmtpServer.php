<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SmtpServer extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'type',
        'host',
        'port',
        'username',
        'password',
        'encryption',
        'sender_emails',
        'sender_email_index',
        'credentials',
        'is_default',
        'is_active',
        'rate_limit_second',
        'rate_limit_minute',
        'rate_limit_hour',
        'rate_limit_day',
        'emails_sent_today',
        'last_reset_date',
    ];

    protected $casts = [
        'credentials' => 'array',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'last_reset_date' => 'date',
    ];

    protected $hidden = [
        'password',
        'credentials',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if the server can send an email
     * Returns true if allowed, false otherwise
     */
    public function canSend(): bool
    {
        return $this->checkRateLimits()['can_send'];
    }

    /**
     * Check rate limits and return detailed information
     * Returns: [
     *   'can_send' => bool,
     *   'blocked_by' => string|null,  // Which limit is blocking (if any)
     *   'available' => int|null,      // Max emails available across all limits
     *   'wait_seconds' => int|null,   // Suggested wait time if blocked
     * ]
     */
    public function checkRateLimits(): array
    {
        if (!$this->is_active) {
            return [
                'can_send' => false,
                'blocked_by' => 'inactive',
                'available' => 0,
                'wait_seconds' => null,
            ];
        }

        // Reset daily counter if needed
        if ($this->last_reset_date != now()->toDateString()) {
            $this->update([
                'emails_sent_today' => 0,
                'last_reset_date' => now()->toDateString(),
            ]);
        }

        // Define all rate limit checks
        $checks = [
            'second' => ['limit' => $this->rate_limit_second, 'ttl' => 1],
            'minute' => ['limit' => $this->rate_limit_minute, 'ttl' => 60],
            'hour' => ['limit' => $this->rate_limit_hour, 'ttl' => 3600],
            'day' => ['limit' => $this->rate_limit_day, 'ttl' => 86400],
        ];

        $available = PHP_INT_MAX; // Start with unlimited
        $blockedBy = null;
        $waitSeconds = 0;

        foreach ($checks as $period => $config) {
            if (!$config['limit']) {
                continue; // No limit set for this period
            }

            // Get current count for this period using sliding window
            if ($period === 'day') {
                $current = $this->emails_sent_today;
            } else {
                // Use sliding window count
                $current = $this->countInSlidingWindow($period, $config['ttl']);
            }

            // Calculate available capacity
            $periodAvailable = $config['limit'] - $current;

            // Check if this period is blocking
            if ($periodAvailable <= 0) {
                $blockedBy = $period;
                $waitSeconds = max($waitSeconds, $config['ttl']);
                $available = 0;
                break; // Blocked, no need to check further
            }

            // Track the most restrictive limit
            $available = min($available, $periodAvailable);
        }

        return [
            'can_send' => $blockedBy === null,
            'blocked_by' => $blockedBy,
            'available' => $available === PHP_INT_MAX ? null : $available,
            'wait_seconds' => $blockedBy ? $waitSeconds : null,
        ];
    }

    /**
     * Record that an email was sent and update rate limit counters
     * 只更新天级别计数器，短期限制由 SendLog 表查询
     */
    public function recordSent(): void
    {
        // Update daily counter in database
        $this->increment('emails_sent_today');
        
        // 注意：秒/分钟/小时级别的统计现在直接从 SendLog 表查询
        // 不再使用 Cache 滑动窗口，保证数据准确性
    }

    /**
     * Count emails sent in the sliding window
     * 改用 SendLog 表查询，保证数据准确性
     * 统计 sent + failed，因为失败也占用服务器限额
     */
    private function countInSlidingWindow(string $period, int $duration): int
    {
        // 计算时间窗口的起始时间
        $startTime = now()->subSeconds($duration);
        
        // 从 SendLog 表查询此服务器在时间窗口内的发送尝试数（包括成功和失败）
        return \App\Models\SendLog::where('smtp_server_id', $this->id)
            ->whereIn('status', ['sent', 'failed'])
            ->where('created_at', '>=', $startTime)
            ->count();
    }

    /**
     * Get current rate limit status with detailed information
     */
    public function getRateLimitStatus(): array
    {
        // Use sliding window counts for accurate rate limiting
        $periods = [
            'second' => $this->countInSlidingWindow('second', 1),
            'minute' => $this->countInSlidingWindow('minute', 60),
            'hour' => $this->countInSlidingWindow('hour', 3600),
            'day' => $this->emails_sent_today,
        ];

        $status = [];
        $mostRestrictive = null;
        $minAvailable = PHP_INT_MAX;

        foreach ($periods as $period => $current) {
            $limit = $this->{"rate_limit_$period"};
            $available = $limit ? $limit - $current : null;
            $percentage = $limit ? round(($current / $limit) * 100, 1) : 0;

            $status[$period] = [
                'limit' => $limit,
                'current' => $current,
                'available' => $available,
                'percentage' => $percentage,
                'status' => $this->getLimitStatus($current, $limit),
            ];

            // Track most restrictive limit
            if ($limit && $available !== null && $available < $minAvailable) {
                $minAvailable = $available;
                $mostRestrictive = $period;
            }
        }

        // Add overall check
        $limitCheck = $this->checkRateLimits();

        return [
            'periods' => $status,
            'can_send' => $limitCheck['can_send'],
            'blocked_by' => $limitCheck['blocked_by'],
            'max_available' => $limitCheck['available'],
            'most_restrictive' => $mostRestrictive,
            'wait_seconds' => $limitCheck['wait_seconds'],
        ];
    }

    /**
     * Get status indicator for a limit
     */
    private function getLimitStatus(int $current, ?int $limit): string
    {
        if (!$limit) {
            return 'unlimited';
        }

        $percentage = ($current / $limit) * 100;

        if ($percentage >= 100) {
            return 'exceeded';
        } elseif ($percentage >= 90) {
            return 'critical';
        } elseif ($percentage >= 70) {
            return 'warning';
        } else {
            return 'normal';
        }
    }
}

