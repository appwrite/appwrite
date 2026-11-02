<?php

declare(strict_types=1);

namespace Tests\Unit\General;

use PHPUnit\Framework\TestCase;
use Utopia\Config\Config;
use Utopia\Database\Attribute;
use Utopia\Query\Schema\ColumnType;

final class CollectionFloatTypesTest extends TestCase
{
    public function testSessionsGeoAttributesKeepTheTypeExistingProjectsStore(): void
    {
        foreach (['projects', 'console'] as $scope) {
            $attributes = $this->attributes($scope, 'sessions');

            foreach (['latitude', 'longitude'] as $key) {
                $this->assertSame(
                    ColumnType::Double,
                    $attributes[$key]->type,
                    "{$scope}.sessions.{$key} must keep the 'double' type every existing install stores in its metadata"
                );
            }
        }
    }

    public function testNoCollectionDeclaresTheFloatColumnType(): void
    {
        $floats = [];

        foreach (Config::getParam('collections', []) as $scope => $collections) {
            foreach ($collections as $id => $collection) {
                foreach ($collection['attributes'] ?? [] as $attribute) {
                    if ($attribute->type === ColumnType::Float) {
                        $floats[] = "{$scope}.{$id}.{$attribute->key}";
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $floats,
            'Every floating-point attribute was stored as \'double\' before the query library, so a config that declares \'float\' disagrees with the metadata of every existing install'
        );
    }

    /**
     * @return array<string, Attribute>
     */
    private function attributes(string $scope, string $collection): array
    {
        $attributes = [];

        foreach (Config::getParam('collections', [])[$scope][$collection]['attributes'] as $attribute) {
            $attributes[$attribute->key] = $attribute;
        }

        return $attributes;
    }
}
