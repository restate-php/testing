<?php

declare(strict_types=1);

namespace Restate\Testing;

use Restate\Testing\Internal\Validate;

/**
 * The Restate server to start as a process of the test harness: `restate-server` on the test host, without a
 * container runtime. The harness gives it a fresh data directory (removed by {@see RestateTestEnvironment::stop()})
 * and three free loopback ports, and owns the process: {@see RestateTestEnvironment::restartRestate()} is SIGTERM or
 * SIGKILL and a start on the same data directory and ports, {@see RestateTestEnvironment::pauseRestate()} is SIGSTOP.
 * Its output is the Restate log.
 *
 * Restate reaches the endpoint on loopback, so a {@see PhpCliServer} listens on `127.0.0.1` by default instead of
 * `0.0.0.0`. The binary comes from `PATH` (nix-restate's `restate-bin` in a Nix or devenv shell, or a release binary
 * from https://github.com/restatedev/restate/releases); it should be the version of
 * {@see RestateContainer::DEFAULT_IMAGE}, which the harness's defaults are tested with.
 *
 * Immutable: every `with…()` method returns a new instance.
 */
final readonly class RestateProcess
{
    /**
     * Restate settings the harness sets itself (the data directory, the addresses it listens on and advertises, how it
     * listens), which {@see withEnvironment()} refuses.
     */
    public const array HARNESS_VARIABLES = [
        'RESTATE_BASE_DIR',
        'RESTATE_NODE_NAME',
        'RESTATE_LISTEN_MODE',
        'RESTATE_BIND_ADDRESS',
        'RESTATE_ADVERTISED_ADDRESS',
        'RESTATE_INGRESS__BIND_ADDRESS',
        'RESTATE_ADMIN__BIND_ADDRESS',
    ];

    /** @param array<string, string> $environment */
    private function __construct(
        /** The `restate-server` binary, a name on `PATH` or a path. */
        public string $binary = 'restate-server',
        /**
         * Restate server configuration as environment variables (`RESTATE_…`), for example
         * `RESTATE_LOG_FILTER=restate=debug`. They replace the harness's defaults of the same name (one partition,
         * compact logs without colors), as for {@see RestateContainer::withEnvironment()}.
         */
        public array $environment = [],
        /** How long Restate and the endpoint may take to become ready. */
        public int $startupTimeoutSeconds = RestateContainer::DEFAULT_STARTUP_TIMEOUT_SECONDS,
    ) {
    }

    /** `restate-server` from `PATH`. */
    public static function default(): self
    {
        return new self();
    }

    /** Use this binary (a name on `PATH` or a path) instead of `restate-server`. */
    public function withBinary(string $binary): self
    {
        return $this->with(binary: Validate::nonEmpty($binary, 'restate-server binary'));
    }

    /**
     * @param array<string, string> $variables added to (and replacing) the variables set so far; a file that a
     *                                         setting points to is a path on the test host (no volume needed)
     */
    public function withEnvironment(array $variables): self
    {
        $variables = Validate::environment($variables);
        $owned = array_values(array_intersect(array_keys($variables), self::HARNESS_VARIABLES));
        if ($owned !== []) {
            throw new \InvalidArgumentException(sprintf(
                'The harness sets %s itself: it gives Restate its own data directory and loopback ports.',
                implode(', ', $owned),
            ));
        }

        return $this->with(environment: [...$this->environment, ...$variables]);
    }

    public function withStartupTimeout(int $seconds): self
    {
        if ($seconds < 1) {
            throw new \InvalidArgumentException("The startup timeout must be at least 1 second, got {$seconds}.");
        }

        return $this->with(startupTimeoutSeconds: $seconds);
    }

    /** @param ?array<string, string> $environment */
    private function with(?string $binary = null, ?array $environment = null, ?int $startupTimeoutSeconds = null): self
    {
        return new self(
            $binary ?? $this->binary,
            $environment ?? $this->environment,
            $startupTimeoutSeconds ?? $this->startupTimeoutSeconds,
        );
    }
}
