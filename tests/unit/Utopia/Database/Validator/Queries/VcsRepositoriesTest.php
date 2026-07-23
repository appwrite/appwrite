<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Validator\Queries;

use Appwrite\Utopia\Database\Validator\Queries\VcsRepositories;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Query;

final class VcsRepositoriesTest extends TestCase
{
    public function testEmptyQueries(): void
    {
        $validator = new VcsRepositories();

        $this->assertTrue($validator->isValid([]), $validator->getDescription());
    }

    public function testValidQueries(): void
    {
        $validator = new VcsRepositories();

        $this->assertTrue($validator->isValid([Query::limit(25)]), $validator->getDescription());
        $this->assertTrue($validator->isValid([Query::offset(0)]), $validator->getDescription());
        $this->assertTrue($validator->isValid([Query::equal('namespace', ['appwrite'])]), $validator->getDescription());
        $this->assertTrue($validator->isValid([
            Query::limit(25),
            Query::offset(0),
            Query::equal('namespace', ['appwrite']),
        ]), $validator->getDescription());
    }

    public function testInvalidQueries(): void
    {
        $validator = new VcsRepositories();

        $this->assertFalse($validator->isValid([Query::equal('name', ['appwrite'])]));
        $this->assertFalse($validator->isValid([Query::greaterThan('namespace', 'appwrite')]));
    }
}
