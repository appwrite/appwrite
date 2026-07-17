<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Databases;

use Appwrite\Platform\Modules\Databases\Pool;
use PHPUnit\Framework\TestCase;
use Utopia\Config\Config;

final class PoolTest extends TestCase
{
    private const array VARIABLES = [
        '_APP_DATABASE_DOCUMENTSDB_KEYS',
        '_APP_DATABASE_DOCUMENTSDB_OVERRIDE',
        '_APP_DATABASE_DOCUMENTSDB_SHARED_TABLES',
        '_APP_DATABASE_SHARED_NAMESPACE',
        '_APP_DATABASE_SHARED_TABLES',
        '_APP_DATABASE_VECTORSDB_KEYS',
        '_APP_DATABASE_VECTORSDB_OVERRIDE',
        '_APP_DATABASE_VECTORSDB_SHARED_TABLES',
        '_APP_DB_HOST_DOCUMENTSDB',
        '_APP_DB_HOST_VECTORSDB',
    ];

    /** @var array<string, mixed> */
    private array $params;

    /** @var array<string, string|false> */
    private array $variables = [];

    protected function setUp(): void
    {
        $this->params = Config::$params;

        foreach (self::VARIABLES as $variable) {
            $this->variables[$variable] = \getenv($variable);
            \putenv($variable);
        }
    }

    protected function tearDown(): void
    {
        Config::$params = $this->params;

        foreach ($this->variables as $variable => $value) {
            \putenv($value === false ? $variable : $variable . '=' . $value);
        }
    }

    public function testReturnsProjectDatabaseForTablesDB(): void
    {
        $dsn = 'mysql://project?database=appwrite';

        $this->assertSame($dsn, Pool::dsn('tablesdb', 'default', $dsn));
    }

    public function testSelectsDocumentsDatabasePool(): void
    {
        Config::setParam('pools-documentsdb', ['documents']);

        $this->assertSame('mongodb://documents', Pool::dsn('documentsdb', 'default', null));
    }

    public function testSelectsVectorsDatabasePool(): void
    {
        Config::setParam('pools-vectorsdb', ['vectors']);

        $this->assertSame('postgresql://vectors', Pool::dsn('vectorsdb', 'default', null));
    }

    public function testSelectsSharedPoolForSharedProject(): void
    {
        Config::setParam('pools-documentsdb', ['documents-dedicated', 'documents-shared']);
        \putenv('_APP_DATABASE_SHARED_TABLES=projects-shared');
        \putenv('_APP_DATABASE_DOCUMENTSDB_SHARED_TABLES=documents-shared');
        \putenv('_APP_DATABASE_SHARED_NAMESPACE=tenant');

        $this->assertSame(
            'appwrite://documents-shared?database=appwrite&namespace=tenant',
            Pool::dsn('documentsdb', 'default', 'mysql://projects-shared')
        );
    }

    public function testSelectsDedicatedPoolForDedicatedProject(): void
    {
        Config::setParam('pools-documentsdb', ['documents-dedicated', 'documents-shared']);
        \putenv('_APP_DATABASE_SHARED_TABLES=projects-shared');
        \putenv('_APP_DATABASE_DOCUMENTSDB_SHARED_TABLES=documents-shared');

        $this->assertSame(
            'mongodb://documents-dedicated',
            Pool::dsn('documentsdb', 'default', 'mysql://projects-dedicated')
        );
    }

    public function testSelectsPoolForRegion(): void
    {
        Config::setParam('pools-documentsdb', ['default-documents']);
        \putenv('_APP_DATABASE_DOCUMENTSDB_KEYS=fra-documents,nyc-documents');

        $this->assertSame(
            'mongodb://fra-documents',
            Pool::dsn('documentsdb', 'fra', null)
        );
    }

    public function testOverrideTakesPrecedence(): void
    {
        Config::setParam('pools-documentsdb', ['documents-default', 'documents-override']);
        \putenv('_APP_DATABASE_DOCUMENTSDB_OVERRIDE=documents-override');

        $this->assertSame(
            'mongodb://documents-override',
            Pool::dsn('documentsdb', 'default', null)
        );
    }
}
