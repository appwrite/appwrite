<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Validator\Queries;

use Appwrite\Utopia\Database\Validator\Queries\Base;
use Appwrite\Utopia\Database\Validator\Queries\DistributionApps;
use Appwrite\Utopia\Database\Validator\Queries\DistributionArtifacts;
use Appwrite\Utopia\Database\Validator\Queries\DistributionBuilds;
use Appwrite\Utopia\Database\Validator\Queries\DistributionConnections;
use Appwrite\Utopia\Database\Validator\Queries\DistributionListings;
use Appwrite\Utopia\Database\Validator\Queries\DistributionLogs;
use Appwrite\Utopia\Database\Validator\Queries\DistributionReleases;
use Appwrite\Utopia\Database\Validator\Queries\DistributionSigningIdentities;
use Appwrite\Utopia\Database\Validator\Queries\DistributionSubmissions;
use Appwrite\Utopia\Database\Validator\Queries\DistributionTargets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Config\Config;
use Utopia\Database\Attribute;
use Utopia\Database\Query;

final class DistributionTest extends TestCase
{
    /**
     * @return \Iterator<string, array{Base, list<string>, string}>
     */
    public static function validators(): \Iterator
    {
        yield 'apps' => [new DistributionApps(), DistributionApps::ALLOWED_ATTRIBUTES, 'distributionApps'];
        yield 'targets' => [new DistributionTargets(), DistributionTargets::ALLOWED_ATTRIBUTES, 'distributionTargets'];
        yield 'connections' => [new DistributionConnections(), DistributionConnections::ALLOWED_ATTRIBUTES, 'distributionConnections'];
        yield 'signingIdentities' => [new DistributionSigningIdentities(), DistributionSigningIdentities::ALLOWED_ATTRIBUTES, 'distributionSigningIdentities'];
        yield 'builds' => [new DistributionBuilds(), DistributionBuilds::ALLOWED_ATTRIBUTES, 'distributionBuilds'];
        yield 'logs' => [new DistributionLogs(), DistributionLogs::ALLOWED_ATTRIBUTES, 'distributionLogs'];
        yield 'artifacts' => [new DistributionArtifacts(), DistributionArtifacts::ALLOWED_ATTRIBUTES, 'distributionArtifacts'];
        yield 'releases' => [new DistributionReleases(), DistributionReleases::ALLOWED_ATTRIBUTES, 'distributionReleases'];
        yield 'submissions' => [new DistributionSubmissions(), DistributionSubmissions::ALLOWED_ATTRIBUTES, 'distributionSubmissions'];
        yield 'listings' => [new DistributionListings(), DistributionListings::ALLOWED_ATTRIBUTES, 'distributionListings'];
    }

    /**
     * @param list<string> $attributes
     */
    #[DataProvider('validators')]
    public function testAllowedAttributesExist(Base $validator, array $attributes, string $collection): void
    {
        $keys = \array_map(
            fn (Attribute $attribute): string => $attribute->key,
            Config::getParam('collections')['projects'][$collection]['attributes']
        );

        foreach ($attributes as $attribute) {
            $this->assertContains($attribute, $keys, "'{$attribute}' is not an attribute of '{$collection}'");
        }
    }

    /**
     * @param list<string> $attributes
     */
    #[DataProvider('validators')]
    public function testFirstAllowedAttributeIsQueryable(Base $validator, array $attributes, string $collection): void
    {
        $query = Query::equal($attributes[0], ['value']);

        $this->assertTrue($validator->isValid([$query]), $validator->getDescription());
    }
}
