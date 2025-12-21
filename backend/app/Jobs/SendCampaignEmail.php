<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\Subscriber;
use App\Models\CampaignSend;
use App\Models\SmtpServer;
use App\Models\SendLog;
use App\Models\Tag;
use App\Services\EmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendCampaignEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // 不重试：失败即失败，记录日志
    public $tries = 1;
    public $timeout = 120;

    // Store determined from_email for this task
    private ?string $fromEmail = null;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Campaign $campaign,
        public Subscriber $subscriber
    ) {}

    /**
     * Execute the job.
     */
    public function handle(EmailService $emailService): void
    {
        $startTime = now();
        $sendLog = null; // 只在最终结果时创建日志

        try {
            // Check if already sent
            $existingSend = CampaignSend::where('campaign_id', $this->campaign->id)
                ->where('subscriber_id', $this->subscriber->id)
                ->first();

            if ($existingSend && $existingSend->status === 'sent') {
                // 已经发送过了，跳过
                Log::info('Task skipped: Email already sent', [
                    'reason' => 'already_sent',
                    'campaign_id' => $this->campaign->id,
                    'campaign_name' => $this->campaign->name,
                    'subscriber_id' => $this->subscriber->id,
                    'subscriber_email' => $this->subscriber->email,
                    'sent_at' => $existingSend->sent_at,
                ]);
                return;
            }

            // Get SMTP server (use campaign's server or fallback to default)
            $smtpServer = null;
            
            if ($this->campaign->smtp_server_id) {
                $smtpServer = SmtpServer::find($this->campaign->smtp_server_id);
            }
            
            if (!$smtpServer) {
                $smtpServer = SmtpServer::where('user_id', $this->campaign->user_id)
                    ->where('is_active', true)
                    ->where('is_default', true)
                    ->first();
            }

            if (!$smtpServer) {
                $error = 'No available SMTP server';
                Log::error($error, [
                    'campaign_id' => $this->campaign->id,
                    'campaign_name' => $this->campaign->name,
                    'subscriber_id' => $this->subscriber->id,
                    'subscriber_email' => $this->subscriber->email,
                    'campaign_smtp_server_id' => $this->campaign->smtp_server_id,
                ]);
                throw new \Exception($error);
            }

            // Check rate limits with detailed information
            $limitCheck = $smtpServer->checkRateLimits();
            
            if (!$limitCheck['can_send']) {
                $waitSeconds = $limitCheck['wait_seconds'] ?? 60;
                
                Log::warning('Rate limit reached, task not sent', [
                    'reason' => 'rate_limit_exceeded',
                    'campaign_id' => $this->campaign->id,
                    'campaign_name' => $this->campaign->name,
                    'subscriber_id' => $this->subscriber->id,
                    'subscriber_email' => $this->subscriber->email,
                    'smtp_server_id' => $smtpServer->id,
                    'smtp_server_name' => $smtpServer->name,
                    'smtp_server_type' => $smtpServer->type,
                    'blocked_by' => $limitCheck['blocked_by'],
                    'wait_seconds' => $waitSeconds,
                    'rate_limit_status' => $limitCheck,
                ]);
                
                // 抛出 RateLimitException，让 Worker 休眠
                throw new \App\Exceptions\RateLimitException(
                    "Rate limit reached for SMTP server {$smtpServer->name}",
                    $waitSeconds
                );
            }

            // Check if already sent (prevent duplicate sends on job retry)
            $send = CampaignSend::where([
                'campaign_id' => $this->campaign->id,
                'subscriber_id' => $this->subscriber->id,
            ])->first();

            if ($send && $send->status === 'sent') {
                // Already sent, skip this job (second check after rate limit verification)
                Log::info('Task skipped: Email already sent (after rate limit check)', [
                    'reason' => 'already_sent_after_rate_check',
                    'campaign_id' => $this->campaign->id,
                    'campaign_name' => $this->campaign->name,
                    'subscriber_id' => $this->subscriber->id,
                    'subscriber_email' => $this->subscriber->email,
                    'smtp_server_id' => $smtpServer->id,
                    'smtp_server_name' => $smtpServer->name,
                    'sent_at' => $send->sent_at,
                ]);
                return;
            }

            // Create or update send record
            if (!$send) {
                $send = CampaignSend::create([
                    'campaign_id' => $this->campaign->id,
                    'subscriber_id' => $this->subscriber->id,
                    'status' => 'pending',
                ]);
            } else {
                $send->update(['status' => 'pending']);
            }

            // Determine from_email: use campaign's or randomly select from server's pool
            $this->fromEmail = $this->campaign->from_email;
            if (empty($this->fromEmail)) {
                $this->fromEmail = $this->getRandomSenderEmail($smtpServer);
            }

            // Replace personalization tags in subject
            $subject = $this->replacePersonalizationTags(
                $this->campaign->subject,
                $this->subscriber
            );

            // Replace personalization tags in content
            $htmlContent = $this->replacePersonalizationTags(
                $this->campaign->html_content,
                $this->subscriber
            );

            // Add preview text
            if ($this->campaign->preview_text) {
                $previewText = $this->replacePersonalizationTags(
                    $this->campaign->preview_text,
                    $this->subscriber
                );
                $htmlContent = $this->addPreviewText($htmlContent, $previewText);
            }

            // Add tracking pixel
            $htmlContent = $this->addTrackingPixel($htmlContent, $this->campaign->id, $this->subscriber->id);

            // Replace links with tracking links
            $htmlContent = $this->replaceLinksWithTracking($htmlContent, $this->campaign->id, $this->subscriber->id);

            // Generate unsubscribe URL for List-Unsubscribe header
            $unsubscribeUrl = $this->getUnsubscribeUrl($this->subscriber);

            // Use from_email as reply_to if reply_to is empty
            $replyTo = $this->campaign->reply_to ?: $this->fromEmail;

            // Get list information for headers
            $listId = $this->campaign->list_id;
            $userId = $this->campaign->user_id;
            $listName = $this->campaign->list->name ?? null;

            // Send email
            $emailService->send(
                $smtpServer,
                $this->subscriber->email,
                $subject,
                $htmlContent,
                $this->campaign->from_name,
                $this->fromEmail,
                $replyTo,
                $unsubscribeUrl,
                $this->campaign->id,
                $this->subscriber->id,
                $listId,
                $userId,
                $listName
            );

            // Update send record
            // 只有首次成功时才增加计数（避免重复计数）
            $wasAlreadySent = $send->status === 'sent';
            
            $send->update([
                'status' => 'sent',
                'sent_at' => now(),
            ]);

            // Update campaign stats (首次成功时才增加计数)
            if (!$wasAlreadySent) {
                $this->campaign->increment('total_sent');      // 已处理数
                $this->campaign->increment('total_delivered'); // 成功送达数
            }

            // Update SMTP server stats and rate limit counters
            $smtpServer->recordSent();

            // Create send log for successful delivery
            SendLog::create([
                'campaign_id' => $this->campaign->id,
                'subscriber_id' => $this->subscriber->id,
                'smtp_server_id' => $smtpServer->id,
                'campaign_name' => $this->campaign->name,
                'smtp_server_name' => $smtpServer->name,
                'email' => $this->subscriber->email,
                'status' => 'sent',
                'started_at' => $startTime,
                'completed_at' => now(),
            ]);
            
            // 检查是否所有任务都已完成（基于 CampaignSend 表）
            $this->checkAndMarkCampaignComplete();

            // Laravel 数据库队列会自动删除已完成的任务

        } catch (\App\Exceptions\RateLimitException $e) {
            // 速率限制异常，直接重新抛出，由 ProcessCampaignQueue 处理
            // 不创建 SendLog，不标记为失败
            Log::info('Task delayed due to rate limit, will retry later', [
                'reason' => 'rate_limit_exception_caught',
                'campaign_id' => $this->campaign->id,
                'campaign_name' => $this->campaign->name,
                'subscriber_id' => $this->subscriber->id,
                'subscriber_email' => $this->subscriber->email,
                'smtp_server_id' => $smtpServer->id ?? null,
                'smtp_server_name' => $smtpServer->name ?? null,
                'wait_seconds' => $e->getWaitSeconds(),
                'message' => $e->getMessage(),
            ]);
            
            throw $e;
            
        } catch (\Exception $e) {
            Log::error('Failed to send campaign email', [
                'campaign_id' => $this->campaign->id,
                'campaign_name' => $this->campaign->name,
                'subscriber_id' => $this->subscriber->id,
                'subscriber_email' => $this->subscriber->email,
                'smtp_server_id' => $smtpServer->id ?? null,
                'smtp_server_name' => $smtpServer->name ?? null,
                'smtp_server_type' => $smtpServer->type ?? null,
                'from_email' => $this->fromEmail ?? $this->campaign->from_email,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            if (isset($send)) {
                // 只有首次失败时才增加 total_sent（避免重试时重复计数）
                $wasAlreadyFailed = $send->status === 'failed';
                
                $send->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
                
                // 首次失败时增加 total_sent（表示已处理，但未送达）
                if (!$wasAlreadyFailed) {
                    $this->campaign->increment('total_sent');
                }
            }
            
            // 失败也要占用服务器限额（只要尝试发送了）
            if (isset($smtpServer)) {
                $smtpServer->recordSent();
            }
            
            // 检查是否所有任务都已完成（基于 CampaignSend 表）
            $this->checkAndMarkCampaignComplete();

            // Create send log for failed delivery
            SendLog::create([
                'campaign_id' => $this->campaign->id,
                'subscriber_id' => $this->subscriber->id,
                'smtp_server_id' => $smtpServer->id ?? null,
                'campaign_name' => $this->campaign->name,
                'smtp_server_name' => $smtpServer->name ?? 'Unknown',
                'email' => $this->subscriber->email,
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'started_at' => $startTime,
                'completed_at' => now(),
            ]);

            // Laravel 数据库队列会自动删除失败的任务（达到重试次数后）
            throw $e;
        }
    }

    private function replacePersonalizationTags(string $content, Subscriber $subscriber): string
    {
        $senderDomain = $this->getSenderDomain();
        $unsubscribeUrl = $this->getUnsubscribeUrl($subscriber);
        $listName = $this->campaign->lists()->first()->name ?? $this->campaign->list->name ?? '';
        $serverName = $this->campaign->smtpServer->name ?? '';
        
        // 订阅者标签（只支持花括号格式 {}）
        $replacements = [
            '{email}' => $subscriber->email,
            '{first_name}' => $subscriber->first_name ?? '',
            '{last_name}' => $subscriber->last_name ?? '',
            '{full_name}' => $subscriber->full_name,
        ];

        // 系统标签（只支持花括号格式 {}）
        $systemReplacements = [
            '{campaign_id}' => $this->campaign->id,
            '{date}' => date('md'), // 格式：1213 (12月13日)
            '{list_name}' => $listName,
            '{server_name}' => $serverName,
            '{sender_domain}' => $senderDomain,
            '{unsubscribe_url}' => $unsubscribeUrl,
        ];

        // 合并所有替换
        $replacements = array_merge($replacements, $systemReplacements);

        // 订阅者自定义字段
        if ($subscriber->custom_fields) {
            foreach ($subscriber->custom_fields as $key => $value) {
                $replacements['{' . $key . '}'] = $value;
            }
        }

        // 替换所有花括号标签
        $content = str_replace(array_keys($replacements), array_values($replacements), $content);

        // 替换自定义标签（随机值）
        $customTags = Tag::where('user_id', $this->campaign->user_id)->get();
        
        foreach ($customTags as $tag) {
            $placeholder = '{' . $tag->name . '}';
            
            // 检查内容中是否包含该标签
            if (strpos($content, $placeholder) !== false) {
                $randomValue = $tag->getRandomValue();
                $content = str_replace($placeholder, $randomValue, $content);
            }
        }

        // 未匹配的标签保持原样，方括号内容不处理
        return $content;
    }

    private function getSenderDomain(): string
    {
        // Use the determined from_email (either from campaign or randomly selected)
        $fromEmail = $this->fromEmail ?? $this->campaign->from_email ?? '';
        if (empty($fromEmail)) {
            return '';
        }
        $parts = explode('@', $fromEmail);
        return $parts[1] ?? '';
    }

    private function getUnsubscribeUrl(Subscriber $subscriber): string
    {
        // 获取第一个关联的列表ID
        $listId = $this->campaign->lists()->first()->id ?? $this->campaign->list_id;
        
        // 使用 UnsubscribeController 生成安全的退订链接
        return \App\Http\Controllers\UnsubscribeController::generateUnsubscribeUrl(
            $subscriber->id,
            $listId,
            $this->campaign->id
        );
    }

    /**
     * Get next sender email from SMTP server's sender_emails pool using round-robin
     * If pool is empty, throw an exception
     * This method always reads fresh data from database to handle real-time changes
     */
    private function getRandomSenderEmail(SmtpServer $smtpServer): string
    {
        // Use database transaction with lock to ensure thread-safe round-robin
        return \DB::transaction(function() use ($smtpServer) {
            // Lock the row for update to prevent race conditions
            // Read fresh data from database to get latest sender_emails
            $server = SmtpServer::lockForUpdate()->find($smtpServer->id);
            
            if (empty($server->sender_emails)) {
                throw new \Exception('Campaign from_email is empty and SMTP server has no sender emails configured');
            }

            // Parse sender_emails (one email per line) - using fresh data
            $emails = array_filter(
                array_map('trim', explode("\n", $server->sender_emails)),
                function($email) {
                    return !empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL);
                }
            );

            if (empty($emails)) {
                throw new \Exception('SMTP server sender_emails contains no valid email addresses');
            }

            // Re-index array to ensure sequential keys starting from 0
            $emails = array_values($emails);
            $totalEmails = count($emails);
            
            // Get current index (modulo ensures it wraps around even if emails were added/removed)
            $currentIndex = $server->sender_email_index % $totalEmails;
            $selectedEmail = $emails[$currentIndex];
            
            // Increment index for next use
            $server->increment('sender_email_index');
            
            return $selectedEmail;
        });
    }

    private function addPreviewText(string $content, string $previewText): string
    {
        // Klaviyo-style preview text implementation
        // Uses industry-standard technique: hidden div with zero-width non-joiner characters
        
        // Create spacer with ZWNJ + nbsp pattern (prevents email clients from showing body content)
        // This is the same technique used by Klaviyo, Mailchimp, and other major ESPs
        $spacer = str_repeat('&zwnj;&nbsp;', 150); // Zero Width Non-Joiner + non-breaking space
        
        // Build preview text HTML
        // Using div (more standard than span) with comprehensive hiding styles
        $previewHtml = '<div style="display:none;font-size:1px;color:#ffffff;line-height:1px;max-height:0px;max-width:0px;opacity:0;overflow:hidden;mso-hide:all;">'
            . htmlspecialchars($previewText, ENT_QUOTES, 'UTF-8')
            . $spacer
            . '</div>';
        
        // Insert preview text right after <body> tag (most reliable position)
        if (preg_match('/<body[^>]*>/i', $content)) {
            $content = preg_replace('/(<body[^>]*>)/i', '$1' . $previewHtml, $content);
        } else {
            // Fallback: prepend to content if no body tag found
            $content = $previewHtml . $content;
        }
        
        return $content;
    }

    private function addTrackingPixel(string $content, int $campaignId, int $subscriberId): string
    {
        $trackingUrl = config('app.url') . "/api/track/open/{$campaignId}/{$subscriberId}";
        $pixel = "<img src=\"{$trackingUrl}\" width=\"1\" height=\"1\" alt=\"\" />";
        
        return str_replace('</body>', $pixel . '</body>', $content);
    }

    private function replaceLinksWithTracking(string $content, int $campaignId, int $subscriberId): string
    {
        // TODO: Implement link tracking replacement
        return $content;
    }

    /**
     * 任务失败处理（不重试，直接记录失败）
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SendCampaignEmail failed permanently', [
            'campaign_id' => $this->campaign->id,
            'subscriber_id' => $this->subscriber->id,
            'error' => $exception->getMessage(),
        ]);

        // 记录失败日志
        SendLog::create([
            'campaign_id' => $this->campaign->id,
            'subscriber_id' => $this->subscriber->id,
            'smtp_server_id' => $this->campaign->smtp_server_id,
            'campaign_name' => $this->campaign->name,
            'smtp_server_name' => $this->campaign->smtpServer->name ?? 'Unknown',
            'email' => $this->subscriber->email,
            'status' => 'failed',
            'error_message' => $exception->getMessage(),
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        // 更新活动发送记录
        CampaignSend::updateOrCreate(
            [
                'campaign_id' => $this->campaign->id,
                'subscriber_id' => $this->subscriber->id,
            ],
            [
                'status' => 'failed',
                'failed_at' => now(),
            ]
        );
    }
    
    /**
     * 检查并标记活动为已完成
     * 基于 CampaignSend 表的实际完成状态，而不是计数器
     */
    private function checkAndMarkCampaignComplete(): void
    {
        // 统计已完成的任务数（成功或失败）
        $totalProcessed = CampaignSend::where('campaign_id', $this->campaign->id)
            ->whereIn('status', ['sent', 'failed'])
            ->count();
        
        // 如果所有任务都已完成
        if ($totalProcessed >= $this->campaign->total_recipients) {
            $this->campaign->update([
                'status' => 'sent',
                'sent_at' => now(),
            ]);
            
            // 清理活动队列中的剩余任务
            $queueName = 'campaign_' . $this->campaign->id;
            \DB::table('jobs')->where('queue', $queueName)->delete();
            
            \Log::info('Campaign completed, queue cleaned', [
                'campaign_id' => $this->campaign->id,
                'queue' => $queueName,
                'total_sent' => $this->campaign->total_sent,
                'total_delivered' => $this->campaign->total_delivered,
                'total_processed' => $totalProcessed,
            ]);
        }
    }
}

