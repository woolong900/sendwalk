<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MailingList;
use Illuminate\Http\Request;

class ListController extends Controller
{
    public function index(Request $request)
    {
        $lists = MailingList::where('user_id', $request->user()->id)
            ->withCount([
                'subscribers as subscribers_count' => function ($query) {
                    $query->where('list_subscriber.status', 'active');
                },
                'subscribers as unsubscribed_count' => function ($query) {
                    $query->where('list_subscriber.status', 'unsubscribed');
                }
            ])
            ->latest()
            ->paginate(15);

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

        // 只加载订阅者计数，不加载所有订阅者（避免内存耗尽）
        $list->loadCount([
            'subscribers as subscribers_count' => function ($query) {
                $query->where('list_subscriber.status', 'active');
            },
            'subscribers as unsubscribed_count' => function ($query) {
                $query->where('list_subscriber.status', 'unsubscribed');
            }
        ]);

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

