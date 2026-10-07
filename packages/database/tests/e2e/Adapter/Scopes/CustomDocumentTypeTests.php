<?php

namespace Tests\E2E\Adapter\Scopes;

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;

// Test custom document classes
class TestUser extends Document
{
    public function getEmail(): string
    {
        return $this->getAttribute('email', '');
    }

    public function getName(): string
    {
        return $this->getAttribute('name', '');
    }

    public function isActive(): bool
    {
        return $this->getAttribute('status') === 'active';
    }
}

class TestPost extends Document
{
    public function getTitle(): string
    {
        return $this->getAttribute('title', '');
    }

    public function getContent(): string
    {
        return $this->getAttribute('content', '');
    }
}

trait CustomDocumentTypeTests
{
    public function testSetDocumentType(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        $database->setDocumentType('users', TestUser::class);

        $this->assertEquals(
            TestUser::class,
            $database->getDocumentType('users')
        );

        // Cleanup
        $database->clearDocumentType('users');
    }

    public function testGetDocumentTypeReturnsNull(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        $this->assertNull($database->getDocumentType('nonexistent_collection'));

        // No cleanup needed - no types were set
    }

    public function testSetDocumentTypeWithInvalidClass(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('does not exist');

        // @phpstan-ignore-next-line - Testing with invalid class name
        $database->setDocumentType('users', 'NonExistentClass');
    }    public function testSetDocumentTypeWithNonDocumentClass(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('must extend');

        // @phpstan-ignore-next-line - Testing with non-Document class
        $database->setDocumentType('users', \stdClass::class);
    }

    public function testClearDocumentType(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        $database->setDocumentType('users', TestUser::class);
        $this->assertEquals(TestUser::class, $database->getDocumentType('users'));

        $database->clearDocumentType('users');
        $this->assertNull($database->getDocumentType('users'));
    }

    public function testClearAllDocumentTypes(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        $database->setDocumentType('users', TestUser::class);
        $database->setDocumentType('posts', TestPost::class);

        $this->assertEquals(TestUser::class, $database->getDocumentType('users'));
        $this->assertEquals(TestPost::class, $database->getDocumentType('posts'));

        $database->clearAllDocumentTypes();

        $this->assertNull($database->getDocumentType('users'));
        $this->assertNull($database->getDocumentType('posts'));
    }

    public function testMethodChaining(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        $result = $database->setDocumentType('users', TestUser::class);

        $this->assertInstanceOf(Database::class, $result);

        $database
            ->setDocumentType('users', TestUser::class)
            ->setDocumentType('posts', TestPost::class);

        $this->assertEquals(TestUser::class, $database->getDocumentType('users'));
        $this->assertEquals(TestPost::class, $database->getDocumentType('posts'));

        // Cleanup to prevent test pollution
        $database->clearAllDocumentTypes();
    }

    public function testCustomDocumentTypeWithGetDocument(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        // Create collection
        $database->createCollection('customUsers', permissions: [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any()),
        ]);

        $database->createAttribute('customUsers', 'email', Database::VAR_STRING, 255, true);
        $database->createAttribute('customUsers', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('customUsers', 'status', Database::VAR_STRING, 50, true);

        $database->setDocumentType('customUsers', TestUser::class);

        /** @var TestUser $created */
        $created = $database->createDocument('customUsers', new Document([
            '$id' => ID::unique(),
            'email' => 'test@example.com',
            'name' => 'Test User',
            'status' => 'active',
            '$permissions' => [Permission::read(Role::any())],
        ]));

        // Verify it's a TestUser instance
        $this->assertInstanceOf(TestUser::class, $created);
        $this->assertEquals('test@example.com', $created->getEmail());
        $this->assertEquals('Test User', $created->getName());
        $this->assertTrue($created->isActive());

        // Get document and verify type
        /** @var TestUser $fetched */
        $fetched = $database->getDocument('customUsers', $created->getId());
        $this->assertInstanceOf(TestUser::class, $fetched);
        $this->assertEquals('test@example.com', $fetched->getEmail());
        $this->assertTrue($fetched->isActive());

        // Cleanup
        $database->deleteCollection('customUsers');
        $database->clearDocumentType('customUsers');
    }

