<?php

namespace Utopia\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;
use Utopia\Migration\Resources\Functions\Func;
use Utopia\Migration\Resources\Sites\Site;

class SpecificationTest extends TestCase
{
    public function testFunctionPreservesBuildAndRuntimeSpecifications(): void
    {
        $function = Func::fromArray([
            'id' => 'function',
            'name' => 'Function',
            'runtime' => 'php-8.4',
            'runtimeSpecification' => 's-1vcpu-512mb',
            'buildSpecification' => 's-2vcpu-2gb',
        ]);

        $this->assertSame('s-1vcpu-512mb', $function->getRuntimeSpecification());
        $this->assertSame('s-2vcpu-2gb', $function->getBuildSpecification());
        $this->assertSame('s-1vcpu-512mb', $function->jsonSerialize()['runtimeSpecification']);
        $this->assertSame('s-2vcpu-2gb', $function->jsonSerialize()['buildSpecification']);
    }

    public function testSitePreservesBuildAndRuntimeSpecifications(): void
    {
        $site = Site::fromArray([
            'id' => 'site',
            'name' => 'Site',
            'framework' => 'other',
            'buildRuntime' => 'node-22',
            'runtimeSpecification' => 's-1vcpu-512mb',
            'buildSpecification' => 's-2vcpu-2gb',
        ]);

        $this->assertSame('s-1vcpu-512mb', $site->getRuntimeSpecification());
        $this->assertSame('s-2vcpu-2gb', $site->getBuildSpecification());
        $this->assertSame('s-1vcpu-512mb', $site->jsonSerialize()['runtimeSpecification']);
        $this->assertSame('s-2vcpu-2gb', $site->jsonSerialize()['buildSpecification']);
    }

    public function testLegacySpecificationIsUsedForBuildAndRuntime(): void
    {
        $function = Func::fromArray([
            'id' => 'function',
            'name' => 'Function',
            'runtime' => 'php-8.4',
            'specification' => 's-1vcpu-512mb',
        ]);

        $this->assertSame('s-1vcpu-512mb', $function->getRuntimeSpecification());
        $this->assertSame('s-1vcpu-512mb', $function->getBuildSpecification());
    }
}
