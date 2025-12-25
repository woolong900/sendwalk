<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TagController extends Controller
{
    /**
     * 系统保留标签（不允许用户创建同名自定义标签）
     */
    private const RESERVED_TAGS = [
        // 订阅者标签
        'email',
        'first_name',
        'last_name',
        'full_name',
        // 系统标签
        'campaign_id',
        'date',
        'list_name',
        'server_name',
        'sender_domain',
        'unsubscribe_url',
    ];

    /**
     * 获取标签列表
     */
    public function index(Request $request)
    {
        $tags = Tag::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($tag) {
                return [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'values' => $tag->values,
                    'placeholder' => $tag->getPlaceholder(),
                    'values_count' => $tag->getValuesCount(),
                    'created_at' => $tag->created_at,
                ];
            });

        return response()->json([
            'data' => $tags,
            'reserved_tags' => self::RESERVED_TAGS, // 返回系统保留标签列表
        ]);
    }

    /**
     * 创建标签
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:50|regex:/^[a-zA-Z0-9_]+$/',
            'values' => 'required|string',
        ], [
            'name.regex' => '标签名称只能包含字母、数字和下划线',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
            ], 422);
        }

        // 检查是否与系统保留标签冲突
        if (in_array(strtolower($request->name), self::RESERVED_TAGS)) {
            return response()->json([
                'message' => '标签名称与系统保留标签冲突，请使用其他名称',
            ], 422);
        }

        // 检查标签名称是否已存在
        $exists = Tag::where('user_id', $request->user()->id)
            ->where('name', $request->name)
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => '标签名称已存在',
            ], 422);
        }

        $tag = Tag::create([
            'user_id' => $request->user()->id,
            'name' => $request->name,
            'values' => $request->values,
        ]);

        // 刷新模型以获取最新数据
        $tag->refresh();
        
        $valuesCount = $tag->getValuesCount();
        
        \Log::info('标签创建成功', [
            'tag_id' => $tag->id,
            'tag_name' => $tag->name,
            'values_count' => $valuesCount,
            'values_length' => strlen($tag->values),
        ]);

        return response()->json([
            'message' => '标签创建成功',
            'data' => [
                'id' => $tag->id,
                'name' => $tag->name,
                'values' => $tag->values,
                'placeholder' => $tag->getPlaceholder(),
                'values_count' => $valuesCount,
                'created_at' => $tag->created_at,
            ],
        ], 201);
    }

    /**
     * 更新标签
     */
    public function update(Request $request, Tag $tag)
    {
        if ($tag->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权访问'], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:50|regex:/^[a-zA-Z0-9_]+$/',
            'values' => 'required|string',
        ], [
            'name.regex' => '标签名称只能包含字母、数字和下划线',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
            ], 422);
        }

        // 检查是否与系统保留标签冲突
        if (in_array(strtolower($request->name), self::RESERVED_TAGS)) {
            return response()->json([
                'message' => '标签名称与系统保留标签冲突，请使用其他名称',
            ], 422);
        }

        // 检查标签名称是否与其他标签冲突
        $exists = Tag::where('user_id', $request->user()->id)
            ->where('name', $request->name)
            ->where('id', '!=', $tag->id)
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => '标签名称已存在',
            ], 422);
        }

        $tag->update([
            'name' => $request->name,
            'values' => $request->values,
        ]);

        // 刷新模型以获取最新数据
        $tag->refresh();
        
        $valuesCount = $tag->getValuesCount();
        
        \Log::info('标签更新成功', [
            'tag_id' => $tag->id,
            'tag_name' => $tag->name,
            'values_count' => $valuesCount,
            'values_length' => strlen($tag->values),
        ]);

        return response()->json([
            'message' => '标签更新成功',
            'data' => [
                'id' => $tag->id,
                'name' => $tag->name,
                'values' => $tag->values,
                'placeholder' => $tag->getPlaceholder(),
                'values_count' => $valuesCount,
                'created_at' => $tag->created_at,
            ],
        ]);
    }

    /**
     * 删除标签
     */
    public function destroy(Request $request, Tag $tag)
    {
        if ($tag->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权访问'], 403);
        }

        $tag->delete();

        return response()->json([
            'message' => '标签删除成功',
        ]);
    }

    /**
     * 测试标签 - 获取随机值
     */
    public function test(Request $request, Tag $tag)
    {
        if ($tag->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权访问'], 403);
        }

        return response()->json([
            'random_value' => $tag->getRandomValue(),
            'all_values' => $tag->getValuesArray(),
        ]);
    }
}

