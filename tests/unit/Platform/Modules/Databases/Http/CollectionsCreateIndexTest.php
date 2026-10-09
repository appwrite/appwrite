<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Databases\Http;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Create;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Adapter;
use Utopia\Database\Attribute;
use Utopia\Database\Capability;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Index;
use Utopia\Query\Schema\IndexType;

require_once __DIR__ . '/../../../../../../app/init.php';
require_once __DIR__ . '/../../../../../../src/Appwrite/Platform/Modules/Databases/Constants.php';

final class CollectionsCreateIndexTest extends TestCase
{
    public function testInlineIndexOnAScalarAttributeKeepsItsOrder(): void
    {
        $built = $this->build(['key' => 'byTitle', 'type' => IndexType::Key->value, 'attributes' => ['title'], 'orders' => ['DESC']], definedAttributes: true);

        $this->assertInstanceOf(Index::class, $built['collection']);
        $this->assertSame([null], $built['collection']->lengths);
        $this->assertSame(['DESC'], $built['document']->getAttribute('orders'));
    }

    public function testInlineIndexOnAnArrayAttributeUsesTheArrayIndexLength(): void
    {
        $built = $this->build(['key' => 'byTags', 'type' => IndexType::Key->value, 'attributes' => ['tags'], 'orders' => ['ASC']], definedAttributes: false);

        $this->assertSame([Database::MAX_ARRAY_INDEX_LENGTH], $built['document']->getAttribute('lengths'), 'an array attribute declared inline is looked up among the attributes created with it');
        $this->assertSame([null], $built['document']->getAttribute('orders'));
    }

    public function testInlineIndexOnAnArrayAttributeIsRefusedWhereAttributesAreDefined(): void
    {
        try {
            $this->build(['key' => 'byTags', 'type' => IndexType::Key->value, 'attributes' => ['tags']], definedAttributes: true);
        } catch (Exception $refusal) {
            $this->assertSame(Exception::INDEX_INVALID, $refusal->getType());

            return;
        }

        $this->fail('an index on an array attribute must be refused on an adapter with defined attributes');
    }

    /**
     * @param array<string, mixed> $definition
     * @return array{collection: Index, document: Document}
     */
    private function build(array $definition, bool $definedAttributes): array
    {
        $adapter = $this->createStub(Adapter::class);
        $adapter->method('supports')->willReturnCallback(
            static fn (Capability $capability): bool => $capability === Capability::DefinedAttributes && $definedAttributes,
        );
        $database = $this->createStub(Database::class);
        $database->method('getAdapter')->willReturn($adapter);

        $action = new class () extends Create {
            /**
             * @param array<string, mixed> $definition
             * @param list<Attribute> $attributes
             * @return array{collection: Index, document: Document}
             */
            public function index(array $definition, array $attributes, Database $database): array
            {
                return $this->buildIndexDocument(
                    new Document(['$id' => 'shop', '$sequence' => '1']),
                    new Document(['$id' => 'books', '$sequence' => '2']),
                    $definition,
                    $attributes,
                    $database,
                );
            }
        };

        return $action->index($definition, [
            Attribute::string(key: 'title', size: 128),
            Attribute::string(key: 'tags', size: 64, array: true),
        ], $database);
    }
}
