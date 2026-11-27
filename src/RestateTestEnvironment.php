<?php

declare(strict_types=1);

namespace Restate\Testing;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Restate\Ingress\IngressClient;
use Restate\Serde;
use Restate\Testing\Internal\Commands;
use Restate\Testing\Internal\ExtensionLocator;
use Restate\Testing\Internal\Http;
use Restate\Testing\Internal\Process;

/**
 * A real Restate server and your endpoint, started for tests: Restate runs in a container (a
 * {@see RestateContainer}, with the `docker` or `podman` CLI) or as a process on the test host (a
 * {@see RestateProcess}, `restate-server`), the endpoint runs as a {@see PhpCliServer} on the test host or as an
 * {@see EndpointContainer}, and the endpoint is registered with Restate. Drive it through {@see ingressClient()} like
 * production code would. A {@see RestateProcess} with a {@see PhpCliServer} needs no container runtime.
 *
 * Either side can also run outside the harness, already started (by `devenv up`, for example): an
 * {@see ExternalRestate} and an {@see ExternalEndpoint} are waited for, registered and read, never started, stopped
 * or signalled.
 *
 * ```php
 * $restate = RestateTestEnvironment::start(PhpCliServer::forScript(__DIR__ . '/endpoint.php'));
 * $greeting = $restate->ingressClient()->serviceClient('Greeter')->request('greet', 'Mary')->call();
 * $restate->stop();
 * ```
 *
 * Failure tests can stop, kill, pause and restart either side while invocations are in flight
 * ({@see restartRestate()}, {@see pauseRestate()}, {@see killEndpoint()}, {@see signalEndpoint()}, …), and read
 * Restate's view of an invocation through the admin SQL API ({@see query()}, {@see invocationRow()},
 * {@see waitForInvocationStatus()}). The ports stay the same across restarts, and Restate's storage survives them.
 *
 * Framework-agnostic; `Restate\Testing\PHPUnit\InteractsWithRestate` wires it into PHPUnit. Everything it starts is
 * removed by {@see stop()}, and at the latest when the PHP process exits, also after a fatal error, and after Ctrl-C
 * or SIGTERM unless the process installed its own handlers for them. A process killed with SIGKILL leaves containers
 * behind, which carry the label {@see CONTAINER_LABEL}, and a Restate process (`restate-server`, with its data in a
 * `restate-php-test-…` directory of the system temporary directory).
 */
final class RestateTestEnvironment
{
    /** Label on every container the harness starts: `docker rm -f $(docker ps -aq --filter label=…)` removes leftovers. */
    public const string CONTAINER_LABEL = 'restate-php-test-environment';

    /** The values of `sys_invocation.status`, for {@see waitForInvocationStatus()}. */
    public const array INVOCATION_STATUSES = ['pending', 'scheduled', 'ready', 'running', 'backing-off', 'suspended', 'paused', 'completed'];

    /** How Restate in a container reaches the test host; the harness adds the name (`--add-host`). */
    private const string CONTAINER_HOST = 'host.docker.internal';

    /** Where the endpoint listens by default: everywhere for a Restate container to reach it, else loopback only. */
    private const string DEFAULT_BIND_ADDRESS = '0.0.0.0';

    private const string PROCESS_BIND_ADDRESS = '127.0.0.1';

    /** The node name of a Restate process: fixed, since Restate refuses data written under another name. */
    private const string PROCESS_NODE_NAME = 'restate-php-test';

    private const string BIND_ADDRESS_VARIABLE = 'RESTATE_PHP_TEST_BIND';

    private const int RESTATE_LOG_TAIL_LINES = 100;

    private const int ENDPOINT_LOG_TAIL_LINES = 200;

    /**
     * How often a port is chosen again when something else took it between choosing and binding it (the harness
     * picks free ports itself, so that they survive restarts).
     */
    private const int PORT_ATTEMPTS = 5;

    /** What `docker`, `podman` and `php -S` report when the port they should listen on is taken. */
    private const string PORT_TAKEN = '/address already in use|port is already allocated/i';

    /** @var array<string, self|TestEnvironmentException> */
    private static array $shared = [];

    private static bool $signalHandlersInstalled = false;

    private readonly string $workDir;

    private readonly string $bindAddress;

    private ?Process $endpointProcess = null;

    private ?int $endpointPort = null;

    /** Where the endpoint log was when the endpoint was last started. */
    private int $endpointLaunchOffset = 0;

    private bool $preflighted = false;

    private ?string $restateContainer = null;

    /** The Restate server of a {@see RestateProcess}. */
    private ?Process $restateProcess = null;

    /** Where the Restate log was when the Restate process was last started. */
    private int $restateLaunchOffset = 0;

    private ?string $endpointContainer = null;

    private ?int $ingressPort = null;

    private ?int $adminPort = null;

    /** A Restate process's node port (node-to-node traffic, which a single node only serves itself). */
    private ?int $nodePort = null;

    private bool $restatePaused = false;

    private bool $stopped = false;

    /**
     * @param ?string $runtime the container CLI, when anything runs in a container
     * @param ?string $restateBinary the `restate-server` of a {@see RestateProcess}, resolved
     */
    private function __construct(
        private readonly PhpCliServer|EndpointContainer|ExternalEndpoint $endpoint,
        private readonly RestateContainer|RestateProcess|ExternalRestate $restate,
        private readonly ?string $runtime,
        private readonly ?string $restateBinary = null,
    ) {
        $bindAddress = ($endpoint instanceof ExternalEndpoint ? null : $endpoint->bindAddress) ?? getenv(self::BIND_ADDRESS_VARIABLE);
        $this->bindAddress = is_string($bindAddress) && $bindAddress !== ''
            ? $bindAddress
            : ($this->restateOnTestHost() ? self::PROCESS_BIND_ADDRESS : self::DEFAULT_BIND_ADDRESS);

        $this->workDir = sys_get_temp_dir() . '/restate-php-test-' . bin2hex(random_bytes(6));
        if (!@mkdir($this->workDir, 0o700, true)) {
            throw new TestEnvironmentException("Could not create the scratch directory {$this->workDir}.");
        }
    }

