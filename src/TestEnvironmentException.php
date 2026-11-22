<?php

declare(strict_types=1);

namespace Restate\Testing;

use Restate\Exception;

/**
 * The test environment could not be started or used: no container runtime, a container that did not start, an
 * endpoint that does not serve or whose extension does not load, or a Restate server that did not become ready. When
 * starting failed, the message ends with the tail of the Restate container log and of the endpoint log.
 */
class TestEnvironmentException extends \RuntimeException implements Exception
{
}
