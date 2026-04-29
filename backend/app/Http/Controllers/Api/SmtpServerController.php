<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SmtpServer;
use Illuminate\Http\Request;

class SmtpServerController extends Controller
{
    public function index(Request $request)
    {
        $servers = SmtpServer::where('user_id', $request->user()->id)
            ->latest()
            ->get();

        // 批量查询所有服务器的速率限制状态
        if ($servers->isNotEmpty()) {
            $serverIds = $servers->pluck('id')->toArray();
            
            $oneHourAgo = now()->subHour();
            $oneMinuteAgo = now()->subMinute();
            $oneSecondAgo = now()->subSecond();
            
            // 使用数据库聚合查询代替加载所有记录到内存
            // 分别查询每个时间窗口的计数
            $hourCounts = \App\Models\SendLog::whereIn('smtp_server_id', $serverIds)
                ->whereIn('status', ['sent', 'failed'])
                ->where('created_at', '>=', $oneHourAgo)
                ->selectRaw('smtp_server_id, COUNT(*) as count')
                ->groupBy('smtp_server_id')
                ->pluck('count', 'smtp_server_id')
                ->toArray();
            
            $minuteCounts = \App\Models\SendLog::whereIn('smtp_server_id', $serverIds)
                ->whereIn('status', ['sent', 'failed'])
                ->where('created_at', '>=', $oneMinuteAgo)
                ->selectRaw('smtp_server_id, COUNT(*) as count')
                ->groupBy('smtp_server_id')
                ->pluck('count', 'smtp_server_id')
                ->toArray();
            
            $secondCounts = \App\Models\SendLog::whereIn('smtp_server_id', $serverIds)
                ->whereIn('status', ['sent', 'failed'])
                ->where('created_at', '>=', $oneSecondAgo)
                ->selectRaw('smtp_server_id, COUNT(*) as count')
                ->groupBy('smtp_server_id')
                ->pluck('count', 'smtp_server_id')
                ->toArray();
            
            // 为每个服务器计算速率限制状态
            $servers->each(function ($server) use ($hourCounts, $minuteCounts, $secondCounts) {
                $counts = [
                    'second' => $secondCounts[$server->id] ?? 0,
                    'minute' => $minuteCounts[$server->id] ?? 0,
                    'hour'   => $hourCounts[$server->id] ?? 0,
                    'day'    => $server->emails_sent_today,
                ];
                
                // 构建速率限制状态
                $status = [];
                foreach ($counts as $period => $current) {
                    $limit = $server->{"rate_limit_$period"};
                    $available = $limit ? $limit - $current : null;
                    $percentage = $limit ? round(($current / $limit) * 100, 1) : 0;
                    
                    $status[$period] = [
                        'limit' => $limit,
                        'current' => $current,
                        'available' => $available,
                        'percentage' => $percentage,
                    ];
                }
                
                // 添加暂停的发件人列表
                $status['paused_senders'] = $server->getPausedSenders();
                
                $server->rate_limit_status = $status;
            });
        }

        return response()->json([
            'data' => $servers,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:smtp,ses,cm',
            'host' => 'required_if:type,smtp,ses|nullable|string',
            'port' => 'required_if:type,smtp|nullable|integer',
            'username' => 'required_if:type,ses|nullable|string',
            'password' => 'required_if:type,ses,cm|nullable|string',
            'encryption' => 'nullable|in:tls,ssl,none',
            'sender_emails' => 'required_if:type,ses,cm|nullable|string',
            'credentials' => 'nullable|array',
            'is_default' => 'boolean',
            'rate_limit_second' => 'nullable|integer|min:1',
            'rate_limit_minute' => 'nullable|integer|min:1',
            'rate_limit_hour' => 'nullable|integer|min:1',
            'rate_limit_day' => 'nullable|integer|min:1',
        ]);

        // 如果设置为默认，取消其他服务器的默认状态
        if ($request->is_default) {
            SmtpServer::where('user_id', $request->user()->id)
                ->update(['is_default' => false]);
        }

        // SES 和 cm.com 等 API 类型不需要 port 和 encryption
        $isApiType = in_array($request->type, ['ses', 'cm']);
        $port = $isApiType ? null : $request->port;
        $encryption = $isApiType ? null : $request->encryption;

        // cm.com 默认使用官方 API 端点
        $host = $request->host;
        if ($request->type === 'cm' && empty($host)) {
            $host = 'https://api.cm.com/email/gateway/v1/marketing';
        }

        $server = SmtpServer::create([
            'user_id' => $request->user()->id,
            'name' => $request->name,
            'type' => $request->type,
            'host' => $host,
            'port' => $port,
            'username' => $request->username,
            'password' => $request->password,
            'encryption' => $encryption,
            'sender_emails' => $request->sender_emails,
            'credentials' => $request->credentials,
            'is_default' => $request->is_default ?? false,
            'is_active' => true,
            'rate_limit_second' => $request->rate_limit_second,
            'rate_limit_minute' => $request->rate_limit_minute,
            'rate_limit_hour' => $request->rate_limit_hour,
            'rate_limit_day' => $request->rate_limit_day,
        ]);

        return response()->json([
            'message' => 'SMTP服务器创建成功',
            'data' => $server,
        ], 201);
    }

