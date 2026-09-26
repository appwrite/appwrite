<?php

declare(strict_types=1);

namespace Tests\Servers\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Servers\Hook;
use Utopia\Validator\Numeric;
use Utopia\Validator\Text;

final class HookTest extends TestCase
{
    protected ?Hook $hook;

    public function setUp(): void
    {
        $this->hook = new Hook();
    }

    public function testDescriptionCanBeSet(): void
    {
        $this->assertSame('', $this->hook->getDesc());

        $this->hook->desc('new hook');

        $this->assertSame('new hook', $this->hook->getDesc());
    }

    public function testGroupsCanBeSet(): void
    {
        $this->assertSame([], $this->hook->getGroups());

        $this->hook->groups(['api', 'homepage']);

        $this->assertSame(['api', 'homepage'], $this->hook->getGroups());
    }

    public function testActionCanBeSet(): void
    {
        $default = $this->hook->getAction();
        $this->assertInstanceOf(\Closure::class, $default);
        $this->assertNull($default());

        $this->hook->action(fn(): string => 'hello world');

        $this->assertEquals('hello world', $this->hook->getAction()());
    }

    public function testParamCanBeSet(): void
    {
        $this->assertSame([], $this->hook->getParams());

        $this->hook
            ->param('x', '', new Text(10))
            ->param('y', '', new Text(10));

        $this->assertCount(2, $this->hook->getParams());
    }

    public function testParamAliasesDefaultEmpty(): void
    {
        $this->hook->param('x', '', new Text(10));

        $params = $this->hook->getParams();
        $this->assertArrayHasKey('aliases', $params['x']);
        $this->assertSame([], $params['x']['aliases']);
    }

    public function testParamAliasesCanBeSet(): void
    {
        $this->hook->param(
            'projectId',
            '',
            new Text(64),
            description: '',
            aliases: ['project', 'project_id'],
        );

        $params = $this->hook->getParams();
        $this->assertSame(['project', 'project_id'], $params['projectId']['aliases']);
    }

    public function testParamEnumCanBeSet(): void
    {
        $enum = new \stdClass();
        $enum->name = 'ArticleStatus';
        $enum->map = ['draft' => 'Draft', 'published' => 'Published'];

        $this->hook->param(
            'status',
            'draft',
            new Text(32),
            description: 'Status.',
            optional: true,
            enum: $enum,
        );

        $params = $this->hook->getParams();
        $this->assertSame($enum, $params['status']['enum']);
    }

    public function testResourcesCanBeInjected(): void
    {
        $this->assertSame([], $this->hook->getInjections());

        $this->hook
            ->inject('user')
            ->inject('time')
            ->action(function (): void {});

        $this->assertCount(2, $this->hook->getInjections());
        $this->assertEquals('user', $this->hook->getInjections()['user']['name']);
        $this->assertEquals('time', $this->hook->getInjections()['time']['name']);
    }

    public function testDependenciesAreReturnedInInjectionOrder(): void
    {
        $this->assertSame([], $this->hook->getDependencies());

        $this->hook
            ->inject('user')
            ->param('x', '', new Text(10))
            ->inject('time')
            ->inject('locale');

        $this->assertSame(['user', 'time', 'locale'], $this->hook->getDependencies());
    }

    public function testParamValuesCanBeSet(): void
    {
        $this->assertSame([], $this->hook->getParams());

        $values = [
            'x' => 'hello',
            'y' => 'world',
        ];

        $this->hook
            ->param('x', '', new Numeric())
            ->param('y', '', new Numeric());

        foreach ($values as $key => $value) {
            $this->hook->setParamValue($key, $value);
        }

        $this->assertCount(2, $this->hook->getParams());
        $this->assertEquals('hello', $this->hook->getParams()['x']['value']);
        $this->assertEquals('world', $this->hook->getParams()['y']['value']);
    }

    public function tearDown(): void
    {
        $this->hook = null;
    }
}
