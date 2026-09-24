<?php

namespace Tests\Query\Builder\Feature\ClickHouse;

use PHPUnit\Framework\TestCase;
use Utopia\Query\Builder\ClickHouse as Builder;
use Utopia\Query\Builder\ClickHouse\Format;
use Utopia\Query\Builder\ClickHouse\FormattedInsertStatement;
use Utopia\Query\Exception\ValidationException;

class BulkInsertTest extends TestCase
{
    public function testBulkInsertSingleRowJsonEachRow(): void
    {
        $result = (new Builder())
            ->into('events')
            ->bulkInsert(Format::JSONEachRow, [
                ['id' => 1, 'event' => 'login', 'time' => '2024-01-01 00:00:00'],
            ]);

        $this->assertInstanceOf(FormattedInsertStatement::class, $result);
        $this->assertSame(
            'INSERT INTO `events` (`id`, `event`, `time`) FORMAT JSONEachRow',
            $result->query
        );
        $this->assertSame([], $result->bindings);
        $this->assertSame(['id', 'event', 'time'], $result->columns);
        $this->assertSame('JSONEachRow', $result->format);
        $this->assertSame(
            '{"id":1,"event":"login","time":"2024-01-01 00:00:00"}',
            $result->body,
        );
    }

    public function testBulkInsertMultipleRowsJsonEachRow(): void
    {
        $result = (new Builder())
            ->into('events')
            ->bulkInsert(Format::JSONEachRow, [
                ['id' => 1, 'event' => 'login'],
                ['id' => 2, 'event' => 'logout'],
                ['id' => 3, 'event' => 'view'],
            ]);

        $this->assertSame(
            'INSERT INTO `events` (`id`, `event`) FORMAT JSONEachRow',
            $result->query
        );
        $this->assertSame(
            '{"id":1,"event":"login"}' . "\n"
            . '{"id":2,"event":"logout"}' . "\n"
            . '{"id":3,"event":"view"}',
            $result->body,
        );
        $this->assertStringEndsNotWith("\n", (string) $result->body);
    }

    public function testBulkInsertEmptyIterableEmitsEmptyBody(): void
    {
        $result = (new Builder())
            ->into('events')
            ->bulkInsert(Format::JSONEachRow, [], ['id', 'event']);

        $this->assertSame(
            'INSERT INTO `events` (`id`, `event`) FORMAT JSONEachRow',
            $result->query
        );
        $this->assertSame('', $result->body);
        $this->assertSame(['id', 'event'], $result->columns);
    }

    public function testBulkInsertEmptyIterableWithoutColumnsOmitsColumnList(): void
    {
        $result = (new Builder())
            ->into('events')
            ->bulkInsert(Format::JSONEachRow, []);

        $this->assertSame(
            'INSERT INTO `events` FORMAT JSONEachRow',
            $result->query
        );
        $this->assertSame('', $result->body);
        $this->assertSame([], $result->columns);
    }

    public function testBulkInsertJsonEachRowEscapesSpecialCharacters(): void
    {
        $result = (new Builder())
            ->into('events')
            ->bulkInsert(Format::JSONEachRow, [
                ['id' => 1, 'note' => "tab\there\nnewline\"quote\\back"],
            ]);

        $this->assertSame(
            '{"id":1,"note":"tab\\there\\nnewline\\"quote\\\\back"}',
            $result->body,
        );
    }

    public function testBulkInsertJsonEachRowPreservesUnicodeAndSlashes(): void
    {
        $result = (new Builder())
            ->into('events')
            ->bulkInsert(Format::JSONEachRow, [
                ['path' => '/api/v1/events', 'label' => 'café — 日本'],
            ]);

        $this->assertSame(
            '{"path":"/api/v1/events","label":"café — 日本"}',
            $result->body,
        );
    }

    public function testBulkInsertJsonEachRowSerializesNull(): void
    {
        $result = (new Builder())
            ->into('events')
            ->bulkInsert(Format::JSONEachRow, [
                ['id' => 1, 'note' => null],
            ]);

        $this->assertSame('{"id":1,"note":null}', $result->body);
    }

