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
        $refresh = $request->boolean('refresh', false);
        
        // 计算时间范围
        [$startDate, $endDate] = $this->getDateRange($range);
        
        // 使用缓存，缓存5分钟
        $cacheKey = "order_analytics_{$range}_" . $startDate->format('Ymd');
        $cacheTtl = 300; // 5分钟
        
        // 如果请求刷新，清除缓存
        if ($refresh) {
            cache()->forget($cacheKey);
        }
        
        return response()->json(
            cache()->remember($cacheKey, $cacheTtl, function () use ($startDate, $endDate, $range) {
                return $this->computeAnalytics($startDate, $endDate, $range);
            })
        );
    }
    
    /**
     * 计算分析数据
     */
    private function computeAnalytics($startDate, $endDate, $range): array
    {
        $startDateStr = $startDate->toDateTimeString();
        $endDateStr = $endDate->toDateTimeString();
        
        // 使用单条 SQL 获取订单汇总和按 utm_medium 分组的数据
        $orderStats = DB::select("
            SELECT 
                COUNT(*) as total_orders,
                COALESCE(SUM(total_price), 0) as total_amount
            FROM orders 
            WHERE paid_at >= ? AND paid_at <= ?
        ", [$startDateStr, $endDateStr]);
        
        $totalOrders = $orderStats[0]->total_orders ?? 0;
        $totalAmount = round($orderStats[0]->total_amount ?? 0, 2);
        
        // 按 utm_medium 统计订单（发件域名）
        $ordersByUtmMedium = DB::select("
            SELECT 
                utm_medium as domain,
                COUNT(*) as order_count,
                SUM(total_price) as total_amount
            FROM orders 
            WHERE paid_at >= ? AND paid_at <= ?
                AND utm_medium IS NOT NULL 
                AND utm_medium != ''
            GROUP BY utm_medium
        ", [$startDateStr, $endDateStr]);
        
        $ordersByDomainMap = [];
        foreach ($ordersByUtmMedium as $row) {
            $ordersByDomainMap[$row->domain] = [
                'order_count' => (int) $row->order_count,
                'total_amount' => round((float) $row->total_amount, 2),
            ];
        }
        
        // 按落地页域名统计
        $byLandingDomain = DB::select("
            SELECT 
                domain,
                COUNT(*) as order_count,
                SUM(total_price) as total_amount
            FROM orders 
            WHERE paid_at >= ? AND paid_at <= ?
                AND domain IS NOT NULL 
                AND domain != ''
            GROUP BY domain
            ORDER BY order_count DESC
        ", [$startDateStr, $endDateStr]);
        
        // 转换为数组格式
        $byLandingDomain = array_map(function ($row) {
            return [
                'domain' => $row->domain,
                'order_count' => (int) $row->order_count,
                'total_amount' => round((float) $row->total_amount, 2),
            ];
        }, $byLandingDomain);
        
        // 发信统计 - 使用索引优化的查询
        // 先获取总发信量（这个查询可以使用 idx_status_created 索引）
        $totalSendCount = SendLog::query()
            ->where('status', 'sent')
            ->where('created_at', '>=', $startDate)
            ->where('created_at', '<=', $endDate)
            ->count();
        
        // 按发件域名统计发信量 - 使用原生 SQL 减少 PHP 处理
        $sendCountByDomain = DB::select("
            SELECT 
                SUBSTRING_INDEX(from_email, '@', -1) as domain,
                COUNT(*) as send_count
            FROM send_logs 
            WHERE status = 'sent' 
                AND created_at >= ? 
                AND created_at <= ?
                AND from_email IS NOT NULL
            GROUP BY SUBSTRING_INDEX(from_email, '@', -1)
            ORDER BY send_count DESC
        ", [$startDateStr, $endDateStr]);
        
        // 合并发信域名和订单数据
        $allSendingDomains = [];
        foreach ($sendCountByDomain as $row) {
            $domain = $row->domain;
            $orderData = $ordersByDomainMap[$domain] ?? null;
            $allSendingDomains[] = [
                'domain' => $domain,
                'send_count' => (int) $row->send_count,
                'order_count' => $orderData ? $orderData['order_count'] : 0,
                'total_amount' => $orderData ? $orderData['total_amount'] : 0,
            ];
        }
        
        // 获取 DOMAIN 标签中的所有域名，找出未出单的域名
        $domainTag = Tag::where('name', 'DOMAIN')->first();
        $allDomains = $domainTag ? $domainTag->getValuesArray() : [];
        
        // 已出单的落地页域名列表
        $domainsWithOrders = array_column($byLandingDomain, 'domain');
        
        // 未出单的域名
        $domainsWithoutOrders = array_values(array_diff($allDomains, $domainsWithOrders));
        
        return [
            'range' => $range,
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
            'summary' => [
                'total_orders' => $totalOrders,
                'total_amount' => $totalAmount,
                'total_send_count' => $totalSendCount,
            ],
            'by_sending_domain' => $allSendingDomains,
            'by_landing_domain' => $byLandingDomain,
            'domains_without_orders' => $domainsWithoutOrders,
        ];
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
