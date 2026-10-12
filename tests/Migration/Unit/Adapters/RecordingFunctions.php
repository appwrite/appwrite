<?php

namespace Utopia\Tests\Unit\Adapters;

use Appwrite\AppwriteException;
use Appwrite\Client;
use Appwrite\Enums\Runtime;
use Appwrite\Models\FunctionModel;
use Appwrite\Services\Functions;
use ReflectionClass;

/**
 * Records the interval each create sends and rejects one below the plan minimum, as Cloud does.
 */
final class RecordingFunctions extends Functions
{
    /** @var list<int|null> */
    public array $intervals = [];

    public function __construct(private readonly int $minimum = 0)
    {
        parent::__construct(new Client());
    }

    public function create(string $functionId, string $name, Runtime $runtime, ?array $execute = null, ?array $events = null, ?string $schedule = null, ?int $timeout = null, ?bool $enabled = null, ?bool $logging = null, ?string $entrypoint = null, ?string $commands = null, ?array $scopes = null, ?string $installationId = null, ?string $providerRepositoryId = null, ?string $providerBranch = null, ?bool $providerSilentMode = null, ?string $providerRootDirectory = null, ?array $providerBranches = null, ?array $providerPaths = null, ?string $buildSpecification = null, ?string $runtimeSpecification = null, ?int $deploymentRetention = null, ?int $interval = null): FunctionModel
    {
        $this->intervals[] = $interval;

        if ($interval !== null && $interval < $this->minimum) {
            throw new AppwriteException('Interval below the plan minimum.', 403, 'billing_feature_unsupported');
        }

        if ((string) $runtime !== 'node-22') {
            throw new AppwriteException('Runtime not available.', 400, 'general_argument_invalid');
        }

        return (new ReflectionClass(FunctionModel::class))->newInstanceWithoutConstructor();
    }
}
