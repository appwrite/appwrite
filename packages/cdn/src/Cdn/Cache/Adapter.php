<?php

declare(strict_types=1);

namespace Utopia\Cdn\Cache;

/**
 * The purge operations every provider adapter offers.
 *
 * Providers differ in what they expose natively — Cloudflare purges a hostname directly, Fastly has
 * to be told which surrogate key stands for one — but a caller should not have to know which. Every
 * operation here is therefore implemented by every adapter, or the adapter raises
 * Exception\UnsupportedOperation to say it cannot serve this one with the configuration it was given.
 *
 * Naming follows the object being purged: paths, a domain, cache keys, the whole zone.
 */
interface Adapter
{
    /**
     * @param array<int, string> $paths
     */
    public function purgePaths(string $domain, array $paths): void;

    public function purgeDomain(string $domain): void;

    /**
     * @param array<int, string> $keys
     */
    public function purgeKeys(array $keys): void;

    /**
     * Purges everything the adapter is configured for, whatever domain it belongs to.
     *
     * The widest operation a provider offers: Cloudflare's purge_everything for the zone, Fastly's
     * purge_all for the service. Everything cached is then re-fetched from origin, including all the
     * content that did not need to be, so reach for purgeDomain() or purgeKeys() first.
     */
    public function purgeZone(): void;
}
