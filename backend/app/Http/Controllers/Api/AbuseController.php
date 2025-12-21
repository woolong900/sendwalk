<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AbuseReport;
use App\Models\Campaign;
use App\Models\Subscriber;
use App\Models\Blacklist;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AbuseController extends Controller
{
    /**
     * 举报滥用
     */
    public function reportAbuse(Request $request, $campaignId, $subscriberId)
    {
        try {
            $campaign = Campaign::findOrFail($campaignId);
            $subscriber = Subscriber::findOrFail($subscriberId);

            // 检查是否已经举报过
            $existingReport = AbuseReport::where('campaign_id', $campaignId)
                ->where('subscriber_id', $subscriberId)
                ->first();

            if ($existingReport) {
                return response()->json([
                    'success' => true,
                    'message' => 'You have already submitted a report. We will process it as soon as possible.',
                ]);
            }

            // 创建举报记录
            $report = AbuseReport::create([
                'campaign_id' => $campaignId,
                'subscriber_id' => $subscriberId,
                'email' => $subscriber->email,
                'reason' => $request->input('reason'),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            Log::info('Abuse report submitted', [
                'report_id' => $report->id,
                'campaign_id' => $campaignId,
                'campaign_name' => $campaign->name,
                'subscriber_id' => $subscriberId,
                'email' => $subscriber->email,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Thank you for your report. We will investigate and take appropriate action.',
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to submit abuse report', [
                'campaign_id' => $campaignId,
                'subscriber_id' => $subscriberId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to submit report. Please try again later.',
            ], 500);
        }
    }

    /**
     * 屏蔽邮箱地址（添加到黑名单）
     */
    public function blockAddress(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email',
            ]);

            $email = $request->input('email');

            // 查找订阅者
            $subscriber = Subscriber::where('email', $email)->first();

            if (!$subscriber) {
                return response()->json([
                    'success' => false,
                    'message' => 'Email address not found.',
                ], 404);
            }

            // 检查是否已在黑名单中
            $existingBlacklist = Blacklist::where('email', $email)
                ->where('user_id', $subscriber->user_id)
                ->first();

            if ($existingBlacklist) {
                return response()->json([
                    'success' => true,
                    'message' => 'This email address is already blocked.',
                ]);
            }

            // 添加到黑名单
            Blacklist::create([
                'user_id' => $subscriber->user_id,
                'email' => $email,
                'reason' => 'User requested block via X-EBS',
            ]);

            // 更新订阅者状态
            $subscriber->update(['status' => 'blacklisted']);

            // 更新所有列表中的状态
            \DB::table('list_subscriber')
                ->where('subscriber_id', $subscriber->id)
                ->update(['status' => 'blacklisted']);

            Log::info('Email address blocked via X-EBS', [
                'email' => $email,
                'subscriber_id' => $subscriber->id,
                'user_id' => $subscriber->user_id,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Your email address has been successfully blocked. You will no longer receive any emails from us.',
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to block email address', [
                'email' => $request->input('email'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to block email address. Please try again later.',
            ], 500);
        }
    }
}
