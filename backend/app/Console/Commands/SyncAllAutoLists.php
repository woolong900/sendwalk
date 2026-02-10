<?php

namespace App\Console\Commands;

use App\Jobs\SyncAutoListSubscribers;
use App\Models\MailingList;
use Illuminate\Console\Command;

class SyncAllAutoLists extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lists:sync-auto';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '同步所有自动列表的订阅者';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $autoLists = MailingList::where('type', MailingList::TYPE_AUTO)->get();

        if ($autoLists->isEmpty()) {
            $this->info('没有找到自动列表');
            return Command::SUCCESS;
        }

        $this->info("找到 {$autoLists->count()} 个自动列表，开始同步...");

        foreach ($autoLists as $list) {
            $this->line("  - 开始同步: [{$list->id}] {$list->name}");
            $startTime = microtime(true);
            
            try {
                (new SyncAutoListSubscribers($list->id))->handle();
                $elapsed = round(microtime(true) - $startTime, 2);
                $list->refresh();
                $this->info("    ✓ 同步完成，订阅者数量: {$list->subscribers_count}，耗时: {$elapsed}s");
            } catch (\Exception $e) {
                $this->error("    ✗ 同步失败: {$e->getMessage()}");
            }
        }

        $this->info('所有自动列表同步完成');

        return Command::SUCCESS;
    }
}
