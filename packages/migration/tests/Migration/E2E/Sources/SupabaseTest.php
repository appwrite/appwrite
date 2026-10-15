<?php

namespace Utopia\Tests\E2E\Sources;

use PHPUnit\Framework\Attributes\Depends;
use Utopia\Migration\Destination;
use Utopia\Migration\Resource;
use Utopia\Migration\Resources\Auth\User;
use Utopia\Migration\Resources\Database\Database;
use Utopia\Migration\Resources\Database\Table;
use Utopia\Migration\Resources\Storage\Bucket;
use Utopia\Migration\Resources\Storage\File;
use Utopia\Migration\Source;
use Utopia\Migration\Sources\Supabase;
use Utopia\Migration\Transfer;
use Utopia\Tests\Unit\Adapters\MockDestination;

class SupabaseTest extends Base
{
    protected ?Source $source = null;

    protected ?Transfer $transfer = null;

    protected ?Destination $destination = null;

    /**
     * @throws \Exception
     */
    protected function setUp(): void
    {
        // Check DB is online and ready
        $pdo = null;
        $tries = 5;

        while ($tries > 0) {
            try {
                $pdo = new \PDO('pgsql:host=supabase-db'.';port=5432;dbname=postgres', 'postgres', 'postgres');
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

                if ($pdo->query('SELECT 1')->fetchColumn() === 1) {
                    break;
                } else {
                    var_dump('DB was offline, waiting 1s then retrying.');
                }
            } catch (\PDOException) {
            }

            sleep(1);
            $tries--;
        }

        if (! $pdo || $tries === 0) {
            throw new \Exception('DB was offline after 5 tries');
        }

        $this->source = new Supabase(
            'http://supabase-api',
            'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
            'supabase-db',
            'postgres',
            'postgres',
            'postgres'
        );

        $this->destination = new MockDestination();
        $this->transfer = new Transfer($this->source, $this->destination);
    }

    /**
     * @throws \Exception
     */
    public function testSourceReport()
    {
        // Test report all
        $report = $this->source->report();

        $this->assertNotEmpty($report);

        return [
            'report' => $report,
        ];
    }

    /**
     * @throws \Exception
     */
    #[Depends('testSourceReport')]
    public function testRunTransfer($state)
    {
        $this->transfer->run(
            $this->source->getSupportedResources(),
            function () {
            }
        );

        $this->assertCount(0, $this->transfer->getReport('error'));

        return array_merge($state, [
            'transfer' => $this->transfer,
            'source' => $this->source,
            'destination' => $this->destination,
        ]);
    }

    #[Depends('testRunTransfer')]
    public function testValidateSourceErrors($state)
    {
        /** @var Transfer $transfer */
        $transfer = $state['transfer'];

        /** @var Source $source */
        $source = $state['source'];

        $statusCounters = $transfer->getStatusCounters();
        $this->assertNotEmpty($statusCounters);

        $errors = $source->getErrors();

        if (!empty($errors)) {
            $this->fail('[Source] Failed: ' . \json_encode($errors, JSON_PRETTY_PRINT));
        }

        return $state;
    }

    #[Depends('testValidateSourceErrors')]
    public function testValidateDestinationErrors($state)
    {
        /** @var Transfer $transfer */
        $transfer = $state['transfer'];

        /** @var Destination $destination */
        $destination = $state['destination'];

        $statusCounters = $transfer->getStatusCounters();
        $this->assertNotEmpty($statusCounters);

        $errors = $destination->getErrors();

        if (!empty($errors)) {
            $this->fail('[Destination] Failed: ' . \json_encode($errors, JSON_PRETTY_PRINT));
        }

        return $state;
    }

    #[Depends('testValidateDestinationErrors')]
    public function testValidateUserTransfer($state): void
    {
        // Find known user
        $users = $state['source']->cache->get(Resource::TYPE_USER);
        $this->assertGreaterThan(0, count($users));
        $foundUser = null;

        foreach ($users as $user) {
            /** @var User $user */
            if ($user->getEmail() == 'albert.kihn95@yahoo.com') {
                $foundUser = $user;

                break;
            }
        }

        if (! $foundUser) {
            $this->fail('User "albert.kihn95@yahoo.com" not found');
        }

        $this->assertSame('success', $foundUser->getStatus());
        $this->assertSame('$2a$10$NGZAAOfXeheUoH9V3dnRoeR.r3J5ynnSZ6KjvHxOUlV8XUrulJzQa', $foundUser->getPasswordHash()->getHash());
        $this->assertSame('bcrypt', $foundUser->getPasswordHash()->getAlgorithm());
    }

