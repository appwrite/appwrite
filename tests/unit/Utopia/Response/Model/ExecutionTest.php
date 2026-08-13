<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Response\Model;

use Appwrite\Utopia\Response\Model\Execution;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class ExecutionTest extends TestCase
{
    public function testFunctionExecutionUsesFunctionId(): void
    {
        $execution = (new Execution())->filter(new Document([
            'resourceType' => 'functions',
            'resourceId' => 'function-id',
        ]));

        $this->assertSame('function-id', $execution->getAttribute('functionId'));
        $this->assertFalse($execution->isSet('siteId'));
        $this->assertFalse($execution->isSet('resourceType'));
        $this->assertFalse($execution->isSet('resourceId'));
    }

    public function testSiteExecutionUsesSiteId(): void
    {
        $execution = (new Execution())->filter(new Document([
            'resourceType' => 'sites',
            'resourceId' => 'site-id',
        ]));

        $this->assertSame('site-id', $execution->getAttribute('siteId'));
        $this->assertFalse($execution->isSet('functionId'));
        $this->assertFalse($execution->isSet('resourceType'));
        $this->assertFalse($execution->isSet('resourceId'));
    }

    public function testResourceIdsAreOptional(): void
    {
        $rules = (new Execution())->getRules();

        $this->assertFalse($rules['functionId']['required']);
        $this->assertFalse($rules['siteId']['required']);
    }
}
