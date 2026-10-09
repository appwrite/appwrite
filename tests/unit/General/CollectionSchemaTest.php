<?php

declare(strict_types=1);

namespace Tests\Unit\General;

use PHPUnit\Framework\TestCase;
use Utopia\Config\Config;
use Utopia\Database\Attribute;
use Utopia\Database\Filter;
use Utopia\Database\IntegerWidth;
use Utopia\Query\Schema\ColumnType;

final class CollectionSchemaTest extends TestCase
{
    private const array ATTRIBUTE_GROUPS = ['attributes', 'defaultAttributes'];

    private const array BITS64_INTEGERS = [
        'buckets.files.sizeActual',
        'buckets.files.sizeOriginal',
        'console.buckets.maximumFileSize',
        'console.users.photoSize',
        'projects.buckets.maximumFileSize',
        'projects.deployments.buildSize',
        'projects.deployments.sourceSize',
        'projects.deployments.totalSize',
        'projects.users.photoSize',
    ];

    public function testVectorAttributesDeclareTheirDimensions(): void
    {
        $missing = [];

        foreach ($this->attributes() as $path => $attribute) {
            if ($attribute->type === ColumnType::Vector && ($attribute->size ?? 0) < 1) {
                $missing[] = $path;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'A vector column cannot be created without its dimensions, so a vector whose dimensions are only known per collection is built where they are known'
        );
    }

    public function testDatetimeAttributesKeepTheStoredDatetimeShape(): void
    {
        $datetimes = 0;

        foreach ($this->attributes() as $path => $attribute) {
            if ($attribute->type !== ColumnType::Datetime) {
                continue;
            }

            $datetimes++;
            $this->assertContains(Filter::Datetime->value, $attribute->filters, "{$path} must keep the datetime filter that converts stored values");
            $this->assertFalse($attribute->signed, "{$path} must stay unsigned, as every existing install stores it");
            $this->assertNull($attribute->size, "{$path} must not declare a size");
        }

        $this->assertGreaterThan(0, $datetimes);
    }

    public function testFileSizeIntegersStaySixtyFourBit(): void
    {
        $attributes = $this->attributes();

        foreach (self::BITS64_INTEGERS as $path) {
            $this->assertArrayHasKey($path, $attributes);
            $this->assertSame(ColumnType::Integer, $attributes[$path]->type, "{$path} must stay an integer");
            $this->assertSame(IntegerWidth::Bits64, $attributes[$path]->width(), "{$path} must keep the 8-byte width existing installs store");
        }
    }

    /**
     * @return array<string, Attribute>
     */
    private function attributes(): array
    {
        $attributes = [];

        foreach (Config::getParam('collections', []) as $scope => $collections) {
            foreach ($collections as $id => $collection) {
                foreach (self::ATTRIBUTE_GROUPS as $group) {
                    foreach ($collection[$group] ?? [] as $attribute) {
                        $attributes["{$scope}.{$id}.{$attribute->key}"] = $attribute;
                    }
                }
            }
        }

        return $attributes;
    }
}
