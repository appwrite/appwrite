<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers\Fixture;

use Executor\Executor;
use Override;

final class CapturingExecutor extends Executor
{
    /**
     * @var array<string, string>
     */
    public array $variables = [];

    /**
     * @var array<string, string>
     */
    public array $headers = [];

    #[Override]
    public function createExecution(
        string $projectId,
        string $deploymentId,
        ?string $body,
        array $variables,
        int $timeout,
        string $image,
        string $source,
        string $entrypoint,
        string $version,
        string $path,
        string $method,
        array $headers,
        float $cpus,
        int $memory,
        bool $logging,
        string $runtimeEntrypoint = '',
        ?int $requestTimeout = null,
        string $responseFormat = self::RESPONSE_FORMAT_OBJECT_HEADERS,
    ): array {
        $this->variables = $variables;
        $this->headers = $headers;

        return [
            'statusCode' => 200,
            'headers' => [],
            'logs' => '',
            'errors' => '',
            'duration' => 0.001,
        ];
    }
}