    public function show(Request $request, SmtpServer $smtpServer)
    {
        if ($smtpServer->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权访问'], 403);
        }

        return response()->json([
            'data' => $smtpServer,
        ]);
    }

    public function update(Request $request, SmtpServer $smtpServer)
    {
        if ($smtpServer->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权访问'], 403);
        }

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'type' => 'sometimes|required|in:smtp,ses,cm',
            'host' => 'nullable|string',
            'port' => 'nullable|integer',
            'username' => 'nullable|string',
            'password' => 'nullable|string',
            'encryption' => 'nullable|in:tls,ssl,none',
            'sender_emails' => 'nullable|string',
            'credentials' => 'nullable|array',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'rate_limit_second' => 'nullable|integer|min:1',
            'rate_limit_minute' => 'nullable|integer|min:1',
            'rate_limit_hour' => 'nullable|integer|min:1',
            'rate_limit_day' => 'nullable|integer|min:1',
        ]);

        // 如果设置为默认，取消其他服务器的默认状态
        if ($request->has('is_default') && $request->is_default) {
            SmtpServer::where('user_id', $request->user()->id)
                ->where('id', '!=', $smtpServer->id)
                ->update(['is_default' => false]);
        }

        $updateData = $request->only([
            'name',
            'type',
            'host',
            'port',
            'username',
            'encryption',
            'sender_emails',
            'credentials',
            'is_default',
            'is_active',
            'rate_limit_second',
            'rate_limit_minute',
            'rate_limit_hour',
            'rate_limit_day',
        ]);

        // SES 和 cm.com 等 API 类型不需要 port 和 encryption
        if ($request->has('type') && in_array($request->type, ['ses', 'cm'])) {
            $updateData['port'] = null;
            $updateData['encryption'] = null;
        }

        // cm.com 默认使用官方 API 端点
        if ($request->has('type') && $request->type === 'cm' && empty($updateData['host'] ?? null) && empty($smtpServer->host)) {
            $updateData['host'] = 'https://api.cm.com/email/gateway/v1/marketing';
        }

        // 只有在提供新密码时才更新
        if ($request->filled('password')) {
            $updateData['password'] = $request->password;
        }

        $smtpServer->update($updateData);

        return response()->json([
            'message' => 'SMTP服务器更新成功',
            'data' => $smtpServer,
        ]);
    }

    public function destroy(Request $request, SmtpServer $smtpServer)
    {
        if ($smtpServer->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权访问'], 403);
        }

        if ($smtpServer->is_default) {
            return response()->json([
                'message' => '无法删除默认服务器，请先设置其他服务器为默认'
            ], 422);
        }

        $smtpServer->delete();

        return response()->json([
            'message' => 'SMTP服务器删除成功',
        ]);
    }

    /**
     * 复制 SMTP 服务器
     */
    public function duplicate(Request $request, SmtpServer $smtpServer)
    {
        if ($smtpServer->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权访问'], 403);
        }

        // 复制服务器，修改名称并重置一些字段
        $newServer = $smtpServer->replicate();
        $newServer->name = $smtpServer->name . ' (副本)';
        $newServer->is_default = false; // 副本不能是默认服务器
        $newServer->emails_sent_today = 0; // 重置发送计数
        $newServer->last_reset_date = null;
        $newServer->created_at = now();
        $newServer->updated_at = now();
        $newServer->save();

        return response()->json([
            'message' => 'SMTP服务器复制成功',
            'data' => $newServer,
        ], 201);
    }

    public function test(Request $request, SmtpServer $smtpServer)
    {
        if ($smtpServer->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权访问'], 403);
        }

        try {
            // Test SMTP connection
            if ($smtpServer->type === 'smtp') {
                // Use PHP's built-in SMTP socket connection for testing
                $this->testSmtpConnection($smtpServer);
            } elseif ($smtpServer->type === 'cm') {
                // 通过沙盒模式调用 cm.com API 测试 token 有效性
                $this->testCmConnection($smtpServer);
            } else {
                // 其他类型（SES 等）只检查凭证是否填写
                if (empty($smtpServer->username) && empty($smtpServer->password)) {
                    throw new \Exception('服务器凭证未配置');
                }
            }
            
            return response()->json([
                'message' => '连接测试成功',
                'details' => [
                    'type' => $smtpServer->type,
                    'host' => $smtpServer->host,
                    'port' => $smtpServer->port,
                    'encryption' => $smtpServer->encryption,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => '连接测试失败: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * 测试 cm.com API 连接（通过沙盒模式）
     */
    private function testCmConnection(SmtpServer $server)
    {
        if (empty($server->password)) {
            throw new \Exception('Product Token 未配置');
        }

        $endpoint = $server->host ?: 'https://api.cm.com/email/gateway/v1/marketing';

        // 解析发件人列表，使用第一个作为测试发件人
        $emails = array_filter(
            array_map('trim', explode("\n", $server->sender_emails ?? '')),
            fn($e) => !empty($e) && filter_var($e, FILTER_VALIDATE_EMAIL)
        );

        if (empty($emails)) {
            throw new \Exception('请先配置至少一个发件人邮箱');
        }

        $fromEmail = reset($emails);

        $payload = [
            'from' => ['email' => $fromEmail, 'name' => 'Test'],
            'to' => [['email' => 'test@example.com', 'name' => 'Test']],
            'subject' => 'Connection Test',
            'text' => 'This is a connection test in sandbox mode.',
        ];

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-CM-PRODUCTTOKEN: ' . $server->password,
                'Sandbox-Mode: true',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new \Exception("无法连接到 cm.com API: {$curlError}");
        }

        if ($httpCode === 401) {
            throw new \Exception('Product Token 缺失或无效');
        }

        if ($httpCode === 403) {
            throw new \Exception('Product Token 无效');
        }

        if ($httpCode >= 400 && $httpCode < 500 && $httpCode !== 400) {
            // 400 可能是因为测试邮箱无效，但 token 有效，可视为通过
            throw new \Exception("cm.com API 返回错误 (HTTP {$httpCode}): " . substr($response, 0, 200));
        }

        if ($httpCode >= 500) {
            throw new \Exception("cm.com 服务器错误 (HTTP {$httpCode})");
        }
    }

    private function testSmtpConnection(SmtpServer $server)
    {
        $host = $server->host;
        $port = $server->port;
        $encryption = $server->encryption;
        $username = $server->username;
        $password = $server->password;

        // Validate required fields
        if (empty($host) || empty($port)) {
            throw new \Exception('SMTP主机和端口不能为空');
        }

        // Determine the connection type
        $timeout = 10;
        $errno = 0;
        $errstr = '';

        // Try to establish socket connection
        if ($encryption === 'ssl') {
            $connectionString = "ssl://{$host}";
        } else {
            $connectionString = $host;
        }

        // Open socket connection
        $socket = @fsockopen($connectionString, $port, $errno, $errstr, $timeout);
        
        if (!$socket) {
            throw new \Exception("无法连接到SMTP服务器 {$host}:{$port} - {$errstr} ({$errno})");
        }

        try {
            // Read server greeting
            $response = fgets($socket, 512);
            if (strpos($response, '220') !== 0) {
                throw new \Exception("SMTP服务器响应异常: {$response}");
            }

            // Send EHLO/HELO
            fwrite($socket, "EHLO localhost\r\n");
            $response = $this->readMultilineResponse($socket);
            
            // If EHLO fails, try HELO
            if (strpos($response, '250') !== 0) {
                fwrite($socket, "HELO localhost\r\n");
                $response = $this->readMultilineResponse($socket);
                if (strpos($response, '250') !== 0) {
                    throw new \Exception("SMTP握手失败: {$response}");
                }
            }

            // If TLS is required, send STARTTLS
            if ($encryption === 'tls') {
                fwrite($socket, "STARTTLS\r\n");
                $response = fgets($socket, 512);
                if (strpos($response, '220') !== 0) {
                    throw new \Exception("STARTTLS失败: {$response}");
                }

                // Enable crypto
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \Exception("无法启用TLS加密");
                }

                // Send EHLO again after STARTTLS
                fwrite($socket, "EHLO localhost\r\n");
                $response = $this->readMultilineResponse($socket);
                if (strpos($response, '250') !== 0) {
                    throw new \Exception("TLS握手后EHLO失败: {$response}");
                }
            }

            // Test authentication if credentials provided
            if (!empty($username) && !empty($password)) {
                // Send AUTH LOGIN
                fwrite($socket, "AUTH LOGIN\r\n");
                $response = fgets($socket, 512);
                if (strpos($response, '334') !== 0) {
                    throw new \Exception("AUTH LOGIN不支持: {$response}");
                }

                // Send username
                fwrite($socket, base64_encode($username) . "\r\n");
                $response = fgets($socket, 512);
                if (strpos($response, '334') !== 0) {
                    throw new \Exception("用户名认证失败: {$response}");
                }

                // Send password
                fwrite($socket, base64_encode($password) . "\r\n");
                $response = fgets($socket, 512);
                if (strpos($response, '235') !== 0) {
                    throw new \Exception("密码认证失败，请检查用户名和密码: {$response}");
                }
            }

            // Send QUIT
            fwrite($socket, "QUIT\r\n");
            
        } finally {
            // Always close the socket
            fclose($socket);
        }
    }

    /**
     * Read multiline SMTP response
     * SMTP responses can be multiline, ending with a line that has a space after the code
     * Example:
     *   250-server.example.com
     *   250-SIZE 31457280
     *   250 HELP
     */
    private function readMultilineResponse($socket)
    {
        $response = '';
        while ($line = fgets($socket, 512)) {
            $response .= $line;
            // Check if this is the last line (has space after code, not hyphen)
            // Format: "250 HELP" (last line) vs "250-SIZE" (more lines follow)
            if (preg_match('/^\d{3} /', $line)) {
                break;
            }
        }
        return $response;
    }

    public function getRateLimitStatus(Request $request, SmtpServer $smtpServer)
    {
        if ($smtpServer->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权访问'], 403);
        }

        $status = $smtpServer->getRateLimitStatus();

        return response()->json([
            'data' => $status,
        ]);
    }
}

