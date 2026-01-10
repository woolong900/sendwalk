<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeoIpService
{
    /**
     * 根据 IP 地址获取国家信息
     * 使用 ip-api.com 免费服务（每分钟最多 45 次请求）
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

        // 使用缓存减少 API 调用，缓存 7 天
        $cacheKey = "geo_ip:{$ip}";
        
        return Cache::remember($cacheKey, 60 * 60 * 24 * 7, function () use ($ip, $default) {
            return $this->fetchCountryFromApi($ip) ?? $default;
        });
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
     * 从 API 获取国家信息
     */
    protected function fetchCountryFromApi(string $ip): ?array
    {
        try {
            // 使用 ip-api.com 免费服务
            // http://ip-api.com/json/{ip}?fields=status,countryCode,country
            $response = Http::timeout(3)->get("http://ip-api.com/json/{$ip}", [
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
            Log::warning('GeoIP lookup failed', [
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * 批量获取多个 IP 的国家信息
     * 使用 ip-api.com 批量查询 API（最多100个IP一次）
     *
     * @param array<string> $ips
     * @return array<string, array{country_code: string|null, country_name: string|null}>
     */
    public function getCountriesByIps(array $ips): array
    {
        $results = [];
        $toFetch = [];
        $default = ['country_code' => null, 'country_name' => null];

        // 先检查缓存
        foreach ($ips as $ip) {
            if (empty($ip) || $this->isLocalOrPrivateIp($ip)) {
                $results[$ip] = $default;
                continue;
            }

            $cacheKey = "geo_ip:{$ip}";
            $cached = Cache::get($cacheKey);
            
            if ($cached !== null) {
                $results[$ip] = $cached;
            } else {
                $toFetch[] = $ip;
            }
        }

        // 批量查询未缓存的 IP
        if (!empty($toFetch)) {
            $batchResults = $this->fetchBatchFromApi($toFetch);
            
            foreach ($batchResults as $ip => $data) {
                $cacheKey = "geo_ip:{$ip}";
                Cache::put($cacheKey, $data, 60 * 60 * 24 * 7);
                $results[$ip] = $data;
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
                Log::warning('GeoIP batch lookup failed', [
                    'error' => $e->getMessage(),
                ]);
                
                // 批量失败时，设置所有 IP 为默认值
                foreach ($chunk as $ip) {
                    $results[$ip] = $default;
                }
            }
        }

        return $results;
    }
}

