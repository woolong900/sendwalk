<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\SendLog;
use App\Models\Tag;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
    
    /**
     * 订单数据分析
     * 
     * @param string $range 时间范围: today, yesterday, 3days, week, month
     */
    public function analytics(Request $request)
    {
        $range = $request->input('range', 'today');
        
        // 计算时间范围
        [$startDate, $endDate] = $this->getDateRange($range);
        
        // 1. 按发件域名统计发信量（从SendLog获取，按from_email的域名分组）
        $sendCountByDomain = SendLog::query()
            ->where('status', 'sent')
            ->where('created_at', '>=', $startDate)
            ->where('created_at', '<=', $endDate)
            ->whereNotNull('from_email')
            ->selectRaw("SUBSTRING_INDEX(from_email, '@', -1) as domain, COUNT(*) as send_count")
            ->groupBy(DB::raw("SUBSTRING_INDEX(from_email, '@', -1)"))
            ->pluck('send_count', 'domain')
            ->toArray();
        
        // 2. 按发件域名(utm_medium)统计订单
        $ordersByUtmMedium = Order::query()
            ->whereDate('paid_at', '>=', $startDate)
            ->whereDate('paid_at', '<=', $endDate)
            ->whereNotNull('utm_medium')
            ->where('utm_medium', '!=', '')
            ->selectRaw('utm_medium as domain, COUNT(*) as order_count, SUM(total_price) as total_amount')
            ->groupBy('utm_medium')
            ->get()
            ->keyBy('domain')
            ->toArray();
        
        // 3. 合并发信域名和订单域名，包括有发信但无订单的域名
        $allSendingDomains = collect($sendCountByDomain)->map(function ($sendCount, $domain) use ($ordersByUtmMedium) {
            $orderData = $ordersByUtmMedium[$domain] ?? null;
            return [
                'domain' => $domain,
                'send_count' => $sendCount,
                'order_count' => $orderData ? $orderData['order_count'] : 0,
                'total_amount' => $orderData ? round($orderData['total_amount'], 2) : 0,
            ];
        })->values()->sortByDesc('send_count')->values();
        
        // 3. 按落地页域名(domain)统计 - 按出单量倒序
        $byLandingDomain = Order::query()
            ->whereDate('paid_at', '>=', $startDate)
            ->whereDate('paid_at', '<=', $endDate)
            ->whereNotNull('domain')
            ->where('domain', '!=', '')
            ->selectRaw('domain, COUNT(*) as order_count, SUM(total_price) as total_amount')
            ->groupBy('domain')
            ->orderByDesc('order_count')
            ->get();
        
        // 4. 获取DOMAIN标签中的所有域名，找出未出单的域名
        $domainTag = Tag::where('name', 'DOMAIN')->first();
        $allDomains = $domainTag ? $domainTag->getValuesArray() : [];
        
        // 已出单的域名列表
        $domainsWithOrders = $byLandingDomain->pluck('domain')->toArray();
        
        // 未出单的域名
        $domainsWithoutOrders = array_values(array_diff($allDomains, $domainsWithOrders));
        
        // 汇总统计
        $totalOrders = Order::query()
            ->whereDate('paid_at', '>=', $startDate)
            ->whereDate('paid_at', '<=', $endDate)
            ->count();
            
        $totalAmount = Order::query()
            ->whereDate('paid_at', '>=', $startDate)
            ->whereDate('paid_at', '<=', $endDate)
            ->sum('total_price');
        
        // 总发信量
        $totalSendCount = SendLog::query()
            ->where('status', 'sent')
            ->where('created_at', '>=', $startDate)
            ->where('created_at', '<=', $endDate)
            ->count();
        
        return response()->json([
            'range' => $range,
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
            'summary' => [
                'total_orders' => $totalOrders,
                'total_amount' => round($totalAmount, 2),
                'total_send_count' => $totalSendCount,
            ],
            'by_sending_domain' => $allSendingDomains,
            'by_landing_domain' => $byLandingDomain,
            'domains_without_orders' => $domainsWithoutOrders,
        ]);
    }
    
    /**
     * 根据范围参数计算起止日期
     */
    private function getDateRange(string $range): array
    {
        $now = Carbon::now();
        
        switch ($range) {
            case 'today':
                return [$now->copy()->startOfDay(), $now->copy()->endOfDay()];
            case 'yesterday':
                return [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()];
            case '3days':
                return [$now->copy()->subDays(2)->startOfDay(), $now->copy()->endOfDay()];
            case 'week':
                return [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()];
            case 'month':
                return [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()];
            default:
                return [$now->copy()->startOfDay(), $now->copy()->endOfDay()];
        }
    }
}
