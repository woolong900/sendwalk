<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DomainBlacklist extends Model
{
    use HasFactory;

    protected $table = 'domain_blacklist';

    protected $fillable = [
        'user_id',
        'domain',
        'reason',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 从邮箱地址中提取域名（小写）
     */
    public static function extractDomain(string $email): ?string
    {
        $email = strtolower(trim($email));
        if (!str_contains($email, '@')) {
            return null;
        }
        $domain = substr($email, strpos($email, '@') + 1);
        return $domain ?: null;
    }

    /**
     * 标准化域名（小写，去掉前后空格）
     */
    public static function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        // 如果传入的是邮箱，提取域名部分
        if (str_contains($domain, '@')) {
            $domain = substr($domain, strpos($domain, '@') + 1);
        }
        return $domain;
    }

    /**
     * 检查某个邮箱的域名是否在黑名单中
     */
    public static function isDomainBlacklisted(int $userId, string $email): bool
    {
        $domain = self::extractDomain($email);
        if (!$domain) {
            return false;
        }
        return self::where('user_id', $userId)
            ->where('domain', $domain)
            ->exists();
    }

    /**
     * 添加域名到黑名单，并更新该域名下所有订阅者状态
     */
    public static function addDomain(int $userId, string $domain, ?string $reason = null): array
    {
        $domain = self::normalizeDomain($domain);

        if (empty($domain) || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $domain)) {
            return [
                'created' => false,
                'error' => '域名格式无效',
            ];
        }

        $entry = self::firstOrCreate(
            ['user_id' => $userId, 'domain' => $domain],
            ['reason' => $reason]
        );

        // 把该域名下所有订阅者状态改为 blacklisted
        $updatedCount = self::blacklistSubscribersByDomain($userId, $domain);

        return [
            'created' => $entry->wasRecentlyCreated,
            'entry' => $entry,
            'subscribers_updated' => $updatedCount,
        ];
    }

    /**
     * 把某个域名下所有订阅者状态置为 blacklisted
     */
    public static function blacklistSubscribersByDomain(int $userId, string $domain): int
    {
        $domain = self::normalizeDomain($domain);

        // 查询该域名下所有订阅者
        $subscriberIds = Subscriber::where('email', 'like', "%@{$domain}")
            ->pluck('id');

        if ($subscriberIds->isEmpty()) {
            return 0;
        }

        // 更新订阅者状态
        $updated = Subscriber::whereIn('id', $subscriberIds)
            ->where('status', '!=', 'blacklisted')
            ->update(['status' => 'blacklisted']);

        // 更新订阅者-列表关系状态
        \DB::table('list_subscriber')
            ->whereIn('subscriber_id', $subscriberIds)
            ->where('status', '!=', 'blacklisted')
            ->update(['status' => 'blacklisted']);

        return $updated;
    }
}
