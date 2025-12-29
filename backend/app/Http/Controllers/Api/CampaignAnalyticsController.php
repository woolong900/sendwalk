<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\SendLog;
use App\Models\EmailOpen;
use App\Models\AbuseReport;
use App\Models\BounceLog;
use App\Models\ListSubscriber;
use Illuminate\Http\Request;

class CampaignAnalyticsController extends Controller
{
    /**
     * 获取特定活动的发送日志
     */
    public function getSendLogs(Request $request, $campaignId)
    {
        $campaign = Campaign::findOrFail($campaignId);
        
        // 检查权限
        if ($campaign->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权访问此活动'], 403);
        }

        $query = SendLog::with(['subscriber', 'smtpServer'])
            ->where('campaign_id', $campaignId)
            ->orderBy('created_at', 'desc');

        // 支持状态筛选
        if ($request->status) {
            $query->where('status', $request->status);
        }

        // 支持搜索
        if ($request->search) {
            $query->where('email', 'like', '%' . $request->search . '%');
        }

        $perPage = $request->per_page ?? 50;
        $logs = $query->paginate($perPage);

        return response()->json($logs);
    }

    /**
     * 获取特定活动的打开记录（按邮箱分组）
     */
    public function getEmailOpens(Request $request, $campaignId)
    {
        $campaign = Campaign::findOrFail($campaignId);
        
        // 检查权限
        if ($campaign->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权访问此活动'], 403);
        }

        // ✅ 性能优化：使用单个查询直接获取所有需要的数据，避免 N+1 查询
        // 使用子查询 + JOIN 获取每个邮箱的首次打开详情
        $subQuery = \DB::table('email_opens as eo')
            ->select([
                'eo.email',
                'eo.subscriber_id',
                \DB::raw('COUNT(*) as open_count'),
                \DB::raw('MAX(eo.opened_at) as last_opened_at'),
                \DB::raw('MIN(eo.opened_at) as first_opened_at'),
                // 使用 MIN 获取首次打开的 IP 和 User Agent
                // 这里使用技巧：SUBSTRING_INDEX 配合 GROUP_CONCAT 获取最早记录的其他字段
                \DB::raw('SUBSTRING_INDEX(GROUP_CONCAT(eo.ip_address ORDER BY eo.opened_at ASC), ",", 1) as first_ip_address'),
                \DB::raw('SUBSTRING_INDEX(GROUP_CONCAT(eo.user_agent ORDER BY eo.opened_at ASC SEPARATOR "|||"), "|||", 1) as first_user_agent')
            ])
            ->where('eo.campaign_id', $campaignId)
            ->groupBy('eo.email', 'eo.subscriber_id');

        // 支持搜索
        if ($request->search) {
            $subQuery->where('eo.email', 'like', '%' . $request->search . '%');
        }

        $subQuery->orderBy('last_opened_at', 'desc');

        $perPage = $request->per_page ?? 50;
        $groupedOpens = $subQuery->paginate($perPage);

        return response()->json($groupedOpens);
    }

    /**
     * 获取特定邮箱的所有打开详情（除了首次打开）
     */
    public function getEmailOpenDetails(Request $request, $campaignId)
    {
        $campaign = Campaign::findOrFail($campaignId);
        
        // 检查权限
        if ($campaign->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权访问此活动'], 403);
        }

        $email = $request->email;
        if (!$email) {
            return response()->json(['message' => '邮箱地址不能为空'], 400);
        }

        // 获取所有打开记录，按时间升序
        $allDetails = EmailOpen::where('campaign_id', $campaignId)
            ->where('email', $email)
            ->orderBy('opened_at', 'asc')
            ->get();

        // 跳过第一条（首次打开已在主行显示）
        $details = $allDetails->skip(1);

        return response()->json(['data' => $details->values()]);
    }