    public function testCustomDocumentTypeWithFind(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        // Create collection
        $database->createCollection('customPosts', permissions: [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
        ]);

        $database->createAttribute('customPosts', 'title', Database::VAR_STRING, 255, true);
        $database->createAttribute('customPosts', 'content', Database::VAR_STRING, 5000, true);

        // Register custom type
        $database->setDocumentType('customPosts', TestPost::class);

        // Create multiple documents
        $post1 = $database->createDocument('customPosts', new Document([
            '$id' => ID::unique(),
            'title' => 'First Post',
            'content' => 'This is the first post',
            '$permissions' => [Permission::read(Role::any())],
        ]));

        $post2 = $database->createDocument('customPosts', new Document([
            '$id' => ID::unique(),
            'title' => 'Second Post',
            'content' => 'This is the second post',
            '$permissions' => [Permission::read(Role::any())],
        ]));

        // Find documents
        /** @var TestPost[] $posts */
        $posts = $database->find('customPosts', [Query::limit(10)]);

        $this->assertCount(2, $posts);
        $this->assertInstanceOf(TestPost::class, $posts[0]);
        $this->assertInstanceOf(TestPost::class, $posts[1]);
        $this->assertEquals('First Post', $posts[0]->getTitle());
        $this->assertEquals('Second Post', $posts[1]->getTitle());

        // Cleanup
        $database->deleteCollection('customPosts');
        $database->clearDocumentType('customPosts');
    }

    public function testCustomDocumentTypeWithUpdateDocument(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        // Create collection
        $database->createCollection('customUsersUpdate', permissions: [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
            Permission::update(Role::any()),
        ]);

        $database->createAttribute('customUsersUpdate', 'email', Database::VAR_STRING, 255, true);
        $database->createAttribute('customUsersUpdate', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('customUsersUpdate', 'status', Database::VAR_STRING, 50, true);

        // Register custom type
        $database->setDocumentType('customUsersUpdate', TestUser::class);

        // Create document
        /** @var TestUser $created */
        $created = $database->createDocument('customUsersUpdate', new Document([
            '$id' => ID::unique(),
            'email' => 'original@example.com',
            'name' => 'Original Name',
            'status' => 'active',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
        ]));

        // Update document
        /** @var TestUser $updated */
        $updated = $database->updateDocument('customUsersUpdate', $created->getId(), new Document([
            '$id' => $created->getId(),
            'email' => 'updated@example.com',
            'name' => 'Updated Name',
            'status' => 'inactive',
        ]));

        // Verify it's still TestUser and has updated values
        $this->assertInstanceOf(TestUser::class, $updated);
        $this->assertEquals('updated@example.com', $updated->getEmail());
        $this->assertEquals('Updated Name', $updated->getName());
        $this->assertFalse($updated->isActive());

        // Cleanup
        $database->deleteCollection('customUsersUpdate');
        $database->clearDocumentType('customUsersUpdate');
    }

    public function testDefaultDocumentForUnmappedCollection(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        // Create collection without custom type
        $database->createCollection('unmappedCollection', permissions: [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
        ]);

        $database->createAttribute('unmappedCollection', 'data', Database::VAR_STRING, 255, true);

        // Create document
        $created = $database->createDocument('unmappedCollection', new Document([
            '$id' => ID::unique(),
            'data' => 'test data',
            '$permissions' => [Permission::read(Role::any())],
        ]));

        // Should be regular Document, not custom type
        $this->assertInstanceOf(Document::class, $created);
        $this->assertNotInstanceOf(TestUser::class, $created);

        // Cleanup
        $database->deleteCollection('unmappedCollection');
    }
}
