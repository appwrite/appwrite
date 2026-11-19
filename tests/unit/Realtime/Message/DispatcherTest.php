<?php

declare(strict_types=1);

namespace Tests\Unit\Realtime\Message;

use Appwrite\Realtime\Message\Dispatcher;
use Appwrite\Realtime\Message\Handlers\Ping;
use Appwrite\Realtime\Message\Handlers\Unsubscribe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DispatcherTest extends TestCase
{
    private function dispatcher(): Dispatcher
    {
        return (new Dispatcher())
            ->addHandler(new Ping())
            ->addHandler(new Unsubscribe());
    }

    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function types(): array
    {
        return [
            'ping' => ['ping', false],
            'unsubscribe' => ['unsubscribe', true],
            'unknown' => ['unknown', true],
            'missing' => [null, true],
            'not a string' => [['ping'], true],
        ];
    }

    #[DataProvider('types')]
    public function testIsRateLimited(mixed $type, bool $expected): void
    {
        $this->assertSame($expected, $this->dispatcher()->isRateLimited($type));
    }

    #[DataProvider('types')]
    public function testRequiresProject(mixed $type, bool $expected): void
    {
        $this->assertSame($expected, $this->dispatcher()->requiresProject($type));
    }
}
