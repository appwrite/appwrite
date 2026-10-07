<?php

namespace Tests\E2E\Adapter;

use PHPUnit\Framework\TestCase;
use Tests\E2E\Adapter\Scopes\AttributeTests;
use Tests\E2E\Adapter\Scopes\CollectionTests;
use Tests\E2E\Adapter\Scopes\CustomDocumentTypeTests;
use Tests\E2E\Adapter\Scopes\DocumentTests;
use Tests\E2E\Adapter\Scopes\GeneralTests;
use Tests\E2E\Adapter\Scopes\IndexTests;
use Tests\E2E\Adapter\Scopes\ObjectAttributeTests;
use Tests\E2E\Adapter\Scopes\OperatorTests;
use Tests\E2E\Adapter\Scopes\PermissionTests;
use Tests\E2E\Adapter\Scopes\RelationshipTests;
use Tests\E2E\Adapter\Scopes\SchemalessTests;
use Tests\E2E\Adapter\Scopes\SpatialTests;
use Tests\E2E\Adapter\Scopes\VectorTests;
use Utopia\Database\Database;
use Utopia\Database\Validator\Authorization;

\ini_set('memory_limit', '2048M');

abstract class Base extends TestCase
{
    use CollectionTests;
    use CustomDocumentTypeTests;
    use DocumentTests;
    use AttributeTests;
    use IndexTests;
    use OperatorTests;
    use PermissionTests;
    use RelationshipTests;
    use SpatialTests;
    use SchemalessTests;
    use ObjectAttributeTests;
    use VectorTests;
    use GeneralTests;

    protected static string $namespace;

    /**
     * @var Authorization
     */
    protected static ?Authorization $authorization = null;

    /**
     * @return Database
     */
    abstract protected function getDatabase(): Database;

    /**
     * @param string $collection
     * @param string $column
     *
     * @return bool
     */
    abstract protected function deleteColumn(string $collection, string $column): bool;

    /**
     * @param string $collection
     * @param string $index
     *
     * @return bool
     */
    abstract protected function deleteIndex(string $collection, string $index): bool;

    public function setUp(): void
    {
        if (is_null(self::$authorization)) {
            self::$authorization = new Authorization();
        }

        self::$authorization->addRole('any');
    }

    public function tearDown(): void
    {
        self::$authorization->setDefaultStatus(true);

    }

    protected string $testDatabase = 'utopiaTests';

}
