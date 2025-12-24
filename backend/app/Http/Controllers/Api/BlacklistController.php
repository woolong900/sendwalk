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
     * Batch upload emails to blacklist
     */
    public function batchUpload(Request $request)
    {
        $request->validate([
            'emails' => 'required|string',
            'reason' => 'nullable|string|max:255',
        ]);

        $emailsText = $request->emails;
        $emailCount = substr_count($emailsText, '@'); // 粗略估算邮箱数量

        // 如果邮箱数量超过10000，使用队列异步处理
        if ($emailCount > 10000) {
            return $this->batchUploadAsync($request, $emailsText);
        }

        // 小批量直接同步处理（保持原有逻辑）
        $emails = preg_split('/[\r\n,;]+/', $emailsText);
        $emails = array_filter(array_map('trim', $emails));

        if (empty($emails)) {
            return response()->json([
                'message' => '未找到有效的邮箱地址',
            ], 422);
        }

        $result = Blacklist::addBatch(
            $request->user()->id,
            $emails,
            $request->reason
        );

        return response()->json([
            'message' => '批量上传完成',
            'added' => $result['added'],
            'already_exists' => $result['already_exists'],
            'invalid' => $result['invalid'],
            'skipped' => $result['skipped'], // 为向后兼容保留
            'subscribers_updated' => $result['subscribers_updated'],
        ]);
    }

    /**
     * 大批量异步上传（使用队列）
     */
    protected function batchUploadAsync(Request $request, string $emailsText)
    {
        // 生成唯一任务ID
        $taskId = 'import_' . time() . '_' . uniqid();
        
        // 使用生成器分批读取，避免内存溢出
        $batchSize = 1000; // 每批处理1000条
        $emails = [];
        $batchNumber = 0;
        $totalEmails = 0;

        // 逐行处理，避免一次性加载所有数据到内存
        $lines = explode("\n", $emailsText);
        
        foreach ($lines as $line) {
            // 支持多种分隔符
            $lineEmails = preg_split('/[,;]+/', $line);
            
            foreach ($lineEmails as $email) {
                $email = trim($email);
                if (!empty($email)) {
                    $emails[] = $email;
                    $totalEmails++;
                    
                    // 达到批次大小，分发队列任务
                    if (count($emails) >= $batchSize) {
                        $batchNumber++;
                        \App\Jobs\ImportBlacklistJob::dispatch(
                            $request->user()->id,
                            $emails,
                            $request->reason,
                            $taskId,
                            $batchNumber,
                            0 // 总批次数暂时设为0，后面更新
                        );
                        $emails = []; // 清空数组
                    }
                }
            }
        }

        // 处理剩余的邮箱
        if (!empty($emails)) {
            $batchNumber++;
            \App\Jobs\ImportBlacklistJob::dispatch(
                $request->user()->id,
                $emails,
                $request->reason,
                $taskId,
                $batchNumber,
                0
            );
        }

        // 更新总批次数
        $totalBatches = $batchNumber;
        
        // 初始化进度缓存
        \Cache::put("blacklist_import_{$taskId}", [
            'total_batches' => $totalBatches,
            'completed_batches' => 0,
            'total_emails' => $totalEmails,
            'added' => 0,
            'already_exists' => 0,
            'invalid' => 0,
            'subscribers_updated' => 0,
            'status' => 'processing',
            'started_at' => now()->toIso8601String(),
        ], 86400); // 缓存24小时

        \Log::info("黑名单批量导入已提交到队列", [
            'user_id' => $request->user()->id,
            'task_id' => $taskId,
            'total_emails' => $totalEmails,
            'total_batches' => $totalBatches,
        ]);

        return response()->json([
            'message' => '大批量导入已提交到队列处理，请稍后查看进度',
            'task_id' => $taskId,
            'total_emails' => $totalEmails,
            'total_batches' => $totalBatches,
            'status' => 'processing',
        ], 202); // 202 Accepted
    }

    /**
     * 查询导入进度
     */
    public function importProgress(Request $request, string $taskId)
    {
        $cacheKey = "blacklist_import_{$taskId}";
        $progress = \Cache::get($cacheKey);

        if (!$progress) {
            return response()->json([
                'message' => '未找到该任务',
            ], 404);
        }

        // 计算进度百分比
        $progress['progress_percentage'] = $progress['total_batches'] > 0
            ? round(($progress['completed_batches'] / $progress['total_batches']) * 100, 2)
            : 0;

        return response()->json($progress);
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
