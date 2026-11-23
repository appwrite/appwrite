<?php

namespace Tests\E2E\Traits;

use Swoole\Coroutine;
use Utopia\Database\Validator\Authorization;

use function Swoole\Coroutine\run;

trait InternalTableName
{
    protected function internalTableName(string $databaseId, string $containerId): string
    {
        $sequences = [];
        $read = function () use ($databaseId, $containerId, &$sequences): void {
            global $register;
            $authorization = new Authorization();
            $factory = $register->get('databaseFactory')($register->get('pools'), $register->get('cache'), $authorization);

            $authorization->skip(function () use ($factory, $databaseId, $containerId, &$sequences): void {
                $dbForProject = $factory->project($factory->platform()->getDocument('projects', $this->getProject()['$id']));
                $database = $dbForProject->getDocument('databases', $databaseId);
                $collection = $dbForProject->getDocument('database_' . $database->getSequence(), $containerId);
                $sequences = [(string) $database->getSequence(), (string) $collection->getSequence()];
            });
        };

        if (Coroutine::getCid() >= 0) {
            $read();
        } else {
            run($read);
        }

        $this->assertCount(2, $sequences, 'the database and the collection were read');
        [$databaseSequence, $collectionSequence] = $sequences;
        $this->assertTrue(\ctype_digit($databaseSequence), "database {$databaseId} has a numeric sequence");
        $this->assertTrue(\ctype_digit($collectionSequence), "collection {$containerId} has a numeric sequence");

        return 'database_' . $databaseSequence . '_collection_' . $collectionSequence;
    }

    protected function missingInternalTableName(string $table): string
    {
        $this->assertStringStartsWith('database_', $table);
        [$database] = \explode('_collection_', $table, 2);

        return $database . '_collection_999999999';
    }
}
