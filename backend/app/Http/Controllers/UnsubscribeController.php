<?php

namespace App\Http\Controllers;

use App\Models\Subscriber;
use App\Models\Campaign;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;

class UnsubscribeController extends Controller
{
    /**
     * 获取取消订阅信息
     */
    public function show(Request $request)
    {
        try {
            // 解密 token
            $data = Crypt::decryptString($request->token);
            $params = json_decode($data, true);
            
            $subscriber = Subscriber::find($params['subscriber_id']);
            $campaign = Campaign::find($params['campaign_id']);
            $listId = $params['list_id'];
            
            if (!$subscriber) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Subscriber not found',
                ], 404);
            }
            
            // 检查是否已经取消订阅
            $subscription = DB::table('list_subscriber')
                ->where('subscriber_id', $subscriber->id)
                ->where('list_id', $listId)
                ->first();
            
            if ($subscription && $subscription->status === 'unsubscribed') {
                return response()->json([
                    'status' => 'already_unsubscribed',
                    'message' => 'You have already unsubscribed',
                    'subscriber' => $subscriber,
                ]);
            }
            
            return response()->json([
                'status' => 'success',
                'subscriber' => $subscriber,
                'campaign' => $campaign,
                'token' => $request->token,
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid unsubscribe link',
            ], 400);
        }
    }
    
    /**
     * 处理取消订阅请求（支持 GET 和 POST）
     * POST 用于 one-click unsubscribe (RFC 8058, required by Gmail/Yahoo since Feb 2024)
     */
    public function unsubscribe(Request $request)
    {
        try {
            // 解密 token
            $data = Crypt::decryptString($request->token);
            $params = json_decode($data, true);
            
            $subscriber = Subscriber::find($params['subscriber_id']);
            $listId = $params['list_id'];
            $campaignId = $params['campaign_id'];
            
            if (!$subscriber) {
                return response()->json(['message' => 'Subscriber not found'], 404);
            }
            
            // 更新订阅状态
            DB::table('list_subscriber')
                ->where('subscriber_id', $subscriber->id)
                ->where('list_id', $listId)
                ->update([
                    'status' => 'unsubscribed',
                    'unsubscribed_at' => now(),
                ]);
            
            // 更新活动统计
            if ($campaignId) {
                Campaign::where('id', $campaignId)
                    ->increment('total_unsubscribed');
            }
            
            \Log::info('Subscriber unsubscribed', [
                'subscriber_id' => $subscriber->id,
                'email' => $subscriber->email,
                'list_id' => $listId,
                'campaign_id' => $campaignId,
                'method' => $request->method(),
                'one_click' => $request->method() === 'POST',
            ]);
            
            // 对于 POST 请求（one-click unsubscribe），返回简单的成功响应
            if ($request->method() === 'POST') {
                return response('', 200);
            }
            
            // 对于 GET 请求，返回 JSON 响应
            return response()->json([
                'message' => 'You have successfully unsubscribed',
                'subscriber' => $subscriber,
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Unsubscribe failed', [
                'error' => $e->getMessage(),
                'token' => $request->token,
                'method' => $request->method(),
            ]);
            
            // 对于 POST 请求，返回简单的错误响应
            if ($request->method() === 'POST') {
                return response('', 500);
            }
            
            return response()->json([
                'message' => 'Failed to unsubscribe. Please try again later.',
            ], 500);
        }
    }
    
    /**
     * 生成取消订阅 URL
     */
    public static function generateUnsubscribeUrl($subscriberId, $listId, $campaignId)
    {
        $data = json_encode([
            'subscriber_id' => $subscriberId,
            'list_id' => $listId,
            'campaign_id' => $campaignId,
            'timestamp' => time(),
        ]);
        
        $token = Crypt::encryptString($data);
        
        // 使用前端 URL（从环境变量获取，如果没有则使用当前域名）
        $frontendUrl = env('FRONTEND_URL', config('app.url'));
        
        return $frontendUrl . '/unsubscribe?token=' . urlencode($token);
    }
}

