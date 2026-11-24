<?php

declare(strict_types=1);

namespace Restate\Testing\Internal;

/**
 * Argument checks shared by the test harness configuration.
 *
 * @internal
 */
final class Validate
{
    private function __construct()
    {
    }

    /** @return non-empty-string the real path of an existing file */
    public static function file(string $path, string $what): string
    {
        $real = is_file($path) ? realpath($path) : false;
        if ($real === false) {
            throw new \InvalidArgumentException("The {$what} {$path} does not exist or is not a file.");
        }

        return $real;
    }

    /** @return non-empty-string the real path of an existing directory */
    public static function directory(string $path, string $what): string
    {
        $real = is_dir($path) ? realpath($path) : false;
        if ($real === false) {
            throw new \InvalidArgumentException("The {$what} {$path} does not exist or is not a directory.");
        }

        return $real;
    }

    /** @return non-empty-string */
    public static function nonEmpty(string $value, string $what): string
    {
        if ($value === '') {
            throw new \InvalidArgumentException("The {$what} must not be empty.");
        }

        return $value;
    }

    /**
     * A name for `-d name=value` or an environment variable.
     *
     * @return non-empty-string
     */
    public static function name(string $name, string $what): string
    {
        if ($name === '' || strpbrk($name, "=\0") !== false) {
            throw new \InvalidArgumentException(sprintf('Invalid %s name %s: it must be non-empty and contain no "=" or NUL.', $what, json_encode($name)));
        }

        return $name;
    }

    /**
     * @param array<array-key, mixed> $variables
     *
     * @return array<string, string>
     */
    public static function environment(array $variables): array
    {
        $checked = [];
        foreach ($variables as $name => $value) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException("The environment variable {$name} must be a string.");
            }
            $checked[self::name((string) $name, 'environment variable')] = $value;
        }

        return $checked;
    }

    /**
     * A bind mount of an existing host directory or file at an absolute container path.
     *
     * @return array{hostPath: string, containerPath: string, readOnly: bool}
     */
    public static function volume(string $hostPath, string $containerPath, bool $readOnly): array
    {
        $real = file_exists($hostPath) ? realpath($hostPath) : false;
        if ($real === false) {
            throw new \InvalidArgumentException("The volume source {$hostPath} does not exist.");
        }
        if (!str_starts_with($containerPath, '/') || str_contains($containerPath, ':')) {
            throw new \InvalidArgumentException("The volume target must be an absolute container path without \":\", got \"{$containerPath}\".");
        }

        return ['hostPath' => $real, 'containerPath' => $containerPath, 'readOnly' => $readOnly];
    }

    public static function bindAddress(string $address): string
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException("The bind address must be an IPv4 or IPv6 address (without brackets or port), got \"{$address}\".");
        }

        return $address;
    }

    /** A path the endpoint is served under: empty, or `/segment[/segment…]` without a trailing slash or query. */
    public static function endpointPath(string $path): string
    {
        if ($path !== '' && preg_match('#^(/[A-Za-z0-9._~!$&\'()*+,;=:@%-]+)+$#', $path) !== 1) {
            throw new \InvalidArgumentException("The endpoint path must be empty or start with \"/\" and have no trailing slash, query or fragment, got \"{$path}\".");
        }

        return $path;
    }

    /**
     * Extra `run` options for a container (`--cpus 1`, `--memory 256m`, …). Options the harness sets itself and
     * depends on (the name, detaching, removal, published ports) are refused.
     *
     * @param array<array-key, mixed> $arguments
     *
     * @return list<string>
     */
    public static function runArguments(array $arguments): array
    {
        $checked = [];
        foreach ($arguments as $argument) {
            if (!is_string($argument) || $argument === '') {
                throw new \InvalidArgumentException('Container run arguments must be non-empty strings.');
            }
            foreach (['--name', '--rm', '--detach', '--publish', '--publish-all', '-d', '-p', '-P'] as $reserved) {
                $short = !str_starts_with($reserved, '--');
                if ($argument === $reserved || str_starts_with($argument, $reserved . '=') || ($short && $reserved !== '-d' && str_starts_with($argument, $reserved))) {
                    throw new \InvalidArgumentException("The container run argument {$argument} is managed by the test harness.");
                }
            }
            $checked[] = $argument;
        }

        return $checked;
    }

    /**
     * An `http://` or `https://` URL with a host and no query or fragment, without a trailing slash.
     *
     * @return non-empty-string
     */
    public static function url(string $url, string $what): string
    {
        $parts = parse_url($url);
        if (
            !is_array($parts)
            || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || ($parts['host'] ?? '') === ''
            || isset($parts['query'])
            || isset($parts['fragment'])
            || isset($parts['user'])
        ) {
            throw new \InvalidArgumentException("The {$what} must be an http:// or https:// URL with a host and no query, fragment or credentials, got \"{$url}\".");
        }

        $trimmed = rtrim($url, '/');

        return $trimmed !== '' ? $trimmed : throw new \LogicException('A URL with a host is not empty.');
    }

    public static function port(int $port): int
    {
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException("The port must be between 1 and 65535, got {$port}.");
        }

        return $port;
    }
}
