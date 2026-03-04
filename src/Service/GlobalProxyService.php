<?php

declare(strict_types=1);

namespace CcSwitch\Service;

use CcSwitch\Database\Repository\SettingsRepository;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Global outbound proxy management.
 */
class GlobalProxyService
{
    private const SETTING_KEY = 'global_proxy_url';

    private const ALLOWED_SCHEMES = ['http', 'https', 'socks5', 'socks5h'];

    private const COMMON_PROXY_PORTS = [1080, 7890, 7891, 8080, 8118, 10808, 10809, 20171];

    public function __construct(private readonly SettingsRepository $settings)
    {
    }

    /**
     * Get the currently configured proxy URL.
     */
    public function getProxyUrl(): ?string
    {
        return $this->settings->get(self::SETTING_KEY);
    }

    /**
     * Set the proxy URL. Pass null to clear.
     *
     * @throws \InvalidArgumentException if URL scheme is not allowed
     */
    public function setProxyUrl(?string $url): void
    {
        if ($url === null || $url === '') {
            $this->settings->delete(self::SETTING_KEY);
            return;
        }

        $this->validateProxyUrl($url);
        $this->settings->set(self::SETTING_KEY, $url);
    }

    /**
     * Test proxy connectivity by connecting to well-known endpoints.
     *
     * @return array{success: bool, results: array<int, array{url: string, ok: bool, latency_ms: ?int, error: ?string}>}
     */
    public function testProxy(string $url): array
    {
        $this->validateProxyUrl($url);

        $testUrls = ['https://www.google.com', 'https://1.1.1.1'];
        $client = new Client([
            'proxy' => $url,
            'timeout' => 10,
            'connect_timeout' => 5,
            'verify' => false,
            'allow_redirects' => true,
        ]);

        $results = [];
        $anySuccess = false;

        foreach ($testUrls as $testUrl) {
            $start = hrtime(true);
            try {
                $response = $client->head($testUrl);
                $latencyMs = (int) round((hrtime(true) - $start) / 1_000_000);
                $results[] = [
                    'url' => $testUrl,
                    'ok' => true,
                    'latency_ms' => $latencyMs,
                    'error' => null,
                ];
                $anySuccess = true;
            } catch (GuzzleException $e) {
                $results[] = [
                    'url' => $testUrl,
                    'ok' => false,
                    'latency_ms' => null,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'success' => $anySuccess,
            'results' => $results,
        ];
    }

    /**
     * Scan common local proxy ports for available proxies.
     *
     * @return array<int, array{port: int, url: string, available: bool}>
     */
    public function scanLocalProxies(): array
    {
        $results = [];

        foreach (self::COMMON_PROXY_PORTS as $port) {
            $available = false;
            $errno = 0;
            $errstr = '';
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);

            if ($fp !== false) {
                $available = true;
                fclose($fp);
            }

            $results[] = [
                'port' => $port,
                'url' => "http://127.0.0.1:{$port}",
                'available' => $available,
            ];
        }

        return $results;
    }

    /**
     * Get Guzzle proxy options array for use with HTTP clients.
     *
     * @return array<string, mixed> e.g. ['proxy' => 'http://...'] or []
     */
    public function getGuzzleProxyOptions(): array
    {
        $url = $this->getProxyUrl();
        if ($url === null || $url === '') {
            return [];
        }
        return ['proxy' => $url];
    }

    /**
     * Validate a proxy URL scheme.
     *
     * @throws \InvalidArgumentException
     */
    private function validateProxyUrl(string $url): void
    {
        $parsed = parse_url($url);
        $scheme = $parsed['scheme'] ?? '';

        if (!in_array(strtolower($scheme), self::ALLOWED_SCHEMES, true)) {
            throw new \InvalidArgumentException(
                "Invalid proxy URL scheme '{$scheme}'. Allowed: " . implode(', ', self::ALLOWED_SCHEMES)
            );
        }

        if (empty($parsed['host'])) {
            throw new \InvalidArgumentException('Proxy URL must have a host');
        }
    }
}
