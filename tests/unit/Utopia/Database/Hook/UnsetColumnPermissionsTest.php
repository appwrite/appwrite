<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Hook;

use Appwrite\Utopia\Database\Hook\UnsetColumnPermissions;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Event;

final class UnsetColumnPermissionsTest extends TestCase
{
    public function testDropsTheColumnFromACollectionDefinition(): void
    {
        $definition = new Document([
            '$id' => 'database_1_collection_2',
            'name' => 'Employees',
            'columnPermissions' => ['read("any", "name")'],
        ]);

        $decorated = (new UnsetColumnPermissions())->decorate(
            Event::DocumentRead,
            new Document(['$id' => Database::METADATA]),
            $definition
        );

        $this->assertFalse($decorated->offsetExists('columnPermissions'));
        $this->assertSame('Employees', $decorated->getAttribute('name'));
    }

    public function testLeavesRowsOfEveryOtherCollectionAlone(): void
    {
        $row = new Document([
            '$id' => 'row',
            'columnPermissions' => ['read("any", "name")'],
        ]);

        $decorated = (new UnsetColumnPermissions())->decorate(
            Event::DocumentRead,
            new Document(['$id' => 'database_1_collection_2']),
            $row
        );

        // Only the metadata collection declares the column in the later release, so a row that
        // happens to carry a same-named attribute is the caller's own data.
        $this->assertSame(['read("any", "name")'], $decorated->getAttribute('columnPermissions'));
    }

    public function testDropsTheColumnOnEveryEventThatReturnsADefinition(): void
    {
        foreach ([Event::DocumentRead, Event::DocumentFind, Event::DocumentCreate, Event::DocumentUpdate] as $event) {
            $decorated = (new UnsetColumnPermissions())->decorate(
                $event,
                new Document(['$id' => Database::METADATA]),
                new Document(['$id' => 'database_1_collection_2', 'columnPermissions' => []])
            );

            $this->assertFalse($decorated->offsetExists('columnPermissions'), $event->name);
        }
    }
}
