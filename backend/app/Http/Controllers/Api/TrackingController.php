<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignSend;
use App\Models\EmailOpen;
use App\Models\Link;
use App\Models\LinkClick;
use App\Models\Subscriber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TrackingController extends Controller
{
    /**
     * Track email open
     */
    public function trackOpen(Request $request, $campaignId, $subscriberId)
    {
        try {
            $send = CampaignSend::where('campaign_id', $campaignId)
                ->where('subscriber_id', $subscriberId)
                ->first();

            if ($send) {
                $subscriber = Subscriber::find($subscriberId);
                
                // 记录每一次打开（无论是否首次）
                EmailOpen::create([
                    'campaign_id' => $campaignId,
                    'subscriber_id' => $subscriberId,
                    'email' => $subscriber ? $subscriber->email : '',
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'opened_at' => now(),
                ]);

                // 如果是首次打开，更新统计
                if (!$send->opened_at) {
                    $send->update([
                        'opened_at' => now(),
                    ]);

                    // Update campaign stats
                    Campaign::where('id', $campaignId)->increment('total_opened');

                    Log::info('Email opened (first time)', [
                        'campaign_id' => $campaignId,
                        'subscriber_id' => $subscriberId,
                        'ip' => $request->ip(),
                    ]);
                } else {
                    Log::info('Email opened (repeat)', [
                        'campaign_id' => $campaignId,
                        'subscriber_id' => $subscriberId,
                        'ip' => $request->ip(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to track email open', [
                'campaign_id' => $campaignId,
                'subscriber_id' => $subscriberId,
                'error' => $e->getMessage(),
            ]);
        }

        // Return a 1x1 transparent GIF
        return response(base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'))
            ->header('Content-Type', 'image/gif')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    /**
     * Track link click
     */
    public function trackClick($campaignId, $linkId, $subscriberId)
    {
        try {
            $link = Link::find($linkId);

            if ($link) {
                // Record click
                LinkClick::create([
                    'link_id' => $linkId,
                    'campaign_id' => $campaignId,
                    'subscriber_id' => $subscriberId,
                    'clicked_at' => now(),
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ]);

                // Update campaign stats
                $send = CampaignSend::where('campaign_id', $campaignId)
                    ->where('subscriber_id', $subscriberId)
                    ->first();

                if ($send && !$send->clicked_at) {
                    $send->update([
                        'clicked_at' => now(),
                    ]);

                    Campaign::where('id', $campaignId)->increment('total_clicked');
                }

                Log::info('Link clicked', [
                    'campaign_id' => $campaignId,
                    'link_id' => $linkId,
                    'subscriber_id' => $subscriberId,
                ]);

                // Redirect to original URL
                return redirect($link->original_url);
            }
        } catch (\Exception $e) {
            Log::error('Failed to track link click', [
                'campaign_id' => $campaignId,
                'link_id' => $linkId,
                'subscriber_id' => $subscriberId,
                'error' => $e->getMessage(),
            ]);
        }

        return response('Link not found', 404);
    }

    /**
     * Track unsubscribe
     */
    public function unsubscribe($campaignId, $subscriberId)
    {
        try {
            $send = CampaignSend::where('campaign_id', $campaignId)
                ->where('subscriber_id', $subscriberId)
                ->first();

            if ($send) {
                $send->subscriber->update([
                    'status' => 'unsubscribed',
                ]);

                // Update campaign stats
                Campaign::where('id', $campaignId)->increment('total_unsubscribed');

                Log::info('User unsubscribed', [
                    'campaign_id' => $campaignId,
                    'subscriber_id' => $subscriberId,
                ]);

                return view('unsubscribe-success');
            }
        } catch (\Exception $e) {
            Log::error('Failed to unsubscribe', [
                'campaign_id' => $campaignId,
                'subscriber_id' => $subscriberId,
                'error' => $e->getMessage(),
            ]);
        }

        return response('Invalid request', 404);
    }
}