    /**
     * Start Restate and the endpoint, and register the endpoint (under its configured path). Returns once Restate
     * accepts invocations. An {@see ExternalRestate} or {@see ExternalEndpoint} is not started, only waited for.
     *
     * @param RestateContainer|RestateProcess|ExternalRestate|null $restate null is {@see RestateContainer::default()}
     *
     * @throws TestEnvironmentException when anything fails to start; nothing the harness started is left running
     */
    public static function start(PhpCliServer|EndpointContainer|ExternalEndpoint $endpoint, RestateContainer|RestateProcess|ExternalRestate|null $restate = null): self
    {
        $restate ??= RestateContainer::default();
        $environment = new self(
            $endpoint,
            $restate,
            match (true) {
                $restate instanceof RestateContainer => self::findRuntime($restate->containerRuntime),
                $endpoint instanceof EndpointContainer => self::findRuntime(null),
                default => null,
            },
            $restate instanceof RestateProcess ? self::findRestateServer($restate->binary) : null,
        );
        register_shutdown_function($environment->shutdown(...));
        self::installSignalHandlers();

        try {
            $environment->boot();
        } catch (\Throwable $exception) {
            $logs = $environment->logTails();
            $environment->stop();

            throw new TestEnvironmentException($exception->getMessage() . $logs, 0, $exception);
        }

        return $environment;
    }

    /**
     * One environment per configuration and PHP process, started on first use and stopped when the process exits: a
     * test suite pays for starting Restate once. A start that failed is not retried; every later call throws a
     * {@see TestEnvironmentException} whose previous exception is the original failure.
     */
    public static function shared(PhpCliServer|EndpointContainer|ExternalEndpoint $endpoint, RestateContainer|RestateProcess|ExternalRestate|null $restate = null): self
    {
        $restate ??= RestateContainer::default();
        $key = hash('sha256', serialize([$endpoint, $restate]));

        $entry = self::$shared[$key] ?? null;
        if ($entry instanceof TestEnvironmentException) {
            throw new TestEnvironmentException('The shared Restate test environment failed to start earlier: ' . $entry->getMessage(), 0, $entry);
        }
        if ($entry instanceof self && !$entry->stopped) {
            return $entry;
        }

        try {
            return self::$shared[$key] = self::start($endpoint, $restate);
        } catch (TestEnvironmentException $exception) {
            self::$shared[$key] = $exception;

            throw $exception;
        }
    }

    /** The Restate ingress as reached from the test process, for example `http://127.0.0.1:32768`. */
    public function ingressUrl(): string
    {
        if ($this->restate instanceof ExternalRestate) {
            return $this->restate->ingressUrl;
        }

        return $this->ingressPort !== null
            ? 'http://127.0.0.1:' . $this->ingressPort
            : throw new TestEnvironmentException('Restate is not running.');
    }

    /** The Restate admin API as reached from the test process. */
    public function adminUrl(): string
    {
        if ($this->restate instanceof ExternalRestate) {
            return $this->restate->adminUrl;
        }

        return $this->adminPort !== null
            ? 'http://127.0.0.1:' . $this->adminPort
            : throw new TestEnvironmentException('Restate is not running.');
    }

    /**
     * The test host as Restate reaches it, for {@see registerDeployment()} URIs of servers on the test host (a proxy
     * in front of the endpoint): `host.docker.internal` from a {@see RestateContainer}, `127.0.0.1` for a
     * {@see RestateProcess}, and {@see ExternalRestate::$testHost} for an external one. A server for a Restate
     * container must listen on an address the container reaches, such as `0.0.0.0`; for a Restate process, loopback
     * is enough.
     */
    public function testHostForRestate(): string
    {
        return match (true) {
            $this->restate instanceof RestateProcess => '127.0.0.1',
            $this->restate instanceof ExternalRestate => $this->restate->testHost,
            default => self::CONTAINER_HOST,
        };
    }

    /** The endpoint (including its configured path) as reached from the test process. */
    public function endpointUrl(): string
    {
        if ($this->endpoint instanceof ExternalEndpoint) {
            return $this->endpoint->url;
        }

        return 'http://' . Commands::reachableHost($this->bindAddress) . ':' . $this->endpointPort() . $this->endpoint->path;
    }

    /**
     * The host port the endpoint listens on (`php -S`) or is published on (an endpoint container), or the port of an
     * {@see ExternalEndpoint}'s URL. It stays the same when the endpoint is killed and started again, since Restate
     * has it registered.
     */
    public function endpointPort(): int
    {
        if ($this->endpoint instanceof ExternalEndpoint) {
            return $this->endpoint->port();
        }

        return $this->endpointPort ?? throw new TestEnvironmentException('The endpoint is not running.');
    }

    /**
     * Whether the endpoint is running, as far as the harness can tell: it was not killed, and did not exit. An
     * {@see ExternalEndpoint} runs while its `/health` answers.
     */
    public function isEndpointRunning(): bool
    {
        if ($this->endpoint instanceof ExternalEndpoint) {
            return Http::isOk('GET', $this->endpointUrl() . '/health');
        }

        return $this->endpointProcess?->isRunning() ?? false;
    }

    /**
     * The container CLI the harness runs (`docker`, `podman`, or the configured path), for commands of your own.
     *
     * @throws TestEnvironmentException when the harness runs nothing in a container (a {@see RestateProcess} or an
     *                                   {@see ExternalRestate}, and a {@see PhpCliServer} or an {@see ExternalEndpoint})
     */
    public function containerRuntime(): string
    {
        return $this->runtime ?? throw new TestEnvironmentException(sprintf(
            'The harness runs nothing in a container: Restate is %s and the endpoint %s.',
            $this->restate instanceof ExternalRestate ? 'external (ExternalRestate)' : 'a process (RestateProcess)',
            $this->endpoint instanceof ExternalEndpoint ? 'external (ExternalEndpoint)' : 'a PhpCliServer',
        ));
    }

    /**
     * The name of the Restate container, for commands of your own (`docker exec`, `docker inspect`).
     *
     * @throws TestEnvironmentException when Restate is not running, runs as a process ({@see RestateProcess}), or
     *                                   outside the harness ({@see ExternalRestate})
     */
    public function restateContainerName(): string
    {
        return $this->restateContainer ?? throw new TestEnvironmentException(match (true) {
            $this->restate instanceof RestateProcess => 'Restate runs as a process (RestateProcess), not in a container.',
            $this->restate instanceof ExternalRestate => self::externalRestate('has no container the harness runs'),
            default => 'Restate is not running.',
        });
    }

