<?php

namespace App\Console\Commands;

use App\Models\Tag;
use App\Models\SmtpServer;
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
                            {--auto-remove : Automatically remove unhealthy domains from tag}
                            {--skip-tag : Skip checking tag domains}
                            {--skip-smtp : Skip checking SMTP sender domains}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check the status of domains in tags and SMTP server sender emails';

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
        $skipTag = $this->option('skip-tag');
        $skipSmtp = $this->option('skip-smtp');

        $this->info("🔍 Starting domain status check...");
        $this->info("   Timeout: {$timeout}s");
        $this->info("   Auto-remove: " . ($autoRemove ? 'Yes' : 'No'));
        $this->line('');

        $allUnhealthyDomains = [];

        // ========== 1. 检测直接指定的域名 ==========
        if ($directDomains) {
            $domains = array_filter(array_map('trim', explode(',', $directDomains)));
            
            $this->info("📋 Checking specified domains (HTTP):");

            foreach ($domains as $domain) {
                $result = $this->checkDomainHttp($domain, $timeout);

                if ($result['healthy']) {
                    $status = $result['error'] ?? "OK";
                    $this->line("   ✅ {$domain} - {$status} ({$result['status_code']}, {$result['response_time']}ms)");
                } else {
                    $allUnhealthyDomains[] = [
                        'type' => 'direct',
                        'domain' => $domain,
                        'error' => $result['error'],
                    ];
                    $this->error("   ❌ {$domain} - FAILED: {$result['error']}");
                }
            }

            $this->line('');
        }

        // ========== 2. 检测 Tag 中的域名 (HTTP) ==========
        $domainsToRemove = [];
        $tagDomainStats = ['total' => 0, 'healthy' => 0];
        
        if (!$skipTag && !$directDomains) {
            $this->info("═══════════════════════════════════════════════════════════");
            $this->info("📡 PART 1: Checking Tag Domains (HTTP/HTTPS)");
            $this->info("═══════════════════════════════════════════════════════════");
            $this->info("   Tag name: {$tagName}");
            $this->line('');
            
            $tags = Tag::where('name', $tagName)->get();

            if ($tags->isEmpty()) {
                $this->warn("   ⚠️  No tags found with name: {$tagName}");
            } else {
                foreach ($tags as $tag) {
                    $domains = $tag->getValuesArray();
                    
                    if (empty($domains)) {
                        continue;
                    }

                    $this->info("📋 Checking domains for user #{$tag->user_id}:");

                    foreach ($domains as $domain) {
                        $tagDomainStats['total']++;
                        $result = $this->checkDomainHttp($domain, $timeout);

                        if ($result['healthy']) {
                            $tagDomainStats['healthy']++;
                            $status = $result['error'] ?? "OK";
                            $this->line("   ✅ {$domain} - {$status} ({$result['status_code']}, {$result['response_time']}ms)");
                        } else {
                            $allUnhealthyDomains[] = [
                                'type' => 'tag',
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
            
            $this->info("📊 Tag Domain Summary:");
            $this->info("   Total: {$tagDomainStats['total']}, Healthy: {$tagDomainStats['healthy']}, Unhealthy: " . ($tagDomainStats['total'] - $tagDomainStats['healthy']));
            $this->line('');
        }

        // ========== 3. 检测 SMTP 发件人域名 (DNS) ==========
        $smtpDomainStats = ['total' => 0, 'healthy' => 0];
        $emailsToRemoveByServer = []; // 按 server_id 分组的待移除邮箱
        
        if (!$skipSmtp && !$directDomains) {
            $this->info("═══════════════════════════════════════════════════════════");
            $this->info("📧 PART 2: Checking SMTP Sender Domains (DNS Records)");
            $this->info("═══════════════════════════════════════════════════════════");
            $this->line('');
            
            $smtpServers = SmtpServer::where('is_active', true)
                ->whereNotNull('sender_emails')
                ->where('sender_emails', '!=', '')
                ->get();
            
            if ($smtpServers->isEmpty()) {
                $this->warn("   ⚠️  No active SMTP servers with sender emails found");
            } else {
                // 收集所有发件人域名（去重），并记录每个邮箱对应的服务器
                $senderDomains = [];
                $emailToServerMap = []; // email => [server_id, ...]
                
                foreach ($smtpServers as $server) {
                    $emails = array_filter(array_map('trim', explode("\n", $server->sender_emails)));
                    
                    foreach ($emails as $email) {
                        if (str_contains($email, '@')) {
                            $domain = strtolower(substr($email, strpos($email, '@') + 1));
                            if (!isset($senderDomains[$domain])) {
                                $senderDomains[$domain] = [
                                    'servers' => [],
                                    'emails' => [],
                                ];
                            }
                            $senderDomains[$domain]['servers'][$server->id] = $server->name;
                            $senderDomains[$domain]['emails'][] = $email;
                            
                            // 记录邮箱对应的服务器
                            if (!isset($emailToServerMap[$email])) {
                                $emailToServerMap[$email] = [];
                            }
                            $emailToServerMap[$email][] = $server->id;
                        }
                    }
                }
                
                $this->info("   Found " . count($senderDomains) . " unique sender domain(s)");
                $this->line('');
                
                foreach ($senderDomains as $domain => $info) {
                    $smtpDomainStats['total']++;
                    $serverNames = implode(', ', array_unique($info['servers']));
                    
                    $this->info("📋 Checking: {$domain}");
                    $this->line("   Used by server(s): {$serverNames}");
                    
                    $dnsResult = $this->checkDomainDns($domain);
                    
                    // 输出 DNS 记录状态
                    $this->line("   DNS Records:");
                    $this->line("     - MX:     " . ($dnsResult['mx']['found'] ? "✅ " . implode(', ', array_slice($dnsResult['mx']['records'], 0, 2)) : "❌ Not found"));
                    $this->line("     - SPF:    " . ($dnsResult['spf']['found'] ? "✅ Found" : "⚠️  Not found"));
                    $this->line("     - DMARC:  " . ($dnsResult['dmarc']['found'] ? "✅ Found" : "⚠️  Not found"));
                    
                    if ($dnsResult['healthy']) {
                        $smtpDomainStats['healthy']++;
                        $this->line("   Status: ✅ OK (DNS resolvable)");
                    } else {
                        $allUnhealthyDomains[] = [
                            'type' => 'smtp_sender',
                            'domain' => $domain,
                            'servers' => $info['servers'],
                            'emails' => $info['emails'],
                            'error' => $dnsResult['error'],
                            'dns' => $dnsResult,
                        ];
                        $this->error("   Status: ❌ FAILED - {$dnsResult['error']}");
                        
                        // 记录该域名下所有邮箱待移除
                        foreach ($info['emails'] as $email) {
                            foreach ($emailToServerMap[$email] ?? [] as $serverId) {
                                if (!isset($emailsToRemoveByServer[$serverId])) {
                                    $emailsToRemoveByServer[$serverId] = [];
                                }
                                if (!in_array($email, $emailsToRemoveByServer[$serverId])) {
                                    $emailsToRemoveByServer[$serverId][] = $email;
                                }
                            }
                        }
                    }
                    
                    $this->line('');
                }
            }
            
            $this->info("📊 SMTP Sender Domain Summary:");
            $this->info("   Total: {$smtpDomainStats['total']}, Healthy: {$smtpDomainStats['healthy']}, Unhealthy: " . ($smtpDomainStats['total'] - $smtpDomainStats['healthy']));
            $this->line('');
        }

        // ========== 4. 自动移除异常域名 ==========
        $removedTagCount = 0;
        $removedSmtpCount = 0;
        
        if ($autoRemove && (!empty($domainsToRemove) || !empty($emailsToRemoveByServer))) {
            $this->info("═══════════════════════════════════════════════════════════");
            $this->info("🗑️  Removing unhealthy domains/emails...");
            $this->info("═══════════════════════════════════════════════════════════");
            
            // 4.1 从 Tag 中移除异常域名
            if (!empty($domainsToRemove)) {
                $this->line('');
                $this->info("   From Tags:");
                foreach ($domainsToRemove as $tagId => $domains) {
                    $tag = Tag::find($tagId);
                    if ($tag) {
                        $removed = $this->removeDomainsFromTag($tag, $domains);
                        $removedTagCount += $removed;
                    }
                }
            }
            
            // 4.2 从 SMTP 服务器中移除异常发件人
            if (!empty($emailsToRemoveByServer)) {
                $this->line('');
                $this->info("   From SMTP Servers:");
                foreach ($emailsToRemoveByServer as $serverId => $emails) {
                    $server = SmtpServer::find($serverId);
                    if ($server) {
                        $removed = $this->removeEmailsFromSmtpServer($server, $emails);
                        $removedSmtpCount += $removed;
                    }
                }
            }
            
            $this->line('');
            $this->info("   Removed from Tags: {$removedTagCount} domain(s)");
            $this->info("   Removed from SMTP: {$removedSmtpCount} email(s)");
            $this->line('');
        }
        
        $removedCount = $removedTagCount + $removedSmtpCount;

        // ========== 5. 总结和通知 ==========
        $this->info("═══════════════════════════════════════════════════════════");
        $this->info("📊 FINAL SUMMARY");
        $this->info("═══════════════════════════════════════════════════════════");
        
        $totalDomains = $tagDomainStats['total'] + $smtpDomainStats['total'];
        $totalHealthy = $tagDomainStats['healthy'] + $smtpDomainStats['healthy'];
        $totalUnhealthy = count($allUnhealthyDomains);
        
        $this->info("   Total domains checked: {$totalDomains}");
        $this->info("   Healthy: {$totalHealthy}");
        $this->info("   Unhealthy: {$totalUnhealthy}");
        
        if ($removedCount > 0) {
            $this->info("   Auto-removed from tags: {$removedCount}");
        }

        // 记录日志
        if (!empty($allUnhealthyDomains)) {
            Log::warning('Domain health check found unhealthy domains', [
                'unhealthy_count' => count($allUnhealthyDomains),
                'domains' => $allUnhealthyDomains,
                'auto_removed' => $autoRemove,
                'removed_count' => $removedCount,
            ]);

            // 可选：发送通知
            if ($shouldNotify) {
                $this->sendNotification($allUnhealthyDomains);
            }
        } else {
            Log::info('Domain health check completed - all domains healthy', [
                'total_domains' => $totalDomains,
            ]);
        }

        $this->line('');
        $this->info("✅ Domain check completed at " . now()->format('Y-m-d H:i:s'));

        return empty($allUnhealthyDomains) ? 0 : 1;
    }

    /**
     * 检测域名 HTTP/HTTPS 状态（用于 Tag 中的网站域名）
     */
    private function checkDomainHttp(string $domain, int $timeout): array
    {
        $domain = trim($domain);
        
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
            $response = Http::timeout($timeout)
                ->withOptions([
                    'verify' => true,
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
            $result['reachable'] = true;

            if ($response->successful() || $response->redirect()) {
                $result['healthy'] = true;
            } elseif ($response->status() >= 400 && $response->status() < 500) {
                $result['healthy'] = true;
                $result['error'] = "HTTP {$response->status()} (reachable)";
            } else {
                $result['error'] = "HTTP {$response->status()}";
            }

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $result['error'] = 'Connection failed: ' . $this->simplifyError($e->getMessage());
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            
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
     * 检测域名 DNS 记录（用于发件人域名）
     */
    private function checkDomainDns(string $domain): array
    {
        $result = [
            'healthy' => false,
            'error' => null,
            'mx' => ['found' => false, 'records' => []],
            'spf' => ['found' => false, 'record' => null],
            'dmarc' => ['found' => false, 'record' => null],
            'a' => ['found' => false, 'records' => []],
        ];

        // 1. 检测 A 记录（域名是否可解析）
        $aRecords = @dns_get_record($domain, DNS_A);
        if ($aRecords && count($aRecords) > 0) {
            $result['a']['found'] = true;
            $result['a']['records'] = array_column($aRecords, 'ip');
        }

        // 2. 检测 MX 记录
        $mxRecords = @dns_get_record($domain, DNS_MX);
        if ($mxRecords && count($mxRecords) > 0) {
            $result['mx']['found'] = true;
            // 按优先级排序
            usort($mxRecords, fn($a, $b) => ($a['pri'] ?? 0) - ($b['pri'] ?? 0));
            $result['mx']['records'] = array_column($mxRecords, 'target');
        }

        // 3. 检测 SPF 记录 (TXT)
        $txtRecords = @dns_get_record($domain, DNS_TXT);
        if ($txtRecords) {
            foreach ($txtRecords as $txt) {
                if (isset($txt['txt']) && stripos($txt['txt'], 'v=spf1') === 0) {
                    $result['spf']['found'] = true;
                    $result['spf']['record'] = $txt['txt'];
                    break;
                }
            }
        }

        // 4. 检测 DMARC 记录
        $dmarcRecords = @dns_get_record('_dmarc.' . $domain, DNS_TXT);
        if ($dmarcRecords) {
            foreach ($dmarcRecords as $txt) {
                if (isset($txt['txt']) && stripos($txt['txt'], 'v=DMARC1') === 0) {
                    $result['dmarc']['found'] = true;
                    $result['dmarc']['record'] = $txt['txt'];
                    break;
                }
            }
        }

        // 判断是否健康：
        // - 必须有 SPF 记录（声明哪些服务器可以发送邮件）
        if ($result['spf']['found']) {
            $result['healthy'] = true;
        } else {
            $result['error'] = 'No SPF record found - domain not configured for email sending';
        }

        return $result;
    }

    /**
     * 简化错误信息
     */
    private function simplifyError(string $message): string
    {
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

        return mb_substr($message, 0, 100);
    }

    /**
     * 从 Tag 中移除指定域名
     */
    private function removeDomainsFromTag(Tag $tag, array $domainsToRemove): int
    {
        $currentDomains = $tag->getValuesArray();
        $removedCount = 0;
        
        $remainingDomains = array_filter($currentDomains, function ($domain) use ($domainsToRemove, &$removedCount) {
            $shouldRemove = in_array($domain, $domainsToRemove);
            if ($shouldRemove) {
                $removedCount++;
            }
            return !$shouldRemove;
        });
        
        if ($removedCount > 0) {
            $newValues = implode("\n", $remainingDomains);
            $tag->update(['values' => $newValues]);
            
            $this->line("     ✅ Tag #{$tag->id} (user #{$tag->user_id}): removed {$removedCount} domain(s)");
            
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
     * 从 SMTP 服务器中移除指定发件人邮箱
     */
    private function removeEmailsFromSmtpServer(SmtpServer $server, array $emailsToRemove): int
    {
        $currentEmails = array_filter(array_map('trim', explode("\n", $server->sender_emails ?? '')));
        $removedCount = 0;
        $removedEmails = [];
        
        $remainingEmails = array_filter($currentEmails, function ($email) use ($emailsToRemove, &$removedCount, &$removedEmails) {
            $shouldRemove = in_array($email, $emailsToRemove);
            if ($shouldRemove) {
                $removedCount++;
                $removedEmails[] = $email;
            }
            return !$shouldRemove;
        });
        
        if ($removedCount > 0) {
            $newValues = implode("\n", $remainingEmails);
            $server->update(['sender_emails' => $newValues ?: null]);
            
            $this->line("     ✅ SMTP #{$server->id} ({$server->name}): removed {$removedCount} email(s)");
            foreach ($removedEmails as $email) {
                $this->line("        - {$email}");
            }
            
            Log::info('Emails removed from SMTP server', [
                'server_id' => $server->id,
                'server_name' => $server->name,
                'user_id' => $server->user_id,
                'removed_emails' => $removedEmails,
                'remaining_count' => count($remainingEmails),
            ]);
        }
        
        return $removedCount;
    }

    /**
     * 发送通知
     */
    private function sendNotification(array $unhealthyDomains): void
    {
        $this->info("📧 Sending notification...");

        // 分类统计
        $tagDomains = array_filter($unhealthyDomains, fn($d) => ($d['type'] ?? '') === 'tag');
        $smtpDomains = array_filter($unhealthyDomains, fn($d) => ($d['type'] ?? '') === 'smtp_sender');

        Log::alert('Domain health check alert - unhealthy domains detected', [
            'total_unhealthy' => count($unhealthyDomains),
            'tag_domains' => count($tagDomains),
            'smtp_sender_domains' => count($smtpDomains),
            'details' => $unhealthyDomains,
            'checked_at' => now()->toDateTimeString(),
        ]);

        $this->info("   Notification sent (logged to storage/logs)");
    }
}
