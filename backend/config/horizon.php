<?php

use Illuminate\Support\Str;

return [

    'domain' => env('HORIZON_DOMAIN'),

    'path' => env('HORIZON_PATH', 'horizon'),

    'use' => 'default',

    'prefix' => env('HORIZON_PREFIX', 'sendwalk_horizon:'),

    'middleware' => ['web'],

    'waits' => [
        'redis:default' => 60,
    ],

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => false,

    'memory_limit' => 64,

    'defaults' => [
        'sendwalk-supervisor' => [
            'connection' => 'redis',
            // 动态生成队列列表（基于正在发送/计划发送的活动）
            'queue' => (function() {
                try {
                    if (app()->bound('db')) {
                        // 方案1：获取所有正在发送或已定时的活动使用的 SMTP 服务器
                        $activeSmtpIds = \Illuminate\Support\Facades\DB::table('campaigns')
                            ->whereIn('status', ['sending', 'scheduled'])
                            ->whereNotNull('smtp_server_id')
                            ->distinct()
                            ->pluck('smtp_server_id');
                        
                        // 方案2：如果没有活跃活动，获取所有启用的 SMTP 服务器作为备用
                        if ($activeSmtpIds->isEmpty()) {
                            $activeSmtpIds = \Illuminate\Support\Facades\DB::table('smtp_servers')
                                ->where('is_active', true)
                                ->pluck('id');
                        }
                        
                        // 生成队列名称
                        $queues = $activeSmtpIds
                            ->map(fn($id) => "smtp_{$id}")
                            ->toArray();
                        
                        // 添加 default 队列
                        $queues[] = 'default';
                        
                        \Illuminate\Support\Facades\Log::info('Horizon: 动态加载队列配置', [
                            'queues' => $queues,
                            'count' => count($queues),
                        ]);
                        
                        return $queues;
                    }
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning('Horizon: 无法从数据库加载队列配置，使用回退方案', [
                        'error' => $e->getMessage()
                    ]);
                }
                
                // 回退方案：返回常用队列
                return ['smtp_1', 'smtp_2', 'smtp_3', 'smtp_4', 'smtp_5', 'default'];
            })(),
            'balance' => 'auto',  // 自动平衡队列
            'autoScalingStrategy' => 'size',  // 根据队列大小自动扩缩容
            'minProcesses' => 2,  // 最少 2 个 worker
            'maxProcesses' => 20,  // 最多 20 个 worker
            'balanceMaxShift' => 1,  // 每次最多增减 1 个 worker
            'balanceCooldown' => 3,  // 扩缩容冷却时间 3 秒
            'maxTime' => 0,
            'maxJobs' => 1000,  // 每个 worker 处理 1000 个任务后重启
            'memory' => 256,
            'tries' => 3,
            'timeout' => 60,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'sendwalk-supervisor' => [
                'minProcesses' => 5,  // 生产环境最少 5 个 worker
                'maxProcesses' => 50,  // 生产环境最多 50 个 worker
                'balanceMaxShift' => 3,  // 每次最多增减 3 个 worker
                'balanceCooldown' => 5,  // 扩缩容冷却时间 5 秒
            ],
        ],

        'local' => [
            'sendwalk-supervisor' => [
                'minProcesses' => 2,  // 本地最少 2 个 worker
                'maxProcesses' => 10,  // 本地最多 10 个 worker
                'balanceMaxShift' => 1,  // 每次最多增减 1 个 worker
                'balanceCooldown' => 3,  // 扩缩容冷却时间 3 秒
            ],
        ],
    ],

];

