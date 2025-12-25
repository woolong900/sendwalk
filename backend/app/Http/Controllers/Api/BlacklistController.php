<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Blacklist;
use Illuminate\Http\Request;

class BlacklistController extends Controller
{
    /**
     * Get all blacklisted emails
     */
    public function index(Request $request)
    {
        $query = Blacklist::where('user_id', $request->user()->id);

        // Search filter
        if ($request->has('search')) {
            $search = $request->search;
            $query->where('email', 'like', "%{$search}%");
        }

        $blacklist = $query->latest()->paginate($request->get('per_page', 15));

        return response()->json($blacklist);
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

        // 保存文件到临时目录
        $tempPath = storage_path('app/blacklist_imports/' . $importId . '.txt');
        $directory = dirname($tempPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        $file->move($directory, basename($tempPath));

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
        $cacheKey = "blacklist_import:{$importId}";
        $progress = \Illuminate\Support\Facades\Cache::get($cacheKey);
        
        // 记录查询日志（只记录前5次）
        static $queryCount = [];
        if (!isset($queryCount[$importId])) {
            $queryCount[$importId] = 0;
        }
        $queryCount[$importId]++;
        
        if ($queryCount[$importId] <= 5) {
            \Illuminate\Support\Facades\Log::info('查询黑名单导入进度', [
                'import_id' => $importId,
                'cache_key' => $cacheKey,
                'query_count' => $queryCount[$importId],
                'progress_found' => $progress !== null,
                'progress_data' => $progress,
            ]);
        }
        
        if (!$progress) {
            \Illuminate\Support\Facades\Log::warning('黑名单导入进度不存在', [
                'import_id' => $importId,
                'cache_key' => $cacheKey,
            ]);
            
            return response()->json([
                'message' => '导入任务不存在或已过期'
            ], 404);
        }
        
        return response()->json([
            'data' => $progress,
        ]);
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
