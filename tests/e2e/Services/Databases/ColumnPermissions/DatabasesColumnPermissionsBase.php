<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Databases\ColumnPermissions;

use Tests\E2E\Client;
use Utopia\Database\Id;
use Utopia\Database\Permission;
use Utopia\Database\Role;

/**
 * A table's permission may name one of its columns, and then grants that column alone.
 *
 * The grants are kept in two forms. The table's row in the metadata collection stores them with
 * every column dropped, because that is the form the read gate matches: a column left there
 * would hide the table from the role it was granted to. The list as the caller wrote it is
 * stored beside it, and is what the API reports and what an update replays.
 *
 * One trait per product rather than one test per product: the rule is the library's and applies
 * to every API that reaches a collection, so each composes this with its own Api* scope.
 */
trait DatabasesColumnPermissionsBase
{
    private function columnGrantsDatabase(string $name): string
    {
        $database = $this->client->call(Client::METHOD_POST, $this->getDatabaseUrl(), $this->getServerHeaders(), [
            'databaseId' => Id::unique(),
            'name' => $name,
        ]);

        $this->assertEquals(201, $database['headers']['status-code']);

        return $database['body']['$id'];
    }

    /**
     * A container with a `name` and a `salary` column, created through whichever API the
     * composing test speaks.
     *
     * @param  list<string>  $permissions
     * @return array<string, mixed>
     */
    private function columnGrantsContainer(string $databaseId, array $permissions): array
    {
        $created = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($databaseId), $this->getServerHeaders(), [
            $this->getContainerIdParam() => Id::unique(),
            'name' => 'Employees',
            'permissions' => $permissions,
        ]);

        if ($created['headers']['status-code'] !== 201) {
            return $created;
        }

        $containerId = $created['body']['$id'];

        foreach ([['string', ['key' => 'name', 'size' => 64]], ['integer', ['key' => 'salary']]] as [$type, $body]) {
            $column = $this->client->call(
                Client::METHOD_POST,
                $this->getSchemaUrl($databaseId, $containerId, $type),
                $this->getServerHeaders(),
                $body + ['required' => false],
            );

            $this->assertEquals(202, $column['headers']['status-code'], $type);
        }

        $this->waitForAttribute($databaseId, $containerId, 'name');
        $this->waitForAttribute($databaseId, $containerId, 'salary');

