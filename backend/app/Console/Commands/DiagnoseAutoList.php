<?php

namespace App\Console\Commands;

use App\Models\MailingList;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DiagnoseAutoList extends Command
{
    protected $signature = 'lists:diagnose {list_id?} {--sync : 直接同步而不是分发到队列}';
    protected $description = '诊断自动列表问题';

    public function handle(): int
    {
        $listId = $this->argument('list_id');
        
        if ($listId) {
            return $this->diagnoseList($listId);
        }
        
        // 诊断所有自动列表
        $this->info('=== 自动列表诊断 ===');
        $this->newLine();
        
        $autoLists = MailingList::where('type', 'auto')->get();
        
        if ($autoLists->isEmpty()) {
            $this->warn('没有找到自动列表');
            return Command::SUCCESS;
        }
        
        $this->info("找到 {$autoLists->count()} 个自动列表:");
        $this->newLine();
        
        foreach ($autoLists as $list) {
            $this->diagnoseList($list->id);
            $this->newLine();
        }
        
        // 检查队列状态
        $this->info('=== 队列状态 ===');
        $pendingJobs = DB::table('jobs')->count();
        $defaultQueueJobs = DB::table('jobs')->where('queue', 'default')->count();
        $this->line("  待处理任务总数: {$pendingJobs}");
        $this->line("  default 队列任务: {$defaultQueueJobs}");
        
        return Command::SUCCESS;
    }
    
    private function diagnoseList($listId): int
    {
        $list = MailingList::find($listId);
        
        if (!$list) {
            $this->error("列表 #{$listId} 不存在");
            return Command::FAILURE;
        }
        
        $this->info("列表 #{$list->id}: {$list->name}");
        $this->line("  类型: {$list->type}");
        $this->line("  条件: " . json_encode($list->conditions));
        $this->line("  subscribers_count: {$list->subscribers_count}");
        
        // 检查 pivot 表
        $pivotCount = DB::table('list_subscriber')->where('list_id', $list->id)->count();
        $this->line("  pivot 表实际数量: {$pivotCount}");
        
        if (!$list->isAutoList()) {
            $this->warn("  ⚠ 这不是自动列表");
            return Command::SUCCESS;
        }
        
        // 检查条件
        $query = $list->getAutoSubscribersQuery();
        
        if (!$query) {
            $this->error("  ✗ 条件解析失败");
            $this->line("    可能原因:");
            $this->line("    - conditions 为空");
            $this->line("    - rules 为空");
            return Command::FAILURE;
        }
        
        // 测试查询
        try {
            $sql = $query->toSql();
            $this->line("  SQL: " . substr($sql, 0, 100) . "...");
            
            $matchCount = $query->count();
            $this->line("  条件匹配数量: {$matchCount}");
            
            if ($matchCount === 0) {
                $this->warn("  ⚠ 没有符合条件的订阅者");
                
                // 进一步诊断
                $conditions = $list->conditions;
                foreach ($conditions['rules'] ?? [] as $index => $rule) {
                    $this->line("    检查规则 #{$index}:");
                    $this->line("      类型: " . ($rule['type'] ?? 'N/A'));
                    
                    if (isset($rule['list_id'])) {
                        $targetList = MailingList::find($rule['list_id']);
                        if ($targetList) {
                            $targetCount = DB::table('list_subscriber')
                                ->where('list_id', $rule['list_id'])
                                ->where('status', 'active')
                                ->count();
                            $this->line("      目标列表: #{$rule['list_id']} ({$targetList->name})");
                            $this->line("      目标列表活跃订阅者: {$targetCount}");
                        } else {
                            $this->error("      ✗ 目标列表 #{$rule['list_id']} 不存在!");
                        }
                    }
                }
            } else {
                $this->info("  ✓ 条件有效，匹配 {$matchCount} 个订阅者");
                
                if ($pivotCount !== $matchCount) {
                    $this->warn("  ⚠ pivot 表数量 ({$pivotCount}) 与匹配数量 ({$matchCount}) 不一致");
                    
                    if ($this->option('sync')) {
                        $this->syncList($list, $query);
                    } else {
                        $this->line("  运行 --sync 选项直接同步: php artisan lists:diagnose {$listId} --sync");
                    }
                }
            }
        } catch (\Exception $e) {
            $this->error("  ✗ 查询执行失败: " . $e->getMessage());
            return Command::FAILURE;
        }
        
        return Command::SUCCESS;
    }
    
    private function syncList(MailingList $list, $query): void
    {
        $this->info("  开始同步...");
        
        // 清空
        DB::table('list_subscriber')->where('list_id', $list->id)->delete();
        $this->line("  已清空旧数据");
        
        // 批量插入
        $inserted = 0;
        $batch = [];
        $now = now();
        $batchSize = 2000;
        
        foreach ($query->cursor() as $subscriber) {
            $batch[] = [
                'list_id' => $list->id,
                'subscriber_id' => $subscriber->id,
                'status' => 'active',
                'subscribed_at' => $now,
                'source' => 'auto_list',
                'created_at' => $now,
                'updated_at' => $now,
            ];
            
            if (count($batch) >= $batchSize) {
                DB::table('list_subscriber')->insert($batch);
                $inserted += count($batch);
                $this->line("  已插入: {$inserted}");
                $batch = [];
            }
        }
        
        if (!empty($batch)) {
            DB::table('list_subscriber')->insert($batch);
            $inserted += count($batch);
        }
        
        // 更新 subscribers_count
        $list->update(['subscribers_count' => $inserted]);
        
        $this->info("  ✓ 同步完成: {$inserted} 个订阅者");
    }
}
