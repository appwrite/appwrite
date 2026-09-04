<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Hooks;

use Appwrite\Utopia\Database\Adapter\Pool;
use Appwrite\Utopia\Database\Hooks\Metadata;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Event;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Hook\Permissions;
use Utopia\Database\Hook\Relationships;
use Utopia\Database\Query;
use Utopia\Database\Relationship;
use Utopia\Database\RelationType;
use Utopia\Database\Validator\Authorization;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Pool as Connections;

final class MetadataRelationshipsTest extends TestCase
{
    /** @return iterable<string, array{string, bool, bool}> */
    public static function contexts(): iterable
    {
        foreach (['collection', 'table'] as $context) {
            foreach ([false, true] as $separate) {
                foreach ([false, true] as $transaction) {
                    yield $context . '-' . (int) $separate . '-' . (int) $transaction => [$context, $separate, $transaction];
                }
            }
        }
    }

    #[DataProvider('contexts')]
    public function testPublicOperationsDecorateNestedRelationships(string $context, bool $separate, bool $transaction): void
    {
        $authorization = new Authorization();
        $authorization->disable();
        $connections = new Connections(new Stack(), 'tenant', 1, static fn (): Memory => new Memory(), 0.0);
        $catalogConnections = $separate
            ? new Connections(new Stack(), 'catalog', 1, static fn (): Memory => new Memory(), 0.0)
            : $connections;
        $tenant = $this->database($connections, $authorization);
        $catalog = $this->database($catalogConnections, $authorization);
        $tenant->create();
        if ($separate) {
            $catalog->create();
        }
        $catalog->createCollection(new Collection(id: 'database_2'));
        $collections = [];
        foreach (['veterinarians', 'animals', 'zoos', 'presidents'] as $name) {
            $record = $catalog->createDocument('database_2', new Document(['$id' => $name]));
            $collections[$name] = 'database_2_collection_' . $record->getSequence();
            $tenant->createCollection(new Collection(
                id: $collections[$name],
                attributes: [Attribute::string(key: 'name', size: 100), Attribute::string(key: 'payload', size: 1000, filters: ['json'])],
                permissions: [Permission::create(Role::any())],
            ));
        }
        if ($separate) {
            $this->assertTrue($tenant->getCollection('database_2')->isEmpty());
        }
        $tenant->addHook(new Permissions());
        $tenant->addHook(new Relationships($tenant));
        foreach ([
            ['veterinarians', 'animals', 'animals', RelationType::ManyToMany],
            ['animals', 'zoos', 'zoo', RelationType::ManyToOne],
            ['animals', 'presidents', 'president', RelationType::ManyToOne],
            ['zoos', 'presidents', 'president', RelationType::ManyToOne],
        ] as [$from, $to, $key, $type]) {
            $tenant->createRelationship(new Relationship(
                collection: $collections[$from],
                relatedCollection: $collections[$to],
                type: $type,
                key: $key,
            ));
        }
        $permissions = [Permission::read(Role::any()), Permission::update(Role::any()), Permission::delete(Role::any())];
        $tenant->createDocument($collections['presidents'], new Document(['$id' => 'leader', '$permissions' => $permissions, 'name' => 'Leader']));
        $tenant->createDocument($collections['zoos'], new Document(['$id' => 'zoo', '$permissions' => $permissions, 'name' => 'Zoo', 'president' => 'leader']));
        $payload = ['$id' => 'not-a-relationship', '$collection' => $collections['zoos'], 'name' => 'JSON value'];
        foreach (['cat', 'dog'] as $id) {
            $tenant->createDocument($collections['animals'], new Document([
                '$id' => $id,
                '$permissions' => $permissions,
                'name' => $id,
                'zoo' => 'zoo',
                'president' => 'leader',
                'payload' => $payload,
            ]));
        }
        $tenant->createDocument($collections['veterinarians'], new Document([
            '$id' => 'vet', '$permissions' => $permissions, 'name' => 'Vet', 'animals' => ['cat', 'dog'],
        ]));
        $authorization->enable();
        $roles = $authorization->getRoles();
        $hook = new Metadata(new Document(['$id' => 'public-database']), $context, Metadata::resolver($tenant, $catalog), $tenant);
        $tenant->addHook($hook);

        $operations = [
            fn (): Document => $tenant->getDocument($collections['veterinarians'], 'vet'),
            fn (): Document => $tenant->find($collections['veterinarians'])[0],
            fn (): Document => $tenant->getDocument($collections['veterinarians'], 'vet', [Query::select(['*', 'animals.*', 'animals.zoo.*', 'animals.president.*'])]),
            fn (): Document => $tenant->updateDocument($collections['veterinarians'], 'vet', new Document(['name' => 'Updated'])),
            fn (): Document => $tenant->createDocument($collections['veterinarians'], new Document([
                '$id' => 'second', '$permissions' => $permissions, 'name' => 'Second', 'animals' => ['cat', 'dog'],
            ])),
        ];
        foreach ($operations as $index => $operation) {
            $hook->resetOperations();
            $document = $transaction ? $tenant->withTransaction($operation) : $operation();
            $this->assertSame('public-database', $document->getAttribute('$databaseId'));
            $this->assertSame('veterinarians', $document->getAttribute('$' . $context . 'Id'));
            $animals = $document->getAttribute('animals');
            $this->assertCount(2, $animals);
            foreach ($animals as $animal) {
                $this->assertSame('animals', $animal->getAttribute('$' . $context . 'Id'));
                foreach (['zoo' => 'zoos', 'president' => 'presidents'] as $key => $collection) {
                    $related = $animal->getAttribute($key);
                    $this->assertInstanceOf(Document::class, $related);
                    $this->assertSame('public-database', $related->getAttribute('$databaseId'));
                    $this->assertSame($collection, $related->getAttribute('$' . $context . 'Id'));
                    $this->assertSame($permissions, $related->getPermissions());
                }
                $this->assertNotInstanceOf(Document::class, $animal->getAttribute('zoo')->getAttribute('president'));
                $value = $animal->getAttribute('payload');
                $this->assertSame($payload, $value instanceof Document ? $value->getArrayCopy() : $value);
            }
            $this->assertGreaterThanOrEqual(7, $hook->getOperations());
            if ($index < 2) {
                $this->assertSame(7, $hook->getOperations());
            }
            $this->assertSame($roles, $authorization->getRoles());
            $this->assertTrue($authorization->getStatus());
            $this->assertSame(1, $connections->count());
            $this->assertSame(1, $catalogConnections->count());
        }

        $authorization->skip(function () use ($tenant, $collections): void {
            $tenant->createDocument($collections['presidents'], new Document(['$id' => 'private', 'name' => 'Private']));
            $tenant->updateDocument($collections['animals'], 'dog', new Document(['president' => 'private']));
        });
        $read = fn (): Document => $tenant->getDocument($collections['veterinarians'], 'vet');
        $document = $transaction ? $tenant->withTransaction($read) : $read();
        foreach ($document->getAttribute('animals') as $animal) {
            if ($animal->getId() === 'dog') {
                $president = $animal->getAttribute('president');
                $this->assertInstanceOf(Document::class, $president);
                $this->assertSame('', $president->getId());
                $this->assertNull($president->getAttribute('name'));
                $this->assertSame([], $president->getPermissions());
            }
        }
        $this->assertTrue($authorization->getStatus());
        $this->assertSame($roles, $authorization->getRoles());
    }

