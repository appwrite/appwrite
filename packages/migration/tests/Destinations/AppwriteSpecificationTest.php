<?php

namespace Utopia\Migration\Tests\Destinations;

use Appwrite\Services\Functions;
use Appwrite\Services\Sites;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Utopia\Migration\Destinations\Appwrite as AppwriteDestination;
use Utopia\Migration\Resource;
use Utopia\Migration\Resources\Functions\Func;
use Utopia\Migration\Resources\Sites\Site;
use Utopia\Migration\Tests\Adapters\RecordingFunctions;
use Utopia\Migration\Tests\Adapters\RecordingSites;
use Utopia\Migration\Warning;

/**
 * A project keeps the specifications its functions and sites were created with when its plan
 * changes, so a copy of it can carry one its destination does not offer. Refusing it failed the
 * function or site and, with it, every variable and deployment that belongs to it.
 */
final class AppwriteSpecificationTest extends TestCase
{
    /** What Cloud's Pro plan offers: builds start where Free's runtimes end */
    private const array BUILDS = ['s-2vcpu-2gb', 's-2vcpu-4gb', 's-4vcpu-4gb'];

    private const array RUNTIMES = ['s-0.5vcpu-512mb', 's-1vcpu-512mb', 's-1vcpu-1gb', 's-2vcpu-2gb', 's-2vcpu-4gb', 's-4vcpu-4gb'];

    public function testOfferedSpecificationsAreKept(): void
    {
        $functions = new RecordingFunctions(builds: self::BUILDS, runtimes: self::RUNTIMES);
        $destination = $this->destination($functions);

        $resource = $destination->importFunctionResource($this->function(runtime: 's-1vcpu-1gb', build: 's-2vcpu-4gb'));

        $this->assertSame([['build' => 's-2vcpu-4gb', 'runtime' => 's-1vcpu-1gb']], $functions->specifications);
        $this->assertSame(Resource::STATUS_SUCCESS, $resource->getStatus());
        $this->assertSame([], $this->warnings($destination));
    }

    public function testFunctionCreatedBeforeItsPlanIsImportedWithThePlanDefaultBuild(): void
    {
        // Created on Free, where builds and runtimes ran on the same specification, and now
        // on Pro, which builds on nothing that small.
        $functions = new RecordingFunctions(builds: self::BUILDS, runtimes: self::RUNTIMES);
        $destination = $this->destination($functions);

        $resource = $destination->importFunctionResource($this->function(runtime: 's-0.5vcpu-512mb'));

        // The build is left to the destination; the runtime it does offer is kept
        $this->assertSame([['build' => null, 'runtime' => 's-0.5vcpu-512mb']], $functions->specifications);
        $this->assertSame(Resource::STATUS_SUCCESS, $resource->getStatus());
        $this->assertCount(1, $this->warnings($destination));
        $this->assertSame('function', $this->warnings($destination)[0]->getResourceId());
        $this->assertStringContainsString('s-0.5vcpu-512mb', $this->warnings($destination)[0]->getMessage());
    }

    public function testRuntimeSpecificationOfAHigherPlanIsLeftToTheDestination(): void
    {
        // The list still names what a higher plan unlocks, flagged disabled: it is not offered
        $functions = new RecordingFunctions(builds: self::BUILDS, runtimes: self::RUNTIMES);
        $destination = $this->destination($functions);

        $resource = $destination->importFunctionResource($this->function(runtime: 's-8vcpu-8gb', build: 's-4vcpu-4gb'));

        $this->assertSame([['build' => 's-4vcpu-4gb', 'runtime' => null]], $functions->specifications);
        $this->assertSame(Resource::STATUS_SUCCESS, $resource->getStatus());
        $this->assertCount(1, $this->warnings($destination));
        $this->assertStringContainsString('s-8vcpu-8gb', $this->warnings($destination)[0]->getMessage());
    }

    public function testSpecificationsAreListedOncePerMigration(): void
    {
        $functions = new RecordingFunctions(builds: self::BUILDS, runtimes: self::RUNTIMES);
        $destination = $this->destination($functions);

        $destination->importFunctionResource($this->function(runtime: 's-0.5vcpu-512mb', id: 'first'));
        $destination->importFunctionResource($this->function(runtime: 's-0.5vcpu-512mb', id: 'second'));

        $this->assertSame(['builds' => 1, 'runtimes' => 1], $functions->listed);
        $this->assertCount(2, $functions->specifications);
    }

