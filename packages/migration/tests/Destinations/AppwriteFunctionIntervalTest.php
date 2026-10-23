<?php

namespace Utopia\Migration\Tests\Destinations;

use Appwrite\AppwriteException;
use Appwrite\Services\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Utopia\Migration\Destinations\Appwrite as AppwriteDestination;
use Utopia\Migration\Resource;
use Utopia\Migration\Resources\Functions\Func;
use Utopia\Migration\Tests\Adapters\RecordingFunctions;

final class AppwriteFunctionIntervalTest extends TestCase
{
    public function testIntervalIsSentOnCreate(): void
    {
        $functions = new RecordingFunctions();
        $destination = $this->destination($functions);

        $destination->importFunctionResource($this->function(60));

        $this->assertSame([60], $functions->intervals);
        $this->assertSame([], $destination->getWarnings());
    }

    public function testIntervalBelowThePlanMinimumIsDroppedWithAWarning(): void
    {
        $functions = new RecordingFunctions(minimum: 60);
        $destination = $this->destination($functions);

        $resource = $destination->importFunctionResource($this->function(5));

        $this->assertSame([5, null], $functions->intervals);
        $this->assertSame(Resource::STATUS_SUCCESS, $resource->getStatus());
        $this->assertCount(1, $destination->getWarnings());
        $this->assertSame('function', $destination->getWarnings()[0]->getResourceId());
    }

    public function testOtherFailuresAreNotRetried(): void
    {
        $functions = new RecordingFunctions(minimum: 60);
        $destination = $this->destination($functions);

        $this->expectException(AppwriteException::class);

        try {
            $destination->importFunctionResource($this->function(0, 'php-8.4'));
        } finally {
            $this->assertSame([null], $functions->intervals);
        }
    }

    private function function(int $interval, string $runtime = 'node-22'): Func
    {
        return new Func('function', 'Function', $runtime, interval: $interval);
    }

    private function destination(Functions $functions): AppwriteDestination
    {
        $class = new ReflectionClass(AppwriteDestination::class);
        $destination = $class->newInstanceWithoutConstructor();
        $class->getProperty('functions')->setValue($destination, $functions);

        return $destination;
    }
}
