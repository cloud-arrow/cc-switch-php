<?php

declare(strict_types=1);

namespace CcSwitch\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Network speed test service.
 *
 * Measures RTT latency to API endpoints using HEAD requests.
 */
class SpeedTestService
{
    /**
     * Test latency to a list of URLs.
     *
     * Sends a HEAD request to each URL and measures round-trip time.
     * Results are sorted by latency (fastest first), with errors at the end.
     *
     * @param string[] $urls
     * @return array<int, array{url: string, latency_ms: int|null, status: int|null, error: string|null}>
     */
    public function test(array $urls, int $timeoutSeconds = 10): array
    {
        if (empty($urls)) {
            return [];
        }

        $timeoutSeconds = max(2, min(30, $timeoutSeconds));
        $client = new Client([
            'timeout' => $timeoutSeconds,
            'connect_timeout' => $timeoutSeconds,
            'verify' => false,
            'allow_redirects' => true,
        ]);

        $results = [];

        foreach ($urls as $url) {
            $url = trim($url);

            if ($url === '') {
                $results[] = [
                    'url' => $url,
                    'latency_ms' => null,
                    'status' => null,
                    'error' => 'URL is empty',
                ];
                continue;
            }

            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                $results[] = [
                    'url' => $url,
                    'latency_ms' => null,
                    'status' => null,
                    'error' => 'Invalid URL',
                ];
                continue;
            }

            // Warm-up request (ignore result, used for connection reuse)
            try {
                $client->head($url);
            } catch (\Throwable $e) {
                // Ignore warm-up errors
            }

            // Timed request
            $start = hrtime(true);
            try {
                $response = $client->head($url);
                $elapsed = (int) round((hrtime(true) - $start) / 1_000_000);

                $results[] = [
                    'url' => $url,
                    'latency_ms' => $elapsed,
                    'status' => $response->getStatusCode(),
                    'error' => null,
                ];
            } catch (GuzzleException $e) {
                $elapsed = (int) round((hrtime(true) - $start) / 1_000_000);
                $errorMsg = 'Request failed';

                if (str_contains($e->getMessage(), 'timed out') || str_contains($e->getMessage(), 'timeout')) {
                    $errorMsg = 'Request timed out';
                } elseif (str_contains($e->getMessage(), 'Could not resolve') || str_contains($e->getMessage(), 'Connection refused')) {
                    $errorMsg = 'Connection failed';
                }

                $results[] = [
                    'url' => $url,
                    'latency_ms' => null,
                    'status' => null,
                    'error' => $errorMsg,
                ];
            }
        }

        // Sort: successful results by latency ASC, errors at the end
        usort($results, function ($a, $b) {
            if ($a['latency_ms'] === null && $b['latency_ms'] === null) {
                return 0;
            }
            if ($a['latency_ms'] === null) {
                return 1;
            }
            if ($b['latency_ms'] === null) {
                return -1;
            }
            return $a['latency_ms'] <=> $b['latency_ms'];
        });

        return $results;
    }
}