    public function testSpecificationIsSentAsIsWhenTheDestinationCannotListThem(): void
    {
        // A key without functions.read cannot list; the create then answers for the specification
        $functions = new RecordingFunctions(listable: false);
        $destination = $this->destination($functions);

        $resource = $destination->importFunctionResource($this->function(runtime: 's-1vcpu-1gb', build: 's-2vcpu-2gb'));

        $this->assertSame([['build' => 's-2vcpu-2gb', 'runtime' => 's-1vcpu-1gb']], $functions->specifications);
        $this->assertSame(Resource::STATUS_SUCCESS, $resource->getStatus());
        $this->assertSame([], $this->warnings($destination));
    }

    public function testFunctionWithoutASpecificationSendsNone(): void
    {
        $functions = new RecordingFunctions(builds: self::BUILDS, runtimes: self::RUNTIMES);
        $destination = $this->destination($functions);

        $destination->importFunctionResource($this->function(runtime: ''));

        $this->assertSame([['build' => null, 'runtime' => null]], $functions->specifications);
        $this->assertSame([], $this->warnings($destination));
    }

    public function testSpecificationIsWarnedAboutOnceWhenTheIntervalIsRefusedToo(): void
    {
        // The interval retry creates the function a second time; the specification it
        // leaves out is still one warning, beside the interval's.
        $functions = new RecordingFunctions(minimum: 60, builds: self::BUILDS, runtimes: self::RUNTIMES);
        $destination = $this->destination($functions);

        $resource = $destination->importFunctionResource(new Func('function', 'Function', 'node-22', specification: 's-0.5vcpu-512mb', interval: 5));

        $this->assertSame([
            ['build' => null, 'runtime' => 's-0.5vcpu-512mb'],
            ['build' => null, 'runtime' => 's-0.5vcpu-512mb'],
        ], $functions->specifications);
        $this->assertSame([5, null], $functions->intervals);
        $this->assertSame(Resource::STATUS_SUCCESS, $resource->getStatus());
        $this->assertCount(2, $this->warnings($destination));
    }

    public function testSiteCreatedBeforeItsPlanIsImportedWithThePlanDefaults(): void
    {
        $sites = new RecordingSites(builds: self::BUILDS, runtimes: self::RUNTIMES);
        $destination = $this->destination(sites: $sites);

        $resource = $destination->importSiteResource(new Site('site', 'Site', 'other', 'static-1', specification: 's-8vcpu-8gb', buildSpecification: 's-0.5vcpu-512mb'));

        $this->assertSame([['build' => null, 'runtime' => null]], $sites->specifications);
        $this->assertSame(Resource::STATUS_SUCCESS, $resource->getStatus());
        $this->assertCount(2, $this->warnings($destination));
        $this->assertSame('site', $this->warnings($destination)[0]->getResourceId());
    }

    public function testSiteKeepsTheSpecificationsItsDestinationOffers(): void
    {
        $sites = new RecordingSites(builds: self::BUILDS, runtimes: self::RUNTIMES);
        $destination = $this->destination(sites: $sites);

        $destination->importSiteResource(new Site('site', 'Site', 'other', 'static-1', specification: 's-1vcpu-512mb', buildSpecification: 's-2vcpu-2gb'));

        $this->assertSame([['build' => 's-2vcpu-2gb', 'runtime' => 's-1vcpu-512mb']], $sites->specifications);
        $this->assertSame([], $this->warnings($destination));
    }

    private function function(string $runtime, string $build = '', string $id = 'function'): Func
    {
        return new Func($id, 'Function', 'node-22', specification: $runtime, buildSpecification: $build);
    }

    /**
     * @return list<Warning>
     */
    private function warnings(AppwriteDestination $destination): array
    {
        $warnings = [];
        foreach ($destination->getWarnings() as $warning) {
            $this->assertInstanceOf(Warning::class, $warning);
            $warnings[] = $warning;
        }

        return $warnings;
    }

    private function destination(?Functions $functions = null, ?Sites $sites = null): AppwriteDestination
    {
        $class = new ReflectionClass(AppwriteDestination::class);
        $destination = $class->newInstanceWithoutConstructor();

        if ($functions !== null) {
            $class->getProperty('functions')->setValue($destination, $functions);
        }

        if ($sites !== null) {
            $class->getProperty('sites')->setValue($destination, $sites);
        }

        return $destination;
    }
}
