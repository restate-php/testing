<?php

declare(strict_types=1);

namespace Restate\Testing\Internal;

use Restate\Testing\EndpointContainer;
use Restate\Testing\PhpCliServer;
use Restate\Testing\RestateContainer;
use Restate\Testing\RestateProcess;
use Restate\Testing\RestateTestEnvironment;

/**
 * The command lines the harness runs (as argument lists, never through a shell).
 *
 * @internal
 */
final class Commands
{
    /** Restate's defaults in the harness; `RestateContainer::withEnvironment()` replaces them. */
    private const array RESTATE_ENVIRONMENT = [
        // One partition: readiness of the admin query proves the whole server is ready.
        'RESTATE_DEFAULT_NUM_PARTITIONS' => '1',
        'RESTATE_LOG_FORMAT' => 'compact',
        'RESTATE_LOG_DISABLE_ANSI_CODES' => 'true',
    ];

    public const int RESTATE_INGRESS_PORT = 8080;

    public const int RESTATE_ADMIN_PORT = 9070;

    private function __construct()
    {
    }

    /** @return list<string> */
    public static function phpCliServer(PhpCliServer $server, string $phpBinary, ?string $extensionPath, string $bindAddress, int $port): array
    {
        return [
            ...self::php($server, $phpBinary, $extensionPath),
            '-S', self::hostPort($bindAddress, $port),
            '-t', $server->documentRoot ?? dirname($server->routerScript),
            $server->routerScript,
        ];
    }

    /**
     * PHP with the server's extension and ini settings.
     *
     * @return list<string>
     */
    public static function php(PhpCliServer $server, string $phpBinary, ?string $extensionPath): array
    {
        $command = [$phpBinary];
        if ($extensionPath !== null) {
            $command[] = '-d';
            $command[] = 'extension=' . $extensionPath;
        }
        foreach ($server->iniSettings as $name => $value) {
            $command[] = '-d';
            $command[] = "{$name}={$value}";
        }

        return $command;
    }

    /**
     * `run --detach` of the Restate container, its ingress and admin ports published on the given `127.0.0.1` host
     * ports. The harness chooses them rather than the daemon, so a container that is stopped and started again keeps
     * them (an explicit mapping is part of the container's configuration).
     *
     * @return list<string>
     */
    public static function restate(string $runtime, RestateContainer $restate, string $name, int $ingressPort, int $adminPort): array
    {
        $command = [
            $runtime, 'run', '--detach',
            '--name', $name,
            '--label', RestateTestEnvironment::CONTAINER_LABEL,
            // Restate reaches the endpoint on the test host through this name (Docker Engine needs it spelled out).
            '--add-host', 'host.docker.internal:host-gateway',
        ];
        foreach ([...self::RESTATE_ENVIRONMENT, ...$restate->environment] as $variable => $value) {
            $command[] = '--env';
            $command[] = "{$variable}={$value}";
        }

        return [
            ...$command,
            ...self::volumes($restate->volumes),
            // On loopback only: nothing but the tests needs to reach Restate.
            '--publish', '127.0.0.1:' . Validate::port($ingressPort) . ':' . self::RESTATE_INGRESS_PORT,
            '--publish', '127.0.0.1:' . Validate::port($adminPort) . ':' . self::RESTATE_ADMIN_PORT,
            ...$restate->runArguments,
            $restate->image,
        ];
    }

    /**
     * `restate-server` as a process of the harness, on loopback ports.
     *
     * @return list<string>
     */
    public static function restateProcess(string $binary): array
    {
        return [$binary, '--no-logo'];
    }

