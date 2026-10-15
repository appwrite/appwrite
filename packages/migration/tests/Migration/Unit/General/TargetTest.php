<?php

namespace Utopia\Tests\Unit\General;

use Override;
use PHPUnit\Framework\TestCase;
use Utopia\Migration\Target;

final class TargetTest extends TestCase
{
    public function testExternalLegacyThreeArgumentRunImplementationRemainsCompatible(): void
    {
        $target = new class () extends Target {
            /** @var array{resources: array<string>, rootResourceId: string}|null */
            public ?array $invocation = null;

            #[Override]
            public static function getName(): string
            {
                return 'Legacy';
            }

            #[Override]
            public static function getSupportedResources(): array
            {
                return [];
            }

            #[Override]
            public function run(array $resources, callable $callback, string $rootResourceId = ''): void
            {
                $this->invocation = [
                    'resources' => $resources,
                    'rootResourceId' => $rootResourceId,
                ];
                $callback([]);
            }

            #[Override]
            public function report(array $resources = [], array $resourceIds = []): array
            {
                return [];
            }
        };

        $callbackCount = 0;
        $target->run(
            ['database'],
            static function () use (&$callbackCount): void {
                $callbackCount++;
            },
            'database',
        );

        $this->assertSame(1, $callbackCount);
        $this->assertSame([
            'resources' => ['database'],
            'rootResourceId' => 'database',
        ], $target->invocation);
    }
}
