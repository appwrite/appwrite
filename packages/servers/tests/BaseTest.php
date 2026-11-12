<?php

declare(strict_types=1);

namespace Utopia\Servers\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Servers\Hook;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Text;

final class BaseTest extends TestCase
{
    private static bool $called = false;

    public static function markCalled(): string
    {
        self::$called = true;

        return 'called';
    }

    public function setUp(): void
    {
        self::$called = false;
    }

    public function testRequestCallableIsPassedAsData(): void
    {
        $hook = new Hook()->param('items', [], new ArrayList(new Text(200)), 'items', true);

        $scope = new Server()->run($hook, ['items' => [self::class, 'markCalled']]);

        $this->assertFalse(self::$called);
        $this->assertSame([self::class, 'markCalled'], $scope->get('items'));
    }

    public function testDefaultCallableIsResolved(): void
    {
        $hook = new Hook()->param('items', fn (): array => ['generated'], new ArrayList(new Text(200)), 'items', true);

        $scope = new Server()->run($hook, []);

        $this->assertSame(['generated'], $scope->get('items'));
    }
}
