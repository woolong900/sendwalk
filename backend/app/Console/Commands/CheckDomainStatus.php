<?php

namespace App\Console\Commands;

use App\Models\Tag;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CheckDomainStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'domains:check 
                            {--tag=DOMAIN : Tag name containing domain list}
                            {--domains= : Comma-separated list of domains to check directly}
                            {--timeout=10 : Request timeout in seconds}
                            {--notify : Send notification on failure}
                            {--auto-remove : Automatically remove unhealthy domains from tag}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check the status of all domains in the specified tag';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $tagName = $this->option('tag');
        $directDomains = $this->option('domains');
        $timeout = (int) $this->option('timeout');
        $shouldNotify = $this->option('notify');
        $autoRemove = $this->option('auto-remove');

        $this->info("🔍 Starting domain status check...");
        $this->info("   Timeout: {$timeout}s");
        $this->info("   Auto-remove: " . ($autoRemove ? 'Yes' : 'No'));
        $this->line('');

        $totalDomains = 0;
        $healthyDomains = 0;
        $unhealthyDomains = [];
        $domainsToRemove = []; // 按 tag_id 分组的待移除域名

        // 如果指定了 --domains 参数，直接检测这些域名
        if ($directDomains) {
            $domains = array_filter(array_map('trim', explode(',', $directDomains)));
            
            $this->info("📋 Checking specified domains:");

            foreach ($domains as $domain) {
                $totalDomains++;
                $result = $this->checkDomain($domain, $timeout);

                if ($result['healthy']) {
                    $healthyDomains++;
                    $status = $result['error'] ?? "OK";
                    $this->line("   ✅ {$domain} - {$status} ({$result['status_code']}, {$result['response_time']}ms)");
                } else {
                    $unhealthyDomains[] = [
                        'user_id' => null,
                        'domain' => $domain,
                        'error' => $result['error'],
                        'ssl_valid' => $result['ssl_valid'],
                    ];
                    $this->error("   ❌ {$domain} - FAILED: {$result['error']}");
                }
            }

            $this->line('');
        } else {
            // 从数据库标签中获取域名
            $this->info("   Tag name: {$tagName}");
            
            $tags = Tag::where('name', $tagName)->get();

            if ($tags->isEmpty()) {
                $this->warn("⚠️  No tags found with name: {$tagName}");
                return 0;
            }

            foreach ($tags as $tag) {
                $domains = $tag->getValuesArray();
                
                if (empty($domains)) {
                    continue;
                }

                $this->info("📋 Checking domains for user #{$tag->user_id}:");

                foreach ($domains as $domain) {
                    $totalDomains++;
                    $result = $this->checkDomain($domain, $timeout);

                    if ($result['healthy']) {
                        $healthyDomains++;
                        $status = $result['error'] ?? "OK";
                        $this->line("   ✅ {$domain} - {$status} ({$result['status_code']}, {$result['response_time']}ms)");
                    } else {
                        $unhealthyDomains[] = [
                            'user_id' => $tag->user_id,
                            'tag_id' => $tag->id,
                            'domain' => $domain,
                            'error' => $result['error'],
                        ];
                        $this->error("   ❌ {$domain} - FAILED: {$result['error']}");
                        
                        // 记录待移除的域名
                        if (!isset($domainsToRemove[$tag->id])) {
                            $domainsToRemove[$tag->id] = [];
                        }
                        $domainsToRemove[$tag->id][] = $domain;
                    }
                }

                $this->line('');
            }
        }

        // 输出统计
        $this->info("📊 Summary:");
        $this->info("   Total domains: {$totalDomains}");
        $this->info("   Healthy: {$healthyDomains}");
        $this->info("   Unhealthy: " . count($unhealthyDomains));

        // 自动移除异常域名
        $removedCount = 0;
        if ($autoRemove && !empty($domainsToRemove)) {
            $this->line('');
            $this->info("🗑️  Removing unhealthy domains from tags...");
            
            foreach ($domainsToRemove as $tagId => $domains) {
                $tag = Tag::find($tagId);
                if ($tag) {
                    $removed = $this->removeDomainsFromTag($tag, $domains);
                    $removedCount += $removed;
                }
            }
            
            $this->info("   Removed: {$removedCount} domain(s)");
        }

        // 记录日志
        if (!empty($unhealthyDomains)) {
            Log::warning('Domain health check found unhealthy domains', [
                'unhealthy_count' => count($unhealthyDomains),
                'domains' => $unhealthyDomains,
                'auto_removed' => $autoRemove,
                'removed_count' => $removedCount,
            ]);

            // 可选：发送通知
            if ($shouldNotify) {
                $this->sendNotification($unhealthyDomains);
            }
        } else {
            Log::info('Domain health check completed - all domains healthy', [
                'total_domains' => $totalDomains,
            ]);
        }

        $this->line('');
        $this->info("✅ Domain check completed at " . now()->format('Y-m-d H:i:s'));

        return empty($unhealthyDomains) ? 0 : 1;
    }

    /**
     * 检测单个域名状态
     */
    private function checkDomain(string $domain, int $timeout): array
    {
        // 清理域名格式
        $domain = trim($domain);
        
        // 如果不是完整 URL，添加协议
        if (!preg_match('/^https?:\/\//', $domain)) {
            $url = 'https://' . $domain;
        } else {
            $url = $domain;
        }

        $result = [
            'healthy' => false,
            'reachable' => false,
            'status_code' => null,
            'response_time' => null,
            'ssl_valid' => null,
            'error' => null,
        ];

        $startTime = microtime(true);

        try {
            // 先尝试 GET 请求（某些服务器不支持 HEAD）
            $response = Http::timeout($timeout)
                ->withOptions([
                    'verify' => true, // 验证 SSL 证书
                    'allow_redirects' => [
                        'max' => 5,
                        'strict' => false,
                        'referer' => true,
                        'protocols' => ['http', 'https'],
                    ],
                ])
                ->get($url);

            $endTime = microtime(true);
            $responseTime = round(($endTime - $startTime) * 1000);

            $result['status_code'] = $response->status();
            $result['response_time'] = $responseTime;
            $result['ssl_valid'] = true;
            $result['reachable'] = true; // 能收到 HTTP 响应就是可达的

            // 判断是否健康：
            // - 2xx/3xx 状态码 = 完全健康
            // - 4xx/5xx = 可达但有问题（仍然认为域名是"可用"的）
            if ($response->successful() || $response->redirect()) {
                $result['healthy'] = true;
            } elseif ($response->status() >= 400 && $response->status() < 500) {
                // 4xx 错误：域名可达，服务器正常响应，只是没有内容或拒绝访问
                // 对于跟踪域名来说，根路径返回 404 是正常的
                $result['healthy'] = true;
                $result['error'] = "HTTP {$response->status()} (reachable)";
            } else {
                // 5xx 错误：服务器问题
                $result['error'] = "HTTP {$response->status()}";
            }

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $result['error'] = 'Connection failed: ' . $this->simplifyError($e->getMessage());
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            
            // 检测 SSL 证书错误
            if (str_contains($errorMessage, 'SSL') || str_contains($errorMessage, 'certificate')) {
                $result['ssl_valid'] = false;
                $result['error'] = 'SSL certificate error';
            } else {
                $result['error'] = $this->simplifyError($errorMessage);
            }
        }

        return $result;
    }

    /**
     * 简化错误信息
     */
    private function simplifyError(string $message): string
    {
        // 常见错误简化
        $patterns = [
            '/cURL error \d+: (.+?) \(see/i' => '$1',
            '/Could not resolve host/i' => 'DNS resolution failed',
            '/Connection timed out/i' => 'Connection timeout',
            '/Connection refused/i' => 'Connection refused',
            '/SSL certificate problem/i' => 'SSL certificate error',
            '/Operation timed out/i' => 'Request timeout',
        ];

        foreach ($patterns as $pattern => $replacement) {
            if (preg_match($pattern, $message)) {
                return preg_replace($pattern, $replacement, $message);
            }
        }

        // 截断过长的错误信息
        return mb_substr($message, 0, 100);
    }

    /**
     * 从 Tag 中移除指定域名
     */
    private function removeDomainsFromTag(Tag $tag, array $domainsToRemove): int
    {
        $currentDomains = $tag->getValuesArray();
        $removedCount = 0;
        
        // 过滤掉要移除的域名
        $remainingDomains = array_filter($currentDomains, function ($domain) use ($domainsToRemove, &$removedCount) {
            $shouldRemove = in_array($domain, $domainsToRemove);
            if ($shouldRemove) {
                $removedCount++;
            }
            return !$shouldRemove;
        });
        
        if ($removedCount > 0) {
            // 更新 Tag 的 values
            $newValues = implode("\n", $remainingDomains);
            $tag->update(['values' => $newValues]);
            
            $this->line("   ✅ Tag #{$tag->id} (user #{$tag->user_id}): removed {$removedCount} domain(s)");
            
            // 记录详细日志
            Log::info('Domains removed from tag', [
                'tag_id' => $tag->id,
                'tag_name' => $tag->name,
                'user_id' => $tag->user_id,
                'removed_domains' => $domainsToRemove,
                'remaining_count' => count($remainingDomains),
            ]);
        }
        
        return $removedCount;
    }

    /**
     * 发送通知（可扩展为邮件、Slack、钉钉等）
     */
    private function sendNotification(array $unhealthyDomains): void
    {
        $this->info("📧 Sending notification...");

        // 记录到日志（可以扩展为发送邮件/webhook）
        Log::alert('Domain health check alert - unhealthy domains detected', [
            'unhealthy_domains' => $unhealthyDomains,
            'checked_at' => now()->toDateTimeString(),
        ]);

        // TODO: 可以在这里添加邮件/Slack/钉钉等通知
        // 例如：
        // Mail::to('admin@example.com')->send(new DomainHealthAlert($unhealthyDomains));
        // Http::post('https://hooks.slack.com/...', ['text' => '...']);

        $this->info("   Notification sent (logged to storage/logs)");
    }
}
