<?php

declare(strict_types=1);

namespace Tests\Unit\Schedule\Source;

use Appwrite\Schedule\Source\Messages;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Query;

final class DatabaseTest extends TestCase
{
    #[DataProvider('regionProvider')]
    public function testSnapshotReadsOnlyTheConfiguredRegion(?string $region, string $expected): void
    {
        $previous = \getenv('_APP_REGION');
        $region === null ? \putenv('_APP_REGION') : \putenv("_APP_REGION={$region}");

        try {
            $database = $this->createMock(Database::class);
            $database
                ->expects($this->once())
                ->method('find')
                ->with('schedules', $this->callback(function (array $queries) use ($expected): bool {
                    foreach ($queries as $query) {
                        if ($query instanceof Query && $query->getAttribute() === 'region') {
                            $this->assertSame([$expected], $query->getValues());

                            return true;
                        }
                    }

                    return false;
                }))
                ->willReturn([]);

            $source = new Messages($database, fn () => $database, fn () => false);

            $this->assertSame([], \iterator_to_array($source->snapshot()));
        } finally {
            $previous === false ? \putenv('_APP_REGION') : \putenv("_APP_REGION={$previous}");
        }
    }

    /**
     * @return iterable<string, array{string|null, string}>
     */
    public static function regionProvider(): iterable
    {
        yield 'self-hosted default' => [null, 'default'];
        yield 'configured region' => ['tor', 'tor'];
    }
}