        return $created;
    }

    public function testACollectionPermissionMayNameOneOfItsColumns(): void
    {
        // A grant names a declared column, so a schemaless product has nothing for one to
        // name and the library refuses it as a column the collection does not have.
        if (!$this->getSupportForAttributes()) {
            $this->markTestSkipped('Column-scoped permissions need declared columns, which this adapter does not have');
        }

        $databaseId = $this->columnGrantsDatabase('Column Grants');

        $created = $this->columnGrantsContainer($databaseId, [
            Permission::create(Role::any()),
            Permission::read(Role::any()),
        ]);

        $this->assertEquals(201, $created['headers']['status-code']);
        $containerId = $created['body']['$id'];

        $updated = $this->client->call(Client::METHOD_PUT, $this->getContainerUrl($databaseId, $containerId), $this->getServerHeaders(), [
            'name' => 'Employees',
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any(), 'name'),
                Permission::update(Role::any(), 'name'),
            ],
        ]);

        $this->assertEquals(200, $updated['headers']['status-code']);

        // Reported as written, not as stored.
        $this->assertContains('read("any", "name")', $updated['body']['$permissions']);
        $this->assertContains('update("any", "name")', $updated['body']['$permissions']);
        $this->assertContains('create("any")', $updated['body']['$permissions']);

        // The storage split is an implementation detail and stays out of the response.
        $this->assertArrayNotHasKey('columnPermissions', $updated['body']);

        // A column left in the row's own permissions would hide it from "any", so a listing
        // that still finds it is what proves the two forms were split.
        $list = $this->client->call(Client::METHOD_GET, $this->getContainerUrl($databaseId), $this->getServerHeaders());
        $this->assertEquals(200, $list['headers']['status-code']);
        $this->assertContains($containerId, \array_column($list['body'][$this->getContainerResource()], '$id'));

        $get = $this->client->call(Client::METHOD_GET, $this->getContainerUrl($databaseId, $containerId), $this->getServerHeaders());
        $this->assertEquals(200, $get['headers']['status-code']);
        $this->assertContains('read("any", "name")', $get['body']['$permissions']);
    }

    /**
     * An update that says nothing about permissions keeps the ones it has.
     *
     * The inherited list has to be the one the caller wrote: inheriting the stored form would
     * widen read("any", "name") to read("any") and replay that, so a rename would quietly drop
     * every column grant.
     */
    public function testUpdatingAnotherFieldKeepsTheColumnGrants(): void
    {
        // A grant names a declared column, so a schemaless product has nothing for one to
        // name and the library refuses it as a column the collection does not have.
        if (!$this->getSupportForAttributes()) {
            $this->markTestSkipped('Column-scoped permissions need declared columns, which this adapter does not have');
        }

        $databaseId = $this->columnGrantsDatabase('Column Grants Inherit');

        $created = $this->columnGrantsContainer($databaseId, [Permission::create(Role::any()), Permission::read(Role::any())]);
        $this->assertEquals(201, $created['headers']['status-code']);
        $containerId = $created['body']['$id'];

        $scoped = $this->client->call(Client::METHOD_PUT, $this->getContainerUrl($databaseId, $containerId), $this->getServerHeaders(), [
            'name' => 'Employees',
            'permissions' => [Permission::create(Role::any()), Permission::read(Role::any(), 'name')],
        ]);
        $this->assertEquals(200, $scoped['headers']['status-code']);

        $renamed = $this->client->call(Client::METHOD_PUT, $this->getContainerUrl($databaseId, $containerId), $this->getServerHeaders(), [
            'name' => 'Renamed',
        ]);

        $this->assertEquals(200, $renamed['headers']['status-code']);
        $this->assertEquals('Renamed', $renamed['body']['name']);
        $this->assertContains('read("any", "name")', $renamed['body']['$permissions'], 'an unrelated update dropped the column grant');

        // Still there on the next read, not just in the update's own response.
        $get = $this->client->call(Client::METHOD_GET, $this->getContainerUrl($databaseId, $containerId), $this->getServerHeaders());
        $this->assertContains('read("any", "name")', $get['body']['$permissions']);
    }

    /**
     * Only read and update answer a question about one column: a create brings a whole row into
     * being, a delete removes one, and write aggregates to all three.
     */
    public function testAColumnScopedCreateDeleteOrWriteIsRejected(): void
    {
        // A grant names a declared column, so a schemaless product has nothing for one to
        // name and the library refuses it as a column the collection does not have.
        if (!$this->getSupportForAttributes()) {
            $this->markTestSkipped('Column-scoped permissions need declared columns, which this adapter does not have');
        }

        $databaseId = $this->columnGrantsDatabase('Column Grants Whole Row');

        $created = $this->columnGrantsContainer($databaseId, [Permission::create(Role::any()), Permission::read(Role::any())]);
        $this->assertEquals(201, $created['headers']['status-code']);
        $containerId = $created['body']['$id'];

        foreach (['create', 'delete', 'write'] as $type) {
            $refused = $this->client->call(Client::METHOD_PUT, $this->getContainerUrl($databaseId, $containerId), $this->getServerHeaders(), [
                'name' => 'Employees',
                'permissions' => [Permission::$type(Role::any(), 'name')],
            ]);

            $this->assertEquals(400, $refused['headers']['status-code'], $type);
            $this->assertStringContainsString('whole row', $refused['body']['message'], $type);
        }
    }

    public function testAPermissionNamingAnUnknownColumnIsRejected(): void
    {
        // A grant names a declared column, so a schemaless product has nothing for one to
        // name and the library refuses it as a column the collection does not have.
        if (!$this->getSupportForAttributes()) {
            $this->markTestSkipped('Column-scoped permissions need declared columns, which this adapter does not have');
        }

        $databaseId = $this->columnGrantsDatabase('Column Grants Unknown');

        $created = $this->columnGrantsContainer($databaseId, [Permission::create(Role::any()), Permission::read(Role::any())]);
        $this->assertEquals(201, $created['headers']['status-code']);

        $refused = $this->client->call(Client::METHOD_PUT, $this->getContainerUrl($databaseId, $created['body']['$id']), $this->getServerHeaders(), [
            'name' => 'Employees',
            'permissions' => [Permission::create(Role::any()), Permission::read(Role::any(), 'nope')],
        ]);

        $this->assertEquals(400, $refused['headers']['status-code']);

        // A refused update leaves nothing behind. The metadata row is written before the
        // collection is, so a refusal that reached the collection would already have stored
        // the grant on the row -- reported to the caller and replayed by the next update.
        $get = $this->client->call(Client::METHOD_GET, $this->getContainerUrl($databaseId, $created['body']['$id']), $this->getServerHeaders());

        $this->assertNotContains('read("any", "nope")', $get['body']['$permissions'], 'a refused update stored its grant anyway');
    }

    /**
     * A column permission belongs to the collection, never to one of its rows: stored on a row
     * it would sit where nothing enforces it, and the read gate would hide the row from the very
     * role it was granted to.
     */
    public function testARowPermissionMayNotNameAColumn(): void
    {
        // A grant names a declared column, so a schemaless product has nothing for one to
        // name and the library refuses it as a column the collection does not have.
        if (!$this->getSupportForAttributes()) {
            $this->markTestSkipped('Column-scoped permissions need declared columns, which this adapter does not have');
        }

        $databaseId = $this->columnGrantsDatabase('Column Grants Rows');

        $created = $this->columnGrantsContainer($databaseId, [Permission::create(Role::any()), Permission::read(Role::any())]);
        $this->assertEquals(201, $created['headers']['status-code']);
        $containerId = $created['body']['$id'];

        $refused = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($databaseId, $containerId), $this->getServerHeaders(), [
            $this->getRecordIdParam() => Id::unique(),
            'data' => ['name' => 'Ada', 'salary' => 120000],
            'permissions' => [Permission::read(Role::any(), 'name')],
        ]);

        $this->assertEquals(400, $refused['headers']['status-code']);

        // A row permission naming no column is unaffected.
        $plain = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($databaseId, $containerId), $this->getServerHeaders(), [
            $this->getRecordIdParam() => Id::unique(),
            'data' => ['name' => 'Ada', 'salary' => 120000],
            'permissions' => [Permission::read(Role::any())],
        ]);

        $this->assertEquals(201, $plain['headers']['status-code']);
    }

    /**
     * The grant is enforced, not merely recorded.
     *
     * Every other test here speaks with an API key, which is unrestricted, so none of them
     * reach the masking. A request carrying only the project is the "any" role, which here
     * holds a grant on one column and nothing else, so what comes back is the proof.
     */
    public function testAGuestReadsOnlyTheGrantedColumn(): void
    {
        // A grant names a declared column, so a schemaless product has nothing for one to
        // name and the library refuses it as a column the collection does not have.
        if (!$this->getSupportForAttributes()) {
            $this->markTestSkipped('Column-scoped permissions need declared columns, which this adapter does not have');
        }

        $databaseId = $this->columnGrantsDatabase('Column Grants Enforced');

        $created = $this->columnGrantsContainer($databaseId, [
            Permission::create(Role::any()),
            Permission::read(Role::any()),
        ]);
        $this->assertEquals(201, $created['headers']['status-code']);
        $containerId = $created['body']['$id'];

        $row = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($databaseId, $containerId), $this->getServerHeaders(), [
            $this->getRecordIdParam() => Id::unique(),
            'data' => ['name' => 'Ada', 'salary' => 120000],
        ]);
        $this->assertEquals(201, $row['headers']['status-code']);
        $rowId = $row['body']['$id'];

        // Narrow "any" to one column, after the row exists.
        $scoped = $this->client->call(Client::METHOD_PUT, $this->getContainerUrl($databaseId, $containerId), $this->getServerHeaders(), [
            'name' => 'Employees',
            'permissions' => [Permission::create(Role::any()), Permission::read(Role::any(), 'name')],
        ]);
        $this->assertEquals(200, $scoped['headers']['status-code']);

        $guest = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        $read = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $containerId, $rowId), $guest);

        $this->assertEquals(200, $read['headers']['status-code'], 'the row is still readable: the grant covers the row, only its columns are narrowed');
        $this->assertEquals('Ada', $read['body']['name']);
        $this->assertArrayNotHasKey('salary', $read['body'], 'a column the role was not granted came back');

        // A listing is shaped the same way.
        $list = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $containerId), $guest);
        $this->assertEquals(200, $list['headers']['status-code']);
        $this->assertArrayNotHasKey('salary', $list['body'][$this->getRecordResource()][0]);

        // The key is withheld, not emptied: the value is still stored.
        $withKey = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $containerId, $rowId), $this->getServerHeaders());
        $this->assertEquals(120000, $withKey['body']['salary']);
    }

    /**
     * A column may be granted at create, not only by a later update.
     */
    public function testAColumnGrantMayBeGivenAtCreate(): void
    {
        // A grant names a declared column, so a schemaless product has nothing for one to
        // name and the library refuses it as a column the collection does not have.
        if (!$this->getSupportForAttributes()) {
            $this->markTestSkipped('Column-scoped permissions need declared columns, which this adapter does not have');
        }

        $databaseId = $this->columnGrantsDatabase('Column Grants At Create');

        // The columns are created with the container, so the grant is judged against the ones
        // the request asks for rather than ones already stored.
        $created = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($databaseId), $this->getServerHeaders(), [
            $this->getContainerIdParam() => Id::unique(),
            'name' => 'Employees',
            'permissions' => [Permission::create(Role::any()), Permission::read(Role::any(), 'name')],
            $this->getIndexAttributesParam() === 'columns' ? 'columns' : 'attributes' => [
                ['key' => 'name', 'type' => 'string', 'size' => 64, 'required' => false],
                ['key' => 'salary', 'type' => 'integer', 'required' => false],
            ],
        ]);

        $this->assertEquals(201, $created['headers']['status-code']);
        $this->assertContains('read("any", "name")', $created['body']['$permissions']);
    }

    /**
     * Dropping the last column-scoped grant clears the stored list, so the collection reads
     * back exactly as one that never had one.
     */
    public function testRemovingTheLastColumnGrantClearsIt(): void
    {
        // A grant names a declared column, so a schemaless product has nothing for one to
        // name and the library refuses it as a column the collection does not have.
        if (!$this->getSupportForAttributes()) {
            $this->markTestSkipped('Column-scoped permissions need declared columns, which this adapter does not have');
        }

        $databaseId = $this->columnGrantsDatabase('Column Grants Cleared');

        $created = $this->columnGrantsContainer($databaseId, [
            Permission::create(Role::any()),
            Permission::read(Role::any()),
        ]);
        $this->assertEquals(201, $created['headers']['status-code']);
        $containerId = $created['body']['$id'];

        $scoped = $this->client->call(Client::METHOD_PUT, $this->getContainerUrl($databaseId, $containerId), $this->getServerHeaders(), [
            'name' => 'Employees',
            'permissions' => [Permission::create(Role::any()), Permission::read(Role::any(), 'name')],
        ]);
        $this->assertContains('read("any", "name")', $scoped['body']['$permissions']);

        $widened = $this->client->call(Client::METHOD_PUT, $this->getContainerUrl($databaseId, $containerId), $this->getServerHeaders(), [
            'name' => 'Employees',
            'permissions' => [Permission::create(Role::any()), Permission::read(Role::any())],
        ]);

        $this->assertEquals(200, $widened['headers']['status-code']);
        $this->assertContains('read("any")', $widened['body']['$permissions']);
        $this->assertNotContains('read("any", "name")', $widened['body']['$permissions']);

        // And it stays cleared on the next read, rather than lingering in the stored list.
        $get = $this->client->call(Client::METHOD_GET, $this->getContainerUrl($databaseId, $containerId), $this->getServerHeaders());
        $this->assertNotContains('read("any", "name")', $get['body']['$permissions']);
    }

    /**
     * A grant goes with the column it names, so nothing is left pointing at a column the
     * collection no longer has -- which is a grant createCollection() would refuse.
     */
    public function testDeletingAColumnDropsItsGrant(): void
    {
        // A grant names a declared column, so a schemaless product has nothing for one to
        // name and the library refuses it as a column the collection does not have.
        if (!$this->getSupportForAttributes()) {
            $this->markTestSkipped('Column-scoped permissions need declared columns, which this adapter does not have');
        }

        $databaseId = $this->columnGrantsDatabase('Column Grants Deleted');

        $created = $this->columnGrantsContainer($databaseId, [
            Permission::create(Role::any()),
            Permission::read(Role::any()),
        ]);
        $this->assertEquals(201, $created['headers']['status-code']);
        $containerId = $created['body']['$id'];

        $scoped = $this->client->call(Client::METHOD_PUT, $this->getContainerUrl($databaseId, $containerId), $this->getServerHeaders(), [
            'name' => 'Employees',
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any(), 'name'),
                Permission::read(Role::any(), 'salary'),
            ],
        ]);
        $this->assertEquals(200, $scoped['headers']['status-code']);

        $deleted = $this->client->call(Client::METHOD_DELETE, $this->getSchemaUrl($databaseId, $containerId, '', 'salary'), $this->getServerHeaders());
        $this->assertEquals(204, $deleted['headers']['status-code']);

        // A column is deleted by a worker, so the grant goes with it once that has run.
        $get = ['body' => ['$permissions' => []]];
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $get = $this->client->call(Client::METHOD_GET, $this->getContainerUrl($databaseId, $containerId), $this->getServerHeaders());

            if (!\in_array('read("any", "salary")', $get['body']['$permissions'], true)) {
                break;
            }

            \usleep(500000);
        }

        $this->assertNotContains('read("any", "salary")', $get['body']['$permissions'], 'a grant outlived the column it named');
        $this->assertContains('read("any", "name")', $get['body']['$permissions'], 'the grant on another column was dropped with it');
    }

    /**
     * The same refusal at create, where the columns are the ones the request asks for.
     *
     * Create writes the metadata row before the collection, so a refusal from the collection
     * has to take the row with it -- otherwise a failed create leaves a table behind that the
     * caller was told it did not get.
     */
    public function testAPermissionNamingAnUnknownColumnIsRejectedAtCreate(): void
    {
        // A grant names a declared column, so a schemaless product has nothing for one to
        // name and the library refuses it as a column the collection does not have.
        if (!$this->getSupportForAttributes()) {
            $this->markTestSkipped('Column-scoped permissions need declared columns, which this adapter does not have');
        }

        $databaseId = $this->columnGrantsDatabase('Column Grants Bad At Create');
        $containerId = \Utopia\Database\Id::unique();

        $refused = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($databaseId), $this->getServerHeaders(), [
            $this->getContainerIdParam() => $containerId,
            'name' => 'Employees',
            'permissions' => [Permission::create(Role::any()), Permission::read(Role::any(), 'nope')],
            $this->getIndexAttributesParam() === 'columns' ? 'columns' : 'attributes' => [
                ['key' => 'name', 'type' => 'string', 'size' => 64, 'required' => false],
            ],
        ]);

        $this->assertEquals(400, $refused['headers']['status-code']);

        // Nothing was left behind by the refusal.
        $get = $this->client->call(Client::METHOD_GET, $this->getContainerUrl($databaseId, $containerId), $this->getServerHeaders());
        $this->assertEquals(404, $get['headers']['status-code'], 'a refused create left the table behind');
    }

    /**
     * A grant follows a column renamed through the API.
     *
     * The library moves the grant on the collection itself. The metadata row keeps its own
     * copy -- the one the API reports and the next update replays -- so if that is not moved
     * too, the row names the old key: unreachable, and replayed back onto the collection as a
     * grant on a column that no longer exists.
     */
    public function testAColumnGrantFollowsARenameThroughTheApi(): void
    {
        // A grant names a declared column, so a schemaless product has nothing for one to
        // name and the library refuses it as a column the collection does not have.
        if (!$this->getSupportForAttributes()) {
            $this->markTestSkipped('Column-scoped permissions need declared columns, which this adapter does not have');
        }

        $databaseId = $this->columnGrantsDatabase('Column Grants Renamed');

        $created = $this->columnGrantsContainer($databaseId, [
            Permission::create(Role::any()),
            Permission::read(Role::any()),
        ]);
        $this->assertEquals(201, $created['headers']['status-code']);
        $containerId = $created['body']['$id'];

        $scoped = $this->client->call(Client::METHOD_PUT, $this->getContainerUrl($databaseId, $containerId), $this->getServerHeaders(), [
            'name' => 'Employees',
            'permissions' => [Permission::create(Role::any()), Permission::read(Role::any(), 'name')],
        ]);
        $this->assertEquals(200, $scoped['headers']['status-code']);

        $renamed = $this->client->call(Client::METHOD_PATCH, $this->getSchemaUrl($databaseId, $containerId, 'string', 'name'), $this->getServerHeaders(), [
            'required' => false,
            'default' => null,
            'newKey' => 'fullName',
        ]);
        $this->assertEquals(200, $renamed['headers']['status-code']);
        $this->waitForAttribute($databaseId, $containerId, 'fullName');

        $get = $this->client->call(Client::METHOD_GET, $this->getContainerUrl($databaseId, $containerId), $this->getServerHeaders());

        $this->assertContains('read("any", "fullName")', $get['body']['$permissions'], 'the grant did not follow the rename');
        $this->assertNotContains('read("any", "name")', $get['body']['$permissions'], 'the grant still names the old key');
    }

    /**
     * A collection that names no column stores nothing extra and reads back exactly as before.
     */
    public function testPermissionsWithoutAColumnAreUnaffected(): void
    {
        // A grant names a declared column, so a schemaless product has nothing for one to
        // name and the library refuses it as a column the collection does not have.
        if (!$this->getSupportForAttributes()) {
            $this->markTestSkipped('Column-scoped permissions need declared columns, which this adapter does not have');
        }

        $databaseId = $this->columnGrantsDatabase('Column Grants None');

        $created = $this->columnGrantsContainer($databaseId, [
            Permission::create(Role::any()),
            Permission::read(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any()),
        ]);

        $this->assertEquals(201, $created['headers']['status-code']);

        foreach (['create("any")', 'read("any")', 'update("any")', 'delete("any")'] as $permission) {
            $this->assertContains($permission, $created['body']['$permissions']);
        }
    }
}
