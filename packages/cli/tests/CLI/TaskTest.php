<?php

declare(strict_types=1);

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\CLI\Task;
use Utopia\Validator\Text;

final class TaskTest extends TestCase
{
    /**
     * @var ?Task
     */
    protected $task;

    public function setUp(): void
    {
        $this->task = new Task('test');
    }

    public function tearDown(): void
    {
        $this->task = null;
    }

    public function testName(): void
    {
        $this->assertEquals('test', $this->task->getName());
    }

    public function testDescription(): void
    {
        $this->task->desc('test task');

        $this->assertEquals('test task', $this->task->getDesc());
    }

    public function testAction(): void
    {
        $this->task->action(fn(): string => 'result');

        $this->assertEquals('result', $this->task->getAction()());
    }

    public function testLabel(): void
    {
        $this->task->label('key', 'value');

        $this->assertEquals('value', $this->task->getLabel('key', 'default'));
        $this->assertEquals('default', $this->task->getLabel('unknown', 'default'));
    }

    public function testParam(): void
    {
        $this->task->param('email', 'me@example.com', new Text(0), 'Param with valid email address', false);

        $this->assertCount(1, $this->task->getParams());
    }

    public function testResources(): void
    {
        $this->assertEquals([], $this->task->getDependencies());

        $this->task
            ->inject('user')
            ->inject('time')
            ->action(function (): void {});

        $this->assertCount(2, $this->task->getDependencies());
        $this->assertEquals('user', $this->task->getDependencies()[0]);
        $this->assertEquals('time', $this->task->getDependencies()[1]);
    }
}
