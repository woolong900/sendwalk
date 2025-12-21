<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Automation;
use Illuminate\Http\Request;

class AutomationController extends Controller
{
    public function index(Request $request)
    {
        $automations = Automation::where('user_id', $request->user()->id)
            ->with('list')
            ->latest()
            ->paginate(15);

        return response()->json([
            'data' => $automations->items(),
            'meta' => [
                'current_page' => $automations->currentPage(),
                'last_page' => $automations->lastPage(),
                'per_page' => $automations->perPage(),
                'total' => $automations->total(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'list_id' => 'nullable|exists:lists,id',
            'workflow_data' => 'required|array',
            'trigger_type' => 'required|in:subscribe,unsubscribe,click,open,date,custom',
            'trigger_config' => 'nullable|array',
        ]);

        $automation = Automation::create([
            'user_id' => $request->user()->id,
            'name' => $request->name,
            'description' => $request->description,
            'list_id' => $request->list_id,
            'workflow_data' => $request->workflow_data,
            'trigger_type' => $request->trigger_type,
            'trigger_config' => $request->trigger_config,
            'is_active' => false,
        ]);

        return response()->json([
            'message' => '自动化流程创建成功',
            'data' => $automation,
        ], 201);
    }

    public function show(Request $request, Automation $automation)
    {
        if ($automation->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权访问'], 403);
        }

        $automation->load('list');

        return response()->json([
            'data' => $automation,
        ]);
    }

    public function update(Request $request, Automation $automation)
    {
        if ($automation->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权访问'], 403);
        }

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'list_id' => 'nullable|exists:lists,id',
            'workflow_data' => 'sometimes|required|array',
            'trigger_type' => 'sometimes|required|in:subscribe,unsubscribe,click,open,date,custom',
            'trigger_config' => 'nullable|array',
        ]);

        $automation->update($request->only([
            'name',
            'description',
            'list_id',
            'workflow_data',
            'trigger_type',
            'trigger_config',
        ]));

        return response()->json([
            'message' => '自动化流程更新成功',
            'data' => $automation,
        ]);
    }

    public function destroy(Request $request, Automation $automation)
    {
        if ($automation->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权访问'], 403);
        }

        $automation->delete();

        return response()->json([
            'message' => '自动化流程删除成功',
        ]);
    }

    public function activate(Request $request, Automation $automation)
    {
        if ($automation->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权访问'], 403);
        }

        $automation->update(['is_active' => true]);

        return response()->json([
            'message' => '自动化流程已激活',
            'data' => $automation,
        ]);
    }

    public function deactivate(Request $request, Automation $automation)
    {
        if ($automation->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权访问'], 403);
        }

        $automation->update(['is_active' => false]);

        return response()->json([
            'message' => '自动化流程已停用',
            'data' => $automation,
        ]);
    }
}

