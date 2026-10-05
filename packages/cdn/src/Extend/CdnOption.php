<?php

declare(strict_types=1);

namespace Utopia\Cdn\Extend;

use Utopia\Balancer\Option;
use Utopia\Cdn\Cache\Adapter;
use Utopia\Cdn\Exception\Configuration;

/**
 * A balancer option that carries a cache adapter.
 *
 * The base option is an untyped state bag, so a filter written against it reads
 * `$option->getState('adapter')` and has to trust the key spelling and the
 * value's type. This subclass fixes both ends: the constructor names what an
 * option needs and the getters return it typed.
 */
class CdnOption extends Option
{
    public const string ADAPTER = 'adapter';

    public const string PROVIDER = 'provider';

    public const string EDGE = 'edge';

    public const string PROVIDER_FASTLY = 'fastly';

    public const string PROVIDER_CLOUDFLARE = 'cloudflare';

    /**
     * @param Adapter $adapter Purges cached content for this option.
     * @param string $provider Vendor the adapter talks to, one of the PROVIDER_* constants.
     * @param bool $edge Whether the option fronts the platform's own edge network rather than customer-owned custom domains.
     */
    public function __construct(Adapter $adapter, string $provider, bool $edge = false)
    {
        parent::__construct([
            self::ADAPTER => $adapter,
            self::PROVIDER => $provider,
            self::EDGE => $edge,
        ]);
    }

    public function getAdapter(): Adapter
    {
        $adapter = $this->getState(self::ADAPTER);

        // State stays publicly writable through setState(), so the type the
        // constructor guaranteed is checked again on the way out.
        if (!$adapter instanceof Adapter) {
            throw new Configuration('Option state "' . self::ADAPTER . '" must be a ' . Adapter::class . '.');
        }

        return $adapter;
    }

    public function getProvider(): string
    {
        $provider = $this->getState(self::PROVIDER);

        if (!\is_string($provider)) {
            throw new Configuration('Option state "' . self::PROVIDER . '" must be a string.');
        }

        return $provider;
    }

    public function isEdge(): bool
    {
        return $this->getState(self::EDGE, false) === true;
    }
}
