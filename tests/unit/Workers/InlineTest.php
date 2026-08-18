<?php

declare(strict_types=1);

namespace Tests\Unit\Workers;

use Appwrite\Workers\Inline;
use PHPUnit\Framework\TestCase;

final class InlineTest extends TestCase
{
    public function testEnabledReadsQueueAdapterEnv(): void
    {
        $this->assertTrue(Inline::enabled($this->env(['_APP_QUEUE_ADAPTER' => 'inline'])));
        $this->assertFalse(Inline::enabled($this->env(['_APP_QUEUE_ADAPTER' => 'redis'])));
        $this->assertFalse(Inline::enabled($this->env([])));
    }

    /**
     * @param array<string, string> $values
     * @return callable(string, mixed=): mixed
     */
    private function env(array $values): callable
    {
        return static function (string $key, mixed $default = null) use ($values): mixed {
            return $values[$key] ?? $default;
        };
    }
}
