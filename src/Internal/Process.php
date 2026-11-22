<?php

declare(strict_types=1);

namespace Restate\Testing\Internal;

use Restate\Testing\TestEnvironmentException;

/**
 * Child processes, always started from an argument list (no shell).
 *
 * @internal
 */
final class Process
{
    /** @var resource */
    private $process;

    /**
     * The children seen while it ran (the workers of `php -S`), which can still be ended once their parent is gone and
     * they are no longer its children.
     *
     * @var list<int>
     */
    private array $children = [];

    /** @param resource $process */
    private function __construct($process)
    {
        $this->process = $process;
    }

    /**
     * Run a command to completion.
     *
     * @param list<string> $command
     * @param array<string, string> $environment added to the current environment
     *
     * @return array{int, string, string} exit code, stdout, stderr
     */
    public static function run(array $command, array $environment = [], ?string $workingDirectory = null): array
    {
        $stdout = tmpfile();
        $stderr = tmpfile();
        if ($stdout === false || $stderr === false) {
            throw new TestEnvironmentException('Could not create temporary files for command output.');
        }

        $process = @proc_open(
            $command,
            [0 => ['file', '/dev/null', 'r'], 1 => $stdout, 2 => $stderr],
            $pipes,
            $workingDirectory,
            $environment === [] ? null : [...getenv(), ...$environment],
        );
        if ($process === false) {
            throw new TestEnvironmentException('Could not run ' . implode(' ', $command) . '.');
        }

        $exitCode = proc_close($process);
        rewind($stdout);
        rewind($stderr);

        return [$exitCode, (string) stream_get_contents($stdout), (string) stream_get_contents($stderr)];
    }

    /**
     * Start a command in the background with stdout and stderr appended to a log file.
     *
     * @param list<string> $command
     * @param array<string, string> $environment added to the current environment, or with `$inheritEnvironment`
     *                                           false the whole environment
     */
    public static function start(array $command, string $logFile, array $environment = [], ?string $workingDirectory = null, bool $inheritEnvironment = true): self
    {
        $process = @proc_open(
            $command,
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']],
            $pipes,
            $workingDirectory,
            !$inheritEnvironment ? $environment : ($environment === [] ? null : [...getenv(), ...$environment]),
        );
        if ($process === false) {
            throw new TestEnvironmentException('Could not start ' . implode(' ', $command) . '.');
        }

        return new self($process);
    }

    public function isRunning(): bool
    {
        return is_resource($this->process) && proc_get_status($this->process)['running'];
    }

    /** The process id, or null once the process has been closed. */
    public function pid(): ?int
    {
        return is_resource($this->process) ? proc_get_status($this->process)['pid'] : null;
    }

    /**
     * Send a signal (`SIGTERM` is 15, `SIGKILL` 9) to the process; nothing once it has exited. When the signal ends
     * a process that has children, they get SIGTERM: what Linux does for the workers of `php -S` (which sets
     * PR_SET_PDEATHSIG on them), done here for every OS, since elsewhere they would outlive their server and keep its
     * port.
     */
    public function signal(int $signal): void
    {
        if (!$this->isRunning()) {
            return;
        }

        $children = $this->rememberChildren();
        proc_terminate($this->process, $signal);
        if ($children !== [] && $this->wait(0.5)) {
            self::signalAll(self::alive($children), 15);
        }
    }

    /** Wait until the process has exited; false when it still runs after the timeout. */
    public function wait(float $timeoutSeconds): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while ($this->isRunning()) {
            if (microtime(true) >= $deadline) {
                return false;
            }
            usleep(20_000);
        }

