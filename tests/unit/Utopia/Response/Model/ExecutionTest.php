<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Response\Model;

use Appwrite\Utopia\Response\Model\Execution;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class ExecutionTest extends TestCase
{
    public function testPreservesResourceIdentity(): void
    {
        $execution = (new Execution())->filter(new Document([
            'resourceType' => 'sites',
            'resourceId' => 'site-id',
            'requestHeaders' => [
                ['name' => 'host', 'value' => ['example.com']],
                ['name' => 'user-agent', 'value' => ['Agent/1.0', 'Agent/2.0']],
                ['name' => 'content-type', 'value' => 'application/json'],
            ],
            'responseHeaders' => [
                new Document(['name' => 'content-length', 'value' => ['42']]),
            ],
        ]));

        $this->assertSame('site-id', $execution->getAttribute('resourceId'));
        $this->assertSame('sites', $execution->getAttribute('resourceType'));
        $this->assertSame([
            ['name' => 'host', 'value' => 'example.com'],
            ['name' => 'user-agent', 'value' => 'Agent/1.0, Agent/2.0'],
            ['name' => 'content-type', 'value' => 'application/json'],
        ], $execution->getAttribute('requestHeaders'));
        $this->assertSame('42', $execution->getAttribute('responseHeaders')[0]->getAttribute('value'));
    }

    public function testResourceIdentityIsRequired(): void
    {
        $rules = (new Execution())->getRules();

        $this->assertTrue($rules['resourceId']['required']);
        $this->assertTrue($rules['resourceType']['required']);
    }
}
