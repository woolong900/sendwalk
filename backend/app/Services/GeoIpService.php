<?php

namespace App\Services;

use GeoIp2\Database\Reader;
use GeoIp2\Exception\AddressNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

class GeoIpService
{
    protected ?Reader $reader = null;
    protected bool $localDatabaseAvailable = false;

    public function __construct()
    {
        $this->initializeLocalDatabase();
    }

    /**
     * 初始化本地数据库
     */
    protected function initializeLocalDatabase(): void
    {
        if (!config('geoip.use_local_database', true)) {
            return;
        }

        $dbPath = config('geoip.database_path');
        
        if ($dbPath && File::exists($dbPath)) {
            try {
                $this->reader = new Reader($dbPath);
                $this->localDatabaseAvailable = true;
                Log::debug('GeoIP: 使用本地数据库', ['path' => $dbPath]);
            } catch (\Exception $e) {
                Log::warning('GeoIP: 本地数据库加载失败', [
                    'path' => $dbPath,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * 根据 IP 地址获取国家信息
     *
     * @param string|null $ip
     * @return array{country_code: string|null, country_name: string|null}
     */
    public function getCountryByIp(?string $ip): array
    {
        $default = ['country_code' => null, 'country_name' => null];

        if (empty($ip)) {
            return $default;
        }

        // 过滤本地和私有 IP
        if ($this->isLocalOrPrivateIp($ip)) {
            return $default;
        }

        // 检查缓存
        if (config('geoip.cache.enabled', true)) {
            $cacheKey = config('geoip.cache.prefix', 'geo_ip:') . $ip;
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        // 查询国家信息
        $result = $this->lookupCountry($ip);

        // 缓存结果
        if (config('geoip.cache.enabled', true)) {
            $ttl = config('geoip.cache.ttl', 60 * 60 * 24 * 7);
            Cache::put($cacheKey, $result, $ttl);
        }

        return $result;
    }

    /**
     * 查询国家信息（优先使用本地数据库）
     */
    protected function lookupCountry(string $ip): array
    {
        $default = ['country_code' => null, 'country_name' => null];

        // 优先使用本地数据库
        if ($this->localDatabaseAvailable && $this->reader) {
            try {
                $record = $this->reader->country($ip);
                return [
                    'country_code' => $record->country->isoCode,
                    'country_name' => $record->country->name,
                ];
            } catch (AddressNotFoundException $e) {
                // IP 地址在数据库中未找到
                return $default;
            } catch (\Exception $e) {
                Log::warning('GeoIP: 本地查询失败，尝试在线 API', [
                    'ip' => $ip,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 回退到在线 API
        if (config('geoip.fallback_api.enabled', true)) {
            return $this->fetchCountryFromApi($ip) ?? $default;
        }

        return $default;
    }

    /**
     * 检查是否为本地或私有 IP
     */
    protected function isLocalOrPrivateIp(string $ip): bool
    {
        // IPv4 本地和私有地址
        $privateRanges = [
            '127.0.0.0/8',      // localhost
            '10.0.0.0/8',       // Class A private
            '172.16.0.0/12',    // Class B private
            '192.168.0.0/16',   // Class C private
            '169.254.0.0/16',   // Link-local
            '0.0.0.0/8',        // Invalid
        ];

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            foreach ($privateRanges as $range) {
                if ($this->ipInRange($ip, $range)) {
                    return true;
                }
            }
        }

        // IPv6 本地地址
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            if (str_starts_with(strtolower($ip), 'fe80:') || 
                str_starts_with($ip, '::1') || 
                str_starts_with($ip, 'fc') ||
                str_starts_with($ip, 'fd')) {
                return true;
            }
        }

        return false;
    }

    /**
     * 检查 IP 是否在指定范围内
     */
    protected function ipInRange(string $ip, string $range): bool
    {
        [$subnet, $bits] = explode('/', $range);
        
        $ip = ip2long($ip);
        $subnet = ip2long($subnet);
        $mask = -1 << (32 - (int)$bits);
        
        $subnet &= $mask;
        
        return ($ip & $mask) === $subnet;
    }

    /**
     * 从在线 API 获取国家信息（后备方案）
     */
    protected function fetchCountryFromApi(string $ip): ?array
    {
        try {
            $apiUrl = config('geoip.fallback_api.url', 'http://ip-api.com/json/');
            $response = Http::timeout(3)->get($apiUrl . $ip, [
                'fields' => 'status,countryCode,country',
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                if (isset($data['status']) && $data['status'] === 'success') {
                    return [
                        'country_code' => $data['countryCode'] ?? null,
                        'country_name' => $data['country'] ?? null,
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::warning('GeoIP: 在线 API 查询失败', [
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * 批量获取多个 IP 的国家信息
     *
     * @param array<string> $ips
     * @return array<string, array{country_code: string|null, country_name: string|null}>
     */
    public function getCountriesByIps(array $ips): array
    {
        $results = [];
        $toFetch = [];
        $default = ['country_code' => null, 'country_name' => null];
        $cacheEnabled = config('geoip.cache.enabled', true);
        $cachePrefix = config('geoip.cache.prefix', 'geo_ip:');

        // 先检查缓存和过滤私有 IP
        foreach ($ips as $ip) {
            if (empty($ip) || $this->isLocalOrPrivateIp($ip)) {
                $results[$ip] = $default;
                continue;
            }

            if ($cacheEnabled) {
                $cached = Cache::get($cachePrefix . $ip);
                if ($cached !== null) {
                    $results[$ip] = $cached;
                    continue;
                }
            }

            $toFetch[] = $ip;
        }

        // 批量查询未缓存的 IP
        if (!empty($toFetch)) {
            $ttl = config('geoip.cache.ttl', 60 * 60 * 24 * 7);

            // 如果有本地数据库，直接批量查询
            if ($this->localDatabaseAvailable && $this->reader) {
                foreach ($toFetch as $ip) {
                    $result = $this->lookupCountry($ip);
                    $results[$ip] = $result;
                    
                    if ($cacheEnabled) {
                        Cache::put($cachePrefix . $ip, $result, $ttl);
                    }
                }
            } else {
                // 使用在线 API 批量查询
                $batchResults = $this->fetchBatchFromApi($toFetch);
                
                foreach ($batchResults as $ip => $data) {
                    $results[$ip] = $data;
                    
                    if ($cacheEnabled) {
                        Cache::put($cachePrefix . $ip, $data, $ttl);
                    }
                }
            }
        }

        return $results;
    }

    /**
     * 批量从 API 获取国家信息
     */
    protected function fetchBatchFromApi(array $ips): array
    {
        $results = [];
        $default = ['country_code' => null, 'country_name' => null];

        // ip-api.com 批量 API 一次最多查询 100 个 IP
        $chunks = array_chunk($ips, 100);

        foreach ($chunks as $chunk) {
            try {
                $response = Http::timeout(5)
                    ->withBody(json_encode($chunk), 'application/json')
                    ->post('http://ip-api.com/batch?fields=query,status,countryCode,country');

                if ($response->successful()) {
                    $batchData = $response->json();
                    
                    foreach ($batchData as $item) {
                        $ip = $item['query'] ?? '';
                        if (isset($item['status']) && $item['status'] === 'success') {
                            $results[$ip] = [
                                'country_code' => $item['countryCode'] ?? null,
                                'country_name' => $item['country'] ?? null,
                            ];
                        } else {
                            $results[$ip] = $default;
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::warning('GeoIP: 批量 API 查询失败', [
                    'error' => $e->getMessage(),
                ]);
                
                foreach ($chunk as $ip) {
                    if (!isset($results[$ip])) {
                        $results[$ip] = $default;
                    }
                }
            }
        }

        // 确保所有 IP 都有结果
        foreach ($ips as $ip) {
            if (!isset($results[$ip])) {
                $results[$ip] = $default;
            }
        }

        return $results;
    }

    /**
     * 检查本地数据库是否可用
     */
    public function isLocalDatabaseAvailable(): bool
    {
        return $this->localDatabaseAvailable;
    }

    /**
     * 获取数据库信息
     */
    public function getDatabaseInfo(): ?array
    {
        if (!$this->localDatabaseAvailable || !$this->reader) {
            return null;
        }

        try {
            $metadata = $this->reader->metadata();
            return [
                'type' => $metadata->databaseType,
                'build_epoch' => $metadata->buildEpoch,
                'build_date' => date('Y-m-d H:i:s', $metadata->buildEpoch),
                'ip_version' => $metadata->ipVersion,
                'node_count' => $metadata->nodeCount,
            ];
        } catch (\Exception $e) {
            return null;
        }
    }
}
