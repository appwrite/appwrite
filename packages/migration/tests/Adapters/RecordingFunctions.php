<?php

namespace Utopia\Migration\Tests\Adapters;

use Appwrite\AppwriteException;
use Appwrite\Client;
use Appwrite\Enums\Runtime;
use Appwrite\Models\FunctionModel;
use Appwrite\Models\SpecificationList;
use Appwrite\Services\Functions;
use ReflectionClass;

/**
 * Records the interval and specifications each create sends, and refuses what Cloud refuses:
 * an interval below the plan minimum and a specification the plan does not offer.
 */
final class RecordingFunctions extends Functions
{
    /** @var list<int|null> */
    public array $intervals = [];

    /** @var list<array{build: string|null, runtime: string|null}> */
    public array $specifications = [];

    /** @var array<string, int> How many times the specifications were listed, by type */
    public array $listed = ['builds' => 0, 'runtimes' => 0];

    /**
     * @param list<string>|null $builds Build specifications the plan offers, or null to offer every one
     * @param list<string>|null $runtimes Runtime specifications the plan offers, or null to offer every one
     */
    public function __construct(
        private readonly int $minimum = 0,
        private readonly ?array $builds = null,
        private readonly ?array $runtimes = null,
        private readonly bool $listable = true,
    ) {
        parent::__construct(new Client());
    }

    public function create(string $functionId, string $name, Runtime $runtime, ?array $execute = null, ?array $events = null, ?string $schedule = null, ?int $timeout = null, ?bool $enabled = null, ?bool $logging = null, ?string $entrypoint = null, ?string $commands = null, ?array $scopes = null, ?string $installationId = null, ?string $providerRepositoryId = null, ?string $providerBranch = null, ?bool $providerSilentMode = null, ?string $providerRootDirectory = null, ?array $providerBranches = null, ?array $providerPaths = null, ?string $buildSpecification = null, ?string $runtimeSpecification = null, ?int $deploymentRetention = null, ?int $interval = null, ?string $description = null): FunctionModel
    {
        $this->intervals[] = $interval;
        $this->specifications[] = ['build' => $buildSpecification, 'runtime' => $runtimeSpecification];

        if ($interval !== null && $interval < $this->minimum) {
            throw new AppwriteException('Interval below the plan minimum.', 403, 'billing_feature_unsupported');
        }

        if ((string) $runtime !== 'node-22') {
            throw new AppwriteException('Runtime not available.', 400, 'general_argument_invalid');
        }

        Specifications::validate('buildSpecification', $buildSpecification, $this->builds);
        Specifications::validate('runtimeSpecification', $runtimeSpecification, $this->runtimes);

        return (new ReflectionClass(FunctionModel::class))->newInstanceWithoutConstructor();
    }

    public function listSpecifications(?string $type = null, ?bool $total = null): SpecificationList
    {
        $type ??= 'runtimes';
        $this->listed[$type] = ($this->listed[$type] ?? 0) + 1;

        if (!$this->listable) {
            throw new AppwriteException('User (role: applications) missing scopes (["functions.read"])', 401, 'general_unauthorized_scope');
        }

        return Specifications::list($type === 'builds' ? $this->builds : $this->runtimes);
    }
}
