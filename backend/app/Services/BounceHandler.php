<?php

namespace App\Services;

use App\Models\Subscriber;
use App\Models\Campaign;
use App\Models\Blacklist;
use App\Models\BounceLog;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class BounceHandler
{
    /**
     * 硬退信的 SMTP 错误码（5xx 系列）
     */
    private const HARD_BOUNCE_CODES = [
        '550', // Mailbox not found
        '551', // User not local
        '552', // Exceeded storage allocation
        '553', // Mailbox name not allowed
        '554', // Transaction failed
    ];

    /**
     * 软退信的 SMTP 错误码（4xx 系列）
     */
    private const SOFT_BOUNCE_CODES = [
        '450', // Mailbox busy
        '451', // Local error
        '452', // Insufficient storage
        '453', // Too many recipients
    ];

    /**
     * 软退信阈值配置
     */
    private const SOFT_BOUNCE_THRESHOLD = 3; // 失败次数阈值
    private const SOFT_BOUNCE_WINDOW_DAYS = 7; // 时间窗口（天）

    /**
     * 处理发送失败，检测退信类型并采取相应行动
     *
     * @param string $email
     * @param int $subscriberId
     * @param int $campaignId
     * @param string $errorMessage
     * @param string|null $smtpResponse
     * @return void
     */
    public function handleBounce(
        string $email,
        ?int $subscriberId,
        ?int $campaignId,
        string $errorMessage,
        ?string $smtpResponse = null
    ): void {
        // 提取错误码
        $errorCode = $this->extractErrorCode($errorMessage, $smtpResponse);
        
        // 判断退信类型
        $bounceType = $this->determineBounceType($errorCode, $errorMessage);
        
        Log::info('处理退信', [
            'email' => $email,
            'subscriber_id' => $subscriberId,
            'campaign_id' => $campaignId,
            'bounce_type' => $bounceType,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
        ]);
        
        // 记录退信日志
        $this->logBounce(
            $email,
            $subscriberId,
            $campaignId,
            $bounceType,
            $errorCode,
            $errorMessage,
            $smtpResponse
        );
        
        // 根据退信类型处理
        if ($bounceType === 'hard') {
            $this->handleHardBounce($email, $subscriberId);
        } elseif ($bounceType === 'soft') {
            $this->handleSoftBounce($email, $subscriberId);
        }
    }

    /**
     * 处理硬退信：立即加入黑名单
     */
    private function handleHardBounce(string $email, ?int $subscriberId): void
    {
        Log::info('处理硬退信', [
            'email' => $email,
            'subscriber_id' => $subscriberId,
            'action' => '立即加入黑名单',
        ]);

        // 检查是否已在黑名单
        $exists = Blacklist::where('email', $email)->exists();
        
        if (!$exists) {
            // 添加到黑名单
            Blacklist::create([
                'user_id' => $this->getUserIdFromSubscriber($subscriberId),
                'email' => $email,
                'reason' => 'hard_bounce',
                'notes' => '硬退信：邮箱不存在或永久拒收',
            ]);
            
            Log::info('已将硬退信邮箱加入黑名单', [
                'email' => $email,
            ]);
        }

        // 更新订阅者状态
        if ($subscriberId) {
            Subscriber::where('id', $subscriberId)->update([
                'status' => 'bounced',
                'bounce_count' => \DB::raw('bounce_count + 1'),
                'last_bounce_at' => now(),
            ]);
        }
    }

    /**
     * 处理软退信：累计次数，超过阈值加入黑名单
     */
    private function handleSoftBounce(string $email, ?int $subscriberId): void
    {
        if (!$subscriberId) {
            return;
        }

        $subscriber = Subscriber::find($subscriberId);
        
        if (!$subscriber) {
            return;
        }

        // 增加退信计数
        $subscriber->increment('bounce_count');
        $subscriber->last_bounce_at = now();
        $subscriber->save();

        // 计算时间窗口内的退信次数
        $windowStart = Carbon::now()->subDays(self::SOFT_BOUNCE_WINDOW_DAYS);
        $recentBounces = BounceLog::where('subscriber_id', $subscriberId)
            ->where('bounce_type', 'soft')
            ->where('created_at', '>=', $windowStart)
            ->count();

        Log::info('软退信计数更新', [
            'email' => $email,
            'subscriber_id' => $subscriberId,
            'bounce_count' => $subscriber->bounce_count,
            'recent_bounces' => $recentBounces,
            'threshold' => self::SOFT_BOUNCE_THRESHOLD,
        ]);

        // 检查是否超过阈值
        if ($recentBounces >= self::SOFT_BOUNCE_THRESHOLD) {
            Log::warning('软退信超过阈值，加入黑名单', [
                'email' => $email,
                'subscriber_id' => $subscriberId,
                'recent_bounces' => $recentBounces,
                'window_days' => self::SOFT_BOUNCE_WINDOW_DAYS,
            ]);

            // 检查是否已在黑名单
            $exists = Blacklist::where('email', $email)->exists();
            
            if (!$exists) {
                // 添加到黑名单
                Blacklist::create([
                    'user_id' => $subscriber->lists()->first()?->user_id ?? 1, // 尝试获取用户ID
                    'email' => $email,
                    'reason' => 'soft_bounce',
                    'notes' => "{$recentBounces}次软退信（{self::SOFT_BOUNCE_WINDOW_DAYS}天内）",
                ]);
                
                Log::info('已将软退信邮箱加入黑名单', [
                    'email' => $email,
                    'bounce_count' => $recentBounces,
                ]);
            }

            // 更新订阅者状态为 bounced
            $subscriber->status = 'bounced';
            $subscriber->save();
        }
    }

    /**
     * 记录退信日志
     */
    private function logBounce(
        string $email,
        ?int $subscriberId,
        ?int $campaignId,
        string $bounceType,
        ?string $errorCode,
        string $errorMessage,
        ?string $smtpResponse
    ): void {
        BounceLog::create([
            'subscriber_id' => $subscriberId,
            'campaign_id' => $campaignId,
            'email' => $email,
            'bounce_type' => $bounceType,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'smtp_response' => $smtpResponse,
        ]);
    }

    /**
     * 从错误消息中提取 SMTP 错误码
     */
    private function extractErrorCode(string $errorMessage, ?string $smtpResponse): ?string
    {
        $text = $smtpResponse ?: $errorMessage;
        
        // 尝试匹配 5xx 或 4xx 错误码
        if (preg_match('/\b([45]\d{2})\b/', $text, $matches)) {
            return $matches[1];
        }
        
        return null;
    }

    /**
     * 判断退信类型
     */
    private function determineBounceType(?string $errorCode, string $errorMessage): string
    {
        // 如果有错误码，根据错误码判断
        if ($errorCode) {
            // 5xx 是硬退信
            if (str_starts_with($errorCode, '5')) {
                return 'hard';
            }
            
            // 4xx 是软退信
            if (str_starts_with($errorCode, '4')) {
                return 'soft';
            }
        }
        
        // 没有错误码，根据错误消息关键词判断
        $hardBounceKeywords = [
            'not found',
            'does not exist',
            'unknown user',
            'invalid recipient',
            'no such user',
            'user unknown',
            'mailbox unavailable',
            'address rejected',
        ];
        
        $lowerMessage = strtolower($errorMessage);
        
        foreach ($hardBounceKeywords as $keyword) {
            if (str_contains($lowerMessage, $keyword)) {
                return 'hard';
            }
        }
        
        // 默认视为软退信
        return 'soft';
    }

    /**
     * 从订阅者获取用户ID
     */
    private function getUserIdFromSubscriber(?int $subscriberId): int
    {
        if (!$subscriberId) {
            return 1; // 默认用户ID
        }

        $subscriber = Subscriber::find($subscriberId);
        
        if (!$subscriber) {
            return 1;
        }

        // 尝试从订阅者的列表中获取用户ID
        $list = $subscriber->lists()->first();
        
        return $list?->user_id ?? 1;
    }
}

