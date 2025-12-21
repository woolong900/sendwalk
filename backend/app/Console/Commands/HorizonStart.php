<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;

class HorizonStart extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'horizon:start';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '启动 Horizon（动态加载 SMTP 队列）';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔍 正在检测正在发送的活动...');
        
        try {
            // 方案1：获取正在发送或已定时的活动
            $activeCampaigns = DB::table('campaigns')
                ->whereIn('status', ['sending', 'scheduled'])
                ->whereNotNull('smtp_server_id')
                ->select('id', 'name', 'status', 'smtp_server_id')
                ->get();
            
            $this->info('📊 找到 ' . $activeCampaigns->count() . ' 个活跃活动');
            
            if ($activeCampaigns->isEmpty()) {
                $this->warn('⚠️  当前没有正在发送的活动');
                $this->info('📋 将监听所有启用的 SMTP 服务器...');
                
                // 获取所有启用的 SMTP 服务器
                $smtpServers = DB::table('smtp_servers')
                    ->where('is_active', true)
                    ->orderBy('id')
                    ->get(['id', 'name']);
                
                if ($smtpServers->isEmpty()) {
                    $this->warn('⚠️  没有找到启用的 SMTP 服务器，只监听 default 队列');
                    $queues = ['default'];
                } else {
                    $queues = $smtpServers->map(fn($s) => "smtp_{$s->id}")->toArray();
                    $queues[] = 'default';
                    
                    $this->info('发现 ' . count($smtpServers) . ' 个启用的 SMTP 服务器:');
                    foreach ($smtpServers as $server) {
                        $this->line("   - {$server->name} (ID: {$server->id})");
                    }
                }
            } else {
                // 获取活跃活动使用的 SMTP 服务器
                $usedSmtpIds = $activeCampaigns->pluck('smtp_server_id')->unique();
                
                // 获取这些 SMTP 服务器的详细信息
                $smtpServers = DB::table('smtp_servers')
                    ->whereIn('id', $usedSmtpIds)
                    ->get(['id', 'name']);
                
                $this->info('🎯 活跃活动使用的 SMTP 服务器:');
                foreach ($smtpServers as $server) {
                    $campaignsUsingThis = $activeCampaigns->where('smtp_server_id', $server->id);
                    $this->line("   - {$server->name} (ID: {$server->id})");
                    foreach ($campaignsUsingThis as $campaign) {
                        $this->line("     └─ 活动: {$campaign->name} [{$campaign->status}]");
                    }
                }
                
                $queues = $usedSmtpIds->map(fn($id) => "smtp_{$id}")->toArray();
                $queues[] = 'default';
            }
            
            $queueList = implode(',', $queues);
            $this->info("\n✅ 将监听以下队列: {$queueList}");
            $this->info("   (共 " . count($queues) . " 个队列)\n");
            
            // 更新环境变量
            putenv("HORIZON_QUEUES={$queueList}");
            $_ENV['HORIZON_QUEUES'] = $queueList;
            
            $this->info("🚀 正在启动 Horizon...\n");
            
            // 启动 Horizon
            Artisan::call('horizon');
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error("❌ 启动失败: {$e->getMessage()}");
            $this->error("堆栈: " . $e->getTraceAsString());
            return 1;
        }
    }
}