    /**
     * The name of the endpoint container, or null for a {@see PhpCliServer}, an {@see ExternalEndpoint} or an endpoint
     * that is not running.
     */
    public function endpointContainerName(): ?string
    {
        return $this->endpointContainer;
    }

    /**
     * An ingress client connected to this Restate server. Without arguments it uses Guzzle (`guzzlehttp/guzzle`,
     * which must be installed); otherwise pass a PSR-18 client and, unless the client is one too, a PSR-17 request
     * factory, as for {@see IngressClient::connect()}.
     *
     * @param array<string, string> $headers
     */
    public function ingressClient(
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        array $headers = [],
        ?Serde $serde = null,
    ): IngressClient {
        $guzzle = class_exists(\GuzzleHttp\Client::class) && class_exists(\GuzzleHttp\Psr7\HttpFactory::class);

        if ($httpClient === null) {
            $httpClient = $guzzle ? new \GuzzleHttp\Client() : throw new TestEnvironmentException(
                'ingressClient() without arguments needs guzzlehttp/guzzle; install it, or pass a PSR-18 client and a PSR-17 request factory.',
            );
        }

        if ($requestFactory === null) {
            $requestFactory = match (true) {
                $httpClient instanceof RequestFactoryInterface => $httpClient,
                $guzzle => new \GuzzleHttp\Psr7\HttpFactory(),
                default => throw new TestEnvironmentException(sprintf('Pass a PSR-17 request factory to go with %s.', $httpClient::class)),
            };
        }

        return IngressClient::connect($this->ingressUrl(), $httpClient, $requestFactory, $streamFactory, $headers, $serde);
    }

    /**
     * Register the endpoint, or a part of it served under `$path` below the endpoint's own path, with Restate
     * (forced; over HTTP/2 when the endpoint is configured {@see EndpointContainer::withHttp2()} or
     * {@see ExternalEndpoint::withHttp2()}, else HTTP/1.1). {@see start()} has already registered the endpoint itself.
     *
     * @return string the deployment id
     *
     * @throws DeploymentRegistrationException when Restate refuses the deployment
     */
    public function registerEndpoint(string $path = ''): string
    {
        if ($this->endpoint instanceof ExternalEndpoint) {
            return $this->registerDeployment($this->endpoint->uriForRestate() . $path, http2: $this->endpoint->http2);
        }

        // A Restate process reaches the endpoint where it listens (loopback by default); a container, through the host.
        $host = match (true) {
            $this->restate instanceof RestateProcess => Commands::reachableHost($this->bindAddress),
            $this->restate instanceof ExternalRestate => $this->restate->testHost,
            default => self::CONTAINER_HOST,
        };

        return $this->registerDeployment(
            'http://' . $host . ':' . $this->endpointPort() . $this->endpoint->path . $path,
            http2: $this->endpoint instanceof EndpointContainer && $this->endpoint->http2,
        );
    }

