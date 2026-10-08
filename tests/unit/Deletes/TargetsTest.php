<?php

declare(strict_types=1);

namespace Tests\Unit\Deletes;

use Appwrite\Deletes\Targets;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\SQLite;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;

final class TargetsTest extends TestCase
{
    private string $file = '';

    protected function tearDown(): void
    {
        if (\is_file($this->file)) {
            \unlink($this->file);
        }
    }

    /**
     * Deleting a user's targets has to finish or fail: a target whose subscribers could
     * not be removed must fail the job, not let it be reported as done.
     */
    public function testDeletingAUsersTargetsFailsWhenTheirSubscribersCannotBeRemoved(): void
    {
        // A target, and no `subscribers` collection to remove its subscribers from.
        $database = $this->database();

        $thrown = null;
        try {
            Targets::delete($database, Query::equal('userInternalId', ['1']));
        } catch (Throwable $th) {
            $thrown = $th;
        }

        $this->assertInstanceOf(DatabaseException::class, $thrown);
    }

    /**
     * The maintenance sweep passes a sink, so the same failure is handed to it and the
     * run carries on to the sweeps behind it.
     */
    public function testASweepHandsTheFailureToItsSink(): void
    {
        $database = $this->database();

        $failures = [];
        Targets::delete($database, Query::equal('userInternalId', ['1']), function (Throwable $th) use (&$failures): void {
            $failures[] = $th;
        });

        $this->assertCount(1, $failures);
        $this->assertInstanceOf(DatabaseException::class, $failures[0]);
    }

    private function database(): Database
    {
        $this->file = (string) \tempnam(\sys_get_temp_dir(), 'targets');

        $adapter = new SQLite(new PDO('sqlite:' . $this->file, null, null, SQLite::getPDOAttributes()));
        $adapter->setEmulateMySQL(true);

        $database = new Database($adapter, new Cache(new NoCache()));
        $database
            ->setAuthorization(new Authorization())
            ->setDatabase('targets')
            ->setNamespace('t_' . \uniqid());
        $database->create();

        $permissions = [Permission::create(Role::any()), Permission::read(Role::any()), Permission::delete(Role::any())];
        $database->createCollection('targets', permissions: $permissions);
        $database->createAttribute('targets', 'userInternalId', Database::VAR_STRING, 16, true);
        $database->createDocument('targets', new Document([
            '$id' => ID::unique(),
            '$permissions' => [Permission::read(Role::any()), Permission::delete(Role::any())],
            'userInternalId' => '1',
        ]));

        return $database;
    }
}
