<?php

use App\Http\Controllers\UnsubscribeController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'message' => 'SendWalk API',
        'version' => '1.0.0',
    ]);
});

// 邮件里的退订链接 /unsubscribe?token=...（不带 /api 前缀）
// - GET：跳转到退订专用域名上的退订确认页（兼容 api.sendwalk.com 上已发出的旧链接）
// - POST：RFC 8058 一键退订（Gmail/Yahoo 根据 List-Unsubscribe-Post 头直接 POST 到该链接）
Route::get('/unsubscribe', function (Request $request) {
    $query = $request->getQueryString();

    return redirect()->away(
        rtrim(config('app.unsubscribe_url'), '/') . '/unsubscribe' . ($query ? '?' . $query : '')
    );
});

Route::post('/unsubscribe', [UnsubscribeController::class, 'unsubscribe'])
    ->withoutMiddleware([
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        \App\Http\Middleware\ValidateCsrfToken::class,
    ]);
