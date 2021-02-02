<?php

namespace Appwrite\Tests;

use Appwrite\Database\Document;
use Appwrite\Migration\Migration;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

abstract class MigrationTest extends TestCase
{
    /**
     * @var PDO
     */
    protected \PDO $pdo;

    /**
     * @var Migration
     */
    protected Migration $migration;

    /**
     * @var ReflectionMethod
     */
    protected ReflectionMethod $method;

    /**
     * Runs every document fix twice, to prevent corrupted data on multiple migrations.
     * 
     * @param Document $document
     */
    protected function fixDocument(Document $document)
    {
        return $this->method->invokeArgs($this->migration, [
            $this->method->invokeArgs($this->migration, [$document])
        ]);
    }

}
