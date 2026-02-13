<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    /**
     * 获取订单列表
     */
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 20);
        $search = $request->input('search');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        
        $query = Order::query()->orderBy('paid_at', 'desc');
        
        // 搜索
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhere('customer_email', 'like', "%{$search}%")
                  ->orWhere('product_names', 'like', "%{$search}%")
                  ->orWhere('transaction_no', 'like', "%{$search}%");
            });
        }
        
        // 日期过滤
        if ($startDate) {
            $query->whereDate('paid_at', '>=', $startDate);
        }
        if ($endDate) {
            $query->whereDate('paid_at', '<=', $endDate);
        }
        
        $orders = $query->paginate($perPage);
        
        return response()->json($orders);
    }
    
    /**
     * 获取单个订单详情
     */
    public function show(Order $order)
    {
        return response()->json($order);
    }
    
    /**
     * 获取订单统计
     */
    public function stats(Request $request)
    {
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        
        $query = Order::query();
        
        if ($startDate) {
            $query->whereDate('paid_at', '>=', $startDate);
        }
        if ($endDate) {
            $query->whereDate('paid_at', '<=', $endDate);
        }
        
        $totalOrders = $query->count();
        $totalAmount = $query->sum('total_price');
        
        // 按UTM来源统计
        $byUtmSource = Order::query()
            ->when($startDate, fn($q) => $q->whereDate('paid_at', '>=', $startDate))
            ->when($endDate, fn($q) => $q->whereDate('paid_at', '<=', $endDate))
            ->selectRaw('utm_source, COUNT(*) as count, SUM(total_price) as amount')
            ->groupBy('utm_source')
            ->orderByDesc('amount')
            ->limit(10)
            ->get();
        
        // 按域名统计
        $byDomain = Order::query()
            ->when($startDate, fn($q) => $q->whereDate('paid_at', '>=', $startDate))
            ->when($endDate, fn($q) => $q->whereDate('paid_at', '<=', $endDate))
            ->selectRaw('domain, COUNT(*) as count, SUM(total_price) as amount')
            ->groupBy('domain')
            ->orderByDesc('amount')
            ->limit(10)
            ->get();
        
        return response()->json([
            'total_orders' => $totalOrders,
            'total_amount' => round($totalAmount, 2),
            'by_utm_source' => $byUtmSource,
            'by_domain' => $byDomain,
        ]);
    }
    
    /**
     * 手动触发同步
     */
    public function sync(Request $request)
    {
        $all = $request->boolean('all', false);
        $days = $request->input('days', 2);
        
        // 异步执行同步命令
        dispatch(function () use ($all, $days) {
            $command = $all ? 'orders:sync --all' : "orders:sync --days={$days}";
            \Artisan::call($command);
        })->afterResponse();
        
        return response()->json([
            'message' => '同步任务已启动',
            'mode' => $all ? '全量同步' : "同步最近{$days}天订单",
        ]);
    }
}
