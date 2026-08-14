<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Validator\Queries;

use Appwrite\Utopia\Database\Validator\Queries\Presences;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Query;

final class BaseTest extends TestCase
{
    public function testQueryAtMaxValuesIsValid(): void
    {
        $validator = new Presences();
        $values = \array_fill(0, APP_DATABASE_QUERY_MAX_VALUES, 'user');

        $this->assertTrue(
            $validator->isValid([Query::equal('userId', $values)]),
            $validator->getDescription()
        );
    }

    public function testQueryOverMaxValuesIsInvalid(): void
    {
        $validator = new Presences();
        $values = \array_fill(0, APP_DATABASE_QUERY_MAX_VALUES + 1, 'user');

        $this->assertFalse(
            $validator->isValid([Query::equal('userId', $values)]),
            'Query with ' . \count($values) . ' values must exceed the ' . APP_DATABASE_QUERY_MAX_VALUES . ' value cap'
        );
        $this->assertStringContainsString(
            'greater than ' . APP_DATABASE_QUERY_MAX_VALUES . ' values',
            $validator->getDescription(),
            'Rejection must be reported against the configured cap, not the validator default'
        );
    }

    public function testSequenceTypedAttributeIsValidated(): void
    {
        $validator = new Presences();

        $this->assertTrue(
            $validator->isValid([Query::equal('userInternalId', [1])]),
            $validator->getDescription()
        );
        $this->assertFalse(
            $validator->isValid([Query::equal('userInternalId', [1.5])]),
            'Fractional value must not validate against a sequence attribute'
        );
    }
}
