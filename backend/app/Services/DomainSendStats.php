<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Redis;

/**
 * 仪表盘「今日域名发信量」的 Redis 预聚合服务
 *
 * 设计：每发送一封邮件就 HINCRBY 一次（O(1)），dashboard 直接 HGETALL（O(N) N=域名数）
 * 完全脱离 send_logs 大表，永远秒级响应。
 *
 * Redis 结构：
 *   key  : dashboard:domain_send:{userId}:{YYYY-MM-DD}
 *   field: {domain}|sent  /  {domain}|failed
 *   value: int (计数)
 *   TTL  : 48 小时（足够跨过当晚 0 点 + 一天，自动清理）
 */
class DomainSendStats
{
    /**
     * 构造 Redis key
     */
    public static function key(int $userId, ?Carbon $date = null): string
    {
        $date = $date ?: Carbon::today();
        return "dashboard:domain_send:{$userId}:" . $date->toDateString();
    }

    /**
     * 从邮箱中提取小写域名
     */
    public static function extractDomain(string $email): ?string
    {
        $email = strtolower(trim($email));
        $pos = strpos($email, '@');
        if ($pos === false) {
            return null;
        }
        $domain = substr($email, $pos + 1);
        return $domain !== '' ? $domain : null;
    }

    /**
     * 记录一次成功发送
     */
    public static function incrSent(int $userId, string $fromEmail): void
    {
        self::incr($userId, $fromEmail, 'sent');
    }

    /**
     * 记录一次失败发送
     */
    public static function incrFailed(int $userId, string $fromEmail): void
    {
        self::incr($userId, $fromEmail, 'failed');
    }

    /**
     * 通用增量
     */
    private static function incr(int $userId, string $fromEmail, string $type): void
    {
        $domain = self::extractDomain($fromEmail);
        if ($domain === null) {
            return;
        }
        try {
            $key = self::key($userId);
            $field = "{$domain}|{$type}";
            $newVal = (int) Redis::hincrby($key, $field, 1);
            // 第一次写入时设置 TTL（48h）
            if ($newVal === 1) {
                Redis::expire($key, 48 * 3600);
            }
        } catch (\Throwable $e) {
            // Redis 不可用时静默失败，不影响发送主流程
            \Log::warning('DomainSendStats incr failed', [
                'user_id' => $userId,
                'from_email' => $fromEmail,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 读取某用户今日所有域名的统计，返回前 $limit 个（按 sent 倒序）
     *
     * @return array<int, array{domain:string, sent:int, failed:int, total:int}>
     */
    public static function getToday(int $userId, int $limit = 50): array
    {
        try {
            $raw = Redis::hgetall(self::key($userId));
        } catch (\Throwable $e) {
            \Log::warning('DomainSendStats hgetall failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        if (empty($raw)) {
            return [];
        }

        $byDomain = [];
        foreach ($raw as $field => $value) {
            // field 格式：{domain}|sent 或 {domain}|failed
            $sep = strrpos($field, '|');
            if ($sep === false) {
                continue;
            }
            $domain = substr($field, 0, $sep);
            $type = substr($field, $sep + 1);
            if (!isset($byDomain[$domain])) {
                $byDomain[$domain] = ['sent' => 0, 'failed' => 0];
            }
            if ($type === 'sent' || $type === 'failed') {
                $byDomain[$domain][$type] = (int) $value;
            }
        }

        $result = [];
        foreach ($byDomain as $domain => $cnt) {
            $result[] = [
                'domain' => $domain,
                'sent' => $cnt['sent'],
                'failed' => $cnt['failed'],
                'total' => $cnt['sent'] + $cnt['failed'],
            ];
        }
        usort($result, fn ($a, $b) => $b['sent'] <=> $a['sent']);
        return array_slice($result, 0, $limit);
    }

    /**
     * 清空某用户今日（或指定日期）的统计（用于回填重建）
     */
    public static function clear(int $userId, ?Carbon $date = null): void
    {
        try {
            Redis::del(self::key($userId, $date));
        } catch (\Throwable $e) {
            // ignore
        }
    }
}
