<?php

declare(strict_types=1);

namespace Tests\Unit\Migration\Version;

use Appwrite\Migration\Version\V23;
use PHPUnit\Framework\TestCase;
use Utopia\Config\Config;
use Utopia\Database\Attribute;

final class V23Test extends TestCase
{
    private mixed $collections;

    protected function setUp(): void
    {
        $this->collections = Config::getParam('collections');
    }

    protected function tearDown(): void
    {
        Config::setParam('collections', $this->collections);
    }

    public function testErrorsSizeIsReadFromTheConfiguredAttributeModel(): void
    {
        self::configure([
            Attribute::string(key: 'status'),
            Attribute::string(key: 'errors', size: 2_000_000, array: true),
        ]);

        $this->assertSame(2_000_000, self::errorsSize(), 'the configured size, not the fallback, is the size V23 migrates to');
    }

    public function testErrorsSizeFallsBackWhenTheAttributeIsNotConfigured(): void
    {
        self::configure([Attribute::string(key: 'status')]);

        $this->assertSame(1_000_000, self::errorsSize());
    }

    /**
     * @param list<Attribute> $attributes
     */
    private static function configure(array $attributes): void
    {
        Config::setParam('collections', ['projects' => ['migrations' => ['attributes' => $attributes]]]);
    }

    private static function errorsSize(): int
    {
        $migration = new class () extends V23 {
            public function __construct()
            {
            }

            public function errorsSize(): int
            {
                return self::configuredErrorsSize();
            }
        };

        return $migration->errorsSize();
    }
}
