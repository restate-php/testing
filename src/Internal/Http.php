<?php

declare(strict_types=1);

namespace Restate\Testing\Internal;

/**
 * The few HTTP requests the harness makes itself (health checks and the admin API), through PHP's HTTP stream wrapper
 * so that it needs neither curl nor a PSR-18 client.
 *
 * @internal
 */
final class Http
{
    private function __construct()
    {
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{int, string}|string the status and body, or the reason no response arrived
     */
    public static function request(string $method, string $url, ?string $body = null, array $headers = [], float $timeout = 5.0): array|string
    {
        $headerLines = ['Connection: close'];
        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => $headerLines,
            'content' => $body ?? '',
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 0,
            'protocol_version' => 1.1,
        ]]);

        $error = 'no response';
        set_error_handler(static function (int $type, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });
        try {
            $responseBody = file_get_contents($url, false, $context);
        } finally {
            restore_error_handler();
        }

        $responseHeaders = http_get_last_response_headers();
        if ($responseBody === false || $responseHeaders === null || $responseHeaders === []) {
            return $error;
        }

        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $responseHeaders[0], $match) !== 1) {
            return "malformed status line: {$responseHeaders[0]}";
        }

        return [(int) $match[1], $responseBody];
    }

    /** @param array<string, string> $headers */
    public static function isOk(string $method, string $url, ?string $body = null, array $headers = [], float $timeout = 2.0): bool
    {
        $response = self::request($method, $url, $body, $headers, $timeout);

        return is_array($response) && $response[0] >= 200 && $response[0] < 300;
    }
}
