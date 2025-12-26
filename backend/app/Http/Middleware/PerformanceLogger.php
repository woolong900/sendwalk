<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class PerformanceLogger
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);
        $requestId = uniqid('req_');
        
        // 记录请求开始
        Log::info('[性能] 请求开始', [
            'request_id' => $requestId,
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'path' => $request->path(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'timestamp' => now()->toIso8601String(),
        ]);
        
        // 添加请求ID到请求中，便于后续日志关联
        $request->attributes->set('request_id', $requestId);
        
        // 处理请求
        $response = $next($request);
        
        // 计算总耗时
        $duration = (microtime(true) - $startTime) * 1000;
        
        // 记录请求完成
        Log::info('[性能] 请求完成', [
            'request_id' => $requestId,
            'method' => $request->method(),
            'path' => $request->path(),
            'status' => $response->getStatusCode(),
            'duration_ms' => round($duration, 2),
            'memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        ]);
        
        // 如果请求耗时超过1秒，记录警告
        if ($duration > 1000) {
            Log::warning('[性能] 慢请求警告', [
                'request_id' => $requestId,
                'method' => $request->method(),
                'path' => $request->path(),
                'duration_ms' => round($duration, 2),
                'threshold_ms' => 1000,
            ]);
        }
        
        // 如果请求耗时超过5秒，记录错误
        if ($duration > 5000) {
            Log::error('[性能] 极慢请求', [
                'request_id' => $requestId,
                'method' => $request->method(),
                'path' => $request->path(),
                'duration_ms' => round($duration, 2),
                'threshold_ms' => 5000,
            ]);
        }
        
        return $response;
    }
}

