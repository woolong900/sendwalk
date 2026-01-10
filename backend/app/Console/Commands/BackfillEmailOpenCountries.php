<?php

namespace App\Console\Commands;

use App\Models\EmailOpen;
use App\Services\GeoIpService;
use Illuminate\Console\Command;

class BackfillEmailOpenCountries extends Command
{
    protected $signature = 'email-opens:backfill-countries {--batch=100 : 每批处理的记录数} {--delay=2 : 批次间隔秒数，避免API限流}';
    protected $description = '为历史邮件打开记录补充国家信息';

    public function handle(GeoIpService $geoIpService): int
    {
        $batchSize = (int) $this->option('batch');
        $delay = (int) $this->option('delay');

        // 获取没有国家信息但有 IP 的记录数量
        $total = EmailOpen::whereNotNull('ip_address')
            ->where('ip_address', '!=', '')
            ->whereNull('country_code')
            ->count();

        if ($total === 0) {
            $this->info('没有需要处理的记录');
            return 0;
        }

        $this->info("发现 {$total} 条记录需要补充国家信息");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $processed = 0;
        $updated = 0;
        $failed = 0;

        // 分批处理
        while (true) {
            $records = EmailOpen::whereNotNull('ip_address')
                ->where('ip_address', '!=', '')
                ->whereNull('country_code')
                ->limit($batchSize)
                ->get();

            if ($records->isEmpty()) {
                break;
            }

            // 收集唯一 IP
            $ips = $records->pluck('ip_address')->unique()->values()->toArray();
            
            // 批量查询 IP 国家信息
            $geoData = $geoIpService->getCountriesByIps($ips);

            // 更新记录
            foreach ($records as $record) {
                $ip = $record->ip_address;
                $geo = $geoData[$ip] ?? ['country_code' => null, 'country_name' => null];

                // 即使没有获取到国家信息，也设置一个空字符串，避免重复处理
                $record->update([
                    'country_code' => $geo['country_code'] ?: '',
                    'country_name' => $geo['country_name'] ?: '',
                ]);

                if ($geo['country_code']) {
                    $updated++;
                } else {
                    $failed++;
                }

                $processed++;
                $bar->advance();
            }

            // 避免 API 限流
            if ($delay > 0) {
                sleep($delay);
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("处理完成！");
        $this->info("  - 总处理: {$processed}");
        $this->info("  - 成功获取国家: {$updated}");
        $this->info("  - 无法识别: {$failed}（可能是私有IP或API限制）");

        return 0;
    }
}

