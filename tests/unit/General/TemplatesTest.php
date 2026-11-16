<?php

declare(strict_types=1);

namespace Tests\Unit\General;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Config\Config;

final class TemplatesTest extends TestCase
{
    /**
     * @return \Iterator<string, array{string}>
     */
    public static function catalogs(): \Iterator
    {
        yield 'functions' => ['templates-function'];
        yield 'sites' => ['templates-site'];
    }

    /**
     * The setup wizards classify a template variable from the catalog entry, so
     * a credential input left unmarked is stored as a readable variable and
     * handed back in clear text to anything holding `functions.read` or
     * `sites.read`.
     */
    #[DataProvider('catalogs')]
    public function testPasswordVariablesAreSecret(string $config): void
    {
        $templates = Config::getParam($config, []);

        $this->assertNotEmpty($templates);

        $passwords = 0;

        foreach ($templates as $template) {
            foreach ($template['variables'] ?? [] as $variable) {
                if (($variable['type'] ?? '') !== 'password') {
                    continue;
                }

                $passwords++;

                $this->assertTrue($variable['secret'] ?? false, ($template['id'] ?? $template['key']) . ': ' . $variable['name']);
            }
        }

        $this->assertGreaterThan(0, $passwords);
    }
}
