<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MailingList;
use Illuminate\Http\Request;

class ListController extends Controller
{
    public function index(Request $request)
    {
        // 直接使用缓存的计数字段，避免复杂的关联查询
        $query = MailingList::where('user_id', $request->user()->id)
            ->select([
                'id',
                'name',
                'description',
                'subscribers_count',
                'unsubscribed_count',
                'created_at',
                'updated_at',
            ])
            ->latest();

        // 如果请求参数包含 all=true，则返回所有列表（用于表单选择）
        if ($request->query('all') === 'true') {
            $lists = $query->get();
            return response()->json([
                'data' => $lists,
            ]);
        }

        // 否则使用分页（用于列表页面）
        $lists = $query->paginate(15);

        return response()->json([
            'data' => $lists->items(),
            'meta' => [
                'current_page' => $lists->currentPage(),
                'last_page' => $lists->lastPage(),
                'per_page' => $lists->perPage(),
                'total' => $lists->total(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'custom_fields' => 'nullable|array',
        ]);

        $list = MailingList::create([
            'user_id' => $request->user()->id,
            'name' => $request->name,
            'description' => $request->description,
            'custom_fields' => $request->custom_fields,
        ]);

        return response()->json([
            'message' => '列表创建成功',
            'data' => $list,
        ], 201);
    }

    public function show(Request $request, MailingList $list)
    {
        if ($list->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权访问'], 403);
        }

        // 直接使用缓存的计数字段，无需额外查询
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
            'custom_fields' => 'nullable|array',
            'double_optin' => 'boolean',
        ]);

        $list->update($request->only([
            'name',
            'description',
            'custom_fields',
            'double_optin',
        ]));

        return response()->json([
            'message' => '列表更新成功',
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

        $request->validate([
            'file' => 'required|file|mimes:csv,txt',
        ]);

        // TODO: Implement CSV import logic
        
        return response()->json([
            'message' => '导入成功',
        ]);
    }
}

