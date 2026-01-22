<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subscriber;
use Illuminate\Http\Request;

class SubscriberController extends Controller
{
    public function index(Request $request)
    {
        $startTime = microtime(true);
        $requestId = uniqid('req_');
        
        \Illuminate\Support\Facades\Log::info('[性能-订阅者] 开始处理列表请求', [
            'request_id' => $requestId,
            'user_id' => $request->user()->id ?? 'N/A',
            'list_id' => $request->get('list_id'),
            'status' => $request->get('status'),
            'search' => $request->get('search'),
            'page' => $request->get('page', 1),
            'timestamp' => now()->toIso8601String(),
        ]);
        
        $query = Subscriber::query();

        // Filter by list
        $listFilterStart = microtime(true);
        if ($request->has('list_id')) {
            $listId = $request->list_id;
            
            \Illuminate\Support\Facades\Log::info('[性能-订阅者] 添加列表过滤', [
                'request_id' => $requestId,
                'list_id' => $listId,
            ]);
            
            // ✅ 性能优化：合并为单个 whereHas，避免双重子查询
            $query->whereHas('lists', function ($q) use ($listId, $request) {
                $q->where('lists.id', $listId);
                
                // 当按列表过滤时，状态过滤应该使用 list_subscriber.status
                if ($request->has('status')) {
                    $q->where('list_subscriber.status', $request->status);
                }
            });
            
            // 加载订阅者在该列表中的状态
            $query->with(['lists' => function ($q) use ($listId) {
                $q->where('lists.id', $listId);
            }]);
        } else {
            // 没有列表过滤时，使用 subscribers.status
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }
        }
        $listFilterDuration = (microtime(true) - $listFilterStart) * 1000;
        
        \Illuminate\Support\Facades\Log::info('[性能-订阅者] 列表过滤条件添加完成', [
            'request_id' => $requestId,
            'duration_ms' => round($listFilterDuration, 2),
        ]);

