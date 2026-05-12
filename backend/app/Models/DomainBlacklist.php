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
     * 添加域名到黑名单
     *
     * 设计：域名黑名单是「过滤器」语义，不修改订阅者数据。
     * - 加入：只插记录，不动 subscribers 表
     * - 发送：在创建任务和发送任务时进行过滤
     * - 移除：下次发送自动恢复，无需任何额外操作
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

        return [
            'created' => $entry->wasRecentlyCreated,
            'entry' => $entry,
        ];
    }

    /**
     * 获取某个用户的所有黑名单域名（数组）
     */
    public static function getBlacklistedDomains(int $userId): array
    {
        return self::where('user_id', $userId)
            ->pluck('domain')
            ->toArray();
    }
}
