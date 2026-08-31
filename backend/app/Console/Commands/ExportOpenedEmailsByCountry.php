<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExportOpenedEmailsByCountry extends Command
{
    protected $signature = 'export:opened-emails
        {country : ISO 3166-1 alpha-2 国家代码，例如 US、AU}
        {--from= : 打开时间起始（Y-m-d 或 Y-m-d H:i:s，可选）}
        {--to= : 打开时间结束（Y-m-d 或 Y-m-d H:i:s，可选，含当天）}';

    protected $description = '按国家导出所有打开过邮件的订阅者邮箱（CSV，按邮箱聚合，格式与 australia-opened-emails 一致）';

    public function handle(): int
    {
        $country = strtoupper(trim((string) $this->argument('country')));
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            $this->error('国家代码格式错误，请使用两位字母，例如 US、AU');
            return 1;
        }

        $from = $this->option('from') ?: null;
        $to = $this->option('to') ?: null;

        foreach (['from' => $from, 'to' => $to] as $label => $value) {
            if ($value !== null && strtotime($value) === false) {
                $this->error("--{$label} 日期格式错误: {$value}（应为 Y-m-d 或 Y-m-d H:i:s）");
                return 1;
            }
        }

        $dir = storage_path('app/exports');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $cc = strtolower($country);
        $filename = sprintf('%s-opened-emails-%s.csv', $cc, now()->format('Ymd-His'));
        $path = $dir . '/' . $filename;

        $out = fopen($path, 'w');

        // UTF-8 BOM，避免 Excel 打开中文乱码
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, [
            'email',
            "{$cc}_open_count",
            "first_{$cc}_opened_at",
            "last_{$cc}_opened_at",
            "{$cc}_ip_addresses",
            'campaign_ids',
            'subscriber_ids',
        ]);

        $pdo = DB::connection()->getPdo();

        // IP/活动ID 列表可能很长，调大 GROUP_CONCAT 上限（默认 1024 会截断）
        $pdo->exec('SET SESSION group_concat_max_len = 4194304');

        // 非缓冲查询：逐行从 MySQL 读取，几十万邮箱也保持恒定内存
        $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

        $where = ['country_code = :country_code'];
        $params = ['country_code' => $country];

        if ($from !== null) {
            $where[] = 'opened_at >= :from_at';
            $params['from_at'] = strlen($from) === 10 ? "{$from} 00:00:00" : $from;
        }
        if ($to !== null) {
            $where[] = 'opened_at <= :to_at';
            $params['to_at'] = strlen($to) === 10 ? "{$to} 23:59:59" : $to;
        }

        $sql = 'SELECT email,
                    COUNT(*) AS open_count,
                    MIN(opened_at) AS first_opened_at,
                    MAX(opened_at) AS last_opened_at,
                    GROUP_CONCAT(DISTINCT ip_address ORDER BY ip_address SEPARATOR \';\') AS ip_addresses,
                    GROUP_CONCAT(DISTINCT campaign_id ORDER BY campaign_id SEPARATOR \';\') AS campaign_ids,
                    GROUP_CONCAT(DISTINCT subscriber_id ORDER BY subscriber_id SEPARATOR \';\') AS subscriber_ids
                FROM email_opens
                WHERE ' . implode(' AND ', $where) . '
                GROUP BY email
                ORDER BY email ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $emailCount = 0;
        $openCount = 0;

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            fputcsv($out, [
                $row['email'],
                $row['open_count'],
                $row['first_opened_at'],
                $row['last_opened_at'],
                $row['ip_addresses'],
                $row['campaign_ids'],
                $row['subscriber_ids'],
            ]);

            $openCount += (int) $row['open_count'];
            $emailCount++;

            if ($emailCount % 50000 === 0) {
                $this->info("已导出 {$emailCount} 个邮箱...");
            }
        }

        // 恢复缓冲模式，避免影响后续查询
        $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        fclose($out);

        $this->newLine();
        $this->info('导出完成！');
        $this->info("  国家代码: {$country}");
        $this->info("  邮箱数: {$emailCount}");
        $this->info("  打开记录数: {$openCount}");
        $this->info("  文件: {$path}");
        $this->info('  大小: ' . number_format(filesize($path) / 1048576, 2) . ' MB');

        return 0;
    }
}
