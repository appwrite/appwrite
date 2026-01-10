<?php

namespace Tests\Unit\Migration;

use Appwrite\Migration\Migration;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Utopia\Database\Document;

abstract class MigrationTest extends TestCase
{
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
