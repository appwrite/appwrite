<?php

declare(strict_types=1);

namespace Utopia\DI\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Container\NotFoundExceptionInterface;
use Utopia\DI\Container;

final class ContainerTest extends TestCase
{
    public function testSetAndGetDependency(): void
    {
        $container = new Container();
        $container->set('foo', fn (): string => 'bar', []);

        $this->assertSame('bar', $container->get('foo'));
    }

    public function testSetDefaultsDependenciesToEmptyArray(): void
    {
        $container = new Container();
        $container->set('foo', fn (): string => 'bar');

        $this->assertSame('bar', $container->get('foo'));
    }

    public function testSetReturnsContainer(): void
    {
        $container = new Container();
        $result = $container->set('foo', fn (): string => 'bar', []);

        $this->assertSame($container, $result);
    }

    public function testGetReturnsCachedInstance(): void
    {
        $callCount = 0;
        $container = new Container();
        $container->set('counter', function () use (&$callCount): \stdClass {
            $callCount++;
            return new \stdClass();
        }, []);

        $first = $container->get('counter');
        $second = $container->get('counter');

        $this->assertSame($first, $second);
        $this->assertSame(1, $callCount);
    }

    public function testGetThrowsForUnknownDependency(): void
    {
        $container = new Container();

        $this->expectException(NotFoundExceptionInterface::class);
        $this->expectExceptionMessage('Dependency missing not found');

        $container->get('missing');
    }

    public function testHasReturnsTrueForRegisteredDependency(): void
    {
        $container = new Container();
        $container->set('foo', fn (): string => 'bar', []);

        $this->assertTrue($container->has('foo'));
    }

    public function testHasReturnsFalseForUnregisteredDependency(): void
    {
        $container = new Container();

        $this->assertFalse($container->has('missing'));
    }

    public function testHasDelegatesToParent(): void
    {
        $parent = new Container();
        $parent->set('parentDep', fn (): string => 'fromParent', []);

        $child = new Container($parent);

        $this->assertTrue($child->has('parentDep'));
    }

    public function testHasReturnsFalseWhenNeitherChildNorParentHasDependency(): void
    {
        $parent = new Container();
        $child = new Container($parent);

        $this->assertFalse($child->has('missing'));
    }

    public function testBuildWithSingleDependency(): void
    {
        $container = new Container();
        $container->set('config', fn (): string => 'configValue', []);
        $container->set('service', fn ($dep): string => "service:$dep", ['config']);

        $this->assertSame('service:configValue', $container->get('service'));
    }

    public function testDependencyChaining(): void
    {
        $container = new Container();
        $container->set('a', fn (): string => 'A', []);
        $container->set('b', fn ($dep): string => "B($dep)", ['a']);
        $container->set('c', fn ($dep): string => "C($dep)", ['b']);

        $this->assertSame('C(B(A))', $container->get('c'));
    }

    public function testFactoryReceivesResolvedDependency(): void
    {
        $container = new Container();
        $container->set('greeting', fn (): string => 'Hello', []);
        $container->set('message', fn ($greeting): string => "$greeting, World!", ['greeting']);

        $this->assertSame('Hello, World!', $container->get('message'));
    }

    public function testSetOverridesPreviousFactory(): void
    {
        $container = new Container();
        $container->set('foo', fn (): string => 'first', []);
        $container->set('foo', fn (): string => 'second', []);

        $this->assertSame('second', $container->get('foo'));
    }

    public function testSetOverridesCachedInstance(): void
    {
        $container = new Container();
        $container->set('foo', fn (): string => 'first', []);
        $this->assertSame('first', $container->get('foo'));

        $container->set('foo', fn (): string => 'second', []);
        $this->assertSame('second', $container->get('foo'));
    }

    public function testFactoryCanReturnNull(): void
    {
        $container = new Container();
        $container->set('nullable', fn (): null => null, []);

        $this->assertNull($container->get('nullable'));
    }

    public function testFactoryCanReturnDifferentTypes(): void
    {
        $container = new Container();
        $container->set('int', fn (): int => 42, []);
        $container->set('array', fn (): array => [1, 2, 3], []);
        $container->set('object', fn (): \stdClass => new \stdClass(), []);

        $this->assertSame(42, $container->get('int'));
        $this->assertSame([1, 2, 3], $container->get('array'));
        $this->assertInstanceOf(\stdClass::class, $container->get('object'));
    }

    public function testNullCachedValueIsReturnedWithoutRebuild(): void
    {
        $callCount = 0;
        $container = new Container();
        $container->set('nullable', function () use (&$callCount): null {
            $callCount++;
            return null;
        }, []);

        $container->get('nullable');
        $container->get('nullable');

        $this->assertSame(1, $callCount);
    }

    public function testChildContainerDoesNotAffectParent(): void
    {
        $parent = new Container();
        $child = new Container($parent);
        $child->set('childOnly', fn (): string => 'childValue', []);

        $this->assertTrue($child->has('childOnly'));
        $this->assertFalse($parent->has('childOnly'));
    }

