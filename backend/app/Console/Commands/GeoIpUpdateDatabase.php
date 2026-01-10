<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\File;

class GeoIpUpdateDatabase extends Command
{
    protected $signature = 'geoip:update {--license-key= : MaxMind License Key}';
    protected $description = '下载/更新 MaxMind GeoLite2-Country 数据库';

    public function handle(): int
    {
        $licenseKey = $this->option('license-key') ?: config('geoip.license_key');

        if (empty($licenseKey)) {
            $this->error('请提供 MaxMind License Key！');
            $this->newLine();
            $this->info('获取方式：');
            $this->line('  1. 访问 https://www.maxmind.com/en/geolite2/signup 注册免费账号');
            $this->line('  2. 登录后在 "My License Key" 页面生成 License Key');
            $this->line('  3. 将 License Key 添加到 .env 文件：MAXMIND_LICENSE_KEY=your_key');
            $this->line('  4. 或直接运行：php artisan geoip:update --license-key=your_key');
            return 1;
        }

        $dbPath = config('geoip.database_path');
        $dbDir = dirname($dbPath);

        // 确保目录存在
        if (!File::isDirectory($dbDir)) {
            File::makeDirectory($dbDir, 0755, true);
        }

        $this->info('正在下载 GeoLite2-Country 数据库...');

        // MaxMind 下载 URL
        $downloadUrl = sprintf(
            'https://download.maxmind.com/app/geoip_download?edition_id=GeoLite2-Country&license_key=%s&suffix=tar.gz',
            $licenseKey
        );

        try {
            // 下载压缩包
            $tempFile = storage_path('app/geoip/GeoLite2-Country.tar.gz');
            
            $response = Http::timeout(120)
                ->withOptions(['sink' => $tempFile])
                ->get($downloadUrl);

            if (!$response->successful()) {
                $this->error('下载失败！请检查 License Key 是否正确。');
                $this->line('HTTP 状态码: ' . $response->status());
                return 1;
            }

            $this->info('下载完成，正在解压...');

            // 解压 tar.gz
            $phar = new \PharData($tempFile);
            $phar->decompress(); // 创建 .tar 文件

            $tarFile = str_replace('.tar.gz', '.tar', $tempFile);
            $phar = new \PharData($tarFile);
            $phar->extractTo($dbDir, null, true);

            // 找到并移动 .mmdb 文件
            $extractedDirs = File::directories($dbDir);
            foreach ($extractedDirs as $dir) {
                $mmdbFile = $dir . '/GeoLite2-Country.mmdb';
                if (File::exists($mmdbFile)) {
                    File::move($mmdbFile, $dbPath);
                    File::deleteDirectory($dir);
                    break;
                }
            }

            // 清理临时文件
            File::delete($tempFile);
            File::delete($tarFile);

            if (File::exists($dbPath)) {
                $size = round(File::size($dbPath) / 1024 / 1024, 2);
                $this->info("✅ 数据库更新成功！");
                $this->line("   路径: {$dbPath}");
                $this->line("   大小: {$size} MB");
                $this->newLine();
                $this->info('建议设置定时任务每周更新一次数据库：');
                $this->line('  0 3 * * 0 cd /path/to/backend && php artisan geoip:update');
                return 0;
            } else {
                $this->error('解压后未找到数据库文件！');
                return 1;
            }

        } catch (\Exception $e) {
            $this->error('发生错误: ' . $e->getMessage());
            return 1;
        }
    }
}

