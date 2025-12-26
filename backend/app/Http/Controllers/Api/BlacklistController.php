<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Blacklist;
use Illuminate\Http\Request;

class BlacklistController extends Controller
{
    /**
     * Get all blacklisted emails (优化版)
     */
    public function index(Request $request)
    {
        $startTime = microtime(true);
        $requestId = uniqid('req_');
        
        \Illuminate\Support\Facades\Log::info('[性能-黑名单] 开始处理列表请求', [
            'request_id' => $requestId,
            'user_id' => $request->user()->id,
            'page' => $request->get('page', 1),
            'per_page' => $request->get('per_page', 15),
            'has_search' => $request->has('search'),
            'search_term' => $request->get('search'),
            'timestamp' => now()->toIso8601String(),
        ]);
        
        $perPage = $request->get('per_page', 15);
        
        // 步骤1: 构建查询
        $queryBuildStart = microtime(true);
        $query = Blacklist::select(['id', 'email', 'reason', 'created_at'])
            ->where('user_id', $request->user()->id);
        $queryBuildDuration = (microtime(true) - $queryBuildStart) * 1000;
        
        \Illuminate\Support\Facades\Log::info('[性能-黑名单] 查询构建完成', [
            'request_id' => $requestId,
            'duration_ms' => round($queryBuildDuration, 2),
        ]);

        // Search filter
        if ($request->has('search') && !empty($request->search)) {
            $searchStart = microtime(true);
            $search = $request->search;
            $query->where('email', 'like', "%{$search}%");
            $searchDuration = (microtime(true) - $searchStart) * 1000;
            
            \Illuminate\Support\Facades\Log::info('[性能-黑名单] 搜索条件添加完成', [
                'request_id' => $requestId,
                'search_term' => $search,
                'duration_ms' => round($searchDuration, 2),
            ]);
        }

        // 步骤2: 获取SQL并记录
        $sql = $query->orderBy('id', 'desc')->toSql();
        $bindings = $query->getBindings();
        
        \Illuminate\Support\Facades\Log::info('[性能-黑名单] 准备执行SQL', [
            'request_id' => $requestId,
            'sql' => $sql,
            'bindings' => $bindings,
        ]);
        
        // 步骤3: 执行数据库查询
        $dbQueryStart = microtime(true);
        $blacklist = $query->orderBy('id', 'desc')->paginate($perPage);
        $dbQueryDuration = (microtime(true) - $dbQueryStart) * 1000;
        
        \Illuminate\Support\Facades\Log::info('[性能-黑名单] 数据库查询完成', [
            'request_id' => $requestId,
            'duration_ms' => round($dbQueryDuration, 2),
            'total_records' => $blacklist->total(),
            'current_page' => $blacklist->currentPage(),
            'per_page' => $blacklist->perPage(),
            'last_page' => $blacklist->lastPage(),
            'returned_count' => $blacklist->count(),
        ]);
        
        // 如果查询超过100ms，记录警告
        if ($dbQueryDuration > 100) {
            \Illuminate\Support\Facades\Log::warning('[性能-黑名单] 数据库查询慢', [
                'request_id' => $requestId,
                'duration_ms' => round($dbQueryDuration, 2),
                'threshold_ms' => 100,
                'total_records' => $blacklist->total(),
            ]);
        }

        // 步骤4: 构建JSON响应
        $responseStart = microtime(true);
        $response = response()->json($blacklist);
        $responseDuration = (microtime(true) - $responseStart) * 1000;
        
        $totalDuration = (microtime(true) - $startTime) * 1000;
        
        \Illuminate\Support\Facades\Log::info('[性能-黑名单] 请求处理完成', [
            'request_id' => $requestId,
            'query_build_ms' => round($queryBuildDuration, 2),
            'db_query_ms' => round($dbQueryDuration, 2),
            'response_build_ms' => round($responseDuration, 2),
            'total_duration_ms' => round($totalDuration, 2),
            'total_records' => $blacklist->total(),
            'returned_count' => $blacklist->count(),
        ]);
        
        // 如果总耗时超过500ms，记录警告
        if ($totalDuration > 500) {
            \Illuminate\Support\Facades\Log::warning('[性能-黑名单] 请求处理慢', [
                'request_id' => $requestId,
                'total_duration_ms' => round($totalDuration, 2),
                'threshold_ms' => 500,
                'db_query_ms' => round($dbQueryDuration, 2),
                'percentage_in_db' => round(($dbQueryDuration / $totalDuration) * 100, 1) . '%',
            ]);
        }

        return $response;
    }

