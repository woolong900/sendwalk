<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use Illuminate\Http\Request;

class CampaignController extends Controller
{
    public function index(Request $request)
    {
        $campaigns = Campaign::where('user_id', $request->user()->id)
            ->with(['list', 'lists', 'smtpServer'])
            ->latest()
            ->paginate(15);

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

        // 只有正在发送或已完成的活动不能修改
        if (in_array($campaign->status, ['sending', 'completed', 'failed'])) {
            return response()->json([
                'message' => '无法修改正在发送或已完成的活动',
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

        // 计算总收件人数量
        $listIds = $campaign->lists->pluck('id')->toArray();
        $totalRecipients = 0;
        
        if (!empty($listIds)) {
            $totalRecipients = \App\Models\Subscriber::whereHas('lists', function ($query) use ($listIds) {
                $query->whereIn('lists.id', $listIds)
                      ->where('list_subscriber.status', 'active');
            })->distinct()->count();
        }

        // ✅ 统一逻辑：设置为 scheduled 状态，scheduled_at 为当前时间
        // 定时任务会立即检测到并处理
        $campaign->update([
            'status' => 'scheduled',
            'scheduled_at' => now(),  // 立即发送 = 当前时间
            'total_recipients' => $totalRecipients,
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

        // 计算总收件人数量
        $listIds = $campaign->lists->pluck('id')->toArray();
        $totalRecipients = 0;
        
        if (!empty($listIds)) {
            $totalRecipients = \App\Models\Subscriber::whereHas('lists', function ($query) use ($listIds) {
                $query->whereIn('lists.id', $listIds)
                      ->where('list_subscriber.status', 'active');
            })->distinct()->count();
        }

        $campaign->update([
            'status' => 'scheduled',
            'scheduled_at' => $request->scheduled_at,
            'total_recipients' => $totalRecipients,
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

        $newCampaign = $campaign->replicate();
        
        // 智能命名：如果标题以 #数字 结尾，则数字加1；否则加上 #1
        $originalName = $campaign->name;
        if (preg_match('/#(\d+)$/', $originalName, $matches)) {
            // 标题以 #数字 结尾，提取数字并加1
            $number = (int)$matches[1];
            $newNumber = $number + 1;
            $newCampaign->name = preg_replace('/#(\d+)$/', '#' . $newNumber, $originalName);
        } else {
            // 标题不以 #数字 结尾，加上 #1
            $newCampaign->name = $originalName . '#1';
        }
        
        $newCampaign->status = 'draft';
        $newCampaign->scheduled_at = null;
        $newCampaign->sent_at = null;
        
        // 重置统计字段
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

        // 重新加载关系数据
        $newCampaign->load('lists', 'smtpServer');

        return response()->json([
            'message' => '邮件活动已复制',
            'data' => $newCampaign,
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

        // 从 jobs 表中删除该活动队列的所有任务
        $queueName = 'campaign_' . $campaign->id;
        $deletedCount = \DB::table('jobs')
            ->where('queue', $queueName)
            ->delete();

        \Log::info('Cancelled campaign and cleaned queue', [
            'campaign_id' => $campaign->id,
            'campaign_name' => $campaign->name,
            'queue' => $queueName,
            'previous_status' => $campaign->status,
            'deleted_jobs_count' => $deletedCount,
        ]);

        // 更新活动状态为草稿，清空定时时间
        $campaign->update([
            'status' => 'draft',
            'scheduled_at' => null,
        ]);

        return response()->json([
            'message' => '活动已取消，已恢复为草稿状态',
            'data' => $campaign,
            'deleted_jobs' => $deletedCount,
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
            'subscriber_email' => $subscriber->email,
        ]);
    }
}

