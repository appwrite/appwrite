<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Hook;

use Appwrite\Utopia\Database\Hook\UniqueId;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Hook\Permissions;
use Utopia\Database\Hook\Relationships;
use Utopia\Database\Permission;
use Utopia\Database\Relationship;
use Utopia\Database\RelationshipDeleteAction;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;

final class UniqueIdTest extends TestCase
{
    private const string UNIQUE = 'unique()';
    private const int GENERATED_LENGTH = 20;

    public function testPlaceholderGetsAGeneratedId(): void
    {
        $prepared = (new UniqueId())->prepare(new Document(), new Document(['$id' => self::UNIQUE, 'name' => 'Artist']));

        $this->assertGenerated($prepared->getId());
        $this->assertSame('Artist', $prepared->getAttribute('name'));
    }

    public function testMissingIdGetsAGeneratedId(): void
    {
        $this->assertGenerated((new UniqueId())->prepare(new Document(), new Document(['name' => 'Artist']))->getId());
    }

    public function testExplicitIdIsKept(): void
    {
        $this->assertSame('artist1', (new UniqueId())->prepare(new Document(), new Document(['$id' => 'artist1']))->getId());
    }

    public function testPreparingItsOwnOutputKeepsTheId(): void
    {
        $hook = new UniqueId();
        $first = $hook->prepare(new Document(), new Document(['$id' => self::UNIQUE]));
        $id = $first->getId();

        $this->assertSame($id, $hook->prepare(new Document(), $first)->getId(), 'a pre-check and the write must relate the same document');
    }

    public function testNestedPlaceholdersAreStoredUnderGeneratedIdsAtEveryDepth(): void
    {
        $database = $this->database();

        $database->createDocument('albums', new Document([
            '$id' => 'album1',
            'artist' => [
                '$id' => self::UNIQUE,
                'name' => 'Artist',
                'label' => ['$id' => self::UNIQUE, 'name' => 'Label'],
            ],
        ]));

        $album = $database->getDocument('albums', 'album1');
        $artist = $album->getAttribute('artist');
        $this->assertInstanceOf(Document::class, $artist);
        $this->assertGenerated($artist->getId());
        $label = $artist->getAttribute('label');
        $this->assertInstanceOf(Document::class, $label);
        $this->assertGenerated($label->getId());
        $this->assertTrue($database->getDocument('artists', self::UNIQUE)->isEmpty(), 'no related document is stored under the placeholder');
    }

    public function testEachWriteCreatesItsOwnRelatedDocument(): void
    {
        $database = $this->database();

        foreach (['album1', 'album2'] as $albumId) {
            $database->createDocument('albums', new Document([
                '$id' => $albumId,
                'artist' => ['$id' => self::UNIQUE, 'name' => $albumId],
            ]));
        }

        $first = $database->getDocument('albums', 'album1')->getAttribute('artist');
        $second = $database->getDocument('albums', 'album2')->getAttribute('artist');
        $this->assertInstanceOf(Document::class, $first);
        $this->assertInstanceOf(Document::class, $second);
        $this->assertNotSame($first->getId(), $second->getId(), 'a second write must not overwrite the first related document');
        $this->assertSame('album1', $first->getAttribute('name'));
        $this->assertSame('album2', $second->getAttribute('name'));
    }

    public function testUpdateRelatesANewDocumentUnderAGeneratedId(): void
    {
        $database = $this->database();
        $database->createDocument('albums', new Document(['$id' => 'album1']));

        $database->updateDocument('albums', 'album1', new Document([
            'artist' => ['$id' => self::UNIQUE, 'name' => 'Artist'],
        ]));

        $artist = $database->getDocument('albums', 'album1')->getAttribute('artist');
        $this->assertInstanceOf(Document::class, $artist);
        $this->assertGenerated($artist->getId());
    }

    private function database(): Database
    {
        $authorization = new Authorization();
        $authorization->disable();
        $database = (new Database(new Memory(), new Cache(new None())))
            ->setAuthorization($authorization)
            ->setDatabase('unique')
            ->setNamespace('unique_' . \uniqid());
        $database->addHook(new Permissions());
        $database->addHook(new Relationships());
        $database->addHook(new UniqueId());

        $database->create();
        $permissions = [Permission::create(Role::any()), Permission::read(Role::any()), Permission::update(Role::any())];
        foreach (['albums', 'artists', 'labels'] as $collection) {
            $database->createCollection(Collection::create($collection, attributes: [Attribute::string('name', size: 64, required: false)], permissions: $permissions));
        }
        $database->createRelationship('albums', Relationship::manyToOne('artists', 'artist', onDelete: RelationshipDeleteAction::SetNull));
        $database->createRelationship('artists', Relationship::manyToOne('labels', 'label', onDelete: RelationshipDeleteAction::SetNull));

        return $database;
    }

    private function assertGenerated(string $id): void
    {
        $this->assertNotSame(self::UNIQUE, $id, 'the placeholder must be replaced');
        $this->assertSame(self::GENERATED_LENGTH, \strlen($id), 'the ID must be generated');
        $this->assertTrue(\ctype_xdigit($id), 'the ID must be generated');
    }
}
