<?php

declare(strict_types=1);

namespace Restate\Testing\PHPUnit;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use Restate\Testing\EndpointContainer;
use Restate\Testing\PhpCliServer;
use Restate\Testing\RestateContainer;
use Restate\Testing\RestateProcess;
use Restate\Testing\RestateTestEnvironment;

/**
 * PHPUnit integration of the {@see RestateTestEnvironment}, for any `TestCase` (PHPUnit 10 or newer):
 *
 * ```php
 * final class GreeterTest extends TestCase
 * {
 *     use InteractsWithRestate;
 *
 *     protected static function restateEndpoint(): PhpCliServer
 *     {
 *         return PhpCliServer::forScript(__DIR__ . '/../public/restate.php');
 *     }
 *
 *     public function testGreets(): void
 *     {
 *         self::assertSame('Hello Mary', self::restate()->ingressClient()->serviceClient('Greeter')->request('greet', 'Mary')->call());
 *     }
 * }
 * ```
 *
 * Every test class with the same configuration shares one environment per PHP process ({@see RestateTestEnvironment::shared()}),
 * started before the first test that uses it and stopped when PHPUnit exits. When a test fails, the Restate log and
 * what the endpoint logged during that test are written to stderr.
 *
 * Restate state outlives a test: use fresh keys (virtual object keys, workflow ids, idempotency keys) per test.
 */
trait InteractsWithRestate
{
    private int $restateEndpointLogOffset = 0;

    /** The endpoint under test. */
    abstract protected static function restateEndpoint(): PhpCliServer|EndpointContainer;

    /**
     * The Restate server to start; override to pin another image, configure it, or run it as a process
     * (`RestateProcess::default()`, without a container runtime).
     */
    protected static function restateContainer(): RestateContainer|RestateProcess
    {
        return RestateContainer::default();
    }

    protected static function restate(): RestateTestEnvironment
    {
        return RestateTestEnvironment::shared(static::restateEndpoint(), static::restateContainer());
    }

    #[Before]
    protected function startRestateEnvironment(): void
    {
        $this->restateEndpointLogOffset = self::restate()->endpointLogSize();
    }

    #[After]
    protected function dumpRestateLogsOnFailure(): void
    {
        $status = $this->status();
        if (!$status->isFailure() && !$status->isError()) {
            return;
        }

        try {
            self::restate()->dumpLogs($this->restateEndpointLogOffset);
        } catch (\Throwable) {
            // The environment did not start; the exception that reported it carries its logs.
        }
    }
}
