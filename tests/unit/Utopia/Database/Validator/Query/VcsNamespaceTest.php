<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Validator\Query;

use Appwrite\Utopia\Database\Validator\Query\VcsNamespace;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Query;

final class VcsNamespaceTest extends TestCase
{
    public function testIsValid(): void
    {
        $validator = new VcsNamespace();

        $this->assertTrue($validator->isValid(Query::equal('namespace', ['appwrite'])), $validator->getDescription());
    }

    public function testInvalidNotQuery(): void
    {
        $validator = new VcsNamespace();

        $this->assertFalse($validator->isValid('namespace'));
    }

    public function testInvalidMethod(): void
    {
        $validator = new VcsNamespace();

        $this->assertFalse($validator->isValid(Query::greaterThan('namespace', 'appwrite')));
    }

    public function testInvalidAttribute(): void
    {
        $validator = new VcsNamespace();

        $this->assertFalse($validator->isValid(Query::equal('name', ['appwrite'])));
    }

    public function testInvalidMultipleValues(): void
    {
        $validator = new VcsNamespace();

        $this->assertFalse($validator->isValid(Query::equal('namespace', ['appwrite', 'utopia'])));
    }

    public function testInvalidValueTooLong(): void
    {
        $validator = new VcsNamespace();

        $this->assertFalse($validator->isValid(Query::equal('namespace', [\str_repeat('a', 257)])));
    }
}
