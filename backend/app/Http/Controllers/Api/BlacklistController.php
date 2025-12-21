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

        // Parse emails (one per line or comma-separated)
        $emailsText = $request->emails;
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
