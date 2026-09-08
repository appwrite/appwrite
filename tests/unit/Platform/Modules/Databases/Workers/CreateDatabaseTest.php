<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Databases\Workers;

use Appwrite\Platform\Modules\Databases\Http\Databases\Create;
use Appwrite\Platform\Modules\Databases\Workers\Databases;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../../../app/init.php';

final class CreateDatabaseTest extends TestCase
{
    public function testDatabaseTypeCreateDatabaseConstantDefined(): void
    {
        $this->assertTrue(defined('DATABASE_TYPE_CREATE_DATABASE'));
        $this->assertEquals('createDatabase', DATABASE_TYPE_CREATE_DATABASE);
    }

    public function testCreateActionName(): void
    {
        $action = new Create();
        $this->assertEquals('createDatabase', $action->getName());
    }

    public function testDatabasesWorkerName(): void
    {
        $worker = new Databases();
        $this->assertEquals('databases', $worker->getName());
    }
}
