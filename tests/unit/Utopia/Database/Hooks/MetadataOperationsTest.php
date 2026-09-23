<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Hooks;

use Appwrite\Usage\Operations;
use Appwrite\Utopia\Database\Hooks\Metadata;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Hook\Permissions;
use Utopia\Database\Hook\Relationships;
use Utopia\Database\Query;
use Utopia\Database\Relationship;
use Utopia\Database\RelationType;
use Utopia\Database\Validator\Authorization;

final class MetadataOperationsTest extends TestCase
{
    private Database $database;

    protected function setUp(): void
    {
        $authorization = new Authorization();
        $authorization->addRole(Role::any()->toString());

        $this->database = (new Database(new Memory(), new Cache(new NoCache())))
            ->setDatabase('metadata')
            ->setNamespace('operations')
            ->setAuthorization($authorization);

        $authorization->skip(function (): void {
            $this->database->create();
            $permissions = [Permission::read(Role::any()), Permission::create(Role::any()), Permission::update(Role::any())];
            foreach (['albums', 'tracks', 'genres'] as $collection) {
                $this->database->createCollection(new Collection(
                    id: $collection,
                    attributes: [Attribute::string(key: 'name', size: 100, required: false)],
                    permissions: $permissions,
                ));
            }
            $this->database->addHook(new Permissions());
            $this->database->addHook(new Relationships($this->database));
            $this->database->createRelationship(new Relationship(
                collection: 'albums',
                relatedCollection: 'tracks',
                type: RelationType::OneToMany,
                twoWay: true,
                key: 'tracks',
                twoWayKey: 'album',
            ));
            $this->database->createRelationship(new Relationship(
                collection: 'tracks',
                relatedCollection: 'genres',
                type: RelationType::ManyToMany,
                key: 'genres',
            ));
            $this->database->createDocument('genres', new Document(['$id' => 'rock']));
            $this->database->createDocument('albums', new Document([
                '$id' => 'first',
                'tracks' => [['$id' => 'track1', 'genres' => ['rock']], ['$id' => 'track2']],
            ]));
            $this->database->createDocument('albums', new Document(['$id' => 'second', 'tracks' => [['$id' => 'track3']]]));
        });
    }

    public function testReadDocumentsRecordTheirNestedOperations(): void
    {
        $operations = new Operations();
        $this->database->addHook(new Metadata(new Document(['$id' => 'library']), tenant: $this->database, operations: $operations));

        $album = $this->database->getDocument('albums', 'first');
        $albums = $this->database->find('albums', [Query::select(['*', 'tracks.*'])]);
        $plain = $this->database->skipRelationships(fn (): Document => $this->database->getDocument('albums', 'first'));

        $this->assertSame(5, $operations->reads([$album]), 'first, track1, rock, track2 and the empty genre list of track2');
        $this->assertSame([3, 2], \array_map(static fn (Document $document): int => $operations->reads([$document]), $albums));
        $this->assertSame(5, $operations->reads($albums));
        $this->assertSame(1, $operations->reads([$plain]));
    }

    public function testRecordedOperationsAddUpToTheHookTotal(): void
    {
        $operations = new Operations();
        $hook = new Metadata(new Document(['$id' => 'library']), tenant: $this->database, operations: $operations);
        $this->database->addHook($hook);

        $albums = $this->database->find('albums');

        $this->assertSame(8, $operations->reads($albums), 'first counts 5, second counts itself, track3 and the empty genre list of track3');
        $this->assertSame($hook->getOperations(), $operations->reads($albums));
    }

    public function testWithoutACounterEveryDocumentReadsAsOne(): void
    {
        $operations = new Operations();
        $hook = new Metadata(new Document(['$id' => 'library']), tenant: $this->database);
        $this->database->addHook($hook);

        $album = $this->database->getDocument('albums', 'first');
        $albums = $this->database->find('albums');

        $this->assertSame(1, $operations->reads([$album]), 'a hook built without the counter, as cloud builds it, records nothing');
        $this->assertSame(2, $operations->reads($albums));
        $this->assertSame(13, $hook->getOperations(), 'the hook still counts what it decorates');
    }
}
