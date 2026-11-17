<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class PublicDomainTagController extends Controller
{
    /**
     * Return the public DOMAIN tag values for the configured account.
     *
     * This endpoint intentionally exposes only the tag name and its values.
     * User identifiers and all other account data stay private.
     */
    public function show(): JsonResponse
    {
        $user = User::query()
            ->where('email', config('public-domain-tag.user_email'))
            ->first();

        if (!$user) {
            return response()->json([
                'message' => 'DOMAIN 标签暂不可用',
            ], 404);
        }

        $tag = Tag::query()
            ->where('user_id', $user->id)
            ->whereRaw('UPPER(name) = ?', ['DOMAIN'])
            ->first();

        if (!$tag) {
            return response()->json([
                'message' => 'DOMAIN 标签暂不可用',
            ], 404);
        }

        return response()->json([
            'data' => [
                'name' => 'DOMAIN',
                'values' => array_values($tag->getValuesArray()),
                'updated_at' => $tag->updated_at?->toIso8601String(),
            ],
        ]);
    }
}
