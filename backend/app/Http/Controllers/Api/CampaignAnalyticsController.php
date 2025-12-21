<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\SendLog;
use App\Models\EmailOpen;
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

        // 按email分组统计打开次数
        $subQuery = EmailOpen::selectRaw('
                email,
                subscriber_id,
                COUNT(*) as open_count,
                MAX(opened_at) as last_opened_at,
                MIN(opened_at) as first_opened_at
            ')
            ->where('campaign_id', $campaignId)
            ->groupBy('email', 'subscriber_id');

        // 支持搜索
        if ($request->search) {
            $subQuery->where('email', 'like', '%' . $request->search . '%');
        }

        $subQuery->orderBy('last_opened_at', 'desc');

        $perPage = $request->per_page ?? 50;
        $groupedOpens = $subQuery->paginate($perPage);

        // 为每个email获取首次打开的详细信息
        $emails = $groupedOpens->pluck('email')->toArray();
        
        if (!empty($emails)) {
            // 获取每个邮箱的首次打开记录
            $firstOpenDetails = [];
            foreach ($emails as $email) {
                $firstOpen = EmailOpen::where('campaign_id', $campaignId)
                    ->where('email', $email)
                    ->orderBy('opened_at', 'asc')
                    ->first();
                if ($firstOpen) {
                    $firstOpenDetails[$email] = $firstOpen;
                }
            }

            // 合并数据
            $data = $groupedOpens->getCollection()->map(function($open) use ($firstOpenDetails) {
                $firstOpen = $firstOpenDetails[$open->email] ?? null;
                $open->first_ip_address = $firstOpen->ip_address ?? null;
                $open->first_user_agent = $firstOpen->user_agent ?? null;
                return $open;
            });

            $groupedOpens->setCollection($data);
        }

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
}