    public function testSchemaTraversalPreservesCyclesSharedNodesAndDepthBoundary(): void
    {
        $authorization = new Authorization();
        $authorization->disable();
        $connections = new Connections(new Stack(), 'cycles', 1, static fn (): Memory => new Memory(), 0.0);
        $tenant = $this->database($connections, $authorization);
        $tenant->create();
        foreach (['roots', 'children', 'leaves', 'beyond'] as $id) {
            $tenant->createCollection(new Collection(id: $id, attributes: [Attribute::string(key: 'payload', size: 1000, filters: ['json'])]));
        }
        foreach ([
            ['roots', 'children', 'first'],
            ['roots', 'children', 'second'],
            ['roots', 'children', 'empty'],
            ['roots', 'children', 'missing'],
            ['roots', 'children', 'identifier'],
            ['roots', 'children', 'many'],
            ['children', 'roots', 'parent'],
            ['children', 'leaves', 'leaf'],
            ['leaves', 'beyond', 'next'],
        ] as [$from, $to, $key]) {
            $tenant->createRelationship(new Relationship(
                collection: $from,
                relatedCollection: $to,
                type: $key === 'many' ? RelationType::ManyToMany : RelationType::ManyToOne,
                key: $key,
                twoWayKey: $from . '_' . $key,
            ));
        }
        $beyond = new Document(['$id' => 'beyond', '$collection' => 'beyond']);
        $leaf = new Document(['$id' => 'leaf', '$collection' => 'leaves', 'next' => $beyond]);
        $payload = new Document(['$id' => 'json', '$collection' => 'leaves', 'next' => $beyond]);
        $root = new Document(['$id' => 'root', '$collection' => 'roots']);
        $child = new Document(['$id' => 'child', '$collection' => 'children', 'parent' => $root, 'leaf' => $leaf, 'payload' => $payload]);
        $root->setAttributes([
            'first' => $child,
            'second' => $child,
            'empty' => [],
            'missing' => null,
            'identifier' => 'child',
            'many' => [$child, 'child', null],
        ]);
        $collection = $tenant->getCollection('roots');
        $hook = new Metadata(new Document(['$id' => 'database']), tenant: $tenant);
        $result = $tenant->withTransaction(fn (): Document => $hook->decorate(Event::DocumentRead, $collection, $root));

        $this->assertSame($root, $result);
        $this->assertSame('roots', $root->getAttribute('$collectionId'));
        $this->assertSame('children', $child->getAttribute('$collectionId'));
        $this->assertSame('leaves', $leaf->getAttribute('$collectionId'));
        $this->assertSame('database', $leaf->getAttribute('$databaseId'));
        $this->assertFalse($beyond->offsetExists('$databaseId'));
        $this->assertFalse($payload->offsetExists('$databaseId'));
        $this->assertSame([], $root->getAttribute('empty'));
        $this->assertNull($root->getAttribute('missing'));
        $this->assertSame('child', $root->getAttribute('identifier'));
        $this->assertSame([$child, 'child', null], $root->getAttribute('many'));
        $this->assertSame(8, $hook->getOperations());
        $this->assertSame(1, $connections->count());
    }

    /** @param Connections<Memory> $connections */
    private function database(Connections $connections, Authorization $authorization): Database
    {
        return (new Database((new Pool($connections))->setHostname('same-host'), new Cache(new NoCache())))
            ->setDatabase('metadata')
            ->setNamespace('relationships')
            ->setAuthorization($authorization);
    }
}
