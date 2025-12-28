<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Template;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class TemplateController extends Controller
{
    /**
     * 获取模板列表
     */
    public function index(Request $request)
    {
        $query = Template::where('user_id', $request->user()->id)
            ->orWhere('is_default', true);

        // 按分类筛选
        if ($request->has('category') && $request->category !== 'all') {
            $query->where('category', $request->category);
        }

        // 搜索
        if ($request->has('search') && $request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%')
                  ->orWhere('description', 'like', '%' . $request->search . '%');
            });
        }

        // 只显示启用的
        if ($request->has('active_only') && $request->active_only) {
            $query->where('is_active', true);
        }

        // 排序
        $sortBy = $request->input('sort_by', 'updated_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // ✅ 性能优化：列表页不返回完整的 HTML 内容，减少响应大小 80-90%
        // 只选择列表页需要的字段
        $query->select([
            'id',
            'user_id',
            'name',
            'description',
            'category',
            'is_active',
            'is_default',
            'created_at',
            'updated_at',
            // 不包括 html_content 和 plain_content（这些字段可能很大）
        ]);

        $templates = $query->paginate($request->input('per_page', 20));

        return response()->json([
            'data' => $templates->items(),
            'meta' => [
                'total' => $templates->total(),
                'per_page' => $templates->perPage(),
                'current_page' => $templates->currentPage(),
                'last_page' => $templates->lastPage(),
            ],
        ]);
    }

    /**
     * 获取单个模板
     */
    public function show(Request $request, $id)
    {
        $template = Template::where('id', $id)
            ->where(function ($query) use ($request) {
                $query->where('user_id', $request->user()->id)
                      ->orWhere('is_default', true);
            })
            ->firstOrFail();

        return response()->json(['data' => $template]);
    }

    /**
     * 创建模板
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'category' => 'required|string|in:' . implode(',', array_keys(Template::CATEGORIES)),
            'description' => 'nullable|string',
            'thumbnail' => 'nullable|string',
            'html_content' => 'required|string',
            'plain_content' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => '验证失败',
                'errors' => $validator->errors(),
            ], 422);
        }

        $template = Template::create([
            'user_id' => $request->user()->id,
            'name' => $request->name,
            'category' => $request->category,
            'description' => $request->description,
            'thumbnail' => $request->thumbnail,
            'html_content' => $request->html_content,
            'plain_content' => $request->plain_content,
            'is_active' => $request->input('is_active', true),
            'is_default' => false,
        ]);

        Log::info('模板已创建', [
            'template_id' => $template->id,
            'name' => $template->name,
            'user_id' => $request->user()->id,
        ]);

        return response()->json([
            'message' => '模板创建成功',
            'data' => $template,
        ], 201);
    }

    /**
     * 更新模板
     */
    public function update(Request $request, $id)
    {
        $template = Template::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        // 系统默认模板不能修改
        if ($template->is_default) {
            return response()->json([
                'message' => '系统默认模板不能修改',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'category' => 'sometimes|required|string|in:' . implode(',', array_keys(Template::CATEGORIES)),
            'description' => 'nullable|string',
            'thumbnail' => 'nullable|string',
            'html_content' => 'sometimes|required|string',
            'plain_content' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => '验证失败',
                'errors' => $validator->errors(),
            ], 422);
        }

        $template->update($request->only([
            'name',
            'category',
            'description',
            'thumbnail',
            'html_content',
            'plain_content',
            'is_active',
        ]));

        Log::info('模板已更新', [
            'template_id' => $template->id,
            'name' => $template->name,
            'user_id' => $request->user()->id,
        ]);

        return response()->json([
            'message' => '模板更新成功',
            'data' => $template,
        ]);
    }

    /**
     * 删除模板
     */
    public function destroy(Request $request, $id)
    {
        $template = Template::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        // 系统默认模板不能删除
        if ($template->is_default) {
            return response()->json([
                'message' => '系统默认模板不能删除',
            ], 403);
        }

        $template->delete();

        Log::info('模板已删除', [
            'template_id' => $id,
            'name' => $template->name,
            'user_id' => $request->user()->id,
        ]);

        return response()->json([
            'message' => '模板删除成功',
        ]);
    }

    /**
     * 复制模板
     */
    public function duplicate(Request $request, $id)
    {
        $template = Template::where('id', $id)
            ->where(function ($query) use ($request) {
                $query->where('user_id', $request->user()->id)
                      ->orWhere('is_default', true);
            })
            ->firstOrFail();

        $newTemplate = $template->duplicate(
            $request->user()->id,
            $request->input('name')
        );

        Log::info('模板已复制', [
            'original_id' => $template->id,
            'new_id' => $newTemplate->id,
            'user_id' => $request->user()->id,
        ]);

        return response()->json([
            'message' => '模板复制成功',
            'data' => $newTemplate,
        ], 201);
    }

    /**
     * 获取模板分类列表
     */
    public function categories()
    {
        return response()->json([
            'data' => collect(Template::CATEGORIES)->map(function ($label, $value) {
                return [
                    'value' => $value,
                    'label' => $label,
                ];
            })->values(),
        ]);
    }

    /**
     * 预览模板
     */
    public function preview(Request $request, $id)
    {
        $template = Template::where('id', $id)
            ->where(function ($query) use ($request) {
                $query->where('user_id', $request->user()->id)
                      ->orWhere('is_default', true);
            })
            ->firstOrFail();

        // 替换预览变量
        $html = $template->html_content;
        
        $previewData = [
            '{email}' => 'subscriber@example.com',
            '{first_name}' => '张',
            '{last_name}' => '三',
            '{full_name}' => '张三',
            '{campaign_id}' => '123',
            '{date}' => date('md'),
            '{list_name}' => '示例列表',
            '{server_name}' => '示例服务器',
            '{sender_domain}' => 'example.com',
            '{unsubscribe_url}' => '#',
        ];

        $html = str_replace(array_keys($previewData), array_values($previewData), $html);

        return response()->json([
            'data' => [
                'html' => $html,
            ],
        ]);
    }
}
