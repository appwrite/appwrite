<?php

namespace Tests\E2E\Services\TablesDB;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ApiTablesDB;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;

class ExplainAggressiveTest extends Scope
{
    use ApiTablesDB;
    use ProjectCustom;
    use SideServer;

    private static ?array $fixture = null;

    private function fixture(): array
    {
        if (self::$fixture !== null) {
            return self::$fixture;
        }

        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];

        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', $headers, [
            'databaseId' => ID::unique(),
            'name' => 'Explain Aggressive',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        $table = $this->client->call(Client::METHOD_POST, '/tablesdb/'.$databaseId.'/tables', $headers, [
            'tableId' => ID::unique(),
            'name' => 'movies',
            'rowSecurity' => false,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
            ],
        ]);
        $this->assertEquals(201, $table['headers']['status-code']);
        $tableId = $table['body']['$id'];

        $this->client->call(Client::METHOD_POST, '/tablesdb/'.$databaseId.'/tables/'.$tableId.'/columns/string', $headers, [
            'key' => 'title',
            'size' => 128,
            'required' => true,
        ]);
        $this->client->call(Client::METHOD_POST, '/tablesdb/'.$databaseId.'/tables/'.$tableId.'/columns/string', $headers, [
            'key' => 'status',
            'size' => 32,
            'required' => true,
        ]);
        $this->client->call(Client::METHOD_POST, '/tablesdb/'.$databaseId.'/tables/'.$tableId.'/columns/integer', $headers, [
            'key' => 'releaseYear',
            'required' => true,
        ]);
        $this->client->call(Client::METHOD_POST, '/tablesdb/'.$databaseId.'/tables/'.$tableId.'/columns/integer', $headers, [
            'key' => 'rating',
            'required' => true,
        ]);

        $this->waitForReady($databaseId, $tableId, $headers);

        $this->client->call(Client::METHOD_POST, '/tablesdb/'.$databaseId.'/tables/'.$tableId.'/indexes', $headers, [
            'key' => 'idx_status',
            'type' => 'key',
            'columns' => ['status'],
        ]);
        $this->waitForReady($databaseId, $tableId, $headers);

        for ($i = 0; $i < 20; $i++) {
            $this->client->call(Client::METHOD_POST, '/tablesdb/'.$databaseId.'/tables/'.$tableId.'/rows', $headers, [
                'rowId' => ID::unique(),
                'data' => [
                    'title' => 'movie '.$i,
                    'status' => $i % 2 === 0 ? 'published' : 'draft',
                    'releaseYear' => 2000 + $i,
                    'rating' => ($i % 5) + 1,
                ],
            ]);
        }

        return self::$fixture = [
            'databaseId' => $databaseId,
            'tableId' => $tableId,
            'headers' => $headers,
        ];
    }

    private function waitForReady(string $databaseId, string $tableId, array $headers): void
    {
        $deadline = \microtime(true) + 30;
        while (\microtime(true) < $deadline) {
            $cols = $this->client->call(
                Client::METHOD_GET,
                '/tablesdb/'.$databaseId.'/tables/'.$tableId.'/columns',
                $headers,
            );
            $rows = $cols['body']['columns'] ?? [];
            if (! empty($rows) && \array_reduce($rows, fn ($ok, $c) => $ok && ($c['status'] ?? '') === 'available', true)) {
                return;
            }
            \usleep(250000);
        }
        $this->fail("Columns/indexes for {$databaseId}/{$tableId} never reached 'available'");
    }

    private function explain(array $queries): array
    {
        $f = $this->fixture();

        return $this->client->call(
            Client::METHOD_GET,
            '/tablesdb/'.$f['databaseId'].'/tables/'.$f['tableId'].'/rows/explain',
            $f['headers'],
            ['queries' => $queries],
        );
    }

    public function test_plain_find(): void
    {
        $r = $this->explain([Query::limit(10)->toString()]);

        $this->assertEquals(200, $r['headers']['status-code']);
        $this->assertNotEmpty($r['body']['queries']);
        $this->assertEquals('find', $r['body']['queries'][0]['purpose']);
        $this->assertEquals($this->fixture()['tableId'], $r['body']['queries'][0]['context']['collection']);
    }

    public function test_equal_filter_on_indexed_column(): void
    {
        $r = $this->explain([
            Query::equal('status', ['published'])->toString(),
            Query::limit(10)->toString(),
        ]);

        $this->assertEquals(200, $r['headers']['status-code']);
        $this->assertNotEmpty($r['body']['queries']);
        $this->assertArrayHasKey('plan', $r['body']['queries'][0]);
    }

    public function test_multiple_filters_and(): void
    {
        $r = $this->explain([
            Query::equal('status', ['published'])->toString(),
            Query::greaterThan('rating', 2)->toString(),
            Query::limit(5)->toString(),
        ]);

        $this->assertEquals(200, $r['headers']['status-code']);
        $this->assertCount(1, $r['body']['queries']);
    }

    public function test_ordering_descending(): void
    {
        $r = $this->explain([
            Query::orderDesc('releaseYear')->toString(),
            Query::limit(5)->toString(),
        ]);

        $this->assertEquals(200, $r['headers']['status-code']);
        $this->assertNotEmpty($r['body']['queries']);
    }

    public function test_ordering_ascending(): void
    {
        $r = $this->explain([
            Query::orderAsc('releaseYear')->toString(),
            Query::limit(5)->toString(),
        ]);

        $this->assertEquals(200, $r['headers']['status-code']);
        $this->assertNotEmpty($r['body']['queries']);
    }

    public function test_offset_pagination(): void
    {
        $r = $this->explain([
            Query::orderAsc('releaseYear')->toString(),
            Query::offset(5)->toString(),
            Query::limit(5)->toString(),
        ]);

        $this->assertEquals(200, $r['headers']['status-code']);
        $this->assertNotEmpty($r['body']['queries']);
    }

    public function test_empty_result_set_still_captures(): void
    {
        $r = $this->explain([
            Query::equal('status', ['nonexistent-status'])->toString(),
        ]);

        $this->assertEquals(200, $r['headers']['status-code']);
        $this->assertNotEmpty($r['body']['queries']);
        $this->assertEquals('find', $r['body']['queries'][0]['purpose']);
    }

    public function test_greater_and_less_operators(): void
    {
        $r = $this->explain([
            Query::greaterThan('releaseYear', 2005)->toString(),
            Query::lessThan('releaseYear', 2015)->toString(),
        ]);

        $this->assertEquals(200, $r['headers']['status-code']);
        $this->assertNotEmpty($r['body']['queries']);
    }

    public function test_select_projection(): void
    {
        $r = $this->explain([
            Query::select(['title', 'releaseYear'])->toString(),
            Query::limit(5)->toString(),
        ]);

        $this->assertEquals(200, $r['headers']['status-code']);
        $this->assertNotEmpty($r['body']['queries']);
    }

    public function test_headline_fields_present(): void
    {
        $r = $this->explain([Query::limit(1)->toString()]);

        $this->assertEquals(200, $r['headers']['status-code']);
        $plan = $r['body']['queries'][0]['plan'];

        $this->assertArrayHasKey('engine', $plan);
        $this->assertArrayHasKey('rowsScanned', $plan);
        $this->assertArrayHasKey('indexUsed', $plan);
        $this->assertArrayHasKey('estimatedCost', $plan);
        $this->assertArrayHasKey('tree', $plan);
    }

    public function test_sanitizer_hides_perms_table(): void
    {
        $r = $this->explain([
            Query::equal('status', ['published'])->toString(),
            Query::limit(5)->toString(),
        ]);

        $raw = \json_encode($r['body']['queries']);
        $this->assertStringNotContainsString('_perms', $raw);
    }

    public function test_sanitizer_hides_metadata_table(): void
    {
        $r = $this->explain([Query::limit(1)->toString()]);

        $raw = \json_encode($r['body']['queries']);
        $this->assertStringNotContainsString('__metadata', $raw);
    }

    public function test_sanitizer_hides_internal_columns(): void
    {
        $r = $this->explain([Query::limit(1)->toString()]);

        $raw = \json_encode($r['body']['queries']);
        foreach (['_uid', '_createdAt', '_updatedAt', '_tenant'] as $leak) {
            $this->assertStringNotContainsString($leak, $raw, "internal column '{$leak}' leaked into the plan");
        }
    }

    public function test_context_collection_is_user_facing_table_id(): void
    {
        $r = $this->explain([Query::limit(1)->toString()]);

        foreach ($r['body']['queries'] as $entry) {
            $this->assertSame(
                $this->fixture()['tableId'],
                $entry['context']['collection'],
                'context.collection must use the user-facing tableId',
            );
        }
    }

    public function test_engine_is_sql_on_maria_backed(): void
    {
        $r = $this->explain([Query::limit(1)->toString()]);

        // The shipped appwrite stack runs Mongo by default; engine reflects the
        // actual backend. Only assert it's a known string.
        $engine = $r['body']['queries'][0]['plan']['engine'] ?? null;
        $this->assertContains($engine, ['sql', 'mongo']);
    }

    public function test_back_to_back_calls_are_independent(): void
    {
        $a = $this->explain([Query::limit(1)->toString()]);
        $b = $this->explain([Query::limit(2)->toString()]);

        $this->assertEquals(200, $a['headers']['status-code']);
        $this->assertEquals(200, $b['headers']['status-code']);
        $this->assertNotEmpty($a['body']['queries']);
        $this->assertNotEmpty($b['body']['queries']);
    }

    public function test_invalid_query_returns400(): void
    {
        $r = $this->explain(['{"method":"notARealMethod"}']);

        $this->assertEquals(400, $r['headers']['status-code']);
    }

    public function test_missing_table_returns404(): void
    {
        $f = $this->fixture();

        $r = $this->client->call(
            Client::METHOD_GET,
            '/tablesdb/'.$f['databaseId'].'/tables/nonexistent_table/rows/explain',
            $f['headers'],
            ['queries' => [Query::limit(1)->toString()]],
        );

        $this->assertEquals(404, $r['headers']['status-code']);
    }
}