        return true;
    }

    /**
     * SIGKILL the process and its direct children (the workers of `php -S`, also those seen before it exited), and
     * wait until it has exited. The children are signalled too because once their parent is gone they could no longer
     * be found, and a worker that outlived its server would keep the port.
     */
    public function kill(): void
    {
        if (!is_resource($this->process)) {
            return;
        }

        $children = $this->rememberChildren();
        if ($this->isRunning()) {
            proc_terminate($this->process, 9);
        }
        self::signalAll(self::alive($children), 9);
        $this->wait(10.0);
        proc_close($this->process);
        self::waitUntilGone($children, 5.0);
    }

    /** SIGTERM, then SIGKILL after the grace period. */
    public function stop(float $graceSeconds = 5.0): void
    {
        if (!is_resource($this->process)) {
            return;
        }

        $children = $this->rememberChildren();
        proc_terminate($this->process, 15);
        if (!$this->wait($graceSeconds)) {
            $this->kill();

            return;
        }
        proc_close($this->process);

        // On Linux the workers of `php -S` die with their server (it sets PR_SET_PDEATHSIG on them); elsewhere they
        // outlive it and keep its port, so they are stopped the same way: SIGTERM, then SIGKILL after the grace period.
        self::signalAll(self::alive($children), 15);
        self::waitUntilGone($children, $graceSeconds);
        self::signalAll(self::alive($children), 9);
        self::waitUntilGone($children, 5.0);
    }

    /**
     * The children seen so far, with those it has now.
     *
     * @return list<int>
     */
    private function rememberChildren(): array
    {
        if ($this->isRunning()) {
            $this->children = array_values(array_unique([...$this->children, ...self::childrenOf(proc_get_status($this->process)['pid'])]));
        }

        return $this->children;
    }

    /**
     * @param list<int> $pids
     *
     * @return list<int>
     */
    private static function alive(array $pids): array
    {
        return array_values(array_filter($pids, self::isAlive(...)));
    }

    /** @param list<int> $pids */
    private static function signalAll(array $pids, int $signal): void
    {
        foreach ($pids as $pid) {
            if (function_exists('posix_kill')) {
                @posix_kill($pid, $signal);
            } else {
                self::run(['kill', '-' . $signal, (string) $pid]);
            }
        }
    }

    /**
     * The direct children of a process: from Linux's `/proc`, elsewhere (macOS, the BSDs) from `pgrep -P`.
     *
     * @return list<int>
     */
    public static function childrenOf(int $pid): array
    {
        if (!is_dir('/proc/self')) {
            [$exitCode, $stdout] = self::run(['pgrep', '-P', (string) $pid]);
            $children = preg_split('/\s+/', trim($stdout), flags: PREG_SPLIT_NO_EMPTY);

            // pgrep exits with 1 when nothing matched.
            return $exitCode === 0 && $children !== false ? array_map(intval(...), $children) : [];
        }

        $children = [];
        $files = glob("/proc/{$pid}/task/*/children");
        foreach ($files === false ? [] : $files as $file) {
            preg_match_all('/\d+/', (string) @file_get_contents($file), $matches);
            foreach ($matches[0] as $child) {
                $children[] = (int) $child;
            }
        }

        return $children;
    }

    /**
     * Whether a process exists and is not a zombie, which is gone for every purpose that matters here: it no longer
     * holds a port.
     */
    public static function isAlive(int $pid): bool
    {
        if (is_dir('/proc/self')) {
            $stat = @file_get_contents("/proc/{$pid}/stat");

            return is_string($stat) && preg_match('/\) Z /', $stat) !== 1;
        }

        [$exitCode, $stdout] = self::run(['ps', '-o', 'stat=', '-p', (string) $pid]);

        return $exitCode === 0 && trim($stdout) !== '' && !str_starts_with(trim($stdout), 'Z');
    }

    /**
     * The number of SIGSTOP or SIGCONT, which differ between Linux (19 and 18) and macOS and the BSDs (17 and 19).
     *
     * @param 'STOP'|'CONT' $name
     */
    public static function signalNumber(string $name): int
    {
        // pcntl's constants, where it is loaded.
        if (defined("SIG{$name}")) {
            return constant("SIG{$name}");
        }

        $bsd = in_array(PHP_OS_FAMILY, ['Darwin', 'BSD'], true);

        return match ($name) {
            'STOP' => $bsd ? 17 : 19,
            'CONT' => $bsd ? 19 : 18,
        };
    }

    /** @param list<int> $pids */
    private static function waitUntilGone(array $pids, float $timeoutSeconds): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        foreach ($pids as $pid) {
            while (self::isAlive($pid) && microtime(true) < $deadline) {
                usleep(20_000);
            }
        }
    }

    /** The first executable with this name on PATH, or the path itself when it contains a slash. */
    public static function findExecutable(string $name): ?string
    {
        if (str_contains($name, DIRECTORY_SEPARATOR)) {
            return is_file($name) && is_executable($name) ? $name : null;
        }

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            $candidate = $directory . DIRECTORY_SEPARATOR . $name;
            if ($directory !== '' && is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