    public function testGetResolvesFromParent(): void
    {
        $parent = new Container();
        $parent->set('foo', fn (): string => 'fromParent', []);

        $child = new Container($parent);

        $this->assertSame('fromParent', $child->get('foo'));
    }

    public function testGetResolvesFromGrandparent(): void
    {
        $grandparent = new Container();
        $grandparent->set('foo', fn (): string => 'fromGrandparent', []);

        $parent = new Container($grandparent);
        $child = new Container($parent);

        $this->assertSame('fromGrandparent', $child->get('foo'));
    }

    public function testChildOverridesParentDependency(): void
    {
        $parent = new Container();
        $parent->set('foo', fn (): string => 'fromParent', []);

        $child = new Container($parent);
        $child->set('foo', fn (): string => 'fromChild', []);

        $this->assertSame('fromChild', $child->get('foo'));
        $this->assertSame('fromParent', $parent->get('foo'));
    }

    public function testGetThrowsWhenNotInChildOrParent(): void
    {
        $parent = new Container();
        $child = new Container($parent);

        $this->expectException(NotFoundExceptionInterface::class);
        $child->get('missing');
    }

    public function testParentDependencyIsCachedInParent(): void
    {
        $callCount = 0;
        $parent = new Container();
        $parent->set('singleton', function () use (&$callCount): \stdClass {
            $callCount++;
            return new \stdClass();
        }, []);

        $child = new Container($parent);

        $fromChild = $child->get('singleton');
        $fromParent = $parent->get('singleton');

        $this->assertSame($fromChild, $fromParent);
        $this->assertSame(1, $callCount);
    }

    public function testChildFactoryCanDependOnParentDependency(): void
    {
        $parent = new Container();
        $parent->set('config', fn (): string => 'prodConfig', []);

        $child = new Container($parent);
        $child->set('service', fn ($cfg): string => "service:$cfg", ['config']);

        $this->assertSame('service:prodConfig', $child->get('service'));
    }

    public function testParentFactoryResolvesChildDependencyViaChildGet(): void
    {
        $parent = new Container();
        $parent->set('greeter', fn ($name): string => "Hello, $name", ['name']);

        $child = new Container($parent);
        $child->set('name', fn (): string => 'Alice', []);

        // Parent factory is resolved through parent->get(), which looks in parent's scope
        // The child provides 'name' but parent resolves its own dependencies from its own container
        // This should throw because 'name' is not in parent
        $this->expectException(NotFoundExceptionInterface::class);
        $child->get('greeter');
    }

    public function testMultipleSiblingContainersShareParent(): void
    {
        $parent = new Container();
        $parent->set('shared', fn (): \stdClass => new \stdClass(), []);

        $child1 = new Container($parent);
        $child2 = new Container($parent);

        $this->assertSame($child1->get('shared'), $child2->get('shared'));
    }

    public function testHasDelegatesToGrandparent(): void
    {
        $grandparent = new Container();
        $grandparent->set('deep', fn (): string => 'deepValue', []);

        $parent = new Container($grandparent);
        $child = new Container($parent);

        $this->assertTrue($child->has('deep'));
    }

    public function testBuildWithMultipleDependencies(): void
    {
        $container = new Container();
        $container->set('first', fn (): string => 'A', []);
        $container->set('second', fn (): string => 'B', []);
        $container->set('combined', fn (string $a, string $b): string => "$a+$b", ['first', 'second']);

        $this->assertSame('A+B', $container->get('combined'));
    }

    public function testBuildWithThreeDependencies(): void
    {
        $container = new Container();
        $container->set('x', fn (): int => 1, []);
        $container->set('y', fn (): int => 2, []);
        $container->set('z', fn (): int => 3, []);
        $container->set('sum', fn (int $x, int $y, int $z): int => $x + $y + $z, ['x', 'y', 'z']);

        $this->assertSame(6, $container->get('sum'));
    }

    public function testMultipleDependenciesPreservesOrder(): void
    {
        $container = new Container();
        $container->set('a', fn (): string => 'first', []);
        $container->set('b', fn (): string => 'second', []);
        $container->set('c', fn (): string => 'third', []);
        $container->set('ordered', fn (string $a, string $b, string $c): string => "$a,$b,$c", ['a', 'b', 'c']);

        $this->assertSame('first,second,third', $container->get('ordered'));
    }

    public function testMultipleDependenciesAllReceived(): void
    {
        $container = new Container();
        $container->set('dep1', fn (): string => 'val1', []);
        $container->set('dep2', fn (): string => 'val2', []);
        $container->set('collector', fn (string $a, string $b): array => [$a, $b], ['dep1', 'dep2']);

        $result = $container->get('collector');
        $this->assertSame(['val1', 'val2'], $result);
    }
}
