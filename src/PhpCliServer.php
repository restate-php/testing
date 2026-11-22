<?php

declare(strict_types=1);

namespace Restate\Testing;

use Restate\Testing\Internal\Validate;

/**
 * The endpoint under test, served by PHP's built-in web server (`php -S`) on the test host with the Restate PHP
 * extension loaded. The router script is your endpoint entrypoint, the script that calls `Endpoint::serve()` or
 * `Endpoint::builder()->…->build()->handle(…)` (see examples/testing/).
 *
 * Restate in a container ({@see RestateContainer}) reaches the server through `host.docker.internal`, so the server
 * must listen on an address the container can reach: by default `0.0.0.0`, which **exposes the endpoint on your
 * network** for as long as the environment runs. Narrow it with {@see withBindAddress()} or `RESTATE_PHP_TEST_BIND`
 * where that matters. Restate as a process ({@see RestateProcess}) reaches it on loopback, the default then.
 *
 * Immutable: every `with…()` returns a new configuration.
 */
final readonly class PhpCliServer
{
    /**
     * Workers of the built-in server (`PHP_CLI_SERVER_WORKERS`). A handler that calls another handler of the same
     * endpoint waits for it, so a single worker would deadlock.
     */
    public const int DEFAULT_WORKERS = 8;

    /**
     * The ini settings every server starts with; {@see withIniSetting()} replaces them one by one. They make the
     * built-in server behave like a production server towards Restate:
     * - `display_errors=0`, `log_errors=1`: PHP's warnings, notices and deprecations go to the server log
     *   ({@see RestateTestEnvironment::endpointLog()}) instead of in front of the protocol frames, which would corrupt
     *   the response and make Restate retry it forever. (`display_errors=stderr` does not help here: the built-in
     *   server, SAPI `cli-server`, still writes the message into the response.)
     * - `enable_post_data_reading=0`: PHP does not parse request bodies into `$_POST`, so a body over `post_max_size`
     *   (8M by default; Restate's request bodies are journals and grow with them) does not raise a startup warning.
     *   `php://input` still has the whole body, which is all the SDK reads.
     */
    public const array DEFAULT_INI_SETTINGS = [
        'display_errors' => '0',
        'log_errors' => '1',
        'enable_post_data_reading' => '0',
    ];

    /**
     * @param array<string, string> $iniSettings
     * @param array<string, string> $environment
     */
    private function __construct(
        /** The router script (real path). */
        public string $routerScript,
        /** Document root (`php -S -t`); null serves the router script's directory. */
        public ?string $documentRoot = null,
        /** PHP binary; null runs the PHP that runs the tests (`PHP_BINARY`). */
        public ?string $phpBinary = null,
        /** The Restate extension module to load with `-d extension=…`; null finds it (see {@see withExtension()}). */
        public ?string $extensionPath = null,
        /** `-d name=value` settings: {@see DEFAULT_INI_SETTINGS} and those set with {@see withIniSetting()}. */
        public array $iniSettings = self::DEFAULT_INI_SETTINGS,
        /** Variables added to the server's environment, which otherwise inherits the test process's. */
        public array $environment = [],
        public int $workers = self::DEFAULT_WORKERS,
        /** Address to listen on; null is `RESTATE_PHP_TEST_BIND`, else `0.0.0.0`. */
        public ?string $bindAddress = null,
        /** Working directory of the server; null is the test process's. */
        public ?string $workingDirectory = null,
        /** The path the endpoint is served under (`''` or `/restate`), which is what gets registered with Restate. */
        public string $path = '',
    ) {
    }

    public static function forScript(string $routerScript): self
    {
        return new self(Validate::file($routerScript, 'router script'));
    }

    public function withDocumentRoot(string $directory): self
    {
        return $this->with(documentRoot: Validate::directory($directory, 'document root'));
    }

    /** A PHP CLI binary of the same version and thread safety as the extension; by default the one running the tests. */
    public function withPhpBinary(string $binary): self
    {
        return $this->with(phpBinary: Validate::nonEmpty($binary, 'PHP binary'));
    }

    /**
     * The Restate extension module the server loads (`-d extension=…`).
     *
     * Without it the harness uses, in this order: `RESTATE_PHP_EXTENSION_PATH`; a module built from source in the
     * project (`extension/target/{debug,release}/restate.so`, as in the Restate PHP SDK's own repository); otherwise
     * nothing, so the PHP binary's own php.ini must load it, as installing the extension with PIE
     * (`pie install restate/ext-restate`) sets up. Before serving, the harness checks in a separate PHP process that the
     * extension loads and matches this package, and fails with PHP's own error if not.
     */
    public function withExtension(string $path): self
    {
        return $this->with(extensionPath: Validate::file($path, 'Restate PHP extension'));
    }

    /** Set an ini setting (`-d name=value`), replacing the default or an earlier value of the same name. */
    public function withIniSetting(string $name, string $value): self
    {
        return $this->with(iniSettings: [...$this->iniSettings, Validate::name($name, 'ini setting') => $value]);
    }

    /** @param array<string, string> $variables added to (and replacing) the variables set so far */
    public function withEnvironment(array $variables): self
    {
        return $this->with(environment: [...$this->environment, ...Validate::environment($variables)]);
    }

    public function withWorkers(int $workers): self
    {
        if ($workers < 1) {
            throw new \InvalidArgumentException("The built-in server needs at least 1 worker, got {$workers}.");
        }

        return $this->with(workers: $workers);
    }

    /** An IP address Restate reaches the server on: see the class description. */
    public function withBindAddress(string $address): self
    {
        return $this->with(bindAddress: Validate::bindAddress($address));
    }

    public function withWorkingDirectory(string $directory): self
    {
        return $this->with(workingDirectory: Validate::directory($directory, 'working directory'));
    }

    /** Serve (and register) the endpoint under a path, for an application that routes Restate requests below one. */
    public function withPath(string $path): self
    {
        return $this->with(path: Validate::endpointPath($path));
    }

    /**
     * @param ?array<string, string> $iniSettings
     * @param ?array<string, string> $environment
     */
    private function with(
        ?string $documentRoot = null,
        ?string $phpBinary = null,
        ?string $extensionPath = null,
        ?array $iniSettings = null,
        ?array $environment = null,
        ?int $workers = null,
        ?string $bindAddress = null,
        ?string $workingDirectory = null,
        ?string $path = null,
    ): self {
        return new self(
            $this->routerScript,
            $documentRoot ?? $this->documentRoot,
            $phpBinary ?? $this->phpBinary,
            $extensionPath ?? $this->extensionPath,
            $iniSettings ?? $this->iniSettings,
            $environment ?? $this->environment,
            $workers ?? $this->workers,
            $bindAddress ?? $this->bindAddress,
            $workingDirectory ?? $this->workingDirectory,
            $path ?? $this->path,
        );
    }
}
