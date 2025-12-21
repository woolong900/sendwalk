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
            // Configure mail settings based on SMTP server type
            $this->configureMailer($smtpServer);

            Log::debug('Sending email', [
                'smtp_server_id' => $smtpServer->id,
                'smtp_server_name' => $smtpServer->name,
                'smtp_server_type' => $smtpServer->type,
                'to' => $to,
                'from' => $fromEmail,
                'subject' => $subject,
            ]);

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
}

