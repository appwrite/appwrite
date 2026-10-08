<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Databases\Http;

use Appwrite\Event\Event;
use Appwrite\Event\Publisher\Database as DatabasePublisher;
use Appwrite\Extend\Exception;
use Appwrite\Utopia\Response;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Adapter;
use Utopia\Database\Attribute;
use Utopia\Database\AttributeUpdate;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Limit as LimitException;
use Utopia\Database\Format;
use Utopia\Database\RelationshipDeleteAction;
use Utopia\Database\RelationshipType;
use Utopia\Database\Unchanged;
use Utopia\Database\Validator\Authorization;
use Utopia\Query\Schema\ColumnType;

require_once __DIR__ . '/../../../../../../app/init.php';
require_once __DIR__ . '/../../../../../../src/Appwrite/Platform/Modules/Databases/Constants.php';

final class AttributesActionTest extends TestCase
{
    private const string DATABASE_ID = 'library';
    private const string COLLECTION_ID = 'books';
    private const string PHYSICAL_COLLECTION = 'database_7_collection_3';

    public function testCreateAttributeChecksLimitsOnThePhysicalCollection(): void
    {
        $checked = [];
        $dbForDatabases = $this->databases($checked, failAt: 1);

        $exception = $this->createFailing(new Document([
            'key' => 'title',
            'type' => ColumnType::String->value,
            'size' => 64,
            'required' => false,
        ]), $dbForDatabases);

        $this->assertSame(Exception::ATTRIBUTE_LIMIT_EXCEEDED, $exception->getType());
        $this->assertSame([self::PHYSICAL_COLLECTION], $checked);
    }

    public function testCreateTwoWayRelationshipChecksLimitsOnBothPhysicalCollections(): void
    {
        $checked = [];
        $dbForDatabases = $this->databases($checked, failAt: 2);

        $exception = $this->createFailing(new Document([
            'key' => 'author',
            'type' => ColumnType::Relationship->value,
            'size' => 0,
            'required' => false,
            'options' => [
                'relatedCollection' => 'authors',
                'relationType' => RelationshipType::ManyToOne->value,
                'twoWay' => true,
                'twoWayKey' => 'books',
                'onDelete' => RelationshipDeleteAction::Restrict->value,
            ],
        ]), $dbForDatabases);

        $this->assertSame(Exception::ATTRIBUTE_LIMIT_EXCEEDED, $exception->getType());
        $this->assertSame([self::PHYSICAL_COLLECTION, 'database_7_collection_4'], $checked);
    }

    public function testUpdateAttributeAppliesTheWholeChangeInOneCall(): void
    {
        $dbForDatabases = $this->createMock(Database::class);
        $dbForDatabases->expects($this->once())
            ->method('updateAttribute')
            ->with(self::PHYSICAL_COLLECTION, 'count', $this->equalTo(new AttributeUpdate(
                required: false,
                default: 5,
                format: new Format(APP_DATABASE_ATTRIBUTE_INT_RANGE, ['min' => 1, 'max' => 10]),
                key: 'total',
            )))
            ->willReturn(Attribute::integer('total'));

        $updated = $this->update(
            new Document([
                '$id' => '7_3_count',
                'key' => 'count',
                'type' => ColumnType::Integer->value,
                'status' => 'available',
                'default' => 2,
                'format' => APP_DATABASE_ATTRIBUTE_INT_RANGE,
                'formatOptions' => ['min' => 0, 'max' => 100],
            ]),
            $dbForDatabases,
            ColumnType::Integer->value,
            ['default' => 5, 'required' => false, 'min' => 1, 'max' => 10, 'newKey' => 'total'],
        );

        $this->assertSame('7_3_total', $updated->getId());
        $this->assertSame(['min' => 1, 'max' => 10], $updated->getAttribute('formatOptions'));
    }

    public function testUpdateAttributeClearsTheDefaultInTheSameCall(): void
    {
        $dbForDatabases = $this->createMock(Database::class);
        $dbForDatabases->expects($this->once())
            ->method('updateAttribute')
            ->with(self::PHYSICAL_COLLECTION, 'title', $this->callback(
                static fn (AttributeUpdate $update): bool => $update->changesDefault()
                    && $update->default === null
                    && $update->format === Unchanged::Value
                    && $update->key === null
            ))
            ->willReturn(Attribute::string('title', 64));

        $this->update(
            new Document([
                '$id' => '7_3_title',
                'key' => 'title',
                'type' => ColumnType::String->value,
                'status' => 'available',
                'size' => 64,
                'default' => 'untitled',
                'format' => '',
            ]),
            $dbForDatabases,
            ColumnType::String->value,
            ['default' => null, 'required' => false],
        );
    }

    /**
     * @param list<string> $checked
     */
    private function databases(array &$checked, int $failAt): Database
    {
        $dbForDatabases = $this->createStub(Database::class);
        $dbForDatabases->method('getAdapter')->willReturn($this->createStub(Adapter::class));
        $dbForDatabases->method('checkAttribute')->willReturnCallback(
            static function (string $collection) use (&$checked, $failAt): bool {
                $checked[] = $collection;
                if (\count($checked) === $failAt) {
                    throw new LimitException('Column limit reached');
                }

                return true;
            }
        );

        return $dbForDatabases;
    }

    private function createFailing(Document $attribute, Database $dbForDatabases): Exception
    {
        $dbForProject = $this->project($attribute);
        $dbForProject->method('createDocument')->willReturnArgument(1);

        try {
            $this->action()->create(
                $attribute,
                $dbForProject,
                static fn (): Database => $dbForDatabases,
                $this->createStub(Response::class),
                $this->createStub(DatabasePublisher::class),
                $this->createStub(Event::class),
                $this->authorization(),
            );
        } catch (Exception $exception) {
            return $exception;
        }

        $this->fail('The attribute limit must be enforced');
    }

    private function project(Document $attribute): Database&Stub
    {
        $dbForProject = $this->createStub(Database::class);
        $dbForProject->method('getDocument')->willReturnCallback(
            static fn (string $collection, string $id): Document => match ([$collection, $id]) {
                ['databases', self::DATABASE_ID] => new Document(['$id' => self::DATABASE_ID, '$sequence' => '7', 'type' => DATABASE_TYPE_LEGACY]),
                ['database_7', self::COLLECTION_ID] => new Document(['$id' => self::COLLECTION_ID, '$sequence' => '3', 'indexes' => []]),
                ['database_7', 'authors'] => new Document(['$id' => 'authors', '$sequence' => '4', 'indexes' => []]),
                ['attributes', $attribute->getId()] => $attribute,
                default => new Document(),
            }
        );
        $dbForProject->method('updateDocument')->willReturnArgument(2);

        return $dbForProject;
    }

    private function authorization(): Authorization
    {
        $authorization = $this->createStub(Authorization::class);
        $authorization->method('skip')->willReturnCallback(static fn (callable $callback): mixed => $callback());

        return $authorization;
    }

    private function action(): AttributeAction
    {
        return new AttributeAction(self::DATABASE_ID, self::COLLECTION_ID);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function update(Document $stored, Database $dbForDatabases, string $type, array $arguments): Document
    {
        return $this->action()->update(
            $stored->getAttribute('key'),
            $this->project($stored),
            static fn (): Database => $dbForDatabases,
            $this->createStub(Event::class),
            $this->authorization(),
            $type,
            $arguments,
        );
    }
}
