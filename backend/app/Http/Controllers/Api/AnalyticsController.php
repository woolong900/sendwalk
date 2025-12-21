<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignSend;
use App\Models\Link;
use App\Models\LinkClick;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function overview(Request $request)
    {
        $userId = $request->user()->id;

        $campaigns = Campaign::where('user_id', $userId)
            ->where('status', 'sent')
            ->get();

        $totalSent = $campaigns->sum('total_sent');
        $totalOpened = $campaigns->sum('total_opened');
        $totalClicked = $campaigns->sum('total_clicked');
        $totalBounced = $campaigns->sum('total_bounced');

        return response()->json([
            'data' => [
                'total_sent' => $totalSent,
                'total_opened' => $totalOpened,
                'total_clicked' => $totalClicked,
                'total_bounced' => $totalBounced,
                'open_rate' => $totalSent > 0 ? round(($totalOpened / $totalSent) * 100, 2) : 0,
                'click_rate' => $totalSent > 0 ? round(($totalClicked / $totalSent) * 100, 2) : 0,
                'bounce_rate' => $totalSent > 0 ? round(($totalBounced / $totalSent) * 100, 2) : 0,
            ],
        ]);
    }

    public function campaign(Request $request, Campaign $campaign)
    {
        if ($campaign->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权访问'], 403);
        }

        $sends = CampaignSend::where('campaign_id', $campaign->id)
            ->with('subscriber')
            ->get();

        $links = Link::where('campaign_id', $campaign->id)
            ->with('clicks')
            ->get();

        return response()->json([
            'data' => [
                'campaign' => $campaign,
                'sends' => $sends,
                'links' => $links,
                'stats' => [
                    'sent' => $campaign->total_sent,
                    'delivered' => $campaign->total_delivered,
                    'opened' => $campaign->total_opened,
                    'clicked' => $campaign->total_clicked,
                    'bounced' => $campaign->total_bounced,
                    'unsubscribed' => $campaign->total_unsubscribed,
                    'open_rate' => $campaign->open_rate,
                    'click_rate' => $campaign->click_rate,
                ],
            ],
        ]);
    }

    public function trackOpen(Request $request, $campaignId, $subscriberId)
    {
        $send = CampaignSend::where('campaign_id', $campaignId)
            ->where('subscriber_id', $subscriberId)
            ->first();

        if ($send) {
            if (!$send->opened_at) {
                $send->update([
                    'opened_at' => now(),
                    'open_count' => 1,
                ]);

                $send->campaign->increment('total_opened');
            } else {
                $send->increment('open_count');
            }
        }

        // Return 1x1 transparent pixel
        return response(base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'))
            ->header('Content-Type', 'image/gif')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    public function trackClick(Request $request, $linkId, $subscriberId)
    {
        $link = Link::findOrFail($linkId);

        $existingClick = LinkClick::where('link_id', $linkId)
            ->where('subscriber_id', $subscriberId)
            ->first();

        if (!$existingClick) {
            $link->increment('unique_click_count');
        }

        $link->increment('click_count');

        LinkClick::create([
            'link_id' => $linkId,
            'subscriber_id' => $subscriberId,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'clicked_at' => now(),
        ]);

        $send = CampaignSend::where('campaign_id', $link->campaign_id)
            ->where('subscriber_id', $subscriberId)
            ->first();

        if ($send) {
            $send->increment('click_count');
            if ($send->click_count == 1) {
                $send->campaign->increment('total_clicked');
            }
        }

        return redirect($link->original_url);
    }
}

