<?php

declare(strict_types=1);

namespace Restate\Testing;

/**
 * Restate's admin API refused to register the endpoint (`POST /deployments`), for example because discovery failed or
 * the manifest is invalid. Restate's reason is in the response body.
 */
final class DeploymentRegistrationException extends TestEnvironmentException
{
    public function __construct(
        private readonly string $deploymentUri,
        private readonly int $statusCode,
        private readonly string $responseBody,
    ) {
        parent::__construct("Restate refused to register the deployment {$deploymentUri} (HTTP {$statusCode}): {$responseBody}");
    }

    /** The endpoint URI as Restate was asked to reach it (`http://host.docker.internal:<port><path>`). */
    public function deploymentUri(): string
    {
        return $this->deploymentUri;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function responseBody(): string
    {
        return $this->responseBody;
    }
}
