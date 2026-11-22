<?php

declare(strict_types=1);

namespace Restate\Testing;

use Restate\Testing\Internal\Validate;

/**
 * The Restate server the harness starts: a container run with the `docker` CLI (Docker, or podman, rootless
 * included), its ingress and admin ports published on `127.0.0.1` ports the harness picks (free ones, kept for the
 * container's lifetime, so a restart does not move them).
 *
 * Immutable: every `with…()` returns a new configuration.
 */
final readonly class RestateContainer
{
    /** The Restate server this SDK release is tested against. */
    public const string DEFAULT_IMAGE = 'docker.restate.dev/restatedev/restate:1.8.1';

    public const int DEFAULT_STARTUP_TIMEOUT_SECONDS = 60;

    /**
     * @param array<string, string> $environment
     * @param list<array{hostPath: string, containerPath: string, readOnly: bool}> $volumes
     * @param list<string> $runArguments
     */
    private function __construct(
        public string $image,
        /**
         * Restate server configuration as environment variables (`RESTATE_…`), for example
         * `RESTATE_LOG_FILTER=restate=debug`. They replace the harness's defaults (one partition, compact logs
         * without colors).
         */
        public array $environment = [],
        /** The container CLI, a name on `PATH` or a path; null uses `docker`, else `podman`. */
        public ?string $containerRuntime = null,
        /** How long Restate and the endpoint may take to become ready. */
        public int $startupTimeoutSeconds = self::DEFAULT_STARTUP_TIMEOUT_SECONDS,
        /** Bind mounts, for files Restate's configuration points to (a config file, a request identity key). */
        public array $volumes = [],
        /** Extra `run` options before the image name, such as `--cpus 1` or `--memory 1g`. */
        public array $runArguments = [],
    ) {
    }

    /** {@see DEFAULT_IMAGE}, or the image named by `RESTATE_PHP_RESTATE_IMAGE` (to run a suite against other versions). */
    public static function default(): self
    {
        $image = getenv('RESTATE_PHP_RESTATE_IMAGE');

        return new self(is_string($image) && $image !== '' ? $image : self::DEFAULT_IMAGE);
    }

    /** A specific Restate image; `RESTATE_PHP_RESTATE_IMAGE` does not apply. */
    public static function forImage(string $image): self
    {
        return new self(Validate::nonEmpty($image, 'Restate image'));
    }

    /** @param array<string, string> $variables added to (and replacing) the variables set so far */
    public function withEnvironment(array $variables): self
    {
        return $this->with(environment: [...$this->environment, ...Validate::environment($variables)]);
    }

    /**
     * Bind-mount an existing host directory or file at an absolute path in the container, for example a request
     * identity private key that `RESTATE_WORKER__INVOKER__REQUEST_IDENTITY_PRIVATE_KEY_PEM_FILE` points to.
     */
    public function withVolume(string $hostPath, string $containerPath, bool $readOnly = false): self
    {
        return $this->with(volumes: [...$this->volumes, Validate::volume($hostPath, $containerPath, $readOnly)]);
    }

    /** Use this container CLI (`docker`, `podman`, or a path) instead of looking for one on `PATH`. */
    public function withContainerRuntime(string $command): self
    {
        return $this->with(containerRuntime: Validate::nonEmpty($command, 'container runtime'));
    }

    public function withStartupTimeout(int $seconds): self
    {
        if ($seconds < 1) {
            throw new \InvalidArgumentException("The startup timeout must be at least 1 second, got {$seconds}.");
        }

        return $this->with(startupTimeoutSeconds: $seconds);
    }

    /**
     * Extra `run` options, added after the ones set so far and placed before the image name: resource limits
     * (`['--cpus', '2', '--memory', '1g']`), a network. The harness's own options (`--name`, `--rm`, `--detach`,
     * `--publish`) are refused.
     *
     * @param list<string> $arguments
     */
    public function withRunArguments(array $arguments): self
    {
        return $this->with(runArguments: [...$this->runArguments, ...Validate::runArguments($arguments)]);
    }

    /**
     * @param ?array<string, string> $environment
     * @param ?list<array{hostPath: string, containerPath: string, readOnly: bool}> $volumes
     * @param ?list<string> $runArguments
     */
    private function with(
        ?array $environment = null,
        ?string $containerRuntime = null,
        ?int $startupTimeoutSeconds = null,
        ?array $volumes = null,
        ?array $runArguments = null,
    ): self {
        return new self(
            $this->image,
            $environment ?? $this->environment,
            $containerRuntime ?? $this->containerRuntime,
            $startupTimeoutSeconds ?? $this->startupTimeoutSeconds,
            $volumes ?? $this->volumes,
            $runArguments ?? $this->runArguments,
        );
    }
}
