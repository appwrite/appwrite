<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Hooks;

use Appwrite\Database\Factory as DatabaseFactory;
use Appwrite\Usage\Context;
use Appwrite\Utopia\Request;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;
use ReflectionNamedType;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Hook\Relationships;
use Utopia\Database\Relationship;
use Utopia\Database\RelationType;
use Utopia\Database\Validator\Authorization;
use Utopia\DI\Container;

/**
 * The HTTP `getDatabasesDB` resource wires the Metadata hook of every tenant database it hands out: the hook must
 * know the public ID of the collection the caller passed without querying the catalog for it, and must record
 * what it decorates on the request's operations counter. Cloud keeps its own copy of the resource, so the closure
 * contract stays as it is.
 */
final class MetadataResourceTest extends TestCase
{
    private const string MOVIES = 'database_4_collection_9';
    private const string ACTORS = 'database_4_collection_10';

    private Authorization $authorization;

    private Memory $adapter;

    protected function setUp(): void
    {
        $this->authorization = new Authorization();
        $this->authorization->addRole(Role::any()->toString());
        $this->adapter = new Memory();

        $tenant = $this->tenant();
        $this->authorization->skip(function () use ($tenant): void {
            $tenant->create();
            foreach ([self::MOVIES, self::ACTORS] as $collection) {
                $tenant->createCollection(new Collection(
                    id: $collection,
                    attributes: [Attribute::string(key: 'name', size: 100, required: false)],
                    permissions: [Permission::read(Role::any()), Permission::create(Role::any())],
                ));
            }
            $tenant->createRelationship(new Relationship(
                collection: self::MOVIES,
                relatedCollection: self::ACTORS,
                type: RelationType::ManyToOne,
                key: 'lead',
            ));
            $tenant->createDocument(self::ACTORS, new Document(['$id' => 'actor']));
            $tenant->createDocument(self::MOVIES, new Document(['$id' => 'film', 'lead' => 'actor']));
        });
    }

    public function testRequestResourceKnowsThePublicIdOfTheCollectionItWasGiven(): void
    {
        $catalog = $this->createMock(Database::class);
        $catalog->expects($this->never())->method('findOne');
        $catalog->method('getAuthorization')->willReturn($this->authorization);
        $catalog->method('silent')->willReturnCallback(static fn (callable $callback): mixed => $callback());

        $getDatabasesDB = $this->requestContainer($catalog)->get('getDatabasesDB');
        $database = $getDatabasesDB(
            new Document(['$id' => 'cinema', '$sequence' => '4']),
            new Document(['$id' => 'movies', '$sequence' => '9']),
        );

        $film = $database->skipRelationships(fn (): Document => $database->getDocument(self::MOVIES, 'film'));

        $this->assertSame('movies', $film->getAttribute('$collectionId'), 'the public ID comes from the collection the caller passed');
        $this->assertSame('cinema', $film->getAttribute('$databaseId'));
    }

    public function testRequestResourceRecordsReadsOnTheRequestCounter(): void
    {
        $catalog = $this->createStub(Database::class);
        $catalog->method('getAuthorization')->willReturn($this->authorization);
        $catalog->method('silent')->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $catalog->method('findOne')->willReturn(new Document(['$id' => 'actors']));

        $container = $this->requestContainer($catalog);
        $getDatabasesDB = $container->get('getDatabasesDB');
        $cinema = new Document(['$id' => 'cinema', '$sequence' => '4']);
        $movies = new Document(['$id' => 'movies', '$sequence' => '9']);

        $film = $getDatabasesDB($cinema, $movies)->getDocument(self::MOVIES, 'film');
        $again = $getDatabasesDB($cinema, $movies)->getDocument(self::MOVIES, 'film');

        $this->assertInstanceOf(Document::class, $film->getAttribute('lead'));
        $this->assertSame(2, $container->get('operations')->reads([$film]), 'the film and its lead actor');
        $this->assertSame(4, $container->get('operations')->reads([$film, $again]), 'every tenant database of the request records on one counter');
    }

    public function testRequestResourceKeepsItsClosureContract(): void
    {
        $getDatabasesDB = $this->requestContainer($this->createStub(Database::class))->get('getDatabasesDB');
        $contract = new ReflectionFunction($getDatabasesDB);
        $parameters = $contract->getParameters();

        $this->assertCount(2, $parameters);
        $this->assertSame(['database', 'collection'], [$parameters[0]->getName(), $parameters[1]->getName()]);
        $this->assertInstanceOf(ReflectionNamedType::class, $parameters[1]->getType());
        $this->assertSame(Document::class, $parameters[1]->getType()->getName());
        $this->assertTrue($parameters[1]->allowsNull());
        $this->assertTrue($parameters[1]->isDefaultValueAvailable());
        $this->assertInstanceOf(ReflectionNamedType::class, $contract->getReturnType());
        $this->assertSame(Database::class, $contract->getReturnType()->getName());
    }

    public function testWorkerResourceTakesTheProjectByName(): void
    {
        $projects = [];
        $factory = $this->createStub(DatabaseFactory::class);
        $factory->method('tenant')->willReturnCallback(function (Document $database, Document $project) use (&$projects): Database {
            $projects[] = $project->getId();

            return $this->createStub(Database::class);
        });

        $register = require __DIR__ . '/../../../../../app/init/worker/message.php';
        $container = new Container();
        $register($container);
        $container->set('databaseFactory', static fn (): DatabaseFactory => $factory);
        $container->set('project', static fn (): Document => new Document(['$id' => 'message', 'database' => 'mariadb://shared']));
        $container->set('usage', static fn (): Context => new Context());

        $getDatabasesDB = $container->get('getDatabasesDB');
        $database = new Document(['$id' => 'cinema', 'database' => 'mariadb://dedicated']);
        $getDatabasesDB($database);
        $getDatabasesDB($database, project: new Document(['$id' => 'source', 'database' => 'mariadb://source']));

        $this->assertSame(['message', 'source'], $projects);
    }

    private function tenant(): Database
    {
        $tenant = (new Database($this->adapter, new Cache(new NoCache())))
            ->setDatabase('resource')
            ->setNamespace('metadata')
            ->setAuthorization($this->authorization);

        return $tenant->addHook(new Relationships($tenant));
    }

    private function requestContainer(Database $catalog): Container
    {
        $factory = $this->createStub(DatabaseFactory::class);
        $factory->method('tenant')->willReturnCallback(fn (): Database => $this->tenant());

        $request = $this->createStub(Request::class);
        $request->method('getURI')->willReturn('/v1/databases/cinema/collections/movies/documents/film');
        $request->method('getHeaderLine')->willReturn('');

        $register = require __DIR__ . '/../../../../../app/init/resources/request.php';
        $container = new Container();
        $register($container);
        $container->set('databaseFactory', static fn (): DatabaseFactory => $factory);
        $container->set('project', static fn (): Document => new Document(['$id' => 'project']));
        $container->set('request', static fn (): Request => $request);
        $container->set('dbForProject', static fn (): Database => $catalog);
        $container->set('usage', static fn (): Context => new Context());

        return $container;
    }
}
