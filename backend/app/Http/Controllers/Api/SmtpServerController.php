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

        // 为每个服务器添加速率限制状态
        $servers->each(function ($server) {
            $rateLimitStatus = $server->getRateLimitStatus();
            $server->rate_limit_status = $rateLimitStatus['periods'] ?? [];
        });

        return response()->json([
            'data' => $servers,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:smtp,ses',
            'host' => 'required|string',
            'port' => 'required_if:type,smtp|nullable|integer',
            'username' => 'required_if:type,ses|nullable|string',
            'password' => 'required_if:type,ses|nullable|string',
            'encryption' => 'nullable|in:tls,ssl,none',
            'sender_emails' => 'required_if:type,ses|nullable|string',
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

        // AWS SES API 不需要 port 和 encryption
        $port = $request->type === 'ses' ? null : $request->port;
        $encryption = $request->type === 'ses' ? null : $request->encryption;

        $server = SmtpServer::create([
            'user_id' => $request->user()->id,
            'name' => $request->name,
            'type' => $request->type,
            'host' => $request->host,
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
            'type' => 'sometimes|required|in:smtp,ses',
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

        // AWS SES API 不需要 port 和 encryption
        if ($request->has('type') && $request->type === 'ses') {
            $updateData['port'] = null;
            $updateData['encryption'] = null;
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
            } else {
                // For other types (SES, SendGrid, etc.), we can't easily test without making API calls
                // So we just check if credentials are present
                if (empty($smtpServer->credentials)) {
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

        return response()->json([
            'data' => $smtpServer->getRateLimitStatus(),
        ]);
    }
}

