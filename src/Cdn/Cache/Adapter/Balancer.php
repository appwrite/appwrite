<?php

namespace Utopia\Cdn\Cache\Adapter;

use Utopia\Balancer\Balancer as OptionBalancer;
use Utopia\Cdn\Cache\Adapter;
use Utopia\Cdn\Domain;
use Utopia\Cdn\Exception\Configuration;
use Utopia\Cdn\Exception\Purge;
use Utopia\Cdn\Exception\UnsupportedOperation;
use Utopia\Cdn\Extend\CdnOption;

/**
 * Purges through every option a balancer's filters leave standing.
 *
 * The balancer holds the full set of configured providers and the filters that
 * narrow it down, which keeps provider selection declarative: a caller states
 * what it wants purged and which options qualify, never which API to call. A
 * domain cached by more than one provider is evicted from all of them, because
 * leaving one provider holding a stale response is the same as not purging.
 *
 * Providers are attempted independently. One failing does not stop the rest,
 * and the collected failures are raised together once every option has been
 * tried, so a single provider outage cannot silently skip the others.
 */
class Balancer implements Adapter
{
    public function __construct(private readonly OptionBalancer $balancer) {}

    public function purgePaths(string $domain, array $paths): void
    {
        $domain = Domain::validate($domain);
        $paths = Domain::validatePaths($paths);

        if ($paths === []) {
            return;
        }

        $this->each('path purging', static function (Adapter $adapter) use ($domain, $paths): void {
            $adapter->purgePaths($domain, $paths);
        });
    }

    public function purgeDomain(string $domain): void
    {
        $domain = Domain::validate($domain);

        $this->each('domain purging', static function (Adapter $adapter) use ($domain): void {
            $adapter->purgeDomain($domain);
        });
    }

    public function purgeKeys(array $keys): void
    {
        if ($keys === []) {
            return;
        }

        $this->each('cache key purging', static function (Adapter $adapter) use ($keys): void {
            $adapter->purgeKeys($keys);
        });
    }

    /**
     * Purges every zone behind a matching option, which is as wide as a purge gets here: each
     * provider drops everything it holds, for every domain, not only the ones these options front.
     */
    public function purgeZone(): void
    {
        $this->each('zone purging', static function (Adapter $adapter): void {
            $adapter->purgeZone();
        });
    }

    /**
     * @param callable(Adapter): void $purge
     */
    private function each(string $operation, callable $purge): void
    {
        $options = $this->balancer->getFilteredOptions();

        if ($options === []) {
            throw new Configuration('No cache options matched the balancer filters.');
        }

        /** @var array<int, \Throwable> $errors */
        $errors = [];
        /** @var array<int, string> $failed */
        $failed = [];
        $purged = false;

        foreach ($options as $option) {
            // A balancer accepts any option, so what it is holding is checked here.
            if (!$option instanceof CdnOption) {
                throw new Configuration('Cache options must be instances of ' . CdnOption::class . '.');
            }

            try {
                $purge($option->getAdapter());
                $purged = true;
            } catch (UnsupportedOperation) {
                // An option that cannot serve this operation is not a failure;
                // the remaining options still have to be purged.
                continue;
            } catch (\Throwable $error) {
                $errors[] = $error;
                $failed[] = $option->getProvider();
            }
        }

        if ($errors !== []) {
            throw new Purge('Cache ' . $operation . ' failed for ' . implode(', ', array_unique($failed)) . '.', $errors);
        }

        if (!$purged) {
            throw new UnsupportedOperation('Cache ' . $operation . ' is not supported by any matching option.');
        }
    }
}