    /**
     * Add single email to blacklist
     */
    public function store(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'reason' => 'nullable|string|max:255',
        ]);

        $email = strtolower(trim($request->email));

        // Check if already exists
        $exists = Blacklist::where('user_id', $request->user()->id)
            ->where('email', $email)
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => '该邮箱已在黑名单中',
            ], 422);
        }

        // Add to blacklist
        $blacklist = Blacklist::create([
            'user_id' => $request->user()->id,
            'email' => $email,
            'reason' => $request->reason,
        ]);

        // Update subscribers
        $updatedCount = \App\Models\Subscriber::where('email', $email)
            ->where('status', '!=', 'blacklisted')
            ->update(['status' => 'blacklisted']);

        // Update list_subscriber pivot table status to blacklisted
        $subscriber = \App\Models\Subscriber::where('email', $email)->first();
        if ($subscriber) {
            \DB::table('list_subscriber')
                ->where('subscriber_id', $subscriber->id)
                ->where('status', '!=', 'blacklisted')
                ->update(['status' => 'blacklisted']);
        }

        return response()->json([
            'message' => '已添加到黑名单',
            'data' => $blacklist,
            'subscribers_updated' => $updatedCount,
        ], 201);
    }

    /**
     * Batch upload emails to blacklist via file (参照 bulkImport)
     */
    public function batchUpload(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:txt,csv,xlsx,xls',
            'reason' => 'nullable|string|max:255',
        ]);

        $file = $request->file('file');
        
        // 生成唯一的导入ID
        $importId = \Illuminate\Support\Str::uuid()->toString();

        // 使用 Laravel Storage 保存文件（自动处理权限）
        $path = $file->storeAs('blacklist_imports', $importId . '.txt');
        $tempPath = storage_path('app/' . $path);

        // 初始化进度缓存
        $cacheKey = "blacklist_import:{$importId}";
        $initialData = [
            'progress' => 0,
            'added' => 0,
            'already_exists' => 0,
            'invalid' => 0,
            'processed' => 0,
            'status' => 'queued',
            'started_at' => now()->toIso8601String(),
        ];
        
        \Illuminate\Support\Facades\Cache::put($cacheKey, $initialData, 3600);
        
        // 记录初始化日志
        \Illuminate\Support\Facades\Log::info('创建黑名单导入任务', [
            'import_id' => $importId,
            'user_id' => $request->user()->id,
            'file_path' => $tempPath,
            'cache_key' => $cacheKey,
            'initial_data' => $initialData,
        ]);

        // 分发异步导入任务
        \App\Jobs\ImportBlacklist::dispatch(
            $tempPath,
            $request->user()->id,
            $request->reason,
            $importId
        )->onQueue('default');

        return response()->json([
            'message' => '导入任务已创建，正在后台处理',
            'data' => [
                'import_id' => $importId,
                'status' => 'queued',
            ],
        ], 202); // 202 Accepted
    }

    /**
     * 获取导入进度
     */
    public function getImportProgress(Request $request, string $importId)
    {
        $startTime = microtime(true);
        $requestId = uniqid('req_');
        
        \Illuminate\Support\Facades\Log::info('[性能] 开始处理黑名单导入进度请求', [
            'request_id' => $requestId,
            'import_id' => $importId,
            'timestamp' => now()->toIso8601String(),
        ]);
        
        $cacheKey = "blacklist_import:{$importId}";
        
        // 记录缓存查询开始
        $cacheStartTime = microtime(true);
        $progress = \Illuminate\Support\Facades\Cache::get($cacheKey);
        $cacheDuration = (microtime(true) - $cacheStartTime) * 1000;
        
        \Illuminate\Support\Facades\Log::info('[性能] 缓存查询完成', [
            'request_id' => $requestId,
            'import_id' => $importId,
            'cache_key' => $cacheKey,
            'duration_ms' => round($cacheDuration, 2),
            'progress_found' => $progress !== null,
            'progress_status' => $progress['status'] ?? 'N/A',
            'progress_percent' => $progress['progress'] ?? 'N/A',
        ]);
        
        if (!$progress) {
            $totalDuration = (microtime(true) - $startTime) * 1000;
            
            \Illuminate\Support\Facades\Log::warning('[性能] 黑名单导入进度不存在', [
                'request_id' => $requestId,
                'import_id' => $importId,
                'cache_key' => $cacheKey,
                'total_duration_ms' => round($totalDuration, 2),
            ]);
            
            return response()->json([
                'message' => '导入任务不存在或已过期'
            ], 404);
        }
        
        // 准备响应
        $responseStartTime = microtime(true);
        $response = response()->json([
            'data' => $progress,
        ]);
        $responseDuration = (microtime(true) - $responseStartTime) * 1000;
        
        $totalDuration = (microtime(true) - $startTime) * 1000;
        
        \Illuminate\Support\Facades\Log::info('[性能] 请求处理完成', [
            'request_id' => $requestId,
            'import_id' => $importId,
            'cache_duration_ms' => round($cacheDuration, 2),
            'response_duration_ms' => round($responseDuration, 2),
            'total_duration_ms' => round($totalDuration, 2),
            'progress_status' => $progress['status'] ?? 'N/A',
        ]);
        
        return $response;
    }

    /**
     * Remove email from blacklist
     */
    public function destroy(Request $request, Blacklist $blacklist)
    {
        if ($blacklist->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权访问'], 403);
        }

        $email = $blacklist->email;
        $blacklist->delete();

        // Optionally restore subscribers to active (you may want to keep them as blacklisted)
        // For now, we'll keep them as blacklisted until manually changed

        return response()->json([
            'message' => '已从黑名单中移除',
        ]);
    }

    /**
     * Batch delete emails from blacklist
     */
    public function batchDestroy(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'required|integer',
        ]);

        $deleted = Blacklist::where('user_id', $request->user()->id)
            ->whereIn('id', $request->ids)
            ->delete();

        return response()->json([
            'message' => "已删除 {$deleted} 个黑名单记录",
            'deleted' => $deleted,
        ]);
    }

    /**
     * Check if email is blacklisted
     */
    public function check(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $email = strtolower(trim($request->email));
        $isBlacklisted = Blacklist::isBlacklisted($request->user()->id, $email);

        return response()->json([
            'email' => $email,
            'is_blacklisted' => $isBlacklisted,
        ]);
    }
}
