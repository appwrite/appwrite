<?php

namespace Utopia\Migration\Tests\Adapters;

use Appwrite\Client;
use Appwrite\Enums\Adapter;
use Appwrite\Enums\BuildRuntime;
use Appwrite\Enums\Framework;
use Appwrite\Models\Site;
use Appwrite\Models\SpecificationList;
use Appwrite\Services\Sites;
use ReflectionClass;

/**
 * Records the specifications each create sends and refuses one the plan does not offer, as Cloud does.
 */
final class RecordingSites extends Sites
{
    /** @var list<array{build: string|null, runtime: string|null}> */
    public array $specifications = [];

    /**
     * @param list<string>|null $builds Build specifications the plan offers, or null to offer every one
     * @param list<string>|null $runtimes Runtime specifications the plan offers, or null to offer every one
     */
    public function __construct(
        private readonly ?array $builds = null,
        private readonly ?array $runtimes = null,
    ) {
        parent::__construct(new Client());
    }

    /**
     * @param array<mixed>|null $providerBranches
     * @param array<mixed>|null $providerPaths
     * @param array<mixed>|null $scopes
     */
    public function create(string $siteId, string $name, Framework $framework, BuildRuntime $buildRuntime, ?bool $enabled = null, ?bool $logging = null, ?int $timeout = null, ?string $installCommand = null, ?string $buildCommand = null, ?string $startCommand = null, ?string $outputDirectory = null, ?Adapter $adapter = null, ?string $installationId = null, ?string $fallbackFile = null, ?string $providerRepositoryId = null, ?string $providerBranch = null, ?bool $providerSilentMode = null, ?string $providerRootDirectory = null, ?array $providerBranches = null, ?array $providerPaths = null, ?string $buildSpecification = null, ?string $runtimeSpecification = null, ?int $deploymentRetention = null, ?array $scopes = null, ?string $description = null): Site
    {
        $this->specifications[] = ['build' => $buildSpecification, 'runtime' => $runtimeSpecification];

        Specifications::validate('buildSpecification', $buildSpecification, $this->builds);
        Specifications::validate('runtimeSpecification', $runtimeSpecification, $this->runtimes);

        return (new ReflectionClass(Site::class))->newInstanceWithoutConstructor();
    }

    public function listSpecifications(?string $type = null, ?bool $total = null): SpecificationList
    {
        return Specifications::list(($type ?? 'runtimes') === 'builds' ? $this->builds : $this->runtimes);
    }
}
