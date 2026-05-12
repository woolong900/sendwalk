<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\Subscriber;
use App\Models\CampaignSend;
use App\Models\SmtpServer;
use App\Models\SendLog;
use App\Models\Tag;
use App\Services\EmailService;
use App\Services\BounceHandler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendCampaignEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    // 不重试：失败即失败，记录日志
    public $tries = 1;
    public $timeout = 120;

    // Store determined from_email for this task
    private ?string $fromEmail = null;
    
    // 模型实例（在 handle 中从 ID 加载）
    private ?Campaign $campaign = null;
    private ?Subscriber $subscriber = null;

    /**
     * Create a new job instance.
     * 
     * 注意：为了性能，我们只存储 ID，不使用 SerializesModels
     * 这样可以大幅提升队列任务创建速度（特别是大批量时）
     */
    public function __construct(
        public int $campaignId,
        public int $subscriberId,
        public ?int $listId = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(EmailService $emailService): void
    {
        $startTime = now();
        
        // 从数据库加载模型（因为我们只存储了 ID）
        $campaign = Campaign::find($this->campaignId);
        $subscriber = Subscriber::find($this->subscriberId);
        
        if (!$campaign || !$subscriber) {
            Log::error('Campaign or subscriber not found', [
                'campaign_id' => $this->campaignId,
                'subscriber_id' => $this->subscriberId,
            ]);
            return;
        }
        
        // 将实例变量设置为加载的模型，以便后续代码使用
        $this->campaign = $campaign;
        $this->subscriber = $subscriber;
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

            // 黑名单最终检查（同时覆盖邮箱黑名单和域名黑名单）
            // 防止任务入队后才被加入黑名单的邮箱被误发
            // 注意：此处仅作过滤拦截，不修改订阅者数据
            //   - 邮箱黑名单：addBatch/store 时已经把订阅者改为 blacklisted，无需重复
            //   - 域名黑名单：作为「过滤器」语义，不应破坏订阅者数据，否则移除黑名单后无法恢复
            if (\App\Models\Blacklist::isBlockedFromSending($this->campaign->user_id, $this->subscriber->email)) {
                Log::info('Task skipped: Email/domain in blacklist', [
                    'reason' => 'blacklisted',
                    'campaign_id' => $this->campaign->id,
                    'campaign_name' => $this->campaign->name,
                    'subscriber_id' => $this->subscriber->id,
                    'subscriber_email' => $this->subscriber->email,
                ]);

                // 标记发送记录为已跳过（避免后续重复尝试）
                if ($existingSend) {
                    $existingSend->update([
                        'status' => 'skipped',
                        'error_message' => 'Email or domain is blacklisted',
                    ]);
                } else {
                    CampaignSend::create([
                        'campaign_id' => $this->campaign->id,
                        'subscriber_id' => $this->subscriber->id,
                        'status' => 'skipped',
                        'error_message' => 'Email or domain is blacklisted',
                    ]);
                }
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
            
            // Check if this sender is paused
            if ($smtpServer->isSenderPaused($this->fromEmail)) {
                $waitSeconds = $smtpServer->getSenderPauseRemainingTime($this->fromEmail) ?? 60;
                
                Log::warning('Sender is paused, task not sent', [
                    'reason' => 'sender_paused',
                    'campaign_id' => $this->campaign->id,
                    'campaign_name' => $this->campaign->name,
                    'subscriber_id' => $this->subscriber->id,
                    'subscriber_email' => $this->subscriber->email,
                    'smtp_server_id' => $smtpServer->id,
                    'smtp_server_name' => $smtpServer->name,
                    'from_email' => $this->fromEmail,
                    'wait_seconds' => $waitSeconds,
                ]);
                
                // 抛出 RateLimitException，让 Worker 休眠
                throw new \App\Exceptions\RateLimitException(
                    "Sender {$this->fromEmail} is temporarily paused",
                    $waitSeconds
                );
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
            $userId = $this->campaign->user_id;
            
            // 使用订阅者实际所属的列表
            if ($this->listId) {
                $listId = $this->listId;
                $listName = \App\Models\MailingList::find($this->listId)->name ?? null;
            } else {
                $listId = $this->campaign->list_id;
            $listName = $this->campaign->list->name ?? null;
            }

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
                'from_email' => $this->fromEmail,  // 记录实际发件人邮箱
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
            $errorMessage = $e->getMessage();
            
            // 🚨 检测 "Excessive message rate" 错误并自动暂停该发件人
            if (isset($smtpServer) && isset($this->fromEmail) && $this->isRateLimitError($errorMessage)) {
                $smtpServer->pauseSender($this->fromEmail, 5, 'Excessive message rate detected');
                
                Log::warning('SMTP sender auto-paused due to rate limit error', [
                    'campaign_id' => $this->campaign->id,
                    'smtp_server_id' => $smtpServer->id,
                    'smtp_server_name' => $smtpServer->name,
                    'from_email' => $this->fromEmail,
                    'error_message' => $errorMessage,
                    'pause_duration' => '5 minutes',
                ]);
            }
            
            Log::error('Failed to send campaign email', [
                'campaign_id' => $this->campaign->id,
                'campaign_name' => $this->campaign->name,
                'subscriber_id' => $this->subscriber->id,
                'subscriber_email' => $this->subscriber->email,
                'smtp_server_id' => $smtpServer->id ?? null,
                'smtp_server_name' => $smtpServer->name ?? null,
                'smtp_server_type' => $smtpServer->type ?? null,
                'from_email' => $this->fromEmail ?? $this->campaign->from_email,
                'error' => $errorMessage,
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
                'from_email' => $this->fromEmail ?? $this->campaign->from_email,  // 记录实际发件人邮箱
                'smtp_server_name' => $smtpServer->name ?? 'Unknown',
                'email' => $this->subscriber->email,
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'started_at' => $startTime,
                'completed_at' => now(),
            ]);

            // 🔥 处理退信：自动检测并加入黑名单
            try {
                $bounceHandler = app(BounceHandler::class);
                $bounceHandler->handleBounce(
                    $this->subscriber->email,
                    $this->subscriber->id,
                    $this->campaign->id,
                    $e->getMessage(),
                    null // SMTP response (如果有的话可以传入)
                );
            } catch (\Exception $bounceException) {
                // 退信处理失败不影响主流程
                Log::error('Failed to handle bounce', [
                    'campaign_id' => $this->campaign->id,
                    'subscriber_id' => $this->subscriber->id,
                    'error' => $bounceException->getMessage(),
                ]);
            }

            // Laravel 数据库队列会自动删除失败的任务（达到重试次数后）
            throw $e;
        }
    }

    private function replacePersonalizationTags(string $content, Subscriber $subscriber): string
    {
        $senderDomain = $this->getSenderDomain();
        $unsubscribeUrl = $this->getUnsubscribeUrl($subscriber);
        
        // 获取订阅者所属的列表名称
        if ($this->listId) {
            // 使用指定的列表 ID
            $listName = \App\Models\MailingList::find($this->listId)->name ?? '';
        } else {
            // 如果没有指定，尝试从订阅者的列表关系中获取（与活动列表交集的第一个）
            $campaignListIds = $this->campaign->list_ids ?? [$this->campaign->list_id];
            $subscriberList = $subscriber->lists()
                ->whereIn('lists.id', $campaignListIds)
                ->first();
            $listName = $subscriberList->name ?? $this->campaign->list->name ?? '';
        }
        
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
            'from_email' => $this->fromEmail ?? $this->campaign->from_email,  // 记录实际发件人邮箱
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

        // 🔥 处理退信：自动检测并加入黑名单
        try {
            $bounceHandler = app(BounceHandler::class);
            $bounceHandler->handleBounce(
                $this->subscriber->email,
                $this->subscriber->id,
                $this->campaign->id,
                $exception->getMessage(),
                null
            );
        } catch (\Exception $bounceException) {
            // 退信处理失败不影响主流程
            Log::error('Failed to handle bounce in failed()', [
                'campaign_id' => $this->campaign->id,
                'subscriber_id' => $this->subscriber->id,
                'error' => $bounceException->getMessage(),
            ]);
        }
    }
    
    /**
     * 检查并标记活动为已完成
     * 
     * 🔥 简单原则：队列为空 = 活动完成
     * - 任务创建是同步的，全部创建完成后才开始发送
     * - 队列检查包含所有任务（包括正在执行的 reserved 任务）
     * - 任务要么成功要么失败，都会从队列中移除
     * 
     * 使用原子性 UPDATE 避免多个 Worker 同时标记
     */
    private function checkAndMarkCampaignComplete(): void
    {
        $queueName = 'campaign_' . $this->campaign->id;
        $campaignId = $this->campaign->id;
        
        // 🔥 原子性更新：队列为空 = 活动完成
        $affected = \DB::update("
            UPDATE campaigns 
            SET status = 'sent', 
                sent_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
            AND status = 'sending'
            AND NOT EXISTS (
                SELECT 1 FROM jobs WHERE queue = ?
            )
        ", [$campaignId, $queueName]);
        
        if ($affected > 0) {
            $this->campaign->refresh();
            \Log::info('Campaign completed (queue empty)', [
                'campaign_id' => $campaignId,
                'queue' => $queueName,
                'total_recipients' => $this->campaign->total_recipients,
                'total_sent' => $this->campaign->total_sent,
                'total_delivered' => $this->campaign->total_delivered,
            ]);
        }
    }
    
    /**
     * 检测是否为频率限制错误
     * 
     * @param string $errorMessage
     * @return bool
     */
    private function isRateLimitError(string $errorMessage): bool
    {
        $rateLimitPatterns = [
            '/excessive message rate/i',
            '/too many messages/i',
            '/rate limit exceeded/i',
            '/sending rate exceeded/i',
            '/throttle/i',
            '/quota exceeded/i',
            '/message rate limit/i',
            '/451 4\.7\.0/i', // Temporary rate limit
            '/452 4\.2\.1/i', // User has exceeded the max number of connections
            '/421 4\.7\.0/i', // Too many errors from your IP
        ];
        
        foreach ($rateLimitPatterns as $pattern) {
            if (preg_match($pattern, $errorMessage)) {
                return true;
            }
        }
        
        return false;
    }
}

