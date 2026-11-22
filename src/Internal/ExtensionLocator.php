<?php

declare(strict_types=1);

namespace Restate\Testing\Internal;

use Restate\Testing\TestEnvironmentException;

/**
 * Finds the Restate extension module a `php -S` endpoint loads (see `PhpCliServer::withExtension()`).
 *
 * @internal
 */
final class ExtensionLocator
{
    public const string ENVIRONMENT_VARIABLE = 'RESTATE_PHP_EXTENSION_PATH';

    /** The extension's module name (`extension_loaded()`, `ext-restate` to Composer). */
    public const string MODULE_NAME = 'restate';

    private const string MODULE_FILE = self::MODULE_NAME . '.so';

    private function __construct()
    {
    }

    /**
     * @param ?string $explicit the configured module (already checked)
     * @param ?string $environment the value of RESTATE_PHP_EXTENSION_PATH
     * @param ?string $projectRoot the root of the project the harness runs in (where vendor/ is), whose extension/target/
     *                             holds a module built from source in the SDK's own repository
     *
     * @return ?string the module to load with `-d extension=…`, or null to rely on php.ini
     */
    public static function locate(?string $explicit, ?string $environment, ?string $projectRoot): ?string
    {
        if ($explicit !== null) {
            return $explicit;
        }

        if ($environment !== null && $environment !== '') {
            $path = is_file($environment) ? realpath($environment) : false;

            return $path !== false ? $path : throw new TestEnvironmentException(
                self::ENVIRONMENT_VARIABLE . " names {$environment}, which does not exist or is not a file.",
            );
        }

        if ($projectRoot === null) {
            return null;
        }

        foreach (['debug', 'release'] as $profile) {
            $path = realpath("{$projectRoot}/extension/target/{$profile}/" . self::MODULE_FILE);
            if ($path !== false && is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    public static function fromEnvironment(): ?string
    {
        $value = getenv(self::ENVIRONMENT_VARIABLE);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
