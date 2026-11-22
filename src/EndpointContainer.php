<?php

declare(strict_types=1);

namespace Restate\Testing;

use Restate\Testing\Internal\Validate;

/**
 * The endpoint under test, served by a container: your application's image, or a FrankenPHP or PHP-FPM image that
 * mounts your code. The harness runs it with the same container runtime as Restate, publishes its port on the bind
 * address, and Restate reaches it as it reaches a {@see PhpCliServer}: through `host.docker.internal` from a
 * {@see RestateContainer}, on loopback as a {@see RestateProcess} (the container runtime is then `docker`, else
 * `podman`, from `PATH`). The image must already exist locally or be pullable.
 *
 * The published port listens on `0.0.0.0` by default with a Restate container, which **exposes the endpoint on your
 * network** for as long as the environment runs, and on `127.0.0.1` with a Restate process; see
 * {@see withBindAddress()}.
 *
 * Immutable: every `with…()` returns a new configuration.
 */
final readonly class EndpointContainer
{
    /**
     * @param list<array{hostPath: string, containerPath: string, readOnly: bool}> $volumes
     * @param array<string, string> $environment
     * @param list<string> $command
     * @param list<string> $runArguments
     */
    private function __construct(
        public string $image,
        /** The port the endpoint listens on inside the container. */
        public int $port,
        /** Bind mounts. */
        public array $volumes = [],
        public array $environment = [],
        /** Arguments after the image name (the image's command); empty runs the image's default. */
        public array $command = [],
        /** Host address to publish the port on; null is `RESTATE_PHP_TEST_BIND`, else `0.0.0.0`. */
        public ?string $bindAddress = null,
        /** The path the endpoint is served under (`''` or `/restate`), which is what gets registered with Restate. */
        public string $path = '',
        /** Whether Restate talks HTTP/2 to the endpoint (cleartext h2c with prior knowledge) instead of HTTP/1.1. */
        public bool $http2 = false,
        /** Extra `run` options before the image name, such as `--cpus 1` or `--memory 256m`. */
        public array $runArguments = [],
    ) {
    }

    public static function forImage(string $image, int $port = 8080): self
    {
        return new self(Validate::nonEmpty($image, 'endpoint image'), Validate::port($port));
    }

    /** Bind-mount an existing host directory or file at an absolute path in the container. */
    public function withVolume(string $hostPath, string $containerPath, bool $readOnly = false): self
    {
        return $this->with(volumes: [...$this->volumes, Validate::volume($hostPath, $containerPath, $readOnly)]);
    }

    /** @param array<string, string> $variables added to (and replacing) the variables set so far */
    public function withEnvironment(array $variables): self
    {
        return $this->with(environment: [...$this->environment, ...Validate::environment($variables)]);
    }

    /** @param list<string> $command */
    public function withCommand(array $command): self
    {
        foreach ($command as $argument) {
            Validate::nonEmpty($argument, 'command argument');
        }

        return $this->with(command: $command);
    }

    public function withBindAddress(string $address): self
    {
        return $this->with(bindAddress: Validate::bindAddress($address));
    }

    /** Serve (and register) the endpoint under a path, for an application that routes Restate requests below one. */
    public function withPath(string $path): self
    {
        return $this->with(path: Validate::endpointPath($path));
    }

    /**
     * Register the endpoint for HTTP/2 instead of HTTP/1.1: Restate then speaks cleartext HTTP/2 with prior knowledge
     * (h2c) to it, so the server in the image must accept that, as Caddy (FrankenPHP) and nginx with `http2 on` do.
     */
    public function withHttp2(bool $http2 = true): self
    {
        return $this->with(http2: $http2);
    }

    /**
     * Extra `run` options, added after the ones set so far and placed before the image name: resource limits
     * (`['--cpus', '1', '--memory', '256m']`), capabilities, a network. The harness's own options (`--name`, `--rm`,
     * `--detach`, `--publish`) are refused.
     *
     * @param list<string> $arguments
     */
    public function withRunArguments(array $arguments): self
    {
        return $this->with(runArguments: [...$this->runArguments, ...Validate::runArguments($arguments)]);
    }

    /**
     * @param ?list<array{hostPath: string, containerPath: string, readOnly: bool}> $volumes
     * @param ?array<string, string> $environment
     * @param ?list<string> $command
     * @param ?list<string> $runArguments
     */
    private function with(
        ?array $volumes = null,
        ?array $environment = null,
        ?array $command = null,
        ?string $bindAddress = null,
        ?string $path = null,
        ?bool $http2 = null,
        ?array $runArguments = null,
    ): self {
        return new self(
            $this->image,
            $this->port,
            $volumes ?? $this->volumes,
            $environment ?? $this->environment,
            $command ?? $this->command,
            $bindAddress ?? $this->bindAddress,
            $path ?? $this->path,
            $http2 ?? $this->http2,
            $runArguments ?? $this->runArguments,
        );
    }
}
