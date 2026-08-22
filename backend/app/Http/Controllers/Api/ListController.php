<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncAutoListSubscribers;
use App\Models\MailingList;
use App\Models\Subscriber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

        // 如果是自动列表，同步订阅者（在响应后执行，不阻塞用户）
        if ($list->isAutoList()) {
            $listId = $list->id;
            dispatch(function () use ($listId) {
                (new SyncAutoListSubscribers($listId))->handle();
            })->afterResponse();
        }

        return response()->json([
            'message' => $list->isAutoList() ? '列表创建成功，正在同步订阅者' : '列表创建成功',
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

        // 如果需要同步订阅者，在响应后执行
        if ($needSync) {
            $listId = $list->id;
            dispatch(function () use ($listId) {
                (new SyncAutoListSubscribers($listId))->handle();
            })->afterResponse();
        }

        return response()->json([
            'message' => $needSync ? '列表更新成功，正在同步订阅者' : '列表更新成功',
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
     * 导出列表中的联系人（CSV）
     * 支持与前端列表页一致的搜索和状态筛选
     */
    public function export(Request $request, MailingList $list)
    {
        if ($list->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权访问'], 403);
        }

        $request->validate([
            'status' => 'nullable|in:pending,active,unsubscribed,bounced,complained,blacklisted',
            'search' => 'nullable|string|max:255',
        ]);

        $status = $request->query('status');
        $search = trim((string) $request->query('search', ''));

        $query = DB::table('list_subscriber')
            ->join('subscribers', 'list_subscriber.subscriber_id', '=', 'subscribers.id')
            ->where('list_subscriber.list_id', $list->id)
            ->whereNull('subscribers.deleted_at')
            ->select([
                // 游标分页依赖此列（结果集中键名为 subscriber_id）
                'list_subscriber.subscriber_id',
                'subscribers.email',
                'subscribers.first_name',
                'subscribers.last_name',
                'subscribers.status as subscriber_status',
                'list_subscriber.status as list_status',
                'list_subscriber.subscribed_at',
                'list_subscriber.unsubscribed_at',
                'subscribers.created_at',
            ]);

        if ($status) {
            $query->where('list_subscriber.status', $status);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('subscribers.email', 'like', "%{$search}%")
                    ->orWhere('subscribers.first_name', 'like', "%{$search}%")
                    ->orWhere('subscribers.last_name', 'like', "%{$search}%");
            });
        }

        $slug = Str::slug($list->name) ?: "list-{$list->id}";
        $filename = $slug . '-subscribers-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($query) {
            // 大列表导出可能耗时较长，取消 PHP 执行时间限制
            set_time_limit(0);

            $out = fopen('php://output', 'w');

            // 写入 UTF-8 BOM，避免 Excel 打开中文乱码
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'email',
                'first_name',
                'last_name',
                'status',
                'list_status',
                'subscribed_at',
                'unsubscribed_at',
                'created_at',
            ]);

            // 使用游标分页（WHERE subscriber_id > ?），走 (list_id, subscriber_id) 索引
            // 注意：不能用 chunk()（offset 翻页），50 万行时每页都要重复排序，复杂度 O(n²)
            $query->chunkById(
                2000,
                function ($subscribers) use ($out) {
                    foreach ($subscribers as $subscriber) {
                        fputcsv($out, [
                            $subscriber->email,
                            $subscriber->first_name,
                            $subscriber->last_name,
                            $subscriber->subscriber_status,
                            $subscriber->list_status,
                            $subscriber->subscribed_at,
                            $subscriber->unsubscribed_at,
                            $subscriber->created_at,
                        ]);
                    }
                },
                'list_subscriber.subscriber_id',
                'subscriber_id'
            );

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
            // 关闭 Nginx FastCGI 缓冲，边生成边下发，避免浏览器长时间无响应
            'X-Accel-Buffering' => 'no',
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
