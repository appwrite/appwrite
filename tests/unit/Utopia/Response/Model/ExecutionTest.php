<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Response\Model;

use Appwrite\Utopia\Response\Model\Execution;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class ExecutionTest extends TestCase
{
    public function testPreservesResourceId(): void
    {
        $execution = (new Execution())->filter(new Document([
            'resourceType' => 'sites',
            'resourceId' => 'site-id',
        ]));

        $this->assertSame('site-id', $execution->getAttribute('resourceId'));
        $this->assertFalse($execution->isSet('resourceType'));
    }

    public function testResourceIdIsRequired(): void
    {
        $rules = (new Execution())->getRules();

        $this->assertTrue($rules['resourceId']['required']);
    }
}
