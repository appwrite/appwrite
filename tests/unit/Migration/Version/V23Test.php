<?php

declare(strict_types=1);

namespace Tests\Unit\Migration\Version;

use Appwrite\Migration\Version\V23;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;

final class V23Test extends TestCase
{
    private mixed $collections;

    #[\Override]
    protected function setUp(): void
    {
        $this->collections = Config::getParam('collections');
    }

    #[\Override]
    protected function tearDown(): void
    {
        Config::setParam('collections', $this->collections);
    }

    public function testResizesMigrationErrorsToConfiguredSize(): void
    {
        $configuredSize = 2_000_000;
        Config::setParam('collections', [
            'projects' => [
                'migrations' => [
                    'attributes' => [Attribute::string(key: 'errors', size: $configuredSize, required: true, array: true)],
                ],
            ],
        ]);

        $authorization = new Authorization();
        $authorization->disable();
        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization($authorization)
            ->setDatabase('migrationTests')
            ->setNamespace('v23_' . \uniqid());
        $database->create();

        $database->createCollection(Collection::create(
            id: 'migrations',
            attributes: [Attribute::string(key: 'errors', size: 1_000_000, required: true, array: true)],
        ));
        $database->createCollection(Collection::create(id: 'databases'));

        $migration = new class ($database) extends V23 {
            public function __construct(Database $database)
            {
                $this->dbForProject = $database;
                $this->project = new Document(['$id' => 'project', '$sequence' => '1']);
                $this->collections = ['projects' => [
                    'migrations' => ['$collection' => Database::METADATA, '$id' => 'migrations'],
                ]];
            }

            #[\Override]
            protected function migrateBuckets(): void
            {
            }
        };

        \ob_start();
        try {
            $migration->execute();
        } finally {
            \ob_end_clean();
        }

        $errors = $database->getCollection('migrations')->attributes()[0];
        $this->assertSame('errors', $errors->key);
        $this->assertSame($configuredSize, $errors->size);
    }

    public function testErrorsSizeIsReadFromTheConfiguredAttributeModel(): void
    {
        self::configure([
            Attribute::string(key: 'status'),
            Attribute::string(key: 'errors', size: 2_000_000, array: true),
        ]);

        $this->assertSame(2_000_000, self::errorsSize(), 'the configured size, not the fallback, is the size V23 migrates to');
    }

    public function testErrorsSizeFallsBackWhenTheAttributeIsNotConfigured(): void
    {
        self::configure([Attribute::string(key: 'status')]);

        $this->assertSame(1_000_000, self::errorsSize());
    }

    /**
     * @param list<Attribute> $attributes
     */
    private static function configure(array $attributes): void
    {
        Config::setParam('collections', ['projects' => ['migrations' => ['attributes' => $attributes]]]);
    }

    private static function errorsSize(): int
    {
        $migration = new class () extends V23 {
            public function __construct()
            {
            }

            public function errorsSize(): int
            {
                return self::configuredErrorsSize();
            }
        };

        return $migration->errorsSize();
    }
}
