<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Memory as CacheMemory;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory as DatabaseMemory;
use Utopia\Database\Database;
use Utopia\Database\Document;

class TypedUser extends Document
{
}

class EmptyDocumentTypeTest extends TestCase
{
    /**
     * A caller that passes the type hint of the mapped class along (an HTTP
     * resource returning the current user, say) must be able to rely on every
     * empty result being that class, including the one for an empty id, which
     * short-circuits before the collection is even looked up.
     */
    public function testEmptyIdReturnsTheMappedDocumentType(): void
    {
        $database = new Database(new DatabaseMemory(), new Cache(new CacheMemory()));
        $database
            ->setDatabase('utopiaTests')
            ->setNamespace('empty_type_' . \uniqid());
        $database->create();
        $database->createCollection('users');
        $database->setDocumentType('users', TypedUser::class);

        $empty = $database->getDocument('users', '');

        $this->assertInstanceOf(TypedUser::class, $empty);
        $this->assertTrue($empty->isEmpty());

        $missing = $database->getDocument('users', 'nobody');

        $this->assertInstanceOf(TypedUser::class, $missing);
        $this->assertTrue($missing->isEmpty());
    }

    public function testEmptyIdOnAnUnmappedCollectionStaysAPlainDocument(): void
    {
        $database = new Database(new DatabaseMemory(), new Cache(new CacheMemory()));

        $empty = $database->getDocument('anything', '');

        $this->assertSame(Document::class, $empty::class);
        $this->assertTrue($empty->isEmpty());
    }
}
