<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserManagementController extends Controller
{
    public function index(Request $request)
    {
        if (!$request->user()->isAdmin()) {
            return response()->json(['message' => '无权访问'], 403);
        }

        $query = User::query()
            ->select([
                'id',
                'name',
                'email',
                'avatar',
                'role',
                'send_quota',
                'sent_quota_used',
                'status',
                'created_at',
                'updated_at',
            ])
            ->latest();

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        $users = $query->paginate((int) $request->input('per_page', 15));

        return response()->json([
            'data' => $users->items(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    public function update(Request $request, User $user)
    {
        if (!$request->user()->isAdmin()) {
            return response()->json(['message' => '无权访问'], 403);
        }

        $data = $request->validate([
            'send_quota' => ['nullable', 'integer', 'min:0'],
            'sent_quota_used' => ['sometimes', 'integer', 'min:0'],
            'status' => ['required', Rule::in(['active', 'banned'])],
        ]);

        if ($request->user()->id === $user->id && $data['status'] === 'banned') {
            return response()->json(['message' => '不能封禁当前登录的管理员账号'], 422);
        }

        if (array_key_exists('send_quota', $data) && $data['send_quota'] !== null) {
            $used = $data['sent_quota_used'] ?? $user->sent_quota_used;
            if ($used > $data['send_quota']) {
                return response()->json(['message' => '已发送额度不能大于总额度'], 422);
            }
        }

        $user->update($data);

        if ($user->status === 'banned') {
            $user->tokens()->delete();
        }

        return response()->json([
            'message' => '用户设置已更新',
            'data' => $user->fresh(),
        ]);
    }
}
