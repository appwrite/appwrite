<?php

declare(strict_types=1);

namespace Utopia\Tests\Cdn\Extend;

use PHPUnit\Framework\TestCase;
use Utopia\Balancer\Algorithm\First;
use Utopia\Balancer\Balancer;
use Utopia\Cdn\Cache\Adapter;
use Utopia\Cdn\Exception\Configuration;
use Utopia\Cdn\Extend\CdnOption;

final class CdnOptionTest extends TestCase
{
    public function testExposesItsStateTyped(): void
    {
        $adapter = $this->adapter();
        $option = new CdnOption($adapter, CdnOption::PROVIDER_FASTLY, true);

        $this->assertSame($adapter, $option->getAdapter());
        $this->assertSame('fastly', $option->getProvider());
        $this->assertTrue($option->isEdge());
        $this->assertFalse(new CdnOption($adapter, CdnOption::PROVIDER_CLOUDFLARE)->isEdge());
    }

    public function testRejectsStateOverwrittenWithTheWrongType(): void
    {
        $option = new CdnOption($this->adapter(), CdnOption::PROVIDER_FASTLY);
        $option->setState(CdnOption::ADAPTER, 'fastly');

        $this->expectException(Configuration::class);
        $option->getAdapter();
    }

    public function testFiltersOnTypedAccessorsInsteadOfStateKeys(): void
    {
        $edge = new CdnOption($this->adapter(), CdnOption::PROVIDER_FASTLY, true);
        $run = new CdnOption($this->adapter(), CdnOption::PROVIDER_FASTLY);
        $cloudflare = new CdnOption($this->adapter(), CdnOption::PROVIDER_CLOUDFLARE);

        $balancer = new Balancer(new First())
            ->addOption($edge)
            ->addOption($run)
            ->addOption($cloudflare);

        $balancer->addFilter(fn(CdnOption $option): bool => !$option->isEdge());

        $this->assertSame([$run, $cloudflare], $balancer->getFilteredOptions());

        $balancer->addFilter(fn(CdnOption $option): bool => $option->getProvider() === CdnOption::PROVIDER_CLOUDFLARE);

        $this->assertSame([$cloudflare], $balancer->getFilteredOptions());

        // Still an ordinary balancer option, so run() picks one as it always did.
        $this->assertSame($cloudflare, $balancer->run());
    }

    private function adapter(): Adapter
    {
        return new class implements Adapter {
            public function purgePaths(string $domain, array $paths): void {}

            public function purgeDomain(string $domain): void {}

            public function purgeKeys(array $keys): void {}

            public function purgeZone(): void {}
        };
    }
}
