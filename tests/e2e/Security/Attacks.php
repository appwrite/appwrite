<?php

declare(strict_types=1);

namespace Tests\E2E\Security;

/**
 * Every concrete class in Attack/ is loaded. Adding a class is one file.
 */
final class Attacks
{
    /**
     * @return list<Attack>
     */
    public static function all(): array
    {
        $attacks = [];
        $directory = __DIR__ . '/Attack';

        foreach (\scandir($directory) ?: [] as $file) {
            if (! \str_ends_with($file, '.php')) {
                continue;
            }
            $class = __NAMESPACE__ . '\\Attack\\' . \substr($file, 0, -4);
            if (! \class_exists($class)) {
                continue;
            }
            $object = new $class();
            if ($object instanceof Attack) {
                $attacks[] = $object;
            }
        }

        \usort($attacks, static fn (Attack $left, Attack $right): int => $left::getName() <=> $right::getName());

        return $attacks;
    }

    public static function named(string $name): Attack
    {
        foreach (self::all() as $attack) {
            if ($attack::getName() === $name) {
                return $attack;
            }
        }

        throw new \InvalidArgumentException('Unknown security attack class: ' . $name);
    }
}