    #[Depends('testValidateDestinationErrors')]
    public function testValidateDatabaseTransfer($state)
    {
        // Find known database
        $databases = $state['source']->cache->get(Resource::TYPE_DATABASE);
        $this->assertGreaterThan(0, count($databases));
        $foundDatabase = null;

        foreach ($databases as $database) {
            /** @var Database $database */
            if ($database->getDatabaseName() === 'public') {
                $foundDatabase = $database;

                break;
            }
        }

        if (! $foundDatabase) {
            $this->fail('Database "public" not found');
        }

        $this->assertSame('success', $foundDatabase->getStatus());
        $this->assertSame('public', $foundDatabase->getDatabaseName());
        $this->assertSame('public', $foundDatabase->getId());

        // Find Known Tables
        $tables = $state['source']->cache->get(Resource::TYPE_TABLE);
        $this->assertGreaterThan(0, count($tables));

        $foundTable = null;

        foreach ($tables as $table) {
            /** @var Table $table */
            if ($table->getDatabase()->getDatabaseName() === 'public' && $table->getTableName() === 'test') {
                $foundTable = $table;

                break;
            }
        }

        if (! $foundTable) {
            $this->fail('Table "test" not found');
        }

        $this->assertSame('success', $foundTable->getStatus());
        $this->assertSame('test', $foundTable->getTableName());
        $this->assertSame('public', $foundTable->getDatabase()->getDatabaseName());
        $this->assertSame('public', $foundTable->getDatabase()->getId());

        // Find Known Documents
        $documents = $state['source']->cache->get(Resource::TYPE_ROW);
        //        $this->assertGreaterThan(0, count($documents));
        //
        //        $foundDocument = null;
        //
        //        foreach ($documents as $document) {
        //            /** @var Document $document */
        //            if ($document->getTable()->getDatabase()->getDatabaseName() === 'public' && $document->getTable()->getCollectionName() === 'test') {
        //                $foundDocument = $document;
        //            }
        //
        //            break;
        //        }
        //
        //        if (! $foundDocument) {
        //            $this->fail('Document "1" not found');
        //        }
        //
        //        $this->assertSame('success', $foundDocument->getStatus());

        return $state;
    }

    #[Depends('testValidateDatabaseTransfer')]
    public function testDatabaseFunctionalDefaultsWarn($state): void
    {
        // Find known table
        $tables = $state['source']->cache->get(Resource::TYPE_TABLE);
        $foundTable = null;

        foreach ($tables as $table) {
            /** @var Table $table */
            if ($table->getTableName() === 'FunctionalDefaultTestTable') {
                $foundTable = $table;
            }

            break;
        }

        if (! $foundTable) {
            $this->fail('Table "FunctionalDefaultTestTable" not found');
        }

        $this->assertSame('warning', $foundTable->getStatus());
        $this->assertSame('FunctionalDefaultTestTable', $foundTable->getTableName());
        $this->assertSame('FunctionalDefaultTestTable', $foundTable->getId());
        $this->assertSame('public', $foundTable->getDatabase()->getId());
    }

    #[Depends('testValidateDestinationErrors')]
    public function testValidateStorageTransfer($state): void
    {
        // Find known bucket
        $buckets = $state['source']->cache->get(Resource::TYPE_BUCKET);
        $this->assertGreaterThan(0, count($buckets));

        $foundBucket = null;

        foreach ($buckets as $bucket) {
            /** @var Bucket $bucket */
            if ($bucket->getBucketName() === 'Test Bucket 1') {
                $foundBucket = $bucket;
            }

            break;
        }

        if (! $foundBucket) {
            $this->fail('Bucket "Test Bucket 1" not found');
        }

        $this->assertSame('success', $foundBucket->getStatus());

        // Find known file
        $files = $state['source']->cache->get(Resource::TYPE_FILE);
        $this->assertGreaterThan(0, count($files));

        $foundFile = null;

        foreach ($files as $file) {
            /** @var File $file */
            if ($file->getFileName() === 'tulips.png') {
                $foundFile = $file;
            }

            break;
        }

        if (! $foundFile) {
            $this->fail('File "tulips.png" not found');
        }
        /** @var File $foundFile */
        $this->assertSame('success', $foundFile->getStatus());
        $this->assertSame('tulips.png', $foundFile->getFileName());
        $this->assertSame('image/png', $foundFile->getMimeType());
        $this->assertSame(679233, $foundFile->getSize());
        $this->assertSame('', $foundFile->getData()); // Memory Leak Check
    }
}
