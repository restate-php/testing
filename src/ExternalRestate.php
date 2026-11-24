<?php

declare(strict_types=1);

namespace Restate\Testing;

use Restate\Testing\Internal\Validate;

/**
 * A Restate server that already runs, outside the test harness: one that `devenv up` started (devenv-extras'
 * `services.restate`), a `restate-server` of your own, or a shared one. The harness connects to it, waits until it is
 * ready, registers the endpoint with it and drives it through its admin API and ingress, but never starts, stops,
 * restarts or pauses it: {@see RestateTestEnvironment::restartRestate()}, {@see RestateTestEnvironment::pauseRestate()}
 * and {@see RestateTestEnvironment::unpauseRestate()} throw, and {@see RestateTestEnvironment::stop()} leaves it
 * running.
 *
 * Its state outlives the tests: invocations, state and deployments of earlier runs stay. Use fresh keys per test (as
 * with any shared environment), and expect more deployments than the ones the tests register.
 *
 * Immutable: every `with…()` method returns a new instance.
 */
final readonly class ExternalRestate
{
    /** The admin API URL that {@see fromEnvironment()} reads, as the `restate` CLI does. */
    public const string ADMIN_URL_VARIABLE = 'RESTATE_ADMIN_URL';

    /** The ingress URL that {@see fromEnvironment()} reads. */
    public const string INGRESS_URL_VARIABLE = 'RESTATE_INGRESS_URL';

    private function __construct(
        /** The admin API as the test process reaches it, without a trailing slash. */
        public string $adminUrl,
        /** The ingress as the test process reaches it, without a trailing slash. */
        public string $ingressUrl,
        /**
         * The test host as Restate reaches it, for the endpoint the harness serves ({@see PhpCliServer},
         * {@see EndpointContainer}) and {@see RestateTestEnvironment::testHostForRestate()}: `127.0.0.1` for a Restate
         * on the test host, `host.docker.internal` for one in a container.
         */
        public string $testHost = '127.0.0.1',
        /** A file the Restate server logs to, for the log tails of failed tests; null: none. */
        public ?string $logFile = null,
        /** How long Restate and the endpoint may take to become ready. */
        public int $startupTimeoutSeconds = RestateContainer::DEFAULT_STARTUP_TIMEOUT_SECONDS,
    ) {
    }

    /**
     * @param string $adminUrl   the admin API as the test process reaches it, for example `http://127.0.0.1:9070`
     * @param string $ingressUrl the ingress as the test process reaches it, for example `http://127.0.0.1:8080`
     */
    public static function at(string $adminUrl, string $ingressUrl): self
    {
        return new self(Validate::url($adminUrl, 'Restate admin URL'), Validate::url($ingressUrl, 'Restate ingress URL'));
    }

    /**
     * The Restate of `RESTATE_ADMIN_URL` and `RESTATE_INGRESS_URL`, which devenv-extras' `services.restate` exports in
     * the dev shell. Reading them is your choice: the harness never connects to a running Restate by itself.
     *
     * @throws \InvalidArgumentException when either is unset or not a URL
     */
    public static function fromEnvironment(): self
    {
        $read = static function (string $name): string {
            $value = getenv($name);

            return is_string($value) && $value !== '' ? $value : throw new \InvalidArgumentException(
                "{$name} is not set: ExternalRestate::fromEnvironment() connects to the Restate that "
                . self::ADMIN_URL_VARIABLE . ' and ' . self::INGRESS_URL_VARIABLE . ' point to (`devenv up` with services.restate exports them).',
            );
        };

        return self::at($read(self::ADMIN_URL_VARIABLE), $read(self::INGRESS_URL_VARIABLE));
    }

    /** The test host as Restate reaches it (see {@see $testHost}): a host name or an IP address. */
    public function withTestHost(string $host): self
    {
        if ($host === '' || preg_match('/^[A-Za-z0-9.\-]+$|^\[[0-9A-Fa-f:.]+\]$/', $host) !== 1) {
            throw new \InvalidArgumentException("The test host must be a host name, an IPv4 address or a bracketed IPv6 address, got \"{$host}\".");
        }

        return $this->with(testHost: $host);
    }

    /** Tail this file as the Restate log of failed tests ({@see RestateTestEnvironment::restateLog()}). */
    public function withLogFile(string $path): self
    {
        return $this->with(logFile: Validate::nonEmpty($path, 'Restate log file'));
    }

    public function withStartupTimeout(int $seconds): self
    {
        if ($seconds < 1) {
            throw new \InvalidArgumentException("The startup timeout must be at least 1 second, got {$seconds}.");
        }

        return $this->with(startupTimeoutSeconds: $seconds);
    }

    private function with(?string $testHost = null, ?string $logFile = null, ?int $startupTimeoutSeconds = null): self
    {
        return new self(
            $this->adminUrl,
            $this->ingressUrl,
            $testHost ?? $this->testHost,
            $logFile ?? $this->logFile,
            $startupTimeoutSeconds ?? $this->startupTimeoutSeconds,
        );
    }
}
