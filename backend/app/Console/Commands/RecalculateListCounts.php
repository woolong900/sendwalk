<?php

namespace App\Console\Commands;

use App\Models\MailingList;
use App\Models\ListSubscriber;
use Illuminate\Console\Command;

class RecalculateListCounts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lists:recalculate-counts';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '重新计算所有邮件列表的订阅者计数';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('开始重新计算邮件列表订阅者计数...');

        $lists = MailingList::all();
        $bar = $this->output->createProgressBar($lists->count());

        foreach ($lists as $list) {
            // 统计活跃订阅者
            $subscribersCount = ListSubscriber::where('list_id', $list->id)
                ->where('status', 'active')
                ->count();

            // 统计取消订阅者
            $unsubscribedCount = ListSubscriber::where('list_id', $list->id)
                ->where('status', 'unsubscribed')
                ->count();

            // 更新计数
            $list->timestamps = false;
            $list->subscribers_count = $subscribersCount;
            $list->unsubscribed_count = $unsubscribedCount;
            $list->save();
            $list->timestamps = true;

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('计数重新计算完成！');
        $this->info("处理了 {$lists->count()} 个列表");

        return 0;
    }
}

