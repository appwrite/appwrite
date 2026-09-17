<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Response\Filters;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filters\V28;
use PHPUnit\Framework\TestCase;

final class V28Test extends TestCase
{
    public function testCollapsesDomainTriggerToHttp(): void
    {
        $filter = new V28();

        $result = $filter->parse([
            '$id' => 'execution',
            'trigger' => 'domain',
        ], Response::MODEL_EXECUTION);

        $this->assertSame([
            '$id' => 'execution',
            'trigger' => 'http',
        ], $result);
    }

    public function testCollapsesDomainTriggerInList(): void
    {
        $filter = new V28();

        $result = $filter->parse([
            'total' => 2,
            'executions' => [
                ['$id' => 'first', 'trigger' => 'domain'],
                ['$id' => 'second', 'trigger' => 'schedule'],
            ],
        ], Response::MODEL_EXECUTION_LIST);

        $this->assertSame([
            'total' => 2,
            'executions' => [
                ['$id' => 'first', 'trigger' => 'http'],
                ['$id' => 'second', 'trigger' => 'schedule'],
            ],
        ], $result);
    }

    public function testLeavesOtherTriggersUntouched(): void
    {
        $filter = new V28();

        foreach (['http', 'schedule', 'event', ''] as $trigger) {
            $result = $filter->parse([
                '$id' => 'execution',
                'trigger' => $trigger,
            ], Response::MODEL_EXECUTION);

            $this->assertSame($trigger, $result['trigger']);
        }
    }

    public function testEmptiesProjectDevKeys(): void
    {
        $filter = new V28();

        $result = $filter->parse([
            '$id' => 'project',
            'devKeys' => [['$id' => 'key']],
        ], Response::MODEL_PROJECT);

        $this->assertSame([], $result['devKeys']);
    }
}
