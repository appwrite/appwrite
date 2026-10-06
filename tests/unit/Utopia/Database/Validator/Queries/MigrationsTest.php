<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Validator\Queries;

use Appwrite\Utopia\Database\Validator\Queries\Migrations;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Query;

final class MigrationsTest extends TestCase
{
    public function testCanonicalResourceFieldsCanBeQueried(): void
    {
        $validator = new Migrations();

        foreach ([
            'resourceId',
            'resourceInternalId',
            'resourceType',
            'parentResourceId',
            'parentResourceInternalId',
            'parentResourceType',
            'destinationResourceId',
            'destinationResourceInternalId',
            'destinationResourceType',
        ] as $attribute) {
            $query = Query::equal($attribute, ['value']);

            $this->assertTrue($validator->isValid([$query]), $validator->getDescription());
        }
    }
}