    public function testBulkInsertExplicitColumnsPinOrderAndFillMissingKeysWithNull(): void
    {
        $result = (new Builder())
            ->into('events')
            ->bulkInsert(
                Format::JSONEachRow,
                [
                    ['id' => 1, 'event' => 'login'],
                    ['event' => 'view', 'id' => 2],
                    ['id' => 3],
                ],
                ['id', 'event'],
            );

        $this->assertSame(['id', 'event'], $result->columns);
        $this->assertSame(
            '{"id":1,"event":"login"}' . "\n"
            . '{"id":2,"event":"view"}' . "\n"
            . '{"id":3,"event":null}',
            $result->body,
        );
    }

    public function testBulkInsertPreservesLiteralDotInTableName(): void
    {
        $result = (new Builder())
            ->into('my.namespace')
            ->bulkInsert(Format::JSONEachRow, [
                ['id' => 1],
            ]);

        $this->assertSame(
            'INSERT INTO `my`.`namespace` (`id`) FORMAT JSONEachRow',
            $result->query
        );
    }

    public function testBulkInsertTabSeparated(): void
    {
        $result = (new Builder())
            ->into('events')
            ->bulkInsert(Format::TabSeparated, [
                ['id' => 1, 'event' => 'login'],
                ['id' => 2, 'event' => 'logout'],
            ]);

        $this->assertSame(
            'INSERT INTO `events` (`id`, `event`) FORMAT TabSeparated',
            $result->query
        );
        $this->assertSame(
            "1\tlogin\n2\tlogout",
            $result->body,
        );
        $this->assertSame('TabSeparated', $result->format);
    }

    public function testBulkInsertTabSeparatedEscapesControlCharacters(): void
    {
        $result = (new Builder())
            ->into('events')
            ->bulkInsert(Format::TabSeparated, [
                ['id' => 1, 'note' => "a\tb\nc\\d"],
            ]);

        $this->assertSame(
            "1\ta\\tb\\nc\\\\d",
            $result->body,
        );
    }

    public function testBulkInsertTabSeparatedRendersNullAsBackslashN(): void
    {
        $result = (new Builder())
            ->into('events')
            ->bulkInsert(Format::TabSeparated, [
                ['id' => 1, 'note' => null],
            ]);

        $this->assertSame("1\t\\N", $result->body);
    }

    public function testBulkInsertTabSeparatedRendersBooleansAsZeroOne(): void
    {
        $result = (new Builder())
            ->into('events')
            ->bulkInsert(Format::TabSeparated, [
                ['id' => 1, 'active' => true, 'archived' => false],
            ]);

        $this->assertSame("1\t1\t0", $result->body);
    }

