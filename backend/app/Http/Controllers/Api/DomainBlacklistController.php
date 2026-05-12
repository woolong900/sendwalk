<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DomainBlacklist;
use Illuminate\Http\Request;

class DomainBlacklistController extends Controller
{
    /**
     * 获取域名黑名单列表
     */
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 15);

        $query = DomainBlacklist::select(['id', 'domain', 'reason', 'created_at'])
            ->where('user_id', $request->user()->id);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where('domain', 'like', "%{$search}%");
        }

        $list = $query->orderBy('id', 'desc')->paginate($perPage);

        return response()->json([
            'data' => $list->items(),
            'current_page' => $list->currentPage(),
            'last_page' => $list->lastPage(),
            'per_page' => $list->perPage(),
            'total' => $list->total(),
        ]);
    }

    /**
     * 添加单个域名到黑名单
     */
    public function store(Request $request)
    {
        $request->validate([
            'domain' => 'required|string|max:255',
            'reason' => 'nullable|string|max:255',
        ]);

        $domain = DomainBlacklist::normalizeDomain($request->domain);

        // 校验域名格式
        if (empty($domain) || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $domain)) {
            return response()->json([
                'message' => '域名格式无效',
            ], 422);
        }

        $exists = DomainBlacklist::where('user_id', $request->user()->id)
            ->where('domain', $domain)
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => '该域名已在黑名单中',
            ], 422);
        }

        $result = DomainBlacklist::addDomain(
            $request->user()->id,
            $domain,
            $request->reason
        );

        return response()->json([
            'message' => '已添加到域名黑名单',
            'data' => $result['entry'],
        ], 201);
    }

    /**
     * 批量添加域名到黑名单
     */
    public function batchStore(Request $request)
    {
        $request->validate([
            'domains' => 'required|array',
            'domains.*' => 'required|string|max:255',
            'reason' => 'nullable|string|max:255',
        ]);

        $userId = $request->user()->id;
        $reason = $request->reason;

        $added = 0;
        $alreadyExists = 0;
        $invalid = 0;

        foreach ($request->domains as $rawDomain) {
            $domain = DomainBlacklist::normalizeDomain($rawDomain);

            if (empty($domain) || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $domain)) {
                $invalid++;
                continue;
            }

            $result = DomainBlacklist::addDomain($userId, $domain, $reason);

            if (isset($result['error'])) {
                $invalid++;
                continue;
            }

            if ($result['created']) {
                $added++;
            } else {
                $alreadyExists++;
            }
        }

        return response()->json([
            'message' => "成功添加 {$added} 个域名",
            'added' => $added,
            'already_exists' => $alreadyExists,
            'invalid' => $invalid,
        ]);
    }

    /**
     * 从域名黑名单中移除单条
     */
    public function destroy(Request $request, DomainBlacklist $domainBlacklist)
    {
        if ($domainBlacklist->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权访问'], 403);
        }

        $domainBlacklist->delete();

        return response()->json([
            'message' => '已从域名黑名单中移除',
        ]);
    }

    /**
     * 批量删除域名黑名单
     */
    public function batchDestroy(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'required|integer',
        ]);

        $deleted = DomainBlacklist::where('user_id', $request->user()->id)
            ->whereIn('id', $request->ids)
            ->delete();

        return response()->json([
            'message' => "已删除 {$deleted} 个域名黑名单记录",
            'deleted' => $deleted,
        ]);
    }

    /**
     * 检查某个域名是否在黑名单中
     */
    public function check(Request $request)
    {
        $request->validate([
            'domain' => 'required|string',
        ]);

        $domain = DomainBlacklist::normalizeDomain($request->domain);
        $isBlacklisted = DomainBlacklist::where('user_id', $request->user()->id)
            ->where('domain', $domain)
            ->exists();

        return response()->json([
            'domain' => $domain,
            'is_blacklisted' => $isBlacklisted,
        ]);
    }
}
