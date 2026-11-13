<?php

namespace Utopia\Migration\Tests\Adapters;

use Appwrite\AppwriteException;
use Appwrite\Models\SpecificationList;

/**
 * The specifications of an Appwrite instance, as its functions and sites services answer for
 * them: the list flags what the plan offers and still names the larger ones a higher plan
 * unlocks, and a create refuses any the plan does not offer.
 */
final class Specifications
{
    private const array ALL = [
        's-0.5vcpu-512mb' => ['cpus' => 0.5, 'memory' => 512],
        's-1vcpu-512mb' => ['cpus' => 1, 'memory' => 512],
        's-1vcpu-1gb' => ['cpus' => 1, 'memory' => 1024],
        's-2vcpu-2gb' => ['cpus' => 2, 'memory' => 2048],
        's-2vcpu-4gb' => ['cpus' => 2, 'memory' => 4096],
        's-4vcpu-4gb' => ['cpus' => 4, 'memory' => 4096],
        's-8vcpu-8gb' => ['cpus' => 8, 'memory' => 8192],
    ];

    /**
     * @param list<string>|null $offered Null offers every specification, as an instance without plans does
     */
    public static function list(?array $offered): SpecificationList
    {
        $offered ??= \array_keys(self::ALL);

        // The largest the plan offers: what is larger stays listed, disabled
        $cpus = 0;
        $memory = 0;
        foreach (self::ALL as $slug => $size) {
            if (\in_array($slug, $offered, true)) {
                $cpus = \max($cpus, $size['cpus']);
                $memory = \max($memory, $size['memory']);
            }
        }

        $specifications = [];
        foreach (self::ALL as $slug => $size) {
            $enabled = \in_array($slug, $offered, true);

            // Smaller than the plan's smallest are left out; larger stay, disabled
            if (!$enabled && $size['cpus'] <= $cpus && $size['memory'] <= $memory) {
                continue;
            }

            $specifications[] = ['slug' => $slug, 'enabled' => $enabled, ...$size];
        }

        return SpecificationList::from(['total' => \count($specifications), 'specifications' => $specifications]);
    }

    /**
     * @param list<string>|null $offered
     */
    public static function validate(string $param, ?string $specification, ?array $offered): void
    {
        if ($specification === null || $offered === null || \in_array($specification, $offered, true)) {
            return;
        }

        throw new AppwriteException('Invalid `' . $param . '` param: Specification must be one of: ' . \implode(', ', $offered), 400, 'general_argument_invalid');
    }
}
