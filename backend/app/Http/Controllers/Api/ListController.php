<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncAutoListSubscribers;
use App\Models\MailingList;
use App\Models\Subscriber;
use Illuminate\Http\Request;

class ListController extends Controller
{
    public function index(Request $request)
    {
        // 包含 type 和 conditions 字段
        $query = MailingList::where('user_id', $request->user()->id)
            ->select([
                'id',
                'name',
                'description',
                'type',
                'conditions',
                'subscribers_count',
                'unsubscribed_count',
                'created_at',
                'updated_at',
            ])
            ->latest();

        // 如果请求参数包含 all=true，则返回所有列表（用于表单选择）
        if ($request->query('all') === 'true') {
            return response()->json([
                'data' => $query->get(),
            ]);
        }

        // 否则使用分页（用于列表页面）
        $lists = $query->paginate(15);

        // 计算总体统计数据
        $stats = MailingList::where('user_id', $request->user()->id)
            ->selectRaw('COUNT(*) as total_lists')
            ->selectRaw('SUM(subscribers_count) as total_subscribers')
            ->selectRaw('SUM(unsubscribed_count) as total_unsubscribed')
            ->first();

        return response()->json([
            'data' => $lists->items(),
            'meta' => [
                'current_page' => $lists->currentPage(),
                'last_page' => $lists->lastPage(),
                'per_page' => $lists->perPage(),
                'total' => $lists->total(),
            ],
            'stats' => [
                'total_lists' => $stats->total_lists ?? 0,
                'total_subscribers' => $stats->total_subscribers ?? 0,
                'total_unsubscribed' => $stats->total_unsubscribed ?? 0,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'nullable|in:manual,auto',
            'conditions' => 'nullable|array',
            'conditions.logic' => 'required_if:type,auto|in:and,or',
            'conditions.rules' => 'required_if:type,auto|array|min:1',
            'conditions.rules.*.type' => 'required_with:conditions.rules|in:in_list,not_in_list,has_opened,has_delivered',
            'custom_fields' => 'nullable|array',
        ]);

        $type = $request->type ?? MailingList::TYPE_MANUAL;
        
        $list = MailingList::create([
            'user_id' => $request->user()->id,
            'name' => $request->name,
            'description' => $request->description,
            'type' => $type,
            'conditions' => $type === MailingList::TYPE_AUTO ? $request->conditions : null,
            'custom_fields' => $request->custom_fields,
        ]);

        // 如果是自动列表，异步同步订阅者
        if ($list->isAutoList()) {
            SyncAutoListSubscribers::dispatch($list->id)->onQueue('default');
        }

        return response()->json([
            'message' => $list->isAutoList() ? '列表创建成功，正在后台同步订阅者' : '列表创建成功',
            'data' => $list,
        ], 201);
    }

    public function show(Request $request, MailingList $list)
    {
        if ($list->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权访问'], 403);
        }

        return response()->json([
            'data' => $list,
        ]);
    }

    public function update(Request $request, MailingList $list)
    {
        if ($list->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权访问'], 403);
        }

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'nullable|in:manual,auto',
            'conditions' => 'nullable|array',
            'conditions.logic' => 'required_if:type,auto|in:and,or',
            'conditions.rules' => 'required_if:type,auto|array|min:1',
            'conditions.rules.*.type' => 'required_with:conditions.rules|in:in_list,not_in_list,has_opened,has_delivered',
            'custom_fields' => 'nullable|array',
            'double_optin' => 'boolean',
        ]);

        $updateData = $request->only([
            'name',
            'description',
            'custom_fields',
            'double_optin',
        ]);

        // 处理类型和条件
        $needSync = false;
        if ($request->has('type')) {
            $updateData['type'] = $request->type;
            $updateData['conditions'] = $request->type === MailingList::TYPE_AUTO ? $request->conditions : null;
            // 如果切换到自动列表或更新自动列表的条件，需要重新同步
            if ($request->type === MailingList::TYPE_AUTO) {
                $needSync = true;
            }
        }

        $list->update($updateData);

        // 如果需要同步订阅者，异步执行
        if ($needSync) {
            SyncAutoListSubscribers::dispatch($list->id)->onQueue('default');
        }

        return response()->json([
            'message' => $needSync ? '列表更新成功，正在后台同步订阅者' : '列表更新成功',
            'data' => $list,
        ]);
    }

    public function destroy(Request $request, MailingList $list)
    {
        if ($list->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权访问'], 403);
        }

        $list->delete();

        return response()->json([
            'message' => '列表删除成功',
        ]);
    }

    public function import(Request $request, MailingList $list)
    {
        if ($list->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权访问'], 403);
        }

        // 自动列表不支持导入
        if ($list->isAutoList()) {
            return response()->json([
                'message' => '自动列表不支持导入联系人',
            ], 400);
        }

        $request->validate([
            'file' => 'required|file|mimes:csv,txt',
        ]);

        // TODO: Implement CSV import logic
        
        return response()->json([
            'message' => '导入成功',
        ]);
    }

    /**
     * 预览自动列表匹配的订阅者数量
     */
    public function previewAutoList(Request $request)
    {
        $request->validate([
            'conditions' => 'required|array',
            'conditions.logic' => 'required|in:and,or',
            'conditions.rules' => 'required|array|min:1',
            'conditions.rules.*.type' => 'required|in:in_list,not_in_list,has_opened,has_delivered',
        ]);

        // 创建临时列表对象来使用查询方法
        $tempList = new MailingList([
            'type' => MailingList::TYPE_AUTO,
            'conditions' => $request->conditions,
        ]);

        $query = $tempList->getAutoSubscribersQuery();
        $count = $query ? $query->count() : 0;

        return response()->json([
            'count' => $count,
        ]);
    }
}

