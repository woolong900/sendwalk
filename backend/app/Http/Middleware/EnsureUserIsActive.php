<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->status === 'banned') {
            return response()->json([
                'message' => '账号已被封禁，请联系管理员',
            ], 403);
        }

        return $next($request);
    }
}
