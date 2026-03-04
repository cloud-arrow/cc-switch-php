<?php

declare(strict_types=1);

namespace CcSwitch\Proxy;

use CcSwitch\Model\Provider;
use CcSwitch\Model\ProxyConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Swoole\Http\Response as SwooleResponse;

/**
 * Handles streaming (SSE) responses: forwards chunks from upstream to the Swoole response,
 * enforcing first_byte_timeout and idle_timeout.
 */
class StreamHandler
{
    /** @var array<string, mixed> */
    private array $proxyOptions = [];

    /**
     * Set global proxy options for Guzzle clients.
     *
     * @param array<string, mixed> $options e.g. ['proxy' => 'http://...']
     */
    public function setProxyOptions(array $options): void
    {
        $this->proxyOptions = $options;
    }

    /**
     * Forward a streaming request to the upstream provider and pipe chunks to the Swoole response.
     *
     * @param SwooleResponse $response      The Swoole response to write chunks to
     * @param Provider       $provider      The upstream provider
     * @param array          $headers       Headers to send to upstream
     * @param string         $url           The upstream URL
     * @param string         $body          The JSON-encoded request body
     * @param ProxyConfig    $config        Proxy config (for timeouts)
     * @return array{status: int, events: array, firstTokenMs: ?int, durationMs: int, error: ?string}
     */
    public function forward(
        SwooleResponse $response,
        Provider $provider,
        array $headers,
        string $url,
        string $body,
        ProxyConfig $config,
    ): array {
        $startTime = microtime(true);
        $firstTokenMs = null;
        $events = [];
        $error = null;
        $statusCode = 200;

        $client = new Client(array_merge([
            'timeout' => 0, // No overall timeout; we handle via idle/first-byte
            'connect_timeout' => 10,
            'verify' => false,
            'http_errors' => false, // Handle 4xx/5xx ourselves instead of throwing
        ], $this->proxyOptions));

        try {
            $upstreamResponse = $client->request('POST', $url, [
                'headers' => $headers,
                'body' => $body,
                'stream' => true,
            ]);

            $statusCode = $upstreamResponse->getStatusCode();

            // If upstream returned an error, forward it as a non-streaming response
            if ($statusCode >= 400) {
                $errorBody = (string) $upstreamResponse->getBody();
                $error = "Upstream returned HTTP {$statusCode}";
                $response->status($statusCode);
                $response->header('Content-Type', 'application/json');
                $response->end($errorBody);

                $durationMs = (int) ((microtime(true) - $startTime) * 1000);
                return [
                    'status' => $statusCode,
                    'events' => $events,
                    'firstTokenMs' => null,
                    'durationMs' => $durationMs,
                    'error' => $error,
                ];
            }

            // Forward status and headers for streaming
            $response->status($statusCode);
            $response->header('Content-Type', 'text/event-stream');
            $response->header('Cache-Control', 'no-cache');
            $response->header('Connection', 'keep-alive');
            $response->header('X-Accel-Buffering', 'no');

            $stream = $upstreamResponse->getBody();
            $buffer = '';
            $receivedFirstByte = false;
            $lastDataTime = microtime(true);

            while (!$stream->eof()) {
                // Check first byte timeout
                if (!$receivedFirstByte) {
                    $elapsed = microtime(true) - $startTime;
                    if ($elapsed > $config->streaming_first_byte_timeout) {
                        $error = 'First byte timeout exceeded (' . $config->streaming_first_byte_timeout . 's)';
                        break;
                    }
                }

                // Check idle timeout
                $idleElapsed = microtime(true) - $lastDataTime;
                if ($receivedFirstByte && $idleElapsed > $config->streaming_idle_timeout) {
                    $error = 'Idle timeout exceeded (' . $config->streaming_idle_timeout . 's)';
                    break;
                }

                $chunk = $stream->read(8192);
                if ($chunk === '') {
                    // No data available, small sleep to avoid busy loop
                    usleep(1000);
                    continue;
                }

                $lastDataTime = microtime(true);

                if (!$receivedFirstByte) {
                    $receivedFirstByte = true;
                    $firstTokenMs = (int) ((microtime(true) - $startTime) * 1000);
                }

                // Write chunk to client
                $response->write($chunk);

                // Parse SSE events for usage tracking
                $buffer .= $chunk;
                $this->parseSSEBuffer($buffer, $events);
            }

            // Process any remaining data in buffer
            if ($buffer !== '') {
                $this->parseSSEBuffer($buffer, $events, true);
            }
        } catch (GuzzleException $e) {
            $error = $e->getMessage();
            $statusCode = 502;
            $response->status($statusCode);
            $response->header('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            $statusCode = 502;
            $response->status($statusCode);
            $response->header('Content-Type', 'application/json');
        }

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        // End the response
        $response->end();

        return [
            'status' => $statusCode,
            'events' => $events,
            'firstTokenMs' => $firstTokenMs,
            'durationMs' => $durationMs,
            'error' => $error,
        ];
    }

    /**
     * Parse SSE events from the buffer, extracting complete events.
     *
     * @param string $buffer Reference to the running buffer
     * @param array  $events Reference to collected events
     * @param bool   $flush  If true, flush remaining buffer content
     */
    private function parseSSEBuffer(string &$buffer, array &$events, bool $flush = false): void
    {
        // SSE events are separated by double newlines
        while (($pos = strpos($buffer, "\n\n")) !== false) {
            $rawEvent = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 2);

            $data = $this->parseSSEEvent($rawEvent);
            if ($data !== null) {
                $events[] = $data;
            }
        }

        if ($flush && $buffer !== '') {
            $data = $this->parseSSEEvent($buffer);
            if ($data !== null) {
                $events[] = $data;
            }
            $buffer = '';
        }
    }

    /**
     * Parse a single SSE event text into a decoded JSON array.
     */
    private function parseSSEEvent(string $eventText): ?array
    {
        $dataLines = [];
        foreach (explode("\n", $eventText) as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'data: ')) {
                $payload = substr($line, 6);
                if ($payload === '[DONE]') {
                    continue;
                }
                $dataLines[] = $payload;
            }
        }

        if (empty($dataLines)) {
            return null;
        }

        $json = implode('', $dataLines);
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }
}
