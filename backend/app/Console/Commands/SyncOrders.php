<?php

namespace App\Console\Commands;

use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:sync 
                            {--all : 同步所有历史订单}
                            {--days=2 : 同步最近几天的订单}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '从外部API同步订单数据';

    /**
     * API配置
     */
    private const API_BASE_URL = 'https://openapi.oemapps.com';
    private const API_TOKEN = 'GkabtsHQDNmtgciOswHfPniIajzOphZjebeBGvWuGIohOWhkcVQXivJsheXPqYz';
    private const API_PATH = '/orders';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('开始同步订单...');
        
        $isAll = $this->option('all');
        $days = (int) $this->option('days');
        
        $page = 1;
        $limit = 100;
        $totalSynced = 0;
        $totalSkipped = 0;
        
        do {
            $params = [
                'page' => $page,
                'limit' => $limit,
                'financial_status' => '230', // 已支付
                'order_field' => 'pay_at',
                'order_by' => 'desc',
            ];
            
            // 如果不是同步全部，则添加时间过滤
            if (!$isAll) {
                $payAtMin = Carbon::now()->subDays($days)->startOfDay()->timestamp;
                $params['pay_at_min'] = $payAtMin;
            }
            
            $this->info("正在获取第 {$page} 页数据...");
            
            try {
                $response = Http::withHeaders([
                    'token' => self::API_TOKEN,
                ])->get(self::API_BASE_URL . self::API_PATH, $params);
                
                if (!$response->successful()) {
                    $this->error("API请求失败: HTTP {$response->status()}");
                    Log::error('订单同步API请求失败', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                    break;
                }
                
                $data = $response->json();
                
                if ($data['code'] !== 0) {
                    $this->error("API返回错误: {$data['msg']}");
                    Log::error('订单同步API返回错误', $data);
                    break;
                }
                
                $orders = $data['data']['list'] ?? [];
                $paginate = $data['data']['paginate'] ?? [];
                
                if (empty($orders)) {
                    $this->info('没有更多订单数据');
                    break;
                }
                
                foreach ($orders as $orderData) {
                    $result = $this->processOrder($orderData);
                    if ($result === 'created') {
                        $totalSynced++;
                    } else {
                        $totalSkipped++;
                    }
                }
                
                $this->info("第 {$page} 页处理完成，本页 " . count($orders) . " 条订单");
                
                // 检查是否还有下一页
                $hasMore = $page < ($paginate['pageTotal'] ?? 1);
                $page++;
                
                // 避免请求过于频繁
                if ($hasMore) {
                    usleep(200000); // 200ms
                }
                
            } catch (\Exception $e) {
                $this->error("同步异常: {$e->getMessage()}");
                Log::error('订单同步异常', [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                break;
            }
            
        } while ($hasMore);
        
        $this->info("同步完成！新增: {$totalSynced} 条，跳过(已存在): {$totalSkipped} 条");
        
        return Command::SUCCESS;
    }
    
    /**
     * 处理单个订单数据
     */
    private function processOrder(array $orderData): string
    {
        $orderNumber = $orderData['order_number'] ?? null;
        
        if (!$orderNumber) {
            return 'skipped';
        }
        
        // 检查订单是否已存在
        $exists = Order::where('order_number', $orderNumber)->exists();
        if ($exists) {
            return 'skipped';
        }
        
        // 提取商品名称
        $productNames = [];
        if (!empty($orderData['products'])) {
            foreach ($orderData['products'] as $product) {
                $productNames[] = $product['product_title'] ?? '';
            }
        }
        
        // 提取流水号
        $transactionNo = $orderData['transaction']['transaction_no'] ?? null;
        
        // 支付时间转换
        $paidAt = null;
        if (!empty($orderData['pay_at'])) {
            $paidAt = Carbon::createFromTimestamp($orderData['pay_at']);
        }
        
        // 创建订单记录
        Order::create([
            'order_number' => $orderNumber,
            'product_names' => implode(', ', array_filter($productNames)),
            'customer_email' => $orderData['customer_email'] ?? null,
            'total_price' => $orderData['total_price'] ?? 0,
            'payment_method' => $orderData['payment_method'] ?? null,
            'paid_at' => $paidAt,
            'utm_source' => $orderData['utm_source'] ?? null,
            'transaction_no' => $transactionNo,
            'domain' => $orderData['domain'] ?? null,
            'landing_page' => $orderData['landing_page'] ?? null,
            'utm_medium' => $orderData['utm_medium'] ?? null,
            'remote_order_id' => $orderData['id'] ?? null,
        ]);
        
        return 'created';
    }
}
