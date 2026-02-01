<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use Illuminate\Http\Request;

class CampaignController extends Controller
{
    public function index(Request $request)
    {
        $query = Campaign::where('user_id', $request->user()->id)
            ->with(['lists:id,name', 'smtpServer:id,name,type'])
            ->select([
                'id', 'user_id', 'list_id', 'smtp_server_id', 'name', 'subject', 
                'status', 'scheduled_at', 'sent_at', 'created_at', 'updated_at',
                'total_recipients', 'total_sent', 'total_delivered', 'total_opened', 
                'total_clicked', 'total_bounced', 'total_complained', 'total_unsubscribed'
            ]);
        
        // 搜索：按名称或主题搜索
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('subject', 'like', "%{$search}%");
            });
        }
        
        // 状态筛选
        if ($request->has('status') && $request->status && $request->status !== 'all') {
            $query->where('status', $request->status);
        }
        
        $campaigns = $query->latest()->paginate(15);

        // 手动添加 list_ids 以避免在每次序列化时都查询
        $campaigns->getCollection()->transform(function ($campaign) {
            $campaign->list_ids = $campaign->lists->pluck('id')->toArray();
            return $campaign;
        });

        return response()->json([
            'data' => $campaigns->items(),
            'meta' => [
                'current_page' => $campaigns->currentPage(),
                'last_page' => $campaigns->lastPage(),
                'per_page' => $campaigns->perPage(),
                'total' => $campaigns->total(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'list_ids' => 'required|array|min:1',
            'list_ids.*' => 'exists:lists,id',
            'smtp_server_id' => 'required|exists:smtp_servers,id',
            'name' => 'required|string|max:255',
            'subject' => 'required|string|max:255',
            'preview_text' => 'nullable|string|max:255',
            'from_name' => 'required|string|max:255',
            'from_email' => 'nullable|email',
            'reply_to' => 'nullable|email',
            'html_content' => 'nullable|string',
            'plain_content' => 'nullable|string',
        ]);

        $campaign = Campaign::create([
            'user_id' => $request->user()->id,
            'smtp_server_id' => $request->smtp_server_id,
            'name' => $request->name,
            'subject' => $request->subject,
            'preview_text' => $request->preview_text,
            'from_name' => $request->from_name,
            'from_email' => $request->from_email,
            'reply_to' => $request->reply_to,
            'html_content' => $request->html_content,
            'plain_content' => $request->plain_content,
            'status' => 'draft',
        ]);

        // Attach lists to campaign
        $campaign->lists()->attach($request->list_ids);

        return response()->json([
            'message' => '邮件活动创建成功',
            'data' => $campaign->load('lists'),
        ], 201);
    }

    public function show(Request $request, Campaign $campaign)
    {
        try {
            if ($campaign->user_id !== $request->user()->id) {
                return response()->json(['message' => '无权访问'], 403);
            }

            // 不加载 sends 关系，因为可能有大量发送记录
            // 编辑活动时不需要所有的发送记录
            $campaign->load(['list', 'lists', 'smtpServer']);
            
            // 手动添加 list_ids（因为从 $appends 中移除了以优化列表页性能）
            $campaign->list_ids = $campaign->lists->pluck('id')->toArray();

            return response()->json([
                'data' => $campaign,
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to show campaign', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'message' => '获取活动详情失败',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, Campaign $campaign)
    {
        if ($campaign->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权访问'], 403);
        }

        // 已取消、正在发送、已完成的活动不能修改
        if (in_array($campaign->status, ['cancelled', 'sending', 'sent', 'completed', 'failed'])) {
            return response()->json([
                'message' => '无法修改已取消、正在发送或已完成的活动',
            ], 422);
        }

        $request->validate([
            'list_ids' => 'sometimes|array|min:1',
            'list_ids.*' => 'exists:lists,id',
            'smtp_server_id' => 'sometimes|required|exists:smtp_servers,id',
            'name' => 'sometimes|required|string|max:255',
            'subject' => 'sometimes|required|string|max:255',
            'preview_text' => 'nullable|string|max:255',
            'from_name' => 'sometimes|required|string|max:255',
            'from_email' => 'nullable|email',
            'reply_to' => 'nullable|email',
            'html_content' => 'nullable|string',
            'plain_content' => 'nullable|string',
        ]);

        $campaign->update($request->only([
            'smtp_server_id',
            'name',
            'subject',
            'preview_text',
            'from_name',
            'from_email',
            'reply_to',
            'html_content',
            'plain_content',
        ]));

        // Update lists if provided
        if ($request->has('list_ids')) {
            $campaign->lists()->sync($request->list_ids);
        }

        return response()->json([
            'message' => '邮件活动更新成功',
            'data' => $campaign->load('lists'),
        ]);
    }

    public function destroy(Request $request, Campaign $campaign)
    {
        if ($campaign->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权访问'], 403);
        }

        // 清理活动队列中的所有任务
        $queueName = 'campaign_' . $campaign->id;
        $deletedCount = \DB::table('jobs')
            ->where('queue', $queueName)
            ->delete();

        \Log::info('Deleted campaign and cleaned queue', [
            'campaign_id' => $campaign->id,
            'campaign_name' => $campaign->name,
            'queue' => $queueName,
            'deleted_jobs_count' => $deletedCount,
        ]);

        $campaign->delete();

        return response()->json([
            'message' => '邮件活动删除成功',
            'deleted_jobs' => $deletedCount,
        ]);
    }

    public function send(Request $request, Campaign $campaign)
    {
        if ($campaign->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权访问'], 403);
        }

        // 只有正在发送或已完成的活动不能修改
        if (in_array($campaign->status, ['sending', 'completed', 'failed'])) {
            return response()->json([
                'message' => '无法修改正在发送或已完成的活动',
            ], 422);
        }

        // ✅ 优化：不在这里计算 total_recipients，延迟到 ProcessScheduledCampaigns 中计算
        // 这样可以立即响应用户，避免等待 6-8 秒
        // total_recipients 会在后台任务处理时准确计算
        $campaign->update([
            'status' => 'scheduled',
            'scheduled_at' => now(),  // 立即发送 = 当前时间
            'total_recipients' => 0,  // 初始为 0，后台任务会更新
        ]);

        return response()->json([
            'message' => '邮件活动已加入发送队列，即将开始发送',
            'data' => $campaign->fresh(),
        ]);
    }

    public function schedule(Request $request, Campaign $campaign)
    {
        if ($campaign->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权访问'], 403);
        }

        // 只有正在发送或已完成的活动不能修改
        if (in_array($campaign->status, ['sending', 'completed', 'failed'])) {
            return response()->json([
                'message' => '无法修改正在发送或已完成的活动',
            ], 422);
        }

        $request->validate([
            'scheduled_at' => 'required|date',
        ]);

        // ✅ 优化：不在这里计算 total_recipients，延迟到 ProcessScheduledCampaigns 中计算
        // 这样可以立即响应用户，避免等待 6-8 秒
        // total_recipients 会在后台任务处理时准确计算
        $campaign->update([
            'status' => 'scheduled',
            'scheduled_at' => $request->scheduled_at,
            'total_recipients' => 0,  // 初始为 0，后台任务会更新
        ]);

        return response()->json([
            'message' => '邮件活动已定时',
            'data' => $campaign->fresh(),
        ]);
    }

    public function duplicate(Request $request, Campaign $campaign)
    {
        if ($campaign->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权访问'], 403);
        }

        // 只复制必要的字段，避免复制大型内容字段导致慢
        $newCampaign = new Campaign();
        $newCampaign->user_id = $campaign->user_id;
        $newCampaign->smtp_server_id = $campaign->smtp_server_id;
        $newCampaign->subject = $campaign->subject;
        $newCampaign->preview_text = $campaign->preview_text;
        $newCampaign->from_name = $campaign->from_name;
        $newCampaign->from_email = $campaign->from_email;
        $newCampaign->reply_to = $campaign->reply_to;
        $newCampaign->html_content = $campaign->html_content;
        $newCampaign->plain_content = $campaign->plain_content;
        
        // 智能命名：如果标题以 #数字 结尾，则数字加1；否则加上 #1
        $originalName = $campaign->name;
        if (preg_match('/#(\d+)$/', $originalName, $matches)) {
            $newCampaign->name = preg_replace('/#(\d+)$/', '#' . ((int)$matches[1] + 1), $originalName);
        } else {
            $newCampaign->name = $originalName . '#1';
        }
        
        $newCampaign->status = 'draft';
        $newCampaign->total_recipients = 0;
        $newCampaign->total_sent = 0;
        $newCampaign->total_delivered = 0;
        $newCampaign->total_opened = 0;
        $newCampaign->total_clicked = 0;
        $newCampaign->total_bounced = 0;
        $newCampaign->total_complained = 0;
        $newCampaign->total_unsubscribed = 0;
        
        $newCampaign->save();

        // 复制列表关联
        $listIds = $campaign->lists()->pluck('lists.id')->toArray();
        if (!empty($listIds)) {
            $newCampaign->lists()->attach($listIds);
        }

        // 立即返回，不加载关系数据（前端会刷新列表）
        return response()->json([
            'message' => '邮件活动已复制',
            'data' => ['id' => $newCampaign->id, 'name' => $newCampaign->name],
        ], 201);
    }

    public function cancelSchedule(Request $request, Campaign $campaign)
    {
        if ($campaign->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权访问'], 403);
        }

        if ($campaign->status !== 'scheduled') {
            return response()->json(['message' => '只能取消已定时的活动'], 400);
        }

        $campaign->update([
            'status' => 'draft',
            'scheduled_at' => null,
        ]);

        return response()->json([
            'message' => '已取消定时发送',
            'data' => $campaign,
        ]);
    }

    public function pause(Request $request, Campaign $campaign)
    {
        if ($campaign->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权访问'], 403);
        }

        // 允许暂停 scheduled 或 sending 状态的活动
        if (!in_array($campaign->status, ['scheduled', 'sending'])) {
            return response()->json(['message' => '只能暂停已定时或正在发送的活动'], 400);
        }

        // 将活动状态改为 paused
        $campaign->update([
            'status' => 'paused',
        ]);

        // 如果有队列任务，将其延迟到很久以后
        $farFuture = now()->addYears(10)->timestamp;
        $queueName = 'campaign_' . $campaign->id;
        
        \DB::table('jobs')
            ->where('queue', $queueName)
            ->whereNull('reserved_at') // 只处理未被保留的任务
            ->update([
                'available_at' => $farFuture,
            ]);

        return response()->json([
            'message' => '活动已暂停',
            'data' => $campaign->fresh(),
        ]);
    }

    public function resume(Request $request, Campaign $campaign)
    {
        if ($campaign->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权访问'], 403);
        }

        if ($campaign->status !== 'paused') {
            return response()->json(['message' => '只能恢复已暂停的活动'], 400);
        }

        // 检查队列中是否有该活动被延迟的任务
        $now = time();
        $queueName = 'campaign_' . $campaign->id;
        
        $delayedJobsCount = \DB::table('jobs')
            ->where('queue', $queueName)
            ->whereNull('reserved_at')
            ->where('available_at', '>', $now + 86400 * 365) // 被延迟很久的任务
            ->count();

        // 判断恢复到哪个状态：
        // 1. 如果有被延迟的任务 → 说明是从 sending 暂停的，恢复为 sending
        // 2. 如果已发送部分邮件 → 恢复为 sending
        // 3. 否则 → 恢复为 scheduled（由调度器处理）
        if ($delayedJobsCount > 0 || $campaign->total_sent > 0) {
            $newStatus = 'sending';
        } else {
            $newStatus = 'scheduled';
        }

        $campaign->update([
            'status' => $newStatus,
        ]);

        // 恢复队列任务（将 available_at 改为当前时间）
        $updatedCount = 0;
        if ($delayedJobsCount > 0) {
            $updatedCount = \DB::table('jobs')
                ->where('queue', $queueName)
                ->whereNull('reserved_at')
                ->where('available_at', '>', $now + 86400 * 365)
                ->update([
                    'available_at' => $now,
                ]);
        }

        $statusText = $newStatus === 'scheduled' ? '已定时' : '发送中';
        
        return response()->json([
            'message' => "活动已恢复为{$statusText}状态",
            'data' => $campaign->fresh(),
            'resumed_jobs' => $updatedCount,
            'new_status' => $newStatus,
        ]);
    }

    public function cancel(Request $request, Campaign $campaign)
    {
        if ($campaign->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权访问'], 403);
        }

        // 只能取消 scheduled 或 sending 状态的活动
        if (!in_array($campaign->status, ['scheduled', 'sending'])) {
            return response()->json([
                'message' => '只能取消已定时或正在发送的活动',
            ], 400);
        }

        $previousStatus = $campaign->status;
        $queueName = 'campaign_' . $campaign->id;

        // 立即更新活动状态为已取消（快速响应用户）
        $campaign->update([
            'status' => 'cancelled',
        ]);

        // 异步删除队列任务（不阻塞响应）
        dispatch(function () use ($campaign, $queueName, $previousStatus) {
            $deletedCount = \DB::table('jobs')
                ->where('queue', $queueName)
                ->delete();

            \Log::info('Cancelled campaign and cleaned queue', [
                'campaign_id' => $campaign->id,
                'campaign_name' => $campaign->name,
                'queue' => $queueName,
                'previous_status' => $previousStatus,
                'deleted_jobs_count' => $deletedCount,
            ]);
        })->afterResponse();

        return response()->json([
            'message' => '活动已取消',
            'data' => $campaign,
        ]);
    }

    public function getPreviewToken(Request $request, Campaign $campaign)
    {
        if ($campaign->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权访问'], 403);
        }

        // 获取活动的订阅者列表
        $listIds = $campaign->lists()->pluck('lists.id');
        
        // 优先获取活跃订阅者，如果没有则获取任意订阅者（用于预览）
        $subscriber = \App\Models\Subscriber::whereHas('lists', function ($query) use ($listIds) {
            $query->whereIn('lists.id', $listIds)
                  ->where('list_subscriber.status', 'active');
        })
        ->inRandomOrder()
        ->first();

        // 如果没有活跃订阅者，获取任意订阅者（包括已取消订阅的）
        if (!$subscriber) {
            $subscriber = \App\Models\Subscriber::whereHas('lists', function ($query) use ($listIds) {
                $query->whereIn('lists.id', $listIds);
            })
            ->inRandomOrder()
            ->first();
        }

        // 如果完全没有订阅者，使用默认值
        if (!$subscriber) {
            return response()->json([
                'unsubscribe_url' => url('/unsubscribe?token=no_subscribers'),
                'subscriber_email' => 'no-subscriber@example.com',
            ]);
        }

        // 获取第一个列表ID
        $listId = $listIds->first();

        // 生成真实的取消订阅链接
        $unsubscribeUrl = \App\Http\Controllers\UnsubscribeController::generateUnsubscribeUrl(
            $subscriber->id,
            $listId,
            $campaign->id
        );

        return response()->json([
            'unsubscribe_url' => $unsubscribeUrl,
            'subscriber_email' => maskEmail($subscriber->email),
        ]);
    }
}

