<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution\Fakes;

/**
 * The one key a distributed lock holds, in memory. Once `$refreshes` lease
 * refreshes have succeeded, the lease is lost to another holder.
 */
final class LeaseRedis extends \Redis
{
    private const string OTHER = 'another-holder';

    public ?int $refreshes = null;

    public int $calls = 0;

    private mixed $value = null;

    public function __construct()
    {
    }

    #[\Override]
    public function set(string $key, mixed $value, mixed $options = null): bool
    {
        if ($this->value !== null) {
            return false;
        }

        $this->value = $value;

        return true;
    }

    #[\Override]
    public function get(string $key): mixed
    {
        return $this->value;
    }

    /**
     * Answers the refresh and release scripts: both act only while the key
     * still holds the caller's token.
     *
     * @param array<int, string> $args
     */
    #[\Override]
    public function eval(string $script, array $args = [], int $num_keys = 0): mixed
    {
        if (\str_contains($script, 'expire')) {
            $this->calls++;

            if ($this->refreshes !== null && $this->calls > $this->refreshes) {
                $this->value = self::OTHER;
            }
        }

        if ($this->value !== ($args[1] ?? null)) {
            return 0;
        }

        if (\str_contains($script, 'del')) {
            $this->value = null;
        }

        return 1;
    }
}
