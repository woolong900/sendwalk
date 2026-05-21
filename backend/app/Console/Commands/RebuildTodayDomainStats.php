<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Models\User;
use App\Services\DomainSendStats;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RebuildTodayDomainStats extends Command
{
    protected $signature = 'dashboard:rebuild-today-domain-stats
                            {--user= : 指定 user_id，不传则处理所有用户}
                            {--date= : 指定日期 YYYY-MM-DD，默认今天}
                            {--chunk=5000 : 每批查询行数}';

    protected $description = '从 send_logs 回填某日的「域名发信量」Redis 缓存（用于功能上线后初始化）';

    public function handle(): int
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))
            : Carbon::today();
        $tomorrow = $date->copy()->addDay();

        $userIds = $this->option('user')
            ? [(int) $this->option('user')]
            : User::pluck('id')->all();

        $chunk = (int) $this->option('chunk');

        $this->info("回填日期: {$date->toDateString()}, 用户数: " . count($userIds) . ", chunk: {$chunk}");

        foreach ($userIds as $userId) {
            $campaignIds = Campaign::where('user_id', $userId)->pluck('id')->toArray();
            if (empty($campaignIds)) {
                continue;
            }

            // 先清掉旧的 Redis 数据，避免重复累加
            DomainSendStats::clear($userId, $date);

            $rowCount = 0;
            $byDomain = []; // 内存累加：[domain => ['sent' => N, 'failed' => N]]

            // 用 PDO unbuffered cursor 流式读取，避免 chunkById 在大 IN 列表下
            // 反复扫主键索引的性能塌方。这里只用 created_at 范围过滤，
            // 让优化器自由选择 (created_at) 或 idx_campaign_time_from_email 索引。
            // 由于一个用户的 campaign_ids 可能很多，先批量分组减少 IN 长度。
            $campaignChunks = array_chunk($campaignIds, 50);
            $bar = $this->output->createProgressBar(count($campaignChunks));
            $bar->setFormat(' user ' . $userId . ' [%bar%] %current%/%max% chunks  rows=%message%');
            $bar->setMessage((string) $rowCount);
            $bar->start();

            foreach ($campaignChunks as $cids) {
                // 按 created_at 范围 + IN(50 个 id) 查询，命中索引快
                $cursor = DB::table('send_logs')
                    ->select('from_email', 'status')
                    ->whereBetween('created_at', [$date, $tomorrow])
                    ->whereIn('campaign_id', $cids)
                    ->whereNotNull('from_email')
                    ->where('from_email', '!=', '')
                    ->cursor();

                foreach ($cursor as $row) {
                    $domain = DomainSendStats::extractDomain($row->from_email);
                    if ($domain === null) {
                        continue;
                    }
                    if (!isset($byDomain[$domain])) {
                        $byDomain[$domain] = ['sent' => 0, 'failed' => 0];
                    }
                    if ($row->status === 'sent') {
                        $byDomain[$domain]['sent']++;
                    } elseif ($row->status === 'failed') {
                        $byDomain[$domain]['failed']++;
                    }
                    $rowCount++;
                }
                $bar->setMessage((string) $rowCount);
                $bar->advance();
            }
            $bar->finish();
            $this->newLine();

            if (empty($byDomain)) {
                $this->line("  user {$userId}: 无数据");
                continue;
            }

            // 一次性写入 Redis Hash
            $key = DomainSendStats::key($userId, $date);
            $hash = [];
            foreach ($byDomain as $domain => $cnt) {
                if ($cnt['sent'] > 0) {
                    $hash["{$domain}|sent"] = $cnt['sent'];
                }
                if ($cnt['failed'] > 0) {
                    $hash["{$domain}|failed"] = $cnt['failed'];
                }
            }
            \Illuminate\Support\Facades\Redis::hmset($key, $hash);
            \Illuminate\Support\Facades\Redis::expire($key, 48 * 3600);

            $domainCount = count($byDomain);
            $this->info("  user {$userId}: 处理 {$rowCount} 行，{$domainCount} 个域名");
        }

        $this->info('回填完成');
        return self::SUCCESS;
    }
}
