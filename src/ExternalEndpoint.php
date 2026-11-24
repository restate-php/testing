<?php

declare(strict_types=1);

namespace Restate\Testing;

use Restate\Testing\Internal\Validate;

/**
 * An endpoint that already runs, outside the test harness: FrankenPHP or PHP-FPM that `devenv up` started, or your
 * application's server. The harness waits for its `/health`, registers it with Restate and reads its log file if you
 * name one, but never starts, stops or signals it: {@see RestateTestEnvironment::killEndpoint()},
 * {@see RestateTestEnvironment::startEndpoint()}, {@see RestateTestEnvironment::restartEndpoint()} and
 * {@see RestateTestEnvironment::signalEndpoint()} throw.
 *
 * Its configuration is its own: what the harness sets for the endpoints it serves (environment variables, ini
 * settings, the extension) does not apply.
 *
 * Immutable: every `with…()` method returns a new instance.
 */
final readonly class ExternalEndpoint
{
    private function __construct(
        /** The endpoint as the test process reaches it, including the path it is served under, without a trailing slash. */
        public string $url,
        /** The endpoint as Restate reaches it, registered with Restate; null: {@see $url}. */
        public ?string $restateUri = null,
        /** Whether Restate talks HTTP/2 to the endpoint (cleartext h2c with prior knowledge) instead of HTTP/1.1. */
        public bool $http2 = false,
        /** A file the endpoint logs to (its server's output, or PHP's `error_log`), for {@see RestateTestEnvironment::endpointLog()}. */
        public ?string $logFile = null,
    ) {
    }

    /** @param string $url the endpoint as the test process reaches it, for example `http://127.0.0.1:8000` or `http://127.0.0.1:8000/restate` */
    public static function at(string $url): self
    {
        return new self(Validate::url($url, 'endpoint URL'));
    }

    /**
     * Register the endpoint with Restate under this URI instead of {@see $url}: where Restate reaches it, when that
     * differs from where the tests do (a Restate in a container, a proxy in front of the endpoint).
     */
    public function withRestateUri(string $uri): self
    {
        return $this->with(restateUri: Validate::url($uri, 'endpoint URI for Restate'));
    }

    /**
     * Register the endpoint for HTTP/2 instead of HTTP/1.1: Restate then speaks cleartext HTTP/2 with prior knowledge
     * (h2c) to it, which the server must accept.
     */
    public function withHttp2(bool $http2 = true): self
    {
        return $this->with(http2: $http2);
    }

    /**
     * Read this file as the endpoint log ({@see RestateTestEnvironment::endpointLog()}, the log tails of failed
     * tests). It may not exist yet.
     */
    public function withLogFile(string $path): self
    {
        return $this->with(logFile: Validate::nonEmpty($path, 'endpoint log file'));
    }

    /** The URI Restate reaches the endpoint at. */
    public function uriForRestate(): string
    {
        return $this->restateUri ?? $this->url;
    }

    /** The port of {@see $url}. */
    public function port(): int
    {
        $port = parse_url($this->url, PHP_URL_PORT);

        return is_int($port) ? $port : (str_starts_with(strtolower($this->url), 'https:') ? 443 : 80);
    }

    private function with(?string $restateUri = null, ?bool $http2 = null, ?string $logFile = null): self
    {
        return new self(
            $this->url,
            $restateUri ?? $this->restateUri,
            $http2 ?? $this->http2,
            $logFile ?? $this->logFile,
        );
    }
}
