<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Redis;

class DomainWarmup extends Model
{
    use HasFactory;

    protected $table = 'domain_warmups';

    protected $fillable = [
        'user_id',
        'smtp_server_id',
        'domain',
        'enabled',
        'started_at',
        'schedule',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'started_at' => 'datetime',
        'schedule' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function smtpServer()
    {
        return $this->belongsTo(SmtpServer::class);
    }

    /**
     * 从邮箱中提取域名（小写）
     */
    public static function extractDomain(string $email): ?string
    {
        $email = strtolower(trim($email));
        if (!str_contains($email, '@')) {
            return null;
        }
        return substr($email, strpos($email, '@') + 1) ?: null;
    }

    /**
     * 获取实际生效的阶梯：自定义优先，否则用默认
     *
     * @return array<int, array{day:int, limit:?int}>
     */
    public function getEffectiveSchedule(): array
    {
        if (!empty($this->schedule)) {
            return $this->schedule;
        }
        return config('warmup.default_schedule', []);
    }

    /**
     * 今天是预热的第几天（从 1 开始）
     */
    public function getCurrentDay(): int
    {
        if (!$this->started_at) {
            return 1;
        }
        // 用日期差而不是秒差，避免开启后到当晚 0 点时跨越
        $startDate = $this->started_at->copy()->startOfDay();
        $today = Carbon::today();
        return (int) $startDate->diffInDays($today) + 1;
    }

    /**
     * 今日发送上限（null 表示不限）
     */
    public function getTodayLimit(): ?int
    {
        $day = $this->getCurrentDay();
        $schedule = $this->getEffectiveSchedule();

        if (empty($schedule)) {
            return null;
        }

        // 阶梯最后一天之后视为预热完成，不再限制
        $lastDay = (int) collect($schedule)->max('day');
        if ($day > $lastDay) {
            return null;
        }

        foreach ($schedule as $step) {
            if ((int) $step['day'] === $day) {
                return $step['limit'] === null ? null : (int) $step['limit'];
            }
        }

        // 没匹配到当天（阶梯定义中跳过了某些天），用就近往前找
        $matched = null;
        foreach ($schedule as $step) {
            if ((int) $step['day'] <= $day) {
                $matched = $step;
            }
        }
        return $matched ? ($matched['limit'] === null ? null : (int) $matched['limit']) : null;
    }

    /**
     * Redis key：今日发送计数器
     */
    public function todayCounterKey(): string
    {
        return sprintf(
            'warmup:%d:%s:%s',
            $this->smtp_server_id,
            $this->domain,
            Carbon::today()->toDateString()
        );
    }

    /**
     * 今日已发送量
     */
    public function getTodaySentCount(): int
    {
        $val = Redis::get($this->todayCounterKey());
        return (int) ($val ?: 0);
    }

    /**
     * 今日剩余配额（null 表示不限）
     */
    public function getTodayRemaining(): ?int
    {
        $limit = $this->getTodayLimit();
        if ($limit === null) {
            return null;
        }
        return max(0, $limit - $this->getTodaySentCount());
    }

    /**
     * 是否还能再发 $count 封（不开启预热则始终返回 true）
     */
    public function canSend(int $count = 1): bool
    {
        if (!$this->enabled) {
            return true;
        }
        $remaining = $this->getTodayRemaining();
        if ($remaining === null) {
            return true;
        }
        return $remaining >= $count;
    }

    /**
     * 原子地预占额度并返回是否成功（同时增加今日计数）
     * 通过 INCR + 上限检查，多 worker 并发安全
     */
    public function tryConsume(int $count = 1): bool
    {
        if (!$this->enabled) {
            return true;
        }

        $limit = $this->getTodayLimit();
        if ($limit === null) {
            return true;
        }

        $key = $this->todayCounterKey();
        $newValue = (int) Redis::incrby($key, $count);

        // 第一次创建 key 时设置 48h TTL（足够跨过当晚 0 点 + 一天）
        if ($newValue === $count) {
            Redis::expire($key, 48 * 3600);
        }

        if ($newValue > $limit) {
            // 超出额度，回滚
            Redis::decrby($key, $count);
            return false;
        }

        return true;
    }

    /**
     * 自动重置（如果今日还没消费记录，将 TTL 续上以防长期闲置）
     * 主要供监控/管理脚本使用
     */
    public function refreshTtl(): void
    {
        $key = $this->todayCounterKey();
        if (Redis::exists($key)) {
            Redis::expire($key, 48 * 3600);
        }
    }
}
