<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Databases\Http;

use Appwrite\Extend\Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Database;

require_once __DIR__ . '/../../../../../../app/init.php';
require_once __DIR__ . '/../../../../../../src/Appwrite/Platform/Modules/Databases/Constants.php';

final class DocumentsWriteTest extends TestCase
{
    public function testReadOnlyAttributesAreStrippedFromTheTopLevelOnlyAsOnMain(): void
    {
        $stripped = $this->action()->strip([
            '$id' => 'movie',
            '$sequence' => '7',
            '$databaseId' => 'library',
            '$collectionId' => 'movies',
            '$createdAt' => '2020-01-01T00:00:00.000+00:00',
            'details' => ['$id' => 'value', '$sequence' => '1', '$createdAt' => '2020-01-01T00:00:00.000+00:00'],
            'items' => [['$id' => 'value', '$sequence' => '1']],
        ]);

        $this->assertSame(['$id', 'details', 'items'], \array_keys($stripped));
        $this->assertSame(['$id' => 'value', '$sequence' => '1', '$createdAt' => '2020-01-01T00:00:00.000+00:00'], $stripped['details'], 'Main stripped nested documents only along relationships');
        $this->assertSame([['$id' => 'value', '$sequence' => '1']], $stripped['items']);
    }

    /**
     * @return \Iterator<string, array{string, mixed}>
     */
    public static function invalidTimestamps(): \Iterator
    {
        yield 'created text' => ['$createdAt', 'not-a-date'];
        yield 'updated text' => ['$updatedAt', 'not-a-date'];
        yield 'created number' => ['$createdAt', 123];
        yield 'created out of range' => ['$createdAt', '2020-13-45T00:00:00.000+00:00'];
    }

    #[DataProvider('invalidTimestamps')]
    public function testAnInvalidTimestampIsRefusedWithTheDatabasesStructureMessage(string $attribute, mixed $value): void
    {
        $database = new Database(new Memory(), new Cache(new None()));
        $limits = $database->profile()->limits;

        try {
            $this->action()->timestamps([$attribute => $value], $database);
            $this->fail('An invalid timestamp must be refused');
        } catch (Exception $error) {
            $this->assertSame(Exception::DOCUMENT_INVALID_STRUCTURE, $error->getType());
            $this->assertSame(
                'Invalid document structure: Attribute "' . $attribute . '" has invalid type. Value must be valid date between ' . $limits->minDateTime->format('Y-m-d H:i:s') . ' and ' . $limits->maxDateTime->format('Y-m-d H:i:s') . '.',
                $error->getMessage(),
                'Main let the database refuse it, with this message',
            );
        }
    }

    public function testATimestampWithinTheDatabasesRangeIsAccepted(): void
    {
        $this->action()->timestamps(['$createdAt' => '9999-12-31T12:00:00.000+00:00', '$updatedAt' => ''], new Database(new Memory(), new Cache(new None())));

        $this->addToAssertionCount(1);
    }

    private function action(): DocumentsWriteAction
    {
        return new DocumentsWriteAction();
    }
}