        // Search
        $searchStart = microtime(true);
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('email', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%");
            });
            $searchDuration = (microtime(true) - $searchStart) * 1000;
            
            \Illuminate\Support\Facades\Log::info('[性能-订阅者] 搜索条件添加完成', [
                'request_id' => $requestId,
                'search_term' => $search,
                'duration_ms' => round($searchDuration, 2),
            ]);
        }

        // 获取SQL并记录
        $sql = $query->latest()->toSql();
        $bindings = $query->getBindings();
        
        \Illuminate\Support\Facades\Log::info('[性能-订阅者] 准备执行SQL', [
            'request_id' => $requestId,
            'sql' => $sql,
            'bindings_count' => count($bindings),
        ]);

        // 执行数据库查询
        $dbQueryStart = microtime(true);
        $subscribers = $query->latest()->paginate(15);
        $dbQueryDuration = (microtime(true) - $dbQueryStart) * 1000;
        
        \Illuminate\Support\Facades\Log::info('[性能-订阅者] 数据库查询完成', [
            'request_id' => $requestId,
            'duration_ms' => round($dbQueryDuration, 2),
            'total_records' => $subscribers->total(),
            'current_page' => $subscribers->currentPage(),
            'per_page' => $subscribers->perPage(),
            'last_page' => $subscribers->lastPage(),
            'returned_count' => $subscribers->count(),
        ]);
        
        // 如果查询超过200ms，记录警告
        if ($dbQueryDuration > 200) {
            \Illuminate\Support\Facades\Log::warning('[性能-订阅者] 数据库查询慢', [
                'request_id' => $requestId,
                'duration_ms' => round($dbQueryDuration, 2),
                'threshold_ms' => 200,
                'has_list_filter' => $request->has('list_id'),
                'has_status_filter' => $request->has('status'),
                'has_search' => $request->has('search'),
            ]);
        }
        
        // 如果有 list_id，为每个订阅者添加在该列表中的状态
        $postProcessStart = microtime(true);
        if ($request->has('list_id')) {
            $items = $subscribers->items();
            foreach ($items as $subscriber) {
                if ($subscriber->lists->isNotEmpty()) {
                    $subscriber->list_status = $subscriber->lists[0]->pivot->status;
                    $subscriber->list_unsubscribed_at = $subscriber->lists[0]->pivot->unsubscribed_at;
                } else {
                    $subscriber->list_status = 'active';
                    $subscriber->list_unsubscribed_at = null;
                }
            }
        }
        $postProcessDuration = (microtime(true) - $postProcessStart) * 1000;
        
        \Illuminate\Support\Facades\Log::info('[性能-订阅者] 后处理完成', [
            'request_id' => $requestId,
            'duration_ms' => round($postProcessDuration, 2),
            'processed_count' => $subscribers->count(),
        ]);

        // 构建响应（邮箱脱敏处理）
        $responseStart = microtime(true);
        $maskedItems = collect($subscribers->items())->map(function ($subscriber) {
            $subscriber->email = maskEmail($subscriber->email);
            return $subscriber;
        })->all();
        
        $response = response()->json([
            'data' => $maskedItems,
            'meta' => [
                'current_page' => $subscribers->currentPage(),
                'last_page' => $subscribers->lastPage(),
                'per_page' => $subscribers->perPage(),
                'total' => $subscribers->total(),
            ],
        ]);
        $responseDuration = (microtime(true) - $responseStart) * 1000;
        
        $totalDuration = (microtime(true) - $startTime) * 1000;
        
        \Illuminate\Support\Facades\Log::info('[性能-订阅者] 请求处理完成', [
            'request_id' => $requestId,
            'list_filter_ms' => round($listFilterDuration, 2),
            'db_query_ms' => round($dbQueryDuration, 2),
            'post_process_ms' => round($postProcessDuration, 2),
            'response_build_ms' => round($responseDuration, 2),
            'total_duration_ms' => round($totalDuration, 2),
            'total_records' => $subscribers->total(),
            'returned_count' => $subscribers->count(),
        ]);
        
        // 如果总耗时超过1秒，记录警告
        if ($totalDuration > 1000) {
            \Illuminate\Support\Facades\Log::warning('[性能-订阅者] 请求处理慢', [
                'request_id' => $requestId,
                'total_duration_ms' => round($totalDuration, 2),
                'threshold_ms' => 1000,
                'db_query_ms' => round($dbQueryDuration, 2),
                'percentage_in_db' => round(($dbQueryDuration / $totalDuration) * 100, 1) . '%',
            ]);
        }

        return $response;
    }

    public function store(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'custom_fields' => 'nullable|array',
            'list_ids' => 'required|array',
            'list_ids.*' => 'exists:lists,id',
        ]);

        // Check if email is blacklisted
        $isBlacklisted = \App\Models\Blacklist::isBlacklisted($request->user()->id, $request->email);
        
        if ($isBlacklisted) {
            return response()->json([
                'message' => '该邮箱在黑名单中，无法添加',
            ], 422);
        }

        // 查找现有订阅者（包括软删除的）
        $subscriber = Subscriber::withTrashed()->where('email', $request->email)->first();
        
        if ($subscriber) {
            // 订阅者已存在
            if ($subscriber->trashed()) {
                // 如果是软删除的，恢复它
                $subscriber->restore();
                
                // 更新信息
                $subscriber->update([
                    'first_name' => $request->first_name,
                    'last_name' => $request->last_name,
                    'custom_fields' => $request->custom_fields,
                    'status' => 'active',
                    'subscribed_at' => now(),
                ]);
            }
        } else {
            // 订阅者不存在，创建新订阅者
            $subscriber = Subscriber::create([
                'email' => $request->email,
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'custom_fields' => $request->custom_fields,
                'status' => 'active',
                'subscribed_at' => now(),
                'ip_address' => $request->ip(),
                'source' => 'manual',
            ]);
        }

        // 检查订阅者是否已在目标列表中
        foreach ($request->list_ids as $listId) {
            $exists = $subscriber->lists()
                ->wherePivot('list_id', $listId)
                ->exists();

            if (!$exists) {
                // 添加到列表
                $subscriber->lists()->attach($listId, [
                    'status' => 'active',
                    'subscribed_at' => now(),
                ]);
            } else {
                // 如果已存在但状态是取消订阅，重新激活
                $subscriber->lists()->updateExistingPivot($listId, [
                    'status' => 'active',
                    'subscribed_at' => now(),
                    'unsubscribed_at' => null,
                ]);
            }
        }

        return response()->json([
            'message' => '订阅者添加成功',
            'data' => $subscriber,
        ], 201);
    }

    public function show(Subscriber $subscriber)
    {
        $subscriber->load('lists');

        return response()->json([
            'data' => $subscriber,
        ]);
    }

    public function update(Request $request, Subscriber $subscriber)
    {
        $request->validate([
            'email' => 'sometimes|required|email|unique:subscribers,email,' . $subscriber->id,
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'custom_fields' => 'nullable|array',
            'status' => 'sometimes|in:active,unsubscribed,bounced,complained',
        ]);

        $subscriber->update($request->only([
            'email',
            'first_name',
            'last_name',
            'custom_fields',
            'status',
        ]));

        return response()->json([
            'message' => '订阅者更新成功',
            'data' => $subscriber,
        ]);
    }

    public function destroy(Subscriber $subscriber)
    {
        $subscriber->delete();

        return response()->json([
            'message' => '订阅者删除成功',
        ]);
    }

    public function bulkImport(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,xlsx,xls,txt',
            'list_id' => 'required|exists:lists,id',
        ]);

        $file = $request->file('file');
        $listId = $request->list_id;
        
        // 检查列表权限
        $list = \App\Models\MailingList::findOrFail($listId);
        if ($list->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权访问此列表'], 403);
        }

        // 生成唯一的导入ID
        $importId = \Illuminate\Support\Str::uuid()->toString();

        // 保存文件到临时目录
        $tempPath = storage_path('app/imports/' . $importId . '.csv');
        $directory = dirname($tempPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        $file->move($directory, basename($tempPath));

        // 初始化进度缓存
        $cacheKey = "import_progress:{$importId}";
        $initialData = [
            'progress' => 0,
            'imported' => 0,
            'skipped' => 0,
            'processed' => 0,
            'status' => 'queued',
            'started_at' => now()->toIso8601String(),
        ];
        
        \Illuminate\Support\Facades\Cache::put($cacheKey, $initialData, 3600);
        
        // 记录初始化日志
        \Illuminate\Support\Facades\Log::info('创建导入任务', [
            'import_id' => $importId,
            'list_id' => $listId,
            'user_id' => $request->user()->id,
            'file_path' => $tempPath,
            'cache_key' => $cacheKey,
            'initial_data' => $initialData,
        ]);

        // 分发异步导入任务
        \App\Jobs\ImportSubscribers::dispatch(
            $tempPath,
            $listId,
            $request->user()->id,
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
        
        \Illuminate\Support\Facades\Log::info('[性能] 开始处理导入进度请求', [
            'request_id' => $requestId,
            'import_id' => $importId,
            'timestamp' => now()->toIso8601String(),
        ]);
        
        $cacheKey = "import_progress:{$importId}";
        
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
            
            \Illuminate\Support\Facades\Log::warning('[性能] 导入进度不存在', [
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

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:subscribers,id',
        ]);

        Subscriber::whereIn('id', $request->ids)->delete();

        return response()->json([
            'message' => '批量删除成功',
        ]);
    }
}