    /**
     * 获取活动的打开统计概览
     */
    public function getOpenStats($campaignId, Request $request)
    {
        $campaign = Campaign::findOrFail($campaignId);
        
        // 检查权限
        if ($campaign->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权访问此活动'], 403);
        }

        $totalOpens = EmailOpen::where('campaign_id', $campaignId)->count();
        $uniqueOpens = EmailOpen::where('campaign_id', $campaignId)
            ->distinct('subscriber_id')
            ->count('subscriber_id');
        
        // 平均每人打开次数
        $avgOpensPerPerson = $uniqueOpens > 0 ? round($totalOpens / $uniqueOpens, 2) : 0;

        return response()->json([
            'data' => [
                'total_opens' => $totalOpens,
                'unique_opens' => $uniqueOpens,
                'avg_opens_per_person' => $avgOpensPerPerson,
                'total_delivered' => $campaign->total_delivered,
                'open_rate' => $campaign->open_rate,
            ]
        ]);
    }

    /**
     * 获取特定活动的投诉报告
     */
    public function getAbuseReports(Request $request, $campaignId)
    {
        $campaign = Campaign::findOrFail($campaignId);
        
        // 检查权限
        if ($campaign->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权访问此活动'], 403);
        }

        $query = AbuseReport::with('subscriber')
            ->where('campaign_id', $campaignId)
            ->orderBy('created_at', 'desc');

        // 支持搜索
        if ($request->search) {
            $query->where('email', 'like', '%' . $request->search . '%');
        }

        $perPage = $request->per_page ?? 50;
        $reports = $query->paginate($perPage);

        return response()->json($reports);
    }

    /**
     * 获取特定活动的弹回记录
     */
    public function getBounces(Request $request, $campaignId)
    {
        $campaign = Campaign::findOrFail($campaignId);
        
        // 检查权限
        if ($campaign->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权访问此活动'], 403);
        }

        $query = BounceLog::with('subscriber')
            ->where('campaign_id', $campaignId)
            ->orderBy('created_at', 'desc');

        // 支持搜索
        if ($request->search) {
            $query->where('email', 'like', '%' . $request->search . '%');
        }

        $perPage = $request->per_page ?? 50;
        $bounces = $query->paginate($perPage);

        return response()->json($bounces);
    }

    /**
     * 获取特定活动的取消订阅记录
     */
    public function getUnsubscribes(Request $request, $campaignId)
    {
        $campaign = Campaign::findOrFail($campaignId);
        
        // 检查权限
        if ($campaign->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权访问此活动'], 403);
        }

        // 获取该活动关联的列表
        $listIds = $campaign->lists()->pluck('lists.id')->toArray();
        if (empty($listIds)) {
            // 如果使用的是单个列表
            $listIds = [$campaign->list_id];
        }

        $query = ListSubscriber::with(['subscriber', 'list'])
            ->whereIn('list_id', $listIds)
            ->where('status', 'unsubscribed')
            ->whereNotNull('unsubscribed_at')
            ->orderBy('unsubscribed_at', 'desc');

        // 支持搜索
        if ($request->search) {
            $query->whereHas('subscriber', function($q) use ($request) {
                $q->where('email', 'like', '%' . $request->search . '%');
            });
        }

        $perPage = $request->per_page ?? 50;
        $unsubscribes = $query->paginate($perPage);

        // 格式化数据
        $unsubscribes->getCollection()->transform(function($item) {
            return [
                'id' => $item->id,
                'email' => $item->subscriber->email ?? 'N/A',
                'list_name' => $item->list->name ?? 'N/A',
                'unsubscribed_at' => $item->unsubscribed_at,
                'subscriber' => $item->subscriber,
            ];
        });

        return response()->json($unsubscribes);
    }

    /**
     * 获取特定活动的送达记录（成功发送的邮件）
     */
    public function getDeliveries(Request $request, $campaignId)
    {
        $campaign = Campaign::findOrFail($campaignId);
        
        // 检查权限
        if ($campaign->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权访问此活动'], 403);
        }

        $query = SendLog::with(['subscriber', 'smtpServer'])
            ->where('campaign_id', $campaignId)
            ->where('status', 'delivered')
            ->orderBy('completed_at', 'desc');

        // 支持搜索
        if ($request->search) {
            $query->where('email', 'like', '%' . $request->search . '%');
        }

        $perPage = $request->per_page ?? 50;
        $deliveries = $query->paginate($perPage);

        return response()->json($deliveries);
    }
}