    /**
     * The process's configuration: the harness's defaults, the {@see RestateProcess} environment over them, then what
     * the harness owns (its data directory and the loopback addresses it listens on and advertises). TCP rather than
     * Unix sockets, which Restate would create under the data directory, whose path easily exceeds macOS's 104-byte
     * limit for them.
     *
     * @return array<string, string>
     */
    public static function restateProcessEnvironment(RestateProcess $restate, string $baseDir, string $nodeName, int $ingressPort, int $adminPort, int $nodePort): array
    {
        $node = '127.0.0.1:' . Validate::port($nodePort);

        return [
            ...self::RESTATE_ENVIRONMENT,
            ...$restate->environment,
            'RESTATE_BASE_DIR' => $baseDir,
            'RESTATE_NODE_NAME' => $nodeName,
            'RESTATE_LISTEN_MODE' => 'tcp',
            'RESTATE_BIND_ADDRESS' => $node,
            'RESTATE_ADVERTISED_ADDRESS' => "http://{$node}/",
            'RESTATE_INGRESS__BIND_ADDRESS' => '127.0.0.1:' . Validate::port($ingressPort),
            'RESTATE_ADMIN__BIND_ADDRESS' => '127.0.0.1:' . Validate::port($adminPort),
        ];
    }

    /**
     * The endpoint container stays attached (`run` without `--detach`), so its output is the endpoint log, and
     * `--rm` removes it when it stops.
     *
     * @return list<string>
     */
    public static function endpointContainer(string $runtime, EndpointContainer $container, string $name, string $bindAddress, int $port): array
    {
        $command = [$runtime, 'run', '--rm', '--name', $name, '--label', RestateTestEnvironment::CONTAINER_LABEL, ...self::volumes($container->volumes)];
        foreach ($container->environment as $variable => $value) {
            $command[] = '--env';
            $command[] = "{$variable}={$value}";
        }

        return [
            ...$command,
            '--publish', self::hostPort($bindAddress, $port) . ':' . $container->port,
            ...$container->runArguments,
            $container->image,
            ...$container->command,
        ];
    }

    /**
     * `stop` with a grace period: the image's stop signal (SIGTERM unless it says otherwise), then SIGKILL after
     * `$timeoutSeconds`.
     *
     * @return list<string>
     */
    public static function stopContainer(string $runtime, string $name, int $timeoutSeconds): array
    {
        return [$runtime, 'stop', '--time', (string) max(0, $timeoutSeconds), $name];
    }

    /**
     * Send a signal to the container's main process; 9 (SIGKILL) kills the container.
     *
     * @return list<string>
     */
    public static function signalContainer(string $runtime, string $name, int $signal): array
    {
        return [$runtime, 'kill', '--signal', (string) $signal, $name];
    }

    /**
     * `start`, `pause` or `unpause` of an existing container, or `wait` until it has stopped.
     *
     * @param 'start'|'pause'|'unpause'|'wait' $verb
     *
     * @return list<string>
     */
    public static function container(string $runtime, string $verb, string $name): array
    {
        return [$runtime, $verb, $name];
    }

    /** @return list<string> */
    public static function removeContainer(string $runtime, string $name): array
    {
        return [$runtime, 'rm', '--force', '--volumes', $name];
    }

    /** The host the test process uses to reach a server listening on the bind address. */
    public static function reachableHost(string $bindAddress): string
    {
        return match ($bindAddress) {
            '0.0.0.0' => '127.0.0.1',
            '::' => '[::1]',
            default => self::host($bindAddress),
        };
    }

    public static function host(string $address): string
    {
        return str_contains($address, ':') ? "[{$address}]" : $address;
    }

    /**
     * @param list<array{hostPath: string, containerPath: string, readOnly: bool}> $volumes
     *
     * @return list<string>
     */
    private static function volumes(array $volumes): array
    {
        $options = [];
        foreach ($volumes as $volume) {
            $options[] = '--volume';
            $options[] = $volume['hostPath'] . ':' . $volume['containerPath'] . ($volume['readOnly'] ? ':ro' : '');
        }

        return $options;
    }

    private static function hostPort(string $address, int $port): string
    {
        return self::host($address) . ':' . $port;
    }
}
