<?php

namespace Utopia\Tests\Unit\Destinations;

use Appwrite\Services\Databases;
use Appwrite\Services\TablesDB;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Utopia\Migration\Destinations\Appwrite as AppwriteDestination;

/**
 * Lock-in for the SDK boundary that drives ATTRIBUTE_IMMUTABLE_FIELDS.
 *
 * Reflects on appwrite/appwrite's TablesDB + Databases services. If the SDK
 * ships a new updateXColumn / updateXAttribute endpoint that exposes a
 * previously-non-SDK field, this test fails so DestinationAppwrite's
 * ATTRIBUTE_IMMUTABLE_FIELDS constant can be synced. Catches drift in CI
 * rather than at runtime.
 */
class AppwriteAttributeSdkBoundaryTest extends TestCase
{
    /** Routing/identity params that aren't mutable column state. */
    private const IGNORED_PARAMS = ['databaseId', 'tableId', 'collectionId', 'key', 'newKey'];

    /**
     * SDK param name → meta-doc field name. Param names that don't appear on
     * the meta doc as a top-level key map to null (e.g. min/max/elements live
     * inside formatOptions; onDelete lives inside the relationship's options).
     *
     * @var array<string, string|null>
     */
    private const PARAM_TO_META_FIELD = [
        'xdefault' => 'default',
        'min' => null,
        'max' => null,
        'elements' => null,
        'onDelete' => null,
    ];

    public function testSdkExposedFieldsAreNotInNonSdkConstant(): void
    {
        $sdkExposed = $this->collectSdkExposedMetaFields();
        $nonSdk = $this->readNonSdkConstant();

        $overlap = \array_intersect($sdkExposed, $nonSdk);
        $this->assertSame(
            [],
            \array_values($overlap),
            'ATTRIBUTE_IMMUTABLE_FIELDS marks these fields as non-SDK, but the appwrite/appwrite '
            . 'SDK exposes them via updateXColumn/updateXAttribute: ' . \implode(', ', $overlap)
            . '. Either drop these from ATTRIBUTE_IMMUTABLE_FIELDS or update PARAM_TO_META_FIELD '
            . 'in this test if the SDK param doesn\'t correspond to a top-level meta-doc field.',
        );
    }

    /**
     * @return list<string> meta-doc field names the SDK can mutate
     */
    private function collectSdkExposedMetaFields(): array
    {
        $fields = [];
        foreach ([TablesDB::class, Databases::class] as $service) {
            $service = new ReflectionClass($service);
            foreach ($service->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if (!\preg_match('/^update.+(Column|Attribute)$/', $method->getName())) {
                    continue;
                }
                foreach ($method->getParameters() as $param) {
                    $name = $param->getName();
                    if (\in_array($name, self::IGNORED_PARAMS, true)) {
                        continue;
                    }
                    $mapped = \array_key_exists($name, self::PARAM_TO_META_FIELD)
                        ? self::PARAM_TO_META_FIELD[$name]
                        : $name;
                    if ($mapped === null) {
                        continue;
                    }
                    $fields[$mapped] = true;
                }
            }
        }
        return \array_keys($fields);
    }

    /**
     * @return list<string>
     */
    private function readNonSdkConstant(): array
    {
        /** @var list<string> $value */
        $value = (new ReflectionClass(AppwriteDestination::class))->getConstant('ATTRIBUTE_IMMUTABLE_FIELDS');
        return $value;
    }
}
