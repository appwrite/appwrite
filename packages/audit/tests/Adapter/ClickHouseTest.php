<?php

declare(strict_types=1);

namespace Utopia\Audit\Tests\Adapter;

use Exception;
use PHPUnit\Framework\TestCase;
use Utopia\Audit\Adapter\ClickHouse;
use Utopia\Audit\Adapter\Database;
use Utopia\Audit\Query;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Client\Exception\ConnectionException;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Attribute;
use Utopia\Database\Database as UtopiaDatabase;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory as RequestFactory;

final class ClickHouseTest extends TestCase
{
    private Client $client;

    private ClickHouse $adapter;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = new Client();
        $this->adapter = new ClickHouse('clickhouse', 'user', 'secret', 8124, client: $this->client);
    }

    public function testPingSendsAuthenticatedGet(): void
    {
        $this->client->respond(200, "Ok.\n");

        $this->assertTrue($this->adapter->ping());

        $request = $this->client->last();
        $this->assertSame(Method::GET, $request->getMethod());
        $this->assertSame('http://clickhouse:8124/ping', (string) $request->getUri());
        $this->assertSame('user', $request->getHeaderLine('X-ClickHouse-User'));
        $this->assertSame('secret', $request->getHeaderLine('X-ClickHouse-Key'));
        $this->assertSame('', (string) $request->getBody());
    }

    public function testPingFailsOnErrorStatus(): void
    {
        $this->client->respond(503);

        $this->assertFalse($this->adapter->ping());
    }

    public function testPingFailsOnNetworkError(): void
    {
        $this->client->fail(new ConnectionException(new RequestFactory()->createRequest(Method::GET, 'http://clickhouse:8124/ping'), 'Connection refused'));

        $this->assertFalse($this->adapter->ping());
    }

    public function testPingUsesHttpsWhenSecure(): void
    {
        $this->client->respond(200);
        $this->adapter->setSecure(true);

        $this->adapter->ping();

        $this->assertSame('https://clickhouse:8124/ping', (string) $this->client->last()->getUri());
    }

    public function testQuerySendsParametersAsMultipartForm(): void
    {
        $this->client->respond(200, "7\n");
        $this->adapter->setDatabase('logs');

        $count = $this->adapter->count([Query::equal('event', ['users.create'])]);

        $this->assertSame(7, $count);

        $request = $this->client->last();
        $this->assertSame(Method::POST, $request->getMethod());
        $this->assertSame('http://clickhouse:8124/', (string) $request->getUri());
        $this->assertSame('user', $request->getHeaderLine('X-ClickHouse-User'));
        $this->assertSame('secret', $request->getHeaderLine('X-ClickHouse-Key'));
        $this->assertSame('logs', $request->getHeaderLine('X-ClickHouse-Database'));
        $this->assertStringStartsWith('multipart/form-data; boundary=', $request->getHeaderLine('Content-Type'));

        $fields = $this->fields($request->getHeaderLine('Content-Type'), (string) $request->getBody());
        $this->assertSame('SELECT COUNT(*) AS count FROM `logs`.`audits` WHERE `event` IN ({param0:String}) FORMAT TabSeparated', $fields['query']);
        $this->assertSame('users.create', $fields['param_param0']);
    }

    public function testBatchInsertSendsRowsAsBody(): void
    {
        $this->client->respond(200);

        $this->adapter->createBatch([[
            'userId' => 'user1',
            'event' => 'users.create',
            'resource' => 'user/user1',
            'userAgent' => 'Mozilla/5.0',
            'ip' => '127.0.0.1',
            'time' => '2026-01-01 00:00:00.000',
            'data' => [],
            'actorType' => 'member',
            'resourceType' => 'user',
            'resourceId' => 'user1',
            'projectId' => 'project1',
            'projectInternalId' => '1',
            'teamId' => 'team1',
            'teamInternalId' => '1',
            'hostname' => 'example.org',
            'country' => 'us',
        ]]);

        $request = $this->client->last();
        $this->assertSame(Method::POST, $request->getMethod());
        parse_str($request->getUri()->getQuery(), $query);
        $this->assertIsString($query['query']);
        $this->assertStringStartsWith('INSERT INTO `default`.`audits` (', $query['query']);
        $this->assertStringEndsWith('FORMAT JSONEachRow', $query['query']);
        $this->assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        $this->assertSame('default', $request->getHeaderLine('X-ClickHouse-Database'));

        $row = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($row);
        $this->assertSame('users.create', $row['event']);
        $this->assertSame('user1', $row['resourceId']);
    }

    public function testQueryReportsErrorStatus(): void
    {
        $this->client->respond(500, 'Code: 60. DB::Exception: Unknown table');

        try {
            $this->adapter->count();
            $this->fail('Expected the query to fail.');
        } catch (Exception $exception) {
            $this->assertSame('ClickHouse query execution failed: ClickHouse query failed with HTTP 500: Code: 60. DB::Exception: Unknown table', $exception->getMessage());
        }
    }

    public function testQueryWrapsNetworkErrors(): void
    {
        $error = new ConnectionException(new RequestFactory()->createRequest(Method::POST, 'http://clickhouse:8124/'), 'Connection refused');
        $this->client->fail($error);

        try {
            $this->adapter->count();
            $this->fail('Expected the query to fail.');
        } catch (Exception $exception) {
            $this->assertSame('ClickHouse query execution failed: Connection refused', $exception->getMessage());
            $this->assertSame($error, $exception->getPrevious());
        }
    }

    /**
     * @return array<string, string>
     */
    private function fields(string $contentType, string $body): array
    {
        $boundary = substr($contentType, \strlen('multipart/form-data; boundary='));
        $fields = [];

        foreach (explode('--' . $boundary, $body) as $part) {
            $sections = explode("\r\n\r\n", $part, 2);
            if (\count($sections) !== 2) {
                continue;
            }

            $name = explode('"', explode('name="', $sections[0], 2)[1] ?? '', 2)[0];
            $fields[$name] = substr($sections[1], 0, -2);
        }

        return $fields;
    }

    public function testRenamesTheUserColumnToActor(): void
    {
        $user = \array_find(
            new Database(new UtopiaDatabase(new Memory(), new Cache(new NoCache())))->getAttributes(),
            static fn (Attribute $attribute): bool => $attribute->key === 'userId',
        );
        $this->assertNotNull($user, 'the Database adapter must still declare the userId column the ClickHouse rename starts from');
        $attributes = [];
        foreach ($this->adapter->getAttributes() as $attribute) {
            $attributes[$attribute->key] = $attribute;
        }
        $indexes = [];
        foreach ($this->adapter->getIndexes() as $index) {
            $indexes[$index->key] = $index;
        }

        $this->assertArrayNotHasKey('userId', $attributes);
        $this->assertSame($user->type, $attributes['actorId']->type);
        $this->assertSame($user->size, $attributes['actorId']->size);
        $this->assertSame($user->required, $attributes['actorId']->required);
        $this->assertSame($user->array, $attributes['actorId']->array);
        $this->assertSame($user->filters, $attributes['actorId']->filters);
        $this->assertArrayNotHasKey('idx_userId_event', $indexes);
        $this->assertSame(['actorId', 'event'], $indexes['idx_actorId_event']->attributes);
    }
}
