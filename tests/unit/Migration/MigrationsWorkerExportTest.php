<?php

namespace Tests\Unit\Migration;

use Appwrite\Event\Publisher\Mail as MailPublisher;
use Appwrite\Event\Realtime;
use Appwrite\Platform\Workers\Migrations;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;

class MigrationsWorkerExportTest extends TestCase
{
    /**
     * An export initiated without a user session (e.g. via an API key) carries an
     * empty `userInternalId`. handleDataExportComplete() must skip the completion
     * notification in that case rather than look the user up by the integer-only
     * `$sequence` attribute with an empty value, which throws a query validation
     * error. Escaping processMigration()'s finally block, that exception masks the
     * migration result and, in the shared worker, disrupts other in-flight
     * migrations.
     *
     * Asserting the platform database is never queried proves the guard
     * short-circuits before the user lookup: without the guard the worker calls
     * findOne() (building Query::equal('$sequence', [''])), so this fails without
     * the fix and passes with it.
     */
    public function testExportCompletionSkipsWhenNoInitiatingUser(): void
    {
        $dbForPlatform = $this->createMock(Database::class);
        $dbForPlatform->expects($this->never())->method('findOne');

        $worker = new class () extends Migrations {
            public function callHandleDataExportComplete(
                Database $dbForPlatform,
                Document $migration,
                MailPublisher $publisherForMails,
                Realtime $queueForRealtime,
                Authorization $authorization,
            ): void {
                $this->dbForPlatform = $dbForPlatform;
                $this->handleDataExportComplete(
                    new Document([]),
                    $migration,
                    $publisherForMails,
                    $queueForRealtime,
                    [],
                    $authorization,
                );
            }
        };

        $migration = new Document([
            '$id' => 'migration-without-user',
            'options' => ['userInternalId' => ''],
        ]);

        $worker->callHandleDataExportComplete(
            $dbForPlatform,
            $migration,
            $this->createStub(MailPublisher::class),
            $this->createStub(Realtime::class),
            $this->createStub(Authorization::class),
        );
    }
}