    /**
     * Register any deployment URI with Restate (`POST /deployments`), for example a proxy in front of the endpoint,
     * or the endpoint under another name. The URI is as Restate reaches it: the test host is
     * {@see testHostForRestate()}.
     *
     * @param bool $http2 talk HTTP/2 to it (h2c with prior knowledge for `http://`) instead of HTTP/1.1
     * @param bool $force replace a deployment registered under the same URI; without it Restate refuses one whose
     *                    services changed incompatibly
     *
     * @return string the deployment id
     *
     * @throws DeploymentRegistrationException when Restate refuses the deployment
     */
    public function registerDeployment(string $uri, bool $http2 = false, bool $force = true): string
    {
        $response = Http::request(
            'POST',
            $this->adminUrl() . '/deployments',
            json_encode(['uri' => $uri, 'use_http_11' => !$http2, 'force' => $force], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ['Content-Type' => 'application/json'],
            timeout: 30.0,
        );

        if (is_string($response)) {
            throw new TestEnvironmentException("Registering {$uri} with Restate failed: {$response}");
        }
        if ($response[0] < 200 || $response[0] >= 300) {
            throw new DeploymentRegistrationException($uri, $response[0], $response[1]);
        }

        $decoded = json_decode($response[1], true);
        $id = is_array($decoded) ? ($decoded['id'] ?? null) : null;

        return is_string($id) ? $id : throw new TestEnvironmentException("Restate registered {$uri} but returned no deployment id: {$response[1]}");
    }

    /**
     * Run a query on Restate's admin SQL API (`POST /query`): the introspection tables such as `sys_invocation`,
     * `sys_journal` and `state`. Restate answers from storage, so a row can lag the invocation by a moment; poll for
     * a condition (as {@see waitForInvocationStatus()} does) rather than asserting on a single read.
     *
     * @return list<array<string, mixed>> the rows, column name to value
     *
     * @throws TestEnvironmentException when Restate rejects the query or does not answer
     */
    public function query(string $sql): array
    {
        $response = Http::request(
            'POST',
            $this->adminUrl() . '/query',
            json_encode(['query' => $sql], JSON_THROW_ON_ERROR),
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            timeout: 10.0,
        );
        if (is_string($response)) {
            throw new TestEnvironmentException("The query {$sql} failed: {$response}");
        }
        if ($response[0] < 200 || $response[0] >= 300) {
            throw new TestEnvironmentException("The query {$sql} failed: HTTP {$response[0]} {$response[1]}");
        }

        $decoded = json_decode($response[1], true);
        $rows = is_array($decoded) ? ($decoded['rows'] ?? null) : null;
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new TestEnvironmentException("The query {$sql} returned no rows array: {$response[1]}");
        }

        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new TestEnvironmentException("The query {$sql} returned a row that is not an object: {$response[1]}");
            }
            /** @var array<string, mixed> $row */
            $result[] = $row;
        }

        return $result;
    }

    /**
     * The invocation's `sys_invocation` row, with the given columns (all of them without any), or null while Restate
     * has none. Useful columns: `status` (see {@see INVOCATION_STATUSES}), `retry_count`, `last_failure`,
     * `last_failure_error_code`, `last_failure_related_command_name`, `journal_size`, `pinned_service_protocol_version`.
     *
     * @return ?array<string, mixed>
     */
    public function invocationRow(string $invocationId, string ...$columns): ?array
    {
        foreach ($columns as $column) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) !== 1) {
                throw new \InvalidArgumentException("Invalid sys_invocation column name \"{$column}\".");
            }
        }

        $rows = $this->query(sprintf(
            "SELECT %s FROM sys_invocation WHERE id = '%s'",
            $columns === [] ? '*' : implode(', ', $columns),
            str_replace("'", "''", $invocationId),
        ));

        return $rows[0] ?? null;
    }

    /**
     * Poll the invocation's `sys_invocation` row (every 100 ms) until its status is `$status`.
     *
     * @param string $status one of {@see INVOCATION_STATUSES}
     *
     * @return array<string, mixed> the whole row, as it was when the status matched
     *
     * @throws TestEnvironmentException on timeout, with the last row Restate returned
     */
    public function waitForInvocationStatus(string $invocationId, string $status, float $timeoutSeconds = 10.0): array
    {
        if (!in_array($status, self::INVOCATION_STATUSES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown invocation status "%s"; Restate reports one of %s.', $status, implode(', ', self::INVOCATION_STATUSES)));
        }

        $deadline = microtime(true) + $timeoutSeconds;
        do {
            $row = $this->invocationRow($invocationId);
            if ($row !== null && ($row['status'] ?? null) === $status) {
                return $row;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        throw new TestEnvironmentException(sprintf(
            'Invocation %s did not become %s within %ss; its sys_invocation row: %s',
            $invocationId,
            $status,
            $timeoutSeconds,
            $row === null ? 'none' : json_encode($row, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        ));
    }

    /**
     * Wait until Restate is ready (admin and ingress health, the partition processor answering a query) and, while
     * it runs, the endpoint is healthy. {@see start()} and the lifecycle methods wait for this themselves.
     *
     * @param ?float $timeoutSeconds null is the {@see RestateContainer::withStartupTimeout()} timeout
     */
    public function waitUntilReady(?float $timeoutSeconds = null): void
    {
        $deadline = $this->deadline($timeoutSeconds);
        $this->waitUntilRestateReady($deadline);
        if ($this->endpointProcess !== null || $this->endpoint instanceof ExternalEndpoint) {
            $this->waitUntilEndpointHealthy($deadline);
        }
    }

    /**
     * Stop and start Restate, and wait until it is ready again. Its storage (registered deployments, journals, state,
     * timers) survives, in the container or the process's data directory; its ports stay the same. Invocations in
     * flight see their attempt fail and are retried once Restate is back.
     *
     * @param bool $graceful SIGTERM (the container's `stop`), then SIGKILL after `$stopTimeoutSeconds`, instead of
     *                       SIGKILL
     */
    public function restartRestate(bool $graceful = true, int $stopTimeoutSeconds = 10, ?float $timeoutSeconds = null): void
    {
        $this->requireOwnRestate('cannot be restarted by it');
        if ($this->restate instanceof RestateProcess) {
            $process = $this->restateProcess ?? throw new TestEnvironmentException('Restate is not running.');
            if ($this->restatePaused) {
                $this->unpauseRestate();
            }
            $graceful ? $process->stop((float) max(0, $stopTimeoutSeconds)) : $process->kill();
            $this->restateProcess = null;

            $this->launchRestateProcess();
            $this->waitUntilRestateReady($this->deadline($timeoutSeconds));

            return;
        }

        $name = $this->restateContainerName();
        if ($this->restatePaused) {
            $this->unpauseRestate();
        }

        if ($graceful) {
            $this->containerCommand(Commands::stopContainer($this->containerRuntime(), $name, $stopTimeoutSeconds), 'stop Restate');
        } else {
            $this->containerCommand(Commands::signalContainer($this->containerRuntime(), $name, 9), 'kill Restate');
            $this->containerCommand(Commands::container($this->containerRuntime(), 'wait', $name), 'wait for Restate to exit');
        }
        $this->containerCommand(Commands::container($this->containerRuntime(), 'start', $name), 'start Restate again');
        $this->waitUntilRestateReady($this->deadline($timeoutSeconds));
    }

    /**
     * Freeze Restate: the container's `pause` (the cgroup freezer; rootless podman needs cgroup v2 with the freezer
     * delegated), or SIGSTOP to the process. Its connections stay open but nothing in it runs, so the endpoint's
     * responses wait in the kernel.
     */
    public function pauseRestate(): void
    {
        $this->requireOwnRestate('cannot be paused by it');
        if ($this->restate instanceof RestateProcess) {
            ($this->restateProcess ?? throw new TestEnvironmentException('Restate is not running.'))->signal(Process::signalNumber('STOP'));
        } else {
            $this->containerCommand(Commands::container($this->containerRuntime(), 'pause', $this->restateContainerName()), 'pause Restate');
        }
        $this->restatePaused = true;
    }

    /**
     * Thaw Restate frozen by {@see pauseRestate()} (`unpause`, or SIGCONT to the process), and wait until it is
     * ready again.
     */
    public function unpauseRestate(?float $timeoutSeconds = null): void
    {
        $this->requireOwnRestate('cannot be unpaused by it');
        if ($this->restate instanceof RestateProcess) {
            ($this->restateProcess ?? throw new TestEnvironmentException('Restate is not running.'))->signal(Process::signalNumber('CONT'));
        } else {
            $this->containerCommand(Commands::container($this->containerRuntime(), 'unpause', $this->restateContainerName()), 'unpause Restate');
        }
        $this->restatePaused = false;
        $this->waitUntilRestateReady($this->deadline($timeoutSeconds));
    }

    /**
     * SIGKILL the endpoint: the `php -S` server and its workers, or the endpoint container. Requests in flight end
     * without a response. Nothing restarts it until {@see startEndpoint()}.
     */
    public function killEndpoint(): void
    {
        $this->requireOwnEndpoint('cannot be killed');
        if ($this->endpointContainer !== null) {
            Process::run(Commands::signalContainer($this->containerRuntime(), $this->endpointContainer, 9));
        }
        $this->discardEndpoint();
    }

    /**
     * Start the endpoint again on the same port, after {@see killEndpoint()} or after it exited, and wait until it is
     * healthy. Restate still has it registered; nothing is registered again.
     */
    public function startEndpoint(?float $timeoutSeconds = null): void
    {
        $this->requireOwnEndpoint('cannot be started');
        if ($this->endpointPort === null) {
            throw new TestEnvironmentException('The environment is not running.');
        }
        if ($this->isEndpointRunning()) {
            throw new TestEnvironmentException('The endpoint is already running.');
        }
        $this->discardEndpoint();

        $deadline = $this->deadline($timeoutSeconds);
        $this->launchEndpoint();
        $this->waitUntilEndpointHealthy($deadline, movePortIfTaken: false);
    }

    /**
     * Kill the endpoint, or with `$graceful` stop it (SIGTERM, or the container's stop signal, then SIGKILL after
     * `$stopTimeoutSeconds`), and start it again on the same port.
     */
    public function restartEndpoint(bool $graceful = false, int $stopTimeoutSeconds = 10, ?float $timeoutSeconds = null): void
    {
        $this->requireOwnEndpoint('cannot be restarted');
        if ($graceful) {
            if ($this->endpointContainer !== null) {
                Process::run(Commands::stopContainer($this->containerRuntime(), $this->endpointContainer, $stopTimeoutSeconds));
            }
            $this->endpointProcess?->stop($stopTimeoutSeconds);
            $this->discardEndpoint();
        } else {
            $this->killEndpoint();
        }
        $this->startEndpoint($timeoutSeconds);
    }

    /**
     * Send a signal to the endpoint: to the `php -S` server process (its workers die with it), or to the main process
     * of the endpoint container (`kill --signal`), for example SIGQUIT or SIGUSR2 to a php-fpm master. The harness
     * notices an endpoint that exited because of it: {@see isEndpointRunning()}, {@see startEndpoint()}.
     */
    public function signalEndpoint(int $signal): void
    {
        $this->requireOwnEndpoint('cannot be signalled');
        if ($this->endpointContainer !== null) {
            $this->containerCommand(Commands::signalContainer($this->containerRuntime(), $this->endpointContainer, $signal), "send signal {$signal} to the endpoint container");

            return;
        }

        ($this->endpointProcess ?? throw new TestEnvironmentException('The endpoint is not running.'))->signal($signal);
    }

    /** The Restate server version as its admin API reports it, for example `1.8.1`. */
    public function serverVersion(): string
    {
        $response = Http::request('GET', $this->adminUrl() . '/version');
        if (!is_array($response) || $response[0] !== 200) {
            throw new TestEnvironmentException('Reading the Restate version failed: ' . (is_array($response) ? "HTTP {$response[0]} {$response[1]}" : $response));
        }

        $decoded = json_decode($response[1], true);
        $version = is_array($decoded) ? ($decoded['version'] ?? null) : null;

        return is_string($version) ? $version : throw new TestEnvironmentException("Restate reported no version: {$response[1]}");
    }

    /** The size of the endpoint log so far: pass it to {@see endpointLog()} to read only what follows. */
    public function endpointLogSize(): int
    {
        clearstatcache(true, $this->endpointLogPath());

        return is_file($this->endpointLogPath()) ? (int) filesize($this->endpointLogPath()) : 0;
    }

    /**
     * What the endpoint wrote to stdout and stderr (the PHP server log, or the endpoint container's output), across
     * restarts of the endpoint; for an {@see ExternalEndpoint}, its log file (empty without one).
     */
    public function endpointLog(int $offset = 0): string
    {
        return is_file($this->endpointLogPath()) ? (string) @file_get_contents($this->endpointLogPath(), offset: $offset) : '';
    }

    /**
     * The last lines of the Restate server log: the container's, the process's output across its restarts, or an
     * {@see ExternalRestate}'s log file (empty without one).
     */
    public function restateLog(int $lines = self::RESTATE_LOG_TAIL_LINES): string
    {
        if ($this->restate instanceof RestateProcess || $this->restate instanceof ExternalRestate) {
            $path = $this->restateLogPath();
            $log = $path !== null && is_file($path) ? (string) @file_get_contents($path) : '';

            return $log === '' ? '' : implode("\n", array_slice(explode("\n", rtrim($log, "\n")), -max(1, $lines))) . "\n";
        }
        if ($this->restateContainer === null) {
            return '';
        }

        [, $stdout, $stderr] = Process::run([$this->containerRuntime(), 'logs', '--tail', (string) max(1, $lines), $this->restateContainer]);

        return $stdout . $stderr;
    }

    /** Write the tail of the Restate log and the endpoint log (from the given offset) to stderr, for a failed test. */
    public function dumpLogs(int $endpointLogOffset = 0): void
    {
        fwrite(STDERR, $this->logTails($endpointLogOffset));
    }

    /**
     * Remove the containers, stop Restate and the endpoint, and delete the scratch directory. Idempotent. An
     * {@see ExternalRestate} and an {@see ExternalEndpoint} keep running.
     */
    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }
        $this->stopped = true;

        if ($this->restateProcess !== null) {
            // A stopped process only acts on SIGTERM once it runs again.
            if ($this->restatePaused) {
                $this->restateProcess->signal(Process::signalNumber('CONT'));
            }
            $this->restateProcess->stop();
            $this->restateProcess = null;
        }
        if ($this->restateContainer !== null && $this->restatePaused) {
            Process::run(Commands::container($this->containerRuntime(), 'unpause', $this->restateContainer));
        }
        foreach ([$this->restateContainer, $this->endpointContainer] as $container) {
            if ($container !== null) {
                Process::run(Commands::removeContainer($this->containerRuntime(), $container));
            }
        }

        $this->endpointProcess?->stop();
        $this->endpointProcess = null;

        self::removeDirectory($this->workDir);
    }

    private function boot(): void
    {
        $this->pullMissingImages();
        $deadline = $this->deadline(null);

        if (!$this->endpoint instanceof ExternalEndpoint) {
            $this->endpointPort = self::freePort($this->bindAddress);
            $this->launchEndpoint();
        }
        $this->startRestate();

        // The endpoint was started first: fail early when it exits meanwhile.
        $this->waitUntilRestateReady($deadline, watchEndpoint: true, movePortsIfTaken: true);
        $this->waitUntilEndpointHealthy($deadline, movePortIfTaken: true);

        $this->registerEndpoint();
    }

    /**
     * Pull the Restate image and the endpoint's when the runtime does not have them, before the startup timeout
     * starts: `run` would pull them itself, and a pull on a fresh machine can take longer than the timeout.
     */
    private function pullMissingImages(): void
    {
        $images = [];
        if ($this->restate instanceof RestateContainer) {
            $images[] = $this->restate->image;
        }
        if ($this->endpoint instanceof EndpointContainer) {
            $images[] = $this->endpoint->image;
        }
        foreach (array_unique($images) as $image) {
            if (Process::run(Commands::inspectImage($this->containerRuntime(), $image))[0] !== 0) {
                $this->containerCommand(Commands::pullImage($this->containerRuntime(), $image), "pull {$image}");
            }
        }
    }

    private function launchEndpoint(): void
    {
        $this->endpointLaunchOffset = $this->endpointLogSize();
        match (true) {
            $this->endpoint instanceof PhpCliServer => $this->startPhpCliServer($this->endpoint),
            $this->endpoint instanceof EndpointContainer => $this->startEndpointContainer($this->endpoint),
            default => throw new \LogicException('The harness does not start an external endpoint.'),
        };
    }

    /** Forget the endpoint process after killing whatever is left of it, and remove its container. */
    private function discardEndpoint(): void
    {
        $this->endpointProcess?->kill();
        $this->endpointProcess = null;

        if ($this->endpointContainer !== null) {
            Process::run(Commands::removeContainer($this->containerRuntime(), $this->endpointContainer));
            $this->endpointContainer = null;
        }
    }

    private function startPhpCliServer(PhpCliServer $server): void
    {
        $php = $server->phpBinary ?? PHP_BINARY;
        $extension = ExtensionLocator::locate($server->extensionPath, ExtensionLocator::fromEnvironment(), self::projectRoot());
        $environment = [...$server->environment, 'PHP_CLI_SERVER_WORKERS' => (string) $server->workers];
        if (!$this->preflighted) {
            $this->preflight($php, $extension, $server, $environment);
            $this->preflighted = true;
        }

        $this->endpointProcess = Process::start(
            Commands::phpCliServer($server, $php, $extension, $this->bindAddress, $this->endpointPort()),
            $this->endpointLogPath(),
            $environment,
            $server->workingDirectory,
        );
    }

    /**
     * Build an endpoint in a throwaway process with the server's PHP, extension and ini settings, so that an
     * extension that does not load, or does not match this package, fails here with its own message.
     *
     * @param array<string, string> $environment
     */
    private function preflight(string $php, ?string $extension, PhpCliServer $server, array $environment): void
    {
        $autoloader = self::composerAutoloader();
        $code = $autoloader !== null
            ? sprintf('require %s; \Restate\Endpoint::builder()->build();', var_export($autoloader, true))
            : sprintf('if (!extension_loaded(%s)) { fwrite(STDERR, "The Restate PHP extension is not loaded.\n"); exit(1); }', var_export(ExtensionLocator::MODULE_NAME, true));

        $command = Commands::php($server, $php, $extension);
        [$exitCode, $stdout, $stderr] = Process::run([...$command, '-r', $code], $environment, $server->workingDirectory);
        if ($exitCode !== 0) {
            throw new TestEnvironmentException(sprintf(
                "The Restate PHP extension does not load in `%s`:\n%s\nInstall it for %s with PIE (`pie install restate/ext-restate`), "
                . 'which also enables it in its php.ini, or point the harness at a module with PhpCliServer::withExtension() or %s.',
                implode(' ', $command),
                trim($stdout . $stderr),
                $php,
                ExtensionLocator::ENVIRONMENT_VARIABLE,
            ));
        }
    }

    private function startEndpointContainer(EndpointContainer $container): void
    {
        // A new name for every start: the runtime may still be removing the previous container (`--rm`).
        $this->endpointContainer = 'restate-php-test-endpoint-' . bin2hex(random_bytes(4));
        $this->endpointProcess = Process::start(
            Commands::endpointContainer($this->containerRuntime(), $container, $this->endpointContainer, $this->bindAddress, $this->endpointPort()),
            $this->endpointLogPath(),
        );
    }

    /**
     * Run the Restate container on two free loopback ports. Another process can take a port between the harness
     * choosing it and the runtime binding it; then the run fails, and it is retried with new ports. A Restate process
     * is started on three, and retried the same way once it exits ({@see waitUntilRestateReady()}).
     */
    private function startRestate(): void
    {
        if ($this->restate instanceof ExternalRestate) {
            return;
        }
        if ($this->restate instanceof RestateProcess) {
            [$this->ingressPort, $this->adminPort, $this->nodePort] = self::freePorts('127.0.0.1', 3);
            $this->launchRestateProcess();

            return;
        }

        for ($attempt = 1; ; $attempt++) {
            [$ingressPort, $adminPort] = self::freePorts('127.0.0.1', 2);
            $name = 'restate-php-test-' . bin2hex(random_bytes(4));
            [$exitCode, $stdout, $stderr] = Process::run(Commands::restate($this->containerRuntime(), $this->restate, $name, $ingressPort, $adminPort));
            if ($exitCode === 0) {
                $this->restateContainer = $name;
                $this->ingressPort = $ingressPort;
                $this->adminPort = $adminPort;

                return;
            }

            // The runtime may have created the container before it failed to start it.
            Process::run(Commands::removeContainer($this->containerRuntime(), $name));
            $output = trim($stdout . $stderr);
            if ($attempt >= self::PORT_ATTEMPTS || preg_match(self::PORT_TAKEN, $output) !== 1) {
                throw new TestEnvironmentException("`{$this->containerRuntime()} run {$this->restate->image}` failed (exit {$exitCode}):\n{$output}");
            }
        }
    }

    /**
     * A Restate process that exits because a port is taken is started again: on new ports before anything is
     * registered (`$movePortsIfTaken`), else on the same ones once they are free.
     */
    private function waitUntilRestateReady(float $deadline, bool $watchEndpoint = false, bool $movePortsIfTaken = false): void
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $this->waitUntilRestateAnswers($deadline, $watchEndpoint);

                return;
            } catch (TestEnvironmentException $exception) {
                if ($this->restate instanceof ExternalRestate) {
                    throw new TestEnvironmentException(sprintf('%s (the external Restate at %s)', $exception->getMessage(), $this->restate->adminUrl), 0, $exception);
                }
                $portTaken = $this->restateProcess !== null && !$this->restateProcess->isRunning()
                    && preg_match(self::PORT_TAKEN, $this->restateLogSince($this->restateLaunchOffset)) === 1;
                if (!$portTaken || $attempt >= self::PORT_ATTEMPTS || microtime(true) >= $deadline) {
                    throw $exception;
                }
            }

            $this->restateProcess = null;
            if ($movePortsIfTaken) {
                [$this->ingressPort, $this->adminPort, $this->nodePort] = self::freePorts('127.0.0.1', 3);
            } else {
                usleep(500_000);
            }
            $this->launchRestateProcess();
        }
    }

    private function waitUntilRestateAnswers(float $deadline, bool $watchEndpoint): void
    {
        $watch = array_filter(['Restate' => $this->restateProcess, 'endpoint' => $watchEndpoint ? $this->endpointProcess : null]);
        $this->waitUntil('the Restate admin API is healthy', $deadline, fn (): bool => Http::isOk('GET', $this->adminUrl() . '/health'), $watch);
        $this->waitUntil('the Restate ingress is healthy', $deadline, fn (): bool => Http::isOk('GET', $this->ingressUrl() . '/restate/health'), $watch);
        // Health turns green before the partition processors run; until then the ingress refuses invocations.
        $this->waitUntil('Restate partitions are ready', $deadline, fn (): bool => Http::isOk(
            'POST',
            $this->adminUrl() . '/query',
            '{"query":"SELECT count(1) FROM sys_invocation"}',
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
        ), $watch);
    }

    /**
     * Start `restate-server` on the ports chosen, with its data directory in the scratch directory, and only the
     * configuration the harness gives it: like a container, it sees none of the test process's `RESTATE_…`
     * variables, which it would read as configuration.
     */
    private function launchRestateProcess(): void
    {
        $restate = $this->restate instanceof RestateProcess ? $this->restate : throw new \LogicException('Restate runs in a container.');
        if ($this->restateBinary === null || $this->ingressPort === null || $this->adminPort === null || $this->nodePort === null) {
            throw new \LogicException('The Restate process has no binary or ports.');
        }

        $inherited = array_filter(getenv(), static fn (string $name): bool => !str_starts_with($name, 'RESTATE_'), ARRAY_FILTER_USE_KEY);
        $logPath = $this->workDir . '/restate.log';
        $this->restateLaunchOffset = is_file($logPath) ? (int) filesize($logPath) : 0;
        $this->restateProcess = Process::start(
            Commands::restateProcess($this->restateBinary),
            $logPath,
            [...$inherited, ...Commands::restateProcessEnvironment($restate, $this->workDir . '/restate', self::PROCESS_NODE_NAME, $this->ingressPort, $this->adminPort, $this->nodePort)],
            $this->workDir,
            inheritEnvironment: false,
        );
    }

    /** The Restate log of a {@see RestateProcess}, or the log file of an {@see ExternalRestate} (null without one). */
    private function restateLogPath(): ?string
    {
        return $this->restate instanceof ExternalRestate ? $this->restate->logFile : $this->workDir . '/restate.log';
    }

    private function restateLogSince(int $offset): string
    {
        $path = $this->restateLogPath();
        if ($path === null) {
            return '';
        }
        clearstatcache(true, $path);

        return is_file($path) ? (string) @file_get_contents($path, offset: $offset) : '';
    }

    /**
     * Wait for the endpoint's `/health`. An endpoint that exits because its port is taken is started again: on a new
     * port before it is registered (`$movePortIfTaken`), else on the same one once it is free.
     */
    private function waitUntilEndpointHealthy(float $deadline, bool $movePortIfTaken = false): void
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $this->waitUntil('the endpoint is healthy at ' . $this->endpointUrl() . '/health', $deadline, fn (): bool => Http::isOk('GET', $this->endpointUrl() . '/health'), $this->endpointProcess);

                return;
            } catch (TestEnvironmentException $exception) {
                if ($this->endpoint instanceof ExternalEndpoint) {
                    throw $exception;
                }
                $portTaken = !$this->isEndpointRunning() && preg_match(self::PORT_TAKEN, $this->endpointLog($this->endpointLaunchOffset)) === 1;
                if (!$portTaken || $attempt >= self::PORT_ATTEMPTS || microtime(true) >= $deadline) {
                    throw $exception;
                }
            }

            $this->discardEndpoint();
            if ($movePortIfTaken) {
                $this->endpointPort = self::freePort($this->bindAddress);
            } else {
                usleep(500_000);
            }
            $this->launchEndpoint();
        }
    }

    /**
     * @param \Closure(): bool $ready
     * @param Process|array<string, Process>|null $watch fail as soon as this process exits (the endpoint), or one of
     *                                                  these (by what they are)
     */
    private function waitUntil(string $condition, float $deadline, \Closure $ready, Process|array|null $watch = null): void
    {
        $watch = $watch instanceof Process ? ['endpoint' => $watch] : ($watch ?? []);
        while (true) {
            foreach ($watch as $what => $process) {
                if (!$process->isRunning()) {
                    throw new TestEnvironmentException("The {$what} exited while waiting until {$condition}.");
                }
            }
            if ($ready()) {
                return;
            }
            if (microtime(true) >= $deadline) {
                throw new TestEnvironmentException(sprintf('Timed out waiting until %s.', $condition));
            }
            usleep(100_000);
        }
    }

    private function deadline(?float $timeoutSeconds): float
    {
        return microtime(true) + ($timeoutSeconds ?? (float) $this->restate->startupTimeoutSeconds);
    }

    /** @param list<string> $command */
    private function containerCommand(array $command, string $what): void
    {
        [$exitCode, $stdout, $stderr] = Process::run($command);
        if ($exitCode !== 0) {
            throw new TestEnvironmentException(sprintf("Could not %s: `%s` failed (exit %d):\n%s", $what, implode(' ', $command), $exitCode, trim($stdout . $stderr)));
        }
    }

    private function logTails(int $endpointLogOffset = 0): string
    {
        $output = '';
        $restateLog = $this->restateContainer ?? $this->restateBinary ?? ($this->restate instanceof ExternalRestate ? $this->restate->logFile : null);
        if ($restateLog !== null) {
            $output .= sprintf("\n--- Restate log (%s, last %d lines) ---\n%s", $restateLog, self::RESTATE_LOG_TAIL_LINES, $this->restateLog());
        }

        $lines = explode("\n", rtrim($this->endpointLog($endpointLogOffset), "\n"));

        return $output . sprintf(
            "\n--- Endpoint log (last %d lines) ---\n%s\n",
            self::ENDPOINT_LOG_TAIL_LINES,
            implode("\n", array_slice($lines, -self::ENDPOINT_LOG_TAIL_LINES)),
        );
    }

    private function shutdown(): void
    {
        $error = error_get_last();
        if (!$this->stopped && $error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            $this->dumpLogs();
        }

        $this->stop();
    }

    /** The log of the endpoint the harness runs, or the log file of an {@see ExternalEndpoint} (without one, a path that does not exist). */
    private function endpointLogPath(): string
    {
        if ($this->endpoint instanceof ExternalEndpoint) {
            return $this->endpoint->logFile ?? $this->workDir . '/no-endpoint-log';
        }

        return $this->workDir . '/endpoint.log';
    }

    /** Whether Restate reaches the test host on loopback, so an endpoint the harness serves need not listen on the network. */
    private function restateOnTestHost(): bool
    {
        return $this->restate instanceof RestateProcess
            || ($this->restate instanceof ExternalRestate && in_array($this->restate->testHost, ['127.0.0.1', 'localhost', '[::1]'], true));
    }

    private function requireOwnRestate(string $what): void
    {
        if ($this->restate instanceof ExternalRestate) {
            throw new TestEnvironmentException(self::externalRestate($what));
        }
    }

    private function requireOwnEndpoint(string $what): void
    {
        if ($this->endpoint instanceof ExternalEndpoint) {
            throw new TestEnvironmentException(sprintf(
                'The endpoint at %s runs outside the harness (ExternalEndpoint) and %s by it: run the test with an endpoint the harness serves (PhpCliServer, EndpointContainer).',
                $this->endpoint->url,
                $what,
            ));
        }
    }

    private static function externalRestate(string $what): string
    {
        return "Restate runs outside the harness (ExternalRestate) and {$what}: run the test with a Restate the harness starts (RestateContainer, RestateProcess).";
    }

    private static function findRuntime(?string $configured): string
    {
        if ($configured !== null) {
            return Process::findExecutable($configured) ?? throw new TestEnvironmentException(
                "The container runtime {$configured} was not found or is not executable.",
            );
        }

        return Process::findExecutable('docker') ?? Process::findExecutable('podman') ?? throw new TestEnvironmentException(
            'No container runtime found: the Restate test environment needs `docker` or `podman` on PATH '
            . '(or RestateContainer::withContainerRuntime()).',
        );
    }

    private static function findRestateServer(string $binary): string
    {
        return Process::findExecutable($binary) ?? throw new TestEnvironmentException(sprintf(
            '%s was not found or is not executable: a RestateProcess runs `restate-server` from PATH (nix-restate\'s '
            . 'restate-bin in a Nix or devenv shell, or a release binary from https://github.com/restatedev/restate/releases), '
            . 'or the binary given to RestateProcess::withBinary().',
            str_contains($binary, DIRECTORY_SEPARATOR) ? $binary : "`{$binary}` on PATH",
        ));
    }

    /** vendor/autoload.php of the project the SDK is installed in. */
    private static function composerAutoloader(): ?string
    {
        if (!class_exists(\Composer\Autoload\ClassLoader::class, false)) {
            return null;
        }

        $file = new \ReflectionClass(\Composer\Autoload\ClassLoader::class)->getFileName();
        $autoloader = is_string($file) ? dirname($file, 2) . '/autoload.php' : null;

        return $autoloader !== null && is_file($autoloader) ? $autoloader : null;
    }

    /** The root of the project the SDK is installed in: the directory holding vendor/. */
    private static function projectRoot(): ?string
    {
        $autoloader = self::composerAutoloader();

        return $autoloader === null ? null : dirname($autoloader, 2);
    }

    private static function freePort(string $bindAddress): int
    {
        [$port] = self::freePorts($bindAddress, 1) + [0];

        return $port;
    }

    /**
     * Distinct ports that are free on the address right now (all held open together while choosing, so they differ).
     * The caller must cope with another process taking one before it binds it.
     *
     * @return list<int>
     */
    private static function freePorts(string $bindAddress, int $count): array
    {
        $sockets = [];
        $ports = [];
        try {
            for ($i = 0; $i < max(1, $count); $i++) {
                $socket = @stream_socket_server('tcp://' . Commands::host($bindAddress) . ':0', $errorCode, $errorMessage);
                if ($socket === false) {
                    throw new TestEnvironmentException("Cannot listen on {$bindAddress}: {$errorMessage}");
                }
                $sockets[] = $socket;
                $name = (string) stream_socket_get_name($socket, false);
                $ports[] = (int) substr($name, (int) strrpos($name, ':') + 1);
            }
        } finally {
            foreach ($sockets as $socket) {
                fclose($socket);
            }
        }

        return $ports;
    }

    /**
     * Turn Ctrl-C and SIGTERM into a normal exit, so the shutdown functions remove the containers, unless the
     * process already handles those signals itself.
     */
    private static function installSignalHandlers(): void
    {
        if (self::$signalHandlersInstalled || !function_exists('pcntl_async_signals')) {
            return;
        }
        self::$signalHandlersInstalled = true;

        foreach ([2, 15] as $signal) {
            if (pcntl_signal_get_handler($signal) !== 0) {
                continue;
            }
            pcntl_async_signals(true);
            pcntl_signal($signal, static function (int $signal): never {
                exit(128 + $signal);
            });
        }
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            /** @var \SplFileInfo $entry */
            $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($directory);
    }
}
