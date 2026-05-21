<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DomainWarmup;
use App\Models\SmtpServer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DomainWarmupController extends Controller
{
    /**
     * 列出某台 SMTP 服务器下所有域名的预热配置及实时状态
     */
    public function index(Request $request, SmtpServer $smtpServer)
    {
        $this->authorizeServer($request, $smtpServer);

        // 从 sender_emails 提取所有域名
        $domains = $this->extractDomains($smtpServer->sender_emails);

        // 一次性查出已有的预热配置
        $warmups = DomainWarmup::where('smtp_server_id', $smtpServer->id)
            ->get()
            ->keyBy('domain');

        $payload = [];
        foreach ($domains as $domain) {
            $warmup = $warmups->get($domain);
            $payload[] = $this->formatStatus($smtpServer, $domain, $warmup);
        }

        return response()->json(['data' => $payload]);
    }

    /**
     * 启用/更新某个域名的预热配置（不存在则创建）
     *
     * 参数：
     * - enabled (bool) 是否启用
     * - reset_started_at (bool, 可选) true 时重置 started_at 为现在（重新开始预热）
     * - schedule (array, 可选) 自定义阶梯，传 null 或不传时使用系统默认
     */
    public function update(Request $request, SmtpServer $smtpServer, string $domain)
    {
        $this->authorizeServer($request, $smtpServer);

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'reset_started_at' => ['sometimes', 'boolean'],
            'schedule' => ['sometimes', 'nullable', 'array'],
            'schedule.*.day' => ['required_with:schedule', 'integer', 'min:1'],
            'schedule.*.limit' => ['present', 'nullable', 'integer', 'min:0'],
        ]);

        $domain = strtolower(trim($domain));
        if ($domain === '' || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $domain)) {
            return response()->json(['message' => '域名格式无效'], 422);
        }

        // 校验该域名确实属于这台服务器的 sender_emails
        $serverDomains = $this->extractDomains($smtpServer->sender_emails);
        if (!in_array($domain, $serverDomains, true)) {
            return response()->json(['message' => '该域名不在此服务器的发件人列表中'], 422);
        }

        $warmup = DomainWarmup::firstOrNew([
            'smtp_server_id' => $smtpServer->id,
            'domain' => $domain,
        ]);

        $warmup->user_id = $smtpServer->user_id;
        $warmup->enabled = (bool) $data['enabled'];

        // 启用预热时，如果是首次启用或显式 reset，设置 started_at = now
        if ($warmup->enabled) {
            if (!$warmup->started_at || !empty($data['reset_started_at'])) {
                $warmup->started_at = now();
            }
        }

        if (array_key_exists('schedule', $data)) {
            // 排序去重
            $schedule = collect($data['schedule'] ?? [])
                ->map(fn ($s) => [
                    'day' => (int) $s['day'],
                    'limit' => $s['limit'] === null ? null : (int) $s['limit'],
                ])
                ->sortBy('day')
                ->values()
                ->all();
            $warmup->schedule = empty($schedule) ? null : $schedule;
        }

        $warmup->save();

        return response()->json([
            'message' => $warmup->enabled ? '预热已启用' : '预热已关闭',
            'data' => $this->formatStatus($smtpServer, $domain, $warmup->fresh()),
        ]);
    }

    /**
     * 从 sender_emails 字符串中提取去重后的域名列表
     */
    private function extractDomains(?string $senderEmails): array
    {
        if (empty($senderEmails)) {
            return [];
        }

        $domains = [];
        $emails = array_filter(array_map('trim', explode("\n", $senderEmails)));
        foreach ($emails as $email) {
            $d = DomainWarmup::extractDomain($email);
            if ($d !== null && !in_array($d, $domains, true)) {
                $domains[] = $d;
            }
        }
        sort($domains);
        return $domains;
    }

    /**
     * 构建一个域名的展示用状态对象
     */
    private function formatStatus(SmtpServer $smtpServer, string $domain, ?DomainWarmup $warmup): array
    {
        $base = [
            'smtp_server_id' => $smtpServer->id,
            'domain' => $domain,
            'enabled' => false,
            'started_at' => null,
            'current_day' => null,
            'today_limit' => null,
            'today_sent' => 0,
            'today_remaining' => null,
            'schedule' => config('warmup.default_schedule'),
            'is_default_schedule' => true,
            'completed' => false,
        ];

        if (!$warmup) {
            return $base;
        }

        $currentDay = $warmup->getCurrentDay();
        $todayLimit = $warmup->getTodayLimit();
        $todaySent = $warmup->getTodaySentCount();

        return array_merge($base, [
            'enabled' => $warmup->enabled,
            'started_at' => $warmup->started_at?->toIso8601String(),
            'current_day' => $currentDay,
            'today_limit' => $todayLimit,
            'today_sent' => $todaySent,
            'today_remaining' => $todayLimit === null ? null : max(0, $todayLimit - $todaySent),
            'schedule' => $warmup->getEffectiveSchedule(),
            'is_default_schedule' => empty($warmup->schedule),
            // 当 today_limit=null 且 current_day > 阶梯最后一天 → 预热完成
            'completed' => $warmup->enabled && $todayLimit === null && $currentDay > count($warmup->getEffectiveSchedule()),
        ]);
    }

    private function authorizeServer(Request $request, SmtpServer $smtpServer): void
    {
        if ($smtpServer->user_id !== $request->user()->id) {
            abort(response()->json(['message' => '无权访问'], 403));
        }
    }
}
