<?php

declare(strict_types=1);

namespace Utopia\Tests\Cdn;

use PHPUnit\Framework\TestCase;
use Utopia\Cdn\Cache;
use Utopia\Cdn\Cache\Adapter;

final class CacheTest extends TestCase
{
    public function testDelegatesCacheOperations(): void
    {
        $calls = new \ArrayObject();
        $cache = new Cache($this->adapter($calls));

        $cache->purgePaths('example.com', ['/file.png']);
        $cache->purgeDomain('example.com');
        $cache->purgeKeys(['key']);
        $cache->purgeZone();

        $this->assertSame([
            ['paths' => ['example.com', ['/file.png']]],
            ['domain' => 'example.com'],
            ['keys' => ['key']],
            ['zone' => true],
        ], $calls->getArrayCopy());
    }

    public function testRejectsInvalidInput(): void
    {
        $cache = new Cache($this->adapter(new \ArrayObject()));

        $this->expectException(\InvalidArgumentException::class);
        $cache->purgePaths('https://example.com', ['relative']);
    }

    /** @param \ArrayObject<int, mixed> $calls */
    private function adapter(\ArrayObject $calls): Adapter
    {
        return new readonly class ($calls) implements Adapter {
            /** @param \ArrayObject<int, mixed> $calls */
            public function __construct(private \ArrayObject $calls) {}
            public function purgePaths(string $domain, array $paths): void
            {
                $this->calls->append(['paths' => [$domain, $paths]]);
            }
            public function purgeDomain(string $domain): void
            {
                $this->calls->append(['domain' => $domain]);
            }
            public function purgeKeys(array $keys): void
            {
                $this->calls->append(['keys' => $keys]);
            }
            public function purgeZone(): void
            {
                $this->calls->append(['zone' => true]);
            }
        };
    }
}
