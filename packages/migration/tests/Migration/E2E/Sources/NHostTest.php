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
use Utopia\Migration\Sources\NHost;
use Utopia\Migration\Transfer;
use Utopia\Tests\Unit\Adapters\MockDestination;

class NHostTest extends Base
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
                $pdo = new \PDO('pgsql:host=nhost-db'.';port=5432;dbname=postgres', 'postgres', 'postgres');
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

                if ($pdo->query('SELECT 1')->fetchColumn() === 1) {
                    break;
                } else {
                    var_dump('DB was offline, waiting 1s then retrying.');
                }
            } catch (\PDOException $e) {
            }

            sleep(1);
            $tries--;
        }

        if (! $pdo || $tries === 0) {
            throw new \Exception('DB was offline after 5 tries');
        }

        // Check Storage is online and ready
        $tries = 5;
        while ($tries > 0) {
            try {
                $this->call('GET', 'http://nhost-storage/', ['Content-Type' => 'text/plain']);

                break;
            } catch (\Exception) {
            }

            sleep(5);
            $tries--;
        }

        if ($tries === 0) {
            throw new \Exception('Storage was offline after 5 tries');
        }

        $this->source = new NHost(
            'xxxxxxxxxxxx',
            'eu-central-1',
            'hasuraSecret',
            'postgres',
            'postgres',
            'password'
        );
        $this->source->pdo = new \PDO('pgsql:host=nhost-db'.';port=5432;dbname=postgres', 'postgres', 'postgres');
        $this->source->storageURL = 'http://nhost-storage';

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
        $foundUser = null;

        foreach ($users as $user) {
            /** @var User $user */
            if ($user->getEmail() === 'test@test.com') {
                $foundUser = $user;
            }

            break;
        }

        if (! $foundUser) {
            $this->fail('User "test@test.com" not found');
        }

        $this->assertSame('success', $foundUser->getStatus());
        $this->assertSame('$2a$10$ARQ/f.K6OmCjZ8XF0U.6fezPMlxDqsmcl0Rs6xQVkvj62u7gcSzOW', $foundUser->getPasswordHash()->getHash());
        $this->assertSame('bcrypt', $foundUser->getPasswordHash()->getAlgorithm());
        $this->assertSame('test@test.com', $foundUser->getUsername());
    }

    #[Depends('testValidateDestinationErrors')]
    public function testValidateDatabaseTransfer($state)
    {
        // Find known database
        $databases = $state['source']->cache->get(Resource::TYPE_DATABASE);
        $foundDatabase = null;

        foreach ($databases as $database) {
            /** @var Database $database */
            if ($database->getDatabaseName() === 'public') {
                $foundDatabase = $database;
            }

            break;
        }

        if (! $foundDatabase) {
            $this->fail('Database "public" not found');
        }

        $this->assertSame('success', $foundDatabase->getStatus());
        $this->assertSame('public', $foundDatabase->getDatabaseName());
        $this->assertSame('public', $foundDatabase->getId());

        // Find known table
        $tables = $state['source']->cache->get(Resource::TYPE_TABLE);
        $foundTable = null;

        foreach ($tables as $table) {
            /** @var Table $table */
            if ($table->getTableName() === 'TestTable') {
                $foundTable = $table;

                break;
            }
        }

        if (! $foundTable) {
            $this->fail('Table "TestTable" not found');
        }

        $this->assertSame('success', $foundTable->getStatus());
        $this->assertSame('TestTable', $foundTable->getTableName());
        $this->assertSame('TestTable', $foundTable->getId());
        $this->assertSame('public', $foundTable->getDatabase()->getId());

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

    #[Depends('testValidateDatabaseTransfer')]
    public function testValidateStorageTransfer($state): void
    {
        // Find known bucket
        $buckets = $state['source']->cache->get(Resource::TYPE_BUCKET);
        $foundBucket = null;

        foreach ($buckets as $bucket) {
            /** @var Bucket $bucket */
            if ($bucket->getId() === 'default') {
                $foundBucket = $bucket;
            }

            break;
        }

        if (! $foundBucket) {
            $this->fail('Bucket "default" not found');
        }

        $this->assertSame('success', $foundBucket->getStatus());
        $this->assertSame('default', $foundBucket->getId());

        // Find known file
        $files = $state['source']->cache->get(Resource::TYPE_FILE);
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
        $this->assertSame('default', $foundFile->getBucket()->getId());
        $this->assertSame('image/png', $foundFile->getMimeType());
        $this->assertSame(679233, $foundFile->getSize());
        $this->assertSame('', $foundFile->getData()); // Memory Leak Check
    }
}
