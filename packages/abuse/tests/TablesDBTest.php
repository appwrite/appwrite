<?php

declare(strict_types=1);

namespace Utopia\Abuse\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Abuse\Adapter\TimeLimit\Appwrite\TablesDB;
use Utopia\Abuse\Result;
use Utopia\Abuse\Tests\TimeLimit\RacingTablesDBClient;
use Utopia\Abuse\Tests\TimeLimit\SetupTablesDBClient;

final class TablesDBTest extends TestCase
{
    public function testConcurrentHitCannotTakeTheSameLastSlot(): void
    {
        $client = new RacingTablesDBClient(count: 1);
        $adapter = new TablesDB('login', 2, 60, $client, 'abuse');

        $competing = null;
        $client->race = function () use (&$competing, $adapter): void {
            $competing = $adapter->check();
        };

        $result = $adapter->check();

        $this->assertInstanceOf(Result::class, $competing);
        $this->assertFalse($competing->limited, 'the competing hit takes the last slot');
        $this->assertTrue($result->limited, 'the hit that lost the race is limited');
        $this->assertSame(2, $client->count);
    }

    public function testHitBelowTheLimitReportsTheCountBeforeIt(): void
    {
        $client = new RacingTablesDBClient(count: 1);

        $result = new TablesDB('login', 3, 60, $client, 'abuse')->check();

        $this->assertFalse($result->limited);
        $this->assertSame(1, $result->remaining);
        $this->assertSame(2, $client->count);
    }

    public function testSetupCreatesTheColumnsAndIndexesWithTheTable(): void
    {
        $client = new SetupTablesDBClient(tableExists: false);

        new TablesDB('login', 2, 60, $client, 'abuse')->setup();

        $creation = $this->paramsOf($client, 'POST', '/tablesdb/abuse/tables')[0];

        $this->assertSame([
            ['key' => 'key', 'type' => 'string', 'size' => 255, 'required' => true],
            ['key' => 'time', 'type' => 'datetime', 'required' => true],
            ['key' => 'count', 'type' => 'integer', 'required' => true, 'min' => 0, 'max' => PHP_INT_MAX],
        ], $creation['columns']);
        $this->assertSame([
            ['key' => 'unique1', 'type' => 'unique', 'attributes' => ['key', 'time']],
            ['key' => 'index2', 'type' => 'key', 'attributes' => ['time']],
        ], $creation['indexes']);

        // Inline columns and indexes come back available, so none are created one by one or polled.
        $this->assertSame([
            'GET /tablesdb/abuse/tables/lock',
            'POST /tablesdb',
            'POST /tablesdb/abuse/tables',
            'POST /tablesdb/abuse/tables',
        ], $this->calls($client));
    }

    public function testSetupRepairsATableLeftBehindByAnEarlierRun(): void
    {
        $client = new SetupTablesDBClient(tableExists: true);

        new TablesDB('login', 2, 60, $client, 'abuse')->setup();

        // Inline definitions only apply while the table is being created, so the columns and
        // indexes of a table that already exists are added through their own endpoints and
        // waited for.
        $this->assertSame([
            'GET /tablesdb/abuse/tables/lock',
            'POST /tablesdb',
            'POST /tablesdb/abuse/tables',
            'POST /tablesdb/abuse/tables/Abuse/columns/string',
            'POST /tablesdb/abuse/tables/Abuse/columns/datetime',
            'POST /tablesdb/abuse/tables/Abuse/columns/integer',
            'GET /tablesdb/abuse/tables/Abuse/columns',
            'POST /tablesdb/abuse/tables/Abuse/indexes',
            'POST /tablesdb/abuse/tables/Abuse/indexes',
            'GET /tablesdb/abuse/tables/Abuse/indexes',
            'POST /tablesdb/abuse/tables',
        ], $this->calls($client));

        $this->assertSame(
            ['key' => 'key', 'size' => 255, 'required' => true],
            $this->subset($client, '/tablesdb/abuse/tables/Abuse/columns/string', ['key', 'size', 'required']),
        );
        $this->assertSame(
            ['key' => 'time', 'required' => true],
            $this->subset($client, '/tablesdb/abuse/tables/Abuse/columns/datetime', ['key', 'required']),
        );
        $this->assertSame(
            ['key' => 'count', 'required' => true, 'min' => 0, 'max' => PHP_INT_MAX],
            $this->subset($client, '/tablesdb/abuse/tables/Abuse/columns/integer', ['key', 'required', 'min', 'max']),
        );

        // The type each index was defined with survives the round trip through the SDK's index type.
        $indexes = $this->paramsOf($client, 'POST', '/tablesdb/abuse/tables/Abuse/indexes');
        $this->assertSame(['unique', 'key'], \array_map(
            static fn (array $params): string => $params['type'] instanceof \Stringable ? (string) $params['type'] : '',
            $indexes,
        ));
        $this->assertSame([['key', 'time'], ['time']], \array_map(
            static fn (array $params): mixed => $params['columns'],
            $indexes,
        ));
    }

    /**
     * @return list<string>
     */
    private function calls(SetupTablesDBClient $client): array
    {
        return \array_map(
            static fn (array $request): string => $request['method'] . ' ' . $request['path'],
            $client->requests,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function paramsOf(SetupTablesDBClient $client, string $method, string $path): array
    {
        return \array_values(\array_map(
            static fn (array $request): array => $request['params'],
            \array_filter(
                $client->requests,
                static fn (array $request): bool => $request['method'] === $method && $request['path'] === $path,
            ),
        ));
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function subset(SetupTablesDBClient $client, string $path, array $keys): array
    {
        return \array_intersect_key(
            $this->paramsOf($client, 'POST', $path)[0],
            \array_fill_keys($keys, null),
        );
    }
}
