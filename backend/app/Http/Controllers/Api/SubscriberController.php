<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subscriber;
use App\Models\MailingList;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SubscriberController extends Controller
{
    public function index(Request $request)
    {
        $startTime = microtime(true);
        $listId = $request->get('list_id');
        
        // 如果有 list_id，使用优化的 JOIN 查询
        if ($listId) {
            return $this->getSubscribersByList($request, $listId, $startTime);
        }
        
        // 没有 list_id 的通用查询
        $query = Subscriber::query();
        
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('email', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%");
            });
        }
        
        $subscribers = $query->latest()->paginate(15);
        
        $maskedItems = collect($subscribers->items())->map(function ($subscriber) {
            $subscriber->email = maskEmail($subscriber->email);
            return $subscriber;
        })->all();

        return response()->json([
            'data' => $maskedItems,
            'meta' => [
                'current_page' => $subscribers->currentPage(),
                'last_page' => $subscribers->lastPage(),
                'per_page' => $subscribers->perPage(),
                'total' => $subscribers->total(),
            ],
        ]);
    }

    /**
     * 优化的列表订阅者查询
     * 使用分离查询代替 JOIN，性能提升 60+ 倍
     */
    private function getSubscribersByList(Request $request, $listId, $startTime)
    {
        $perPage = 15;
        $page = (int) $request->get('page', 1);
        $hasSearch = $request->has('search');
        $hasStatus = $request->has('status');
        
        // 第一步：从 list_subscriber 获取订阅者 ID（极快，使用索引）
        $pivotQuery = DB::table('list_subscriber')
            ->where('list_id', $listId);
        
        if ($hasStatus) {
            $pivotQuery->where('status', $request->status);
        }
        
        // 如果有搜索，需要用不同的方式处理
        if ($hasSearch) {
            return $this->getSubscribersByListWithSearch($request, $listId, $startTime);
        }
        
        // 获取 total（无搜索时使用缓存）
        if ($hasStatus) {
            $total = (clone $pivotQuery)->count();
        } else {
            $total = DB::table('lists')->where('id', $listId)->value('subscribers_count') ?? 0;
        }
        
        // 游标分页
        $afterId = $request->get('after_id');
        if ($afterId) {
            $pivotQuery->where('subscriber_id', '<', $afterId);
        } else if ($page > 1) {
            $pivotQuery->offset(($page - 1) * $perPage);
        }
        
        // 获取当前页的 pivot 数据
        $pivotData = $pivotQuery
            ->orderBy('subscriber_id', 'desc')
            ->limit($perPage)
            ->get(['subscriber_id', 'status', 'subscribed_at', 'unsubscribed_at']);
        
        if ($pivotData->isEmpty()) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'current_page' => $page,
                    'last_page' => max(1, (int) ceil($total / $perPage)),
                    'per_page' => $perPage,
                    'total' => $total,
                ],
            ]);
        }
        
        // 第二步：批量获取订阅者详情（使用 IN 查询，极快）
        $subscriberIds = $pivotData->pluck('subscriber_id')->toArray();
        $subscribers = DB::table('subscribers')
            ->whereIn('id', $subscriberIds)
            ->whereNull('deleted_at')
            ->get(['id', 'email', 'first_name', 'last_name', 'status', 'created_at'])
            ->keyBy('id');
        
        // 合并数据，保持 pivot 的排序
        $maskedItems = $pivotData->map(function ($pivot) use ($subscribers) {
            $sub = $subscribers->get($pivot->subscriber_id);
            if (!$sub) return null;
            
            return [
                'id' => $sub->id,
                'email' => maskEmail($sub->email),
                'first_name' => $sub->first_name,
                'last_name' => $sub->last_name,
                'status' => $sub->status,
                'list_status' => $pivot->status,
                'subscribed_at' => $pivot->subscribed_at,
                'list_unsubscribed_at' => $pivot->unsubscribed_at,
                'created_at' => $sub->created_at,
            ];
        })->filter()->values()->all();
        
        $lastPage = $total > 0 ? max(1, (int) ceil($total / $perPage)) : 1;
        $lastId = $pivotData->isNotEmpty() ? $pivotData->last()->subscriber_id : null;

        return response()->json([
            'data' => $maskedItems,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'has_more' => count($maskedItems) === $perPage,
                'next_cursor' => $lastId,
            ],
        ]);
    }

    /**
     * 带搜索的查询（需要 JOIN）
     */
    private function getSubscribersByListWithSearch(Request $request, $listId, $startTime)
    {
        $perPage = 15;
        $page = (int) $request->get('page', 1);
        $search = $request->search;
        
        // 搜索时必须用 JOIN
        $query = DB::table('list_subscriber')
            ->join('subscribers', 'list_subscriber.subscriber_id', '=', 'subscribers.id')
            ->where('list_subscriber.list_id', $listId)
            ->whereNull('subscribers.deleted_at')
            ->where(function ($q) use ($search) {
                $q->where('subscribers.email', 'like', "%{$search}%")
                    ->orWhere('subscribers.first_name', 'like', "%{$search}%")
                    ->orWhere('subscribers.last_name', 'like', "%{$search}%");
            });
        
        if ($request->has('status')) {
            $query->where('list_subscriber.status', $request->status);
        }
        
        $total = (clone $query)->count();
        
        $items = $query
            ->select([
                'subscribers.id',
                'subscribers.email',
                'subscribers.first_name',
                'subscribers.last_name',
                'subscribers.status',
                'subscribers.created_at',
                'list_subscriber.status as list_status',
                'list_subscriber.subscribed_at',
                'list_subscriber.unsubscribed_at as list_unsubscribed_at',
            ])
            ->orderBy('list_subscriber.subscriber_id', 'desc')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();
        
        $maskedItems = $items->map(function ($item) {
            return [
                'id' => $item->id,
                'email' => maskEmail($item->email),
                'first_name' => $item->first_name,
                'last_name' => $item->last_name,
                'status' => $item->status,
                'list_status' => $item->list_status,
                'subscribed_at' => $item->subscribed_at,
                'list_unsubscribed_at' => $item->list_unsubscribed_at,
                'created_at' => $item->created_at,
            ];
        })->all();
        
        $lastPage = $total > 0 ? max(1, (int) ceil($total / $perPage)) : 1;

        return response()->json([
            'data' => $maskedItems,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ]);
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