    public function testBulkInsertTabSeparatedRejectsArrayValues(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())
            ->into('events')
            ->bulkInsert(Format::TabSeparated, [
                ['id' => 1, 'tags' => ['a', 'b']],
            ]);
    }

    public function testBulkInsertAcceptsGenerator(): void
    {
        $generator = (function (): iterable {
            yield ['id' => 1, 'event' => 'login'];
            yield ['id' => 2, 'event' => 'logout'];
        })();

        $result = (new Builder())
            ->into('events')
            ->bulkInsert(Format::JSONEachRow, $generator);

        $this->assertSame(
            'INSERT INTO `events` (`id`, `event`) FORMAT JSONEachRow',
            $result->query
        );
        $this->assertSame(
            '{"id":1,"event":"login"}' . "\n" . '{"id":2,"event":"logout"}',
            $result->body,
        );
    }

    public function testBulkInsertRejectsNonAssociativeRow(): void
    {
        $this->expectException(ValidationException::class);

        $generator = (function (): iterable {
            yield 'not-a-row';
        })();

        (new Builder())
            ->into('events')
            /** @phpstan-ignore argument.type */
            ->bulkInsert(Format::JSONEachRow, $generator);
    }

    public function testBulkInsertRejectsEmptyColumnName(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())
            ->into('events')
            ->bulkInsert(Format::JSONEachRow, [['id' => 1]], ['']);
    }

    public function testBulkInsertRequiresTable(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())
            ->bulkInsert(Format::JSONEachRow, [['id' => 1]]);
    }

    public function testFormattedInsertStatementWithExecutorPreservesBody(): void
    {
        $result = (new Builder())
            ->into('events')
            ->bulkInsert(Format::JSONEachRow, [['id' => 1]]);

        $executor = fn (): int => 0;
        $rebound = $result->withExecutor($executor);

        $this->assertInstanceOf(FormattedInsertStatement::class, $rebound);
        $this->assertSame($result->query, $rebound->query);
        $this->assertSame($result->bindings, $rebound->bindings);
        $this->assertSame($result->columns, $rebound->columns);
        $this->assertSame($result->format, $rebound->format);
        $this->assertSame($result->body, $rebound->body);
    }

    public function testInsertFormatEnvelopeStillEmitsNullBodyForBackCompat(): void
    {
        $result = (new Builder())
            ->into('events')
            ->insertFormat('JSONEachRow', ['id', 'event'])
            ->insert();

        $this->assertInstanceOf(FormattedInsertStatement::class, $result);
        $this->assertNull($result->body);
    }

    public function testBulkInsertAndInsertFormatEmitIdenticalEnvelopeForSameInputs(): void
    {
        $bulk = (new Builder())
            ->into('events')
            ->bulkInsert(Format::JSONEachRow, [
                ['id' => 1, 'event' => 'login'],
            ]);

        $envelope = (new Builder())
            ->into('events')
            ->insertFormat('JSONEachRow', ['id', 'event'])
            ->insert();

        $this->assertInstanceOf(FormattedInsertStatement::class, $envelope);
        $this->assertSame($bulk->query, $envelope->query);
        $this->assertSame($bulk->columns, $envelope->columns);
        $this->assertSame($bulk->format, $envelope->format);
    }

    public function testInsertFormatEnvelopeQuotesTableWithLiteralDot(): void
    {
        $envelope = (new Builder())
            ->into('my.namespace')
            ->insertFormat('JSONEachRow', ['id'])
            ->insert();

        $bulk = (new Builder())
            ->into('my.namespace')
            ->bulkInsert(Format::JSONEachRow, [['id' => 1]]);

        $this->assertSame(
            'INSERT INTO `my`.`namespace` (`id`) FORMAT JSONEachRow',
            $envelope->query,
        );
        $this->assertSame($envelope->query, $bulk->query);
    }

    public function testInsertFormatRejectsEmptyColumnNameMatchingBulkInsert(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())
            ->into('events')
            ->insertFormat('JSONEachRow', ['id', ''])
            ->insert();
    }

    public function testBulkInsertDoesNotPersistFormatStateOnBuilder(): void
    {
        $builder = (new Builder())
            ->into('events');

        $builder->bulkInsert(Format::JSONEachRow, [['id' => 1]]);

        $regular = $builder
            ->into('users')
            ->set(['name' => 'alice'])
            ->insert();

        $this->assertNotInstanceOf(FormattedInsertStatement::class, $regular);
        $this->assertStringNotContainsString('FORMAT', $regular->query);
        $this->assertSame(
            'INSERT INTO `users` (`name`) VALUES (?)',
            $regular->query,
        );
        $this->assertSame(['alice'], $regular->bindings);
    }

    public function testFormatSerializeExplicitColumnsPinOrderingAcrossInconsistentRows(): void
    {
        $rows = [
            ['id' => 1, 'event' => 'login'],
            ['event' => 'view', 'id' => 2],
            ['id' => 3],
        ];

        $tabSeparated = Format::TabSeparated->serialize($rows, ['id', 'event']);
        $this->assertSame(
            "1\tlogin\n2\tview\n3\t\\N",
            $tabSeparated,
        );

        $jsonEachRow = Format::JSONEachRow->serialize($rows, ['id', 'event']);
        $this->assertSame(
            '{"id":1,"event":"login"}' . "\n"
            . '{"id":2,"event":"view"}' . "\n"
            . '{"id":3,"event":null}',
            $jsonEachRow,
        );
    }
}
