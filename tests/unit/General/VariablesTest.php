<?php

declare(strict_types=1);

namespace Tests\Unit\General;

use PHPUnit\Framework\TestCase;

final class VariablesTest extends TestCase
{
    public function testBuildTimeoutDefaultsToFortyFiveMinutes(): void
    {
        $variable = $this->find('_APP_COMPUTE_BUILD_TIMEOUT');

        $this->assertSame('2700', $variable['default'], 'The installer writes this default into .env for new self-hosted installs; it must stay 2700 seconds as on main.');
        $this->assertStringContainsString('The default value is 2700 seconds.', $variable['description']);
    }

    /**
     * @return array<string, mixed>
     */
    private function find(string $name): array
    {
        $categories = require __DIR__ . '/../../../app/config/variables.php';

        foreach ($categories as $category) {
            foreach ($category['variables'] as $variable) {
                if ($variable['name'] === $name) {
                    return $variable;
                }
            }
        }

        $this->fail("Variable {$name} is missing from app/config/variables.php");
    }
}
