<?php

declare(strict_types=1);

namespace Tests\Unit\General;

use Appwrite\Utopia\Database\Validator\Queries\Executions;
use Appwrite\Utopia\Database\Validator\Queries\Logs;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Query;

final class CollectionsTest extends TestCase
{
    protected array $collections;

    public function setUp(): void
    {
        $this->collections = require('app/config/collections.php');
    }

    public function testDuplicateRules(): void
    {
        foreach ($this->collections as $key => $sections) {
            foreach ($sections as $key => $collection) {
                if (array_key_exists('attributes', $collection)) {
                    foreach ($collection['attributes'] as $check) {
                        $occurrences = 0;
                        foreach ($collection['attributes'] as $attribute) {
                            if ($attribute['$id'] == $check['$id']) {
                                $occurrences++;
                            }
                        }
                        $this->assertSame(1, $occurrences);
                    }
                }
            }
        }
    }

    /**
     * columnSecurity has to stay optional with a false default on every schema that
     * declares it.
     *
     * Required would break the fleet, not this test: a collections row written before
     * the attribute existed carries no value for it, so marking it required makes the
     * next write of such a row invalid -- on every database the migration has not
     * reached. documentSecurity beside it is required only because it predates every
     * row that exists.
     *
     * The default has to be false rather than null so a row written after the attribute
     * exists is distinguishable from one that predates it. The backfill relies on that:
     * "IS NULL" has to mean "predates the attribute", not "nobody set it".
     */
    public function testColumnSecurityIsOptional(): void
    {
        foreach (['databases', 'vectorsdb'] as $schema) {
            $attributes = $this->collections[$schema]['collections']['attributes'];

            $index = \array_search('columnSecurity', \array_column($attributes, '$id'), true);
            $this->assertNotFalse($index, "{$schema} does not declare columnSecurity");

            $attribute = $attributes[$index];

            $this->assertSame(false, $attribute['required'], "columnSecurity must stay optional in {$schema}");
            $this->assertSame(false, $attribute['default'], "columnSecurity must default to false in {$schema}");
        }
    }

    public function testExecutionQueryValidatorsDoNotNeedCollectionSchema(): void
    {
        $this->assertTrue((new Executions())->isValid([
            Query::equal('status', ['completed']),
        ]));
        $this->assertTrue((new Logs())->isValid([
            Query::equal('status', ['completed']),
        ]));
    }
}
