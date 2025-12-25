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
     * Check if the server can send an email with a specific sender
     * Returns true if allowed, false otherwise
     * 
     * @param string|null $fromEmail 发件人邮箱，如果不提供则只检查服务器级别
     */
    public function canSend(?string $fromEmail = null): bool
    {
        // 如果提供了发件人，检查该发件人是否被暂停
        if ($fromEmail && $this->isSenderPaused($fromEmail)) {
            return false;
        }
        
        return $this->checkRateLimits()['can_send'];
    }
    
    /**
     * 检查特定发件人是否被暂停
     * 
     * @param string $fromEmail 发件人邮箱
     * @return bool
     */
    public function isSenderPaused(string $fromEmail): bool
    {
        $cacheKey = $this->getSenderPauseCacheKey($fromEmail);
        return Cache::has($cacheKey);
    }
    
    /**
     * 获取发件人暂停剩余时间（秒）
     * 
     * @param string $fromEmail 发件人邮箱
     * @return int|null
     */
    public function getSenderPauseRemainingTime(string $fromEmail): ?int
    {
        $cacheKey = $this->getSenderPauseCacheKey($fromEmail);
        $pausedUntil = Cache::get($cacheKey);
        
        if (!$pausedUntil) {
            return null;
        }
        
        $remaining = $pausedUntil - time();
        return $remaining > 0 ? $remaining : null;
    }
    
    /**
     * 临时暂停特定发件人
     * 
     * @param string $fromEmail 发件人邮箱
     * @param int $minutes 暂停分钟数
     * @param string $reason 暂停原因
     */
    public function pauseSender(string $fromEmail, int $minutes = 5, string $reason = 'Rate limit exceeded'): void
    {
        $cacheKey = $this->getSenderPauseCacheKey($fromEmail);
        $pausedUntil = time() + ($minutes * 60);
        
        Cache::put($cacheKey, $pausedUntil, $minutes * 60);
        
        \Log::warning('SMTP sender temporarily paused', [
            'server_id' => $this->id,
            'server_name' => $this->name,
            'from_email' => $fromEmail,
            'pause_minutes' => $minutes,
            'paused_until' => date('Y-m-d H:i:s', $pausedUntil),
            'reason' => $reason,
        ]);
    }
    /**
     * 获取所有被暂停的发件人列表
     * 
     * @return array [['email' => '...', 'remaining_seconds' => ...], ...]
     */
    public function getPausedSenders(): array
    {
        $pattern = "smtp_sender_paused_{$this->id}_*";
        $keys = Cache::get('_paused_senders_list_' . $this->id, []);
        
        $pausedSenders = [];
        foreach ($keys as $key) {
            if (Cache::has($key)) {
                // 从 key 中提取邮箱：smtp_sender_paused_{id}_{email_hash}
                $email = Cache::get($key . '_email');
                if ($email) {
                    $remaining = $this->getSenderPauseRemainingTime($email);
                    if ($remaining > 0) {
                        $pausedSenders[] = [
                            'email' => $email,
                            'remaining_seconds' => $remaining,
                        ];
                    }
                }
            }
        }
        
        return $pausedSenders;
    }
    
    /**
     * 生成发件人暂停的缓存键
     * 
     * @param string $fromEmail
     * @return string
     */
    private function getSenderPauseCacheKey(string $fromEmail): string
    {
        // 使用 MD5 hash 避免邮箱中的特殊字符问题
        $emailHash = md5(strtolower(trim($fromEmail)));
        return "smtp_sender_paused_{$this->id}_{$emailHash}";
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
        $queryStart = microtime(true);
        
        // 计算时间窗口的起始时间
        $startTime = now()->subSeconds($duration);
        
        \Log::debug('[SmtpServer] Counting sliding window', [
            'server_id' => $this->id,
            'period' => $period,
            'duration_seconds' => $duration,
            'start_time' => $startTime->toDateTimeString(),
        ]);
        
        // 从 SendLog 表查询此服务器在时间窗口内的发送尝试数（包括成功和失败）
        $count = \App\Models\SendLog::where('smtp_server_id', $this->id)
            ->whereIn('status', ['sent', 'failed'])
            ->where('created_at', '>=', $startTime)
            ->count();
        
        $queryTime = (microtime(true) - $queryStart) * 1000;
        \Log::debug('[SmtpServer] Sliding window count completed', [
            'server_id' => $this->id,
            'period' => $period,
            'count' => $count,
            'time_ms' => round($queryTime, 2),
        ]);
        
        return $count;
    }

    /**
     * Get current rate limit status with detailed information
     */
    public function getRateLimitStatus(): array
    {
        $methodStart = microtime(true);
        \Log::debug('[SmtpServer] getRateLimitStatus started', [
            'server_id' => $this->id,
            'server_name' => $this->name,
        ]);
        
        // Use sliding window counts for accurate rate limiting
        $periods = [
            'second' => $this->countInSlidingWindow('second', 1),
            'minute' => $this->countInSlidingWindow('minute', 60),
            'hour' => $this->countInSlidingWindow('hour', 3600),
            'day' => $this->emails_sent_today,
        ];
        
        $countingTime = (microtime(true) - $methodStart) * 1000;
        \Log::debug('[SmtpServer] Counting periods completed', [
            'server_id' => $this->id,
            'time_ms' => round($countingTime, 2),
            'counts' => $periods,
        ]);

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

        $totalTime = (microtime(true) - $methodStart) * 1000;
        \Log::debug('[SmtpServer] getRateLimitStatus completed', [
            'server_id' => $this->id,
            'total_time_ms' => round($totalTime, 2),
        ]);

        return [
            'periods' => $status,
            'can_send' => $limitCheck['can_send'],
            'blocked_by' => $limitCheck['blocked_by'],
            'max_available' => $limitCheck['available'],
            'most_restrictive' => $mostRestrictive,
            'wait_seconds' => $limitCheck['wait_seconds'],
            'paused_senders' => $this->getPausedSenders(),
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

