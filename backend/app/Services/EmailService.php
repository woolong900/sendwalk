<?php

namespace App\Services;

use App\Models\SmtpServer;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class EmailService
{
    public function send(
        SmtpServer $smtpServer,
        string $to,
        string $subject,
        string $htmlContent,
        string $fromName,
        string $fromEmail,
        ?string $replyTo = null,
        ?string $unsubscribeUrl = null,
        ?int $campaignId = null,
        ?int $subscriberId = null,
        ?int $listId = null,
        ?int $userId = null,
        ?string $listName = null
    ): void {
        try {
            Log::debug('Sending email', [
                'smtp_server_id' => $smtpServer->id,
                'smtp_server_name' => $smtpServer->name,
                'smtp_server_type' => $smtpServer->type,
                'to' => $to,
                'from' => $fromEmail,
                'subject' => $subject,
            ]);

            // cm.com 走 HTTP API，不经过 Laravel Mail
            if ($smtpServer->type === 'cm') {
                $this->sendViaCmApi(
                    $smtpServer,
                    $to,
                    $subject,
                    $htmlContent,
                    $fromName,
                    $fromEmail,
                    $replyTo,
                    $campaignId,
                    $subscriberId
                );

                Log::debug('Email sent successfully via cm.com', [
                    'smtp_server_id' => $smtpServer->id,
                    'to' => $to,
                ]);
                return;
            }

            // SendGrid 走 HTTP API，不经过 Laravel Mail
            if ($smtpServer->type === 'sendgrid') {
                $this->sendViaSendGridApi(
                    $smtpServer,
                    $to,
                    $subject,
                    $htmlContent,
                    $fromName,
                    $fromEmail,
                    $replyTo,
                    $unsubscribeUrl,
                    $campaignId,
                    $subscriberId,
                    $listId,
                    $userId,
                    $listName
                );

                Log::debug('Email sent successfully via SendGrid', [
                    'smtp_server_id' => $smtpServer->id,
                    'to' => $to,
                ]);
                return;
            }

            // Configure mail settings based on SMTP server type
            $this->configureMailer($smtpServer);

            // Send email
            Mail::send([], [], function ($message) use ($to, $subject, $htmlContent, $fromName, $fromEmail, $replyTo, $unsubscribeUrl, $campaignId, $subscriberId, $listId, $userId, $listName) {
                $message->to($to)
                    ->subject($subject)
                    ->from($fromEmail, $fromName)
                    ->html($htmlContent);

                if ($replyTo) {
                    $message->replyTo($replyTo);
                }

                // Add Precedence: Bulk header to prevent auto-replies and improve deliverability
                $message->getHeaders()->addTextHeader('Precedence', 'Bulk');

                // Add List-Id header (required by Gmail for bulk senders)
                if ($listId) {
                    // Format: List-Id: List Name <list-id.domain>
                    $listIdentifier = "list-{$listId}." . parse_url(config('app.url'), PHP_URL_HOST);
                    if ($listName) {
                        $message->getHeaders()->addTextHeader('List-Id', $listName . ' <' . $listIdentifier . '>');
                    } else {
                        $message->getHeaders()->addTextHeader('List-Id', '<' . $listIdentifier . '>');
                    }
                }

                // Add Feedback-ID header for FBL (Feedback Loop) tracking
                if ($campaignId && $listId && $userId) {
                    // Format: Feedback-ID: campaignId:type:listId:userId
                    $feedbackId = "campaign-{$campaignId}:bulk:list-{$listId}:user-{$userId}";
                    $message->getHeaders()->addTextHeader('Feedback-ID', $feedbackId);
                }

                // Add List-Unsubscribe headers (required by Gmail/Yahoo for bulk senders since Feb 2024)
                if ($unsubscribeUrl) {
                    // List-Unsubscribe header with HTTPS URL for one-click unsubscribe
                    $message->getHeaders()->addTextHeader('List-Unsubscribe', '<' . $unsubscribeUrl . '>');
                    
                    // List-Unsubscribe-Post header for one-click unsubscribe
                    $message->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
                }

                // Add X-Report-Abuse header for abuse reporting (points to frontend page)
                if ($campaignId && $subscriberId) {
                    $reportAbuseUrl = config('app.frontend_url') . "/abuse/report/{$campaignId}/{$subscriberId}";
                    $message->getHeaders()->addTextHeader('X-Report-Abuse', $reportAbuseUrl);
                }

                // Add X-EBS header for email blocking system (points to frontend page)
                if ($to) {
                    $blockUrl = config('app.frontend_url') . "/abuse/block?email=" . urlencode($to);
                    $message->getHeaders()->addTextHeader('X-EBS', $blockUrl);
                }
            });
            
            Log::debug('Email sent successfully', [
                'smtp_server_id' => $smtpServer->id,
                'to' => $to,
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to send email', [
                'smtp_server_id' => $smtpServer->id,
                'smtp_server_name' => $smtpServer->name,
                'smtp_server_type' => $smtpServer->type,
                'to' => $to,
                'from' => $fromEmail,
                'subject' => $subject,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);
            
            throw $e;
        }
    }

    private function configureMailer(SmtpServer $smtpServer): void
    {
        try {
            switch ($smtpServer->type) {
                case 'smtp':
                    Log::debug('Configuring SMTP mailer', [
                        'server_id' => $smtpServer->id,
                        'host' => $smtpServer->host,
                        'port' => $smtpServer->port,
                        'encryption' => $smtpServer->encryption,
                        'username' => $smtpServer->username,
                    ]);
                    
                    Config::set('mail.mailers.smtp', [
                        'transport' => 'smtp',
                        'host' => $smtpServer->host,
                        'port' => $smtpServer->port,
                        'encryption' => $smtpServer->encryption,
                        'username' => $smtpServer->username,
                        'password' => $smtpServer->password,
                    ]);
                    Config::set('mail.default', 'smtp');
                    break;

                case 'ses':
                    // Configure AWS SES Web API
                    // Extract region from host (e.g., email.us-east-1.amazonaws.com -> us-east-1)
                    $region = 'us-east-1'; // default
                    if (preg_match('/\.([a-z0-9-]+)\.amazonaws\.com$/', $smtpServer->host, $matches)) {
                        $region = $matches[1];
                    }
                    
                    Log::debug('Configuring AWS SES mailer', [
                        'server_id' => $smtpServer->id,
                        'host' => $smtpServer->host,
                        'region' => $region,
                        'access_key_id' => substr($smtpServer->username, 0, 8) . '...',
                    ]);
                    
                    if (empty($smtpServer->username) || empty($smtpServer->password)) {
                        throw new \Exception('AWS SES credentials (Access Key ID and Secret Access Key) are required');
                    }
                    
                    Config::set('mail.mailers.ses', [
                        'transport' => 'ses',
                    ]);
                    Config::set('services.ses', [
                        'key' => $smtpServer->username, // Access Key ID
                        'secret' => $smtpServer->password, // Secret Access Key
                        'region' => $region,
                    ]);
                    Config::set('mail.default', 'ses');
                    break;

                default:
                    throw new \Exception('Unsupported SMTP server type: ' . $smtpServer->type);
            }
            
            Log::debug('Mailer configured successfully', [
                'server_id' => $smtpServer->id,
                'type' => $smtpServer->type,
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to configure mailer', [
                'server_id' => $smtpServer->id,
                'server_name' => $smtpServer->name,
                'type' => $smtpServer->type,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            throw $e;
        }
    }

    /**
     * 通过 cm.com Email Gateway API 发送邮件
     * 文档：https://developers.cm.com/messaging/docs/send-marketing-email
     */
    private function sendViaCmApi(
        SmtpServer $smtpServer,
        string $to,
        string $subject,
        string $htmlContent,
        string $fromName,
        string $fromEmail,
        ?string $replyTo = null,
        ?int $campaignId = null,
        ?int $subscriberId = null
    ): void {
        if (empty($smtpServer->password)) {
            throw new \Exception('cm.com Product Token 未配置');
        }

        $endpoint = $smtpServer->host ?: 'https://api.cm.com/email/gateway/v1/marketing';

        $payload = [
            'from' => [
                'email' => $fromEmail,
                'name' => $fromName,
            ],
            'to' => [
                ['email' => $to],
            ],
            'subject' => $subject,
            'html' => $htmlContent,
        ];

        if (!empty($replyTo)) {
            $payload['replyTo'] = ['email' => $replyTo];
        }

        // 使用活动+订阅者作为客户引用，便于 cm.com 平台追踪
        if ($campaignId && $subscriberId) {
            $payload['customerReference'] = "campaign-{$campaignId}-sub-{$subscriberId}";
        }

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-CM-PRODUCTTOKEN: ' . $smtpServer->password,
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new \Exception("cm.com API 请求失败: {$curlError}");
        }

        $body = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300) {
            // 成功响应通常包含 success: true 和 messageId
            if (is_array($body) && isset($body['success']) && $body['success'] === false) {
                $msg = $body['message'] ?? 'Unknown error';
                throw new \Exception("cm.com API 返回失败: {$msg}");
            }
            return;
        }

        $errorMsg = is_array($body) && isset($body['message'])
            ? $body['message']
            : (is_array($body) && isset($body['title']) ? $body['title'] : substr((string) $response, 0, 500));

        switch ($httpCode) {
            case 400:
                throw new \Exception("cm.com 请求参数无效: {$errorMsg}");
            case 401:
                throw new \Exception('cm.com Product Token 缺失');
            case 403:
                throw new \Exception('cm.com Product Token 无效');
            case 404:
                throw new \Exception('cm.com API 端点未找到');
            case 429:
                throw new \Exception('cm.com 速率限制超出');
            default:
                throw new \Exception("cm.com API 错误 (HTTP {$httpCode}): {$errorMsg}");
        }
    }

    /**
     * 通过 SendGrid Web API v3 发送邮件
     * 文档：https://docs.sendgrid.com/api-reference/mail-send/mail-send
     *
     * Auth: Authorization: Bearer {API_KEY}
     * Endpoint: https://api.sendgrid.com/v3/mail/send
     * 成功返回 HTTP 202 Accepted
     */
    private function sendViaSendGridApi(
        SmtpServer $smtpServer,
        string $to,
        string $subject,
        string $htmlContent,
        string $fromName,
        string $fromEmail,
        ?string $replyTo = null,
        ?string $unsubscribeUrl = null,
        ?int $campaignId = null,
        ?int $subscriberId = null,
        ?int $listId = null,
        ?int $userId = null,
        ?string $listName = null
    ): void {
        if (empty($smtpServer->password)) {
            throw new \Exception('SendGrid API Key 未配置');
        }

        $endpoint = $smtpServer->host ?: 'https://api.sendgrid.com/v3/mail/send';

        // 构建自定义 headers（与 SMTP 路径对齐：List-Id, Feedback-ID, List-Unsubscribe, X-Report-Abuse, X-EBS）
        $headers = [];

        if ($listId) {
            $listIdentifier = "list-{$listId}." . parse_url(config('app.url'), PHP_URL_HOST);
            $headers['List-Id'] = $listName
                ? "{$listName} <{$listIdentifier}>"
                : "<{$listIdentifier}>";
        }

        if ($campaignId && $listId && $userId) {
            $headers['Feedback-ID'] = "campaign-{$campaignId}:bulk:list-{$listId}:user-{$userId}";
        }

        if ($unsubscribeUrl) {
            $headers['List-Unsubscribe'] = "<{$unsubscribeUrl}>";
            $headers['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
        }

        if ($campaignId && $subscriberId) {
            $headers['X-Report-Abuse'] = rtrim(config('app.frontend_url', ''), '/') . "/abuse/report/{$campaignId}/{$subscriberId}";
        }

        if ($to) {
            $headers['X-EBS'] = rtrim(config('app.frontend_url', ''), '/') . "/abuse/block?email=" . urlencode($to);
        }

        // 始终带上 Precedence: Bulk
        $headers['Precedence'] = 'Bulk';

        $payload = [
            'personalizations' => [[
                'to' => [['email' => $to]],
                'subject' => $subject,
            ]],
            'from' => [
                'email' => $fromEmail,
                'name' => $fromName,
            ],
            'content' => [
                ['type' => 'text/html', 'value' => $htmlContent],
            ],
        ];

        // 注意：SendGrid 的 headers / custom_args / mail_settings 等字段都要求 JSON object {}，
        // 不能传空数组（PHP json_encode 会把空数组编码成 []，触发 "Expected: object, given: array" 错误）。
        // 因此只在非空时加入，并显式 cast 成 object 防御。
        if (!empty($headers)) {
            $payload['headers'] = (object) $headers;
        }

        if (!empty($replyTo)) {
            $payload['reply_to'] = ['email' => $replyTo];
        }

        // 用 custom_args 让 SendGrid event webhook 能追溯到具体活动/订阅者
        if ($campaignId && $subscriberId) {
            $payload['custom_args'] = (object) [
                'campaign_id' => (string) $campaignId,
                'subscriber_id' => (string) $subscriberId,
            ];
        }

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $smtpServer->password,
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new \Exception("SendGrid API 请求失败: {$curlError}");
        }

        // 成功：202 Accepted（SendGrid 标准成功响应，body 通常为空）
        if ($httpCode === 202) {
            return;
        }

        // 失败：解析 errors 数组返回的错误信息
        $body = json_decode($response, true);
        $errorMsg = 'Unknown error';
        if (is_array($body) && !empty($body['errors']) && is_array($body['errors'])) {
            $errorMsg = implode('; ', array_map(
                fn ($e) => is_array($e) ? ($e['message'] ?? json_encode($e)) : (string) $e,
                $body['errors']
            ));
        } elseif (is_string($response) && $response !== '') {
            $errorMsg = substr($response, 0, 500);
        }

        switch ($httpCode) {
            case 400:
                throw new \Exception("SendGrid 请求参数无效: {$errorMsg}");
            case 401:
                throw new \Exception('SendGrid API Key 缺失或无效');
            case 403:
                throw new \Exception("SendGrid 拒绝请求（发件人域名未验证或权限不足）: {$errorMsg}");
            case 413:
                throw new \Exception('SendGrid 邮件体过大');
            case 429:
                throw new \Exception('SendGrid 速率限制超出');
            default:
                throw new \Exception("SendGrid API 错误 (HTTP {$httpCode}): {$errorMsg}");
        }
    }
}

