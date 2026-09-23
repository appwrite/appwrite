<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Migrations;

use Appwrite\Platform\Modules\Migrations\Report;
use PHPUnit\Framework\TestCase;
use Utopia\Config\Config;
use Utopia\Database\Attribute;
use Utopia\Migration\Cache;
use Utopia\Migration\Destination;
use Utopia\Migration\Resource;
use Utopia\Migration\Resources\Auth\User;
use Utopia\Migration\Resources\Database\Columns\Text;
use Utopia\Migration\Resources\Database\Database as DatabaseResource;
use Utopia\Migration\Resources\Database\Row;
use Utopia\Migration\Resources\Database\Table;
use Utopia\Migration\Resources\Storage\Bucket;
use Utopia\Migration\Resources\Storage\File;
use Utopia\Migration\Source;
use Utopia\Migration\Transfer;

final class ReportTest extends TestCase
{
    public function testReportMatchesTheTransferReportWhileItFits(): void
    {
        $transfer = new Transfer($this->createStub(Source::class), $this->createStub(Destination::class));
        $cache = $transfer->getCache();
        $report = new Report();

        $database = new DatabaseResource('database-1', 'Database');
        $table = new Table($database, 'Table', 'table-1');
        $bucket = new Bucket('bucket-1', 'Bucket');
        $batches = [
            [$this->resource($database)],
            [$this->resource($table)],
            [$this->resource(new Text('title', $table)), $this->resource(new Text('body', $table), Resource::STATUS_ERROR, 'Attribute limit exceeded')],
            [$this->resource(new Row('row-1', $table)), $this->resource(new Row('row-2', $table)), $this->resource(new Row('row-3', $table), Resource::STATUS_ERROR, 'Document already exists')],
            [$this->resource($bucket), $this->resource(new File('file-1', $bucket, 'report.pdf'), Resource::STATUS_WARNING, 'File signature mismatch, Possibly corrupted.')],
            [$this->resource(new User('user-1')), $this->resource(new User('user-2'), Resource::STATUS_SKIPPED, 'Already exists on destination')],
        ];

        foreach ($batches as $batch) {
            $cache->updateAll($batch);
            $report->track($batch, $cache);

            $this->assertSame(\json_encode($transfer->getReport()), $report->encode());
        }

        $report->reconcile($cache);

        $this->assertSame(\json_encode($transfer->getReport()), $report->encode());
    }

    public function testReportThatOutgrowsTheLimitKeepsOnlyUnsuccessfulResources(): void
    {
        $cache = new Cache();
        $report = new Report();
        $errors = [];
        $warnings = [];

        for ($offset = 0; $offset < 5_000; $offset += 500) {
            $batch = [];
            for ($index = $offset; $index < $offset + 500; $index++) {
                $id = \sprintf('user%024d', $index);
                if ($index % 50 === 0) {
                    $batch[] = $this->resource(new User($id), Resource::STATUS_ERROR, \str_repeat('e', 1_000));
                    $errors[] = $id;
                } elseif ($index % 70 === 0) {
                    $batch[] = $this->resource(new User($id), Resource::STATUS_WARNING, 'Password hash is not supported');
                    $warnings[] = $id;
                } else {
                    $batch[] = $this->resource(new User($id));
                }
            }

            $cache->updateAll($batch);
            $report->track($batch, $cache);

            $this->assertLessThanOrEqual(Report::LIMIT, \strlen($report->encode()));
        }

        $entries = \json_decode($report->encode(), true);
        $this->assertSame(
            [...$errors, ...$warnings],
            \array_column($entries, 'id'),
            'Errors come first, then warnings, and nothing that succeeded',
        );
        $this->assertSame(\str_repeat('e', Report::MESSAGE_LIMIT), $entries[0]['message']);
        $this->assertSame('Password hash is not supported', $entries[\count($errors)]['message']);
    }

    public function testCondensedReportStopsAtTheLimitWithErrorsFirst(): void
    {
        $cache = new Cache();
        $report = new Report(limit: 1_000);
        $batch = [];

        for ($index = 0; $index < 40; $index++) {
            $batch[] = $this->resource(new User('warning-' . $index), Resource::STATUS_WARNING, 'Warning ' . $index);
            $batch[] = $this->resource(new User('error-' . $index), Resource::STATUS_ERROR, 'Error ' . $index);
        }

        $cache->updateAll($batch);
        $report->track($batch, $cache);

        $encoded = $report->encode();
        $ids = \array_column(\json_decode($encoded, true), 'id');
        $this->assertLessThanOrEqual(1_000, \strlen($encoded));
        $this->assertNotEmpty($ids);
        $this->assertSame(\array_map(static fn (int $index): string => 'error-' . $index, \range(0, \count($ids) - 1)), $ids);
    }

    public function testReportAtTheLimitStaysComplete(): void
    {
        $cache = new Cache();
        $batch = \array_map(fn (int $index): User => $this->resource(new User('user-' . $index)), \range(1, 10));
        $cache->updateAll($batch);

        $complete = new Report();
        $complete->track($batch, $cache);
        $length = \strlen($complete->encode());

        $exact = new Report(limit: $length);
        $exact->track($batch, $cache);
        $this->assertSame($complete->encode(), $exact->encode());

        $short = new Report(limit: $length - 1);
        $short->track($batch, $cache);
        $this->assertSame('[]', $short->encode(), 'Only unsuccessful resources are kept once the report does not fit');
    }

    public function testStatusChangesMoveResourcesInACondensedReport(): void
    {
        $cache = new Cache();
        $report = new Report(limit: 200);
        $bucket = $this->resource(new Bucket('bucket-1', 'Bucket'));
        $file = $this->resource(new File('file-1', $bucket, 'large.bin'), Resource::STATUS_PROCESSING);
        $users = \array_map(fn (int $index): User => $this->resource(new User('user-' . $index)), \range(1, 5));

        $cache->updateAll([$bucket, $file, ...$users]);
        $report->track([$bucket, $file, ...$users], $cache);
        $this->assertSame([['resource' => Resource::TYPE_FILE, 'id' => 'file-1', 'status' => Resource::STATUS_PROCESSING, 'message' => '']], \json_decode($report->encode(), true));

        $file->setStatus(Resource::STATUS_ERROR, 'Upload failed');
        $cache->update($file);
        $report->track([$file], $cache);
        $this->assertSame([['resource' => Resource::TYPE_FILE, 'id' => 'file-1', 'status' => Resource::STATUS_ERROR, 'message' => 'Upload failed']], \json_decode($report->encode(), true));

        $file->setStatus(Resource::STATUS_SUCCESS);
        $cache->update($file);
        $report->track([$file], $cache);
        $this->assertSame('[]', $report->encode());
    }

    public function testRowTotalsAreReportedOnlyWhileTheReportIsComplete(): void
    {
        $cache = new Cache();
        $report = new Report(limit: 400);
        $table = new Table(new DatabaseResource('database-1', 'Database'), 'Table', 'table-1');
        $rows = [$this->resource(new Row('row-1', $table)), $this->resource(new Row('row-2', $table), Resource::STATUS_ERROR, 'Document already exists')];

        $cache->updateAll($rows);
        $report->track($rows, $cache);
        $this->assertSame([
            ['resource' => Resource::TYPE_ROW, 'id' => Resource::STATUS_SUCCESS, 'status' => '1', 'message' => ''],
            ['resource' => Resource::TYPE_ROW, 'id' => Resource::STATUS_ERROR, 'status' => '1', 'message' => ''],
        ], \json_decode($report->encode(), true));

        $users = \array_map(fn (int $index): User => $this->resource(new User('user-' . $index)), \range(1, 10));
        $cache->updateAll($users);
        $report->track($users, $cache);
        $this->assertSame('[]', $report->encode());
    }

    public function testReconcileRecordsResourcesAddedWithoutACallback(): void
    {
        $cache = new Cache();
        $report = new Report();
        $user = $this->resource(new User('user-1'));
        $cache->update($user);
        $report->track([$user], $cache);

        $denied = new Table(new DatabaseResource('(default)', '(default)'), 'firestore', 'firestore');
        $denied->setStatus(Resource::STATUS_ERROR, 'Missing or insufficient permissions.');
        $cache->add($denied);
        $report->reconcile($cache);

        $this->assertSame([
            ['resource' => Resource::TYPE_USER, 'id' => 'user-1', 'status' => Resource::STATUS_SUCCESS, 'message' => ''],
            ['resource' => Resource::TYPE_TABLE, 'id' => 'firestore', 'status' => Resource::STATUS_ERROR, 'message' => 'Missing or insufficient permissions.'],
        ], \json_decode($report->encode(), true));
    }

    public function testInvalidUtf8MessagesStillEncode(): void
    {
        $cache = new Cache();
        $user = $this->resource(new User('user-1'), Resource::STATUS_ERROR, "Invalid \xB1\x31 sequence");
        $cache->update($user);
        $report = new Report();
        $report->track([$user], $cache);

        $entries = \json_decode($report->encode(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame("Invalid \u{FFFD}1 sequence", $entries[0]['message']);
    }

    public function testLimitStaysBelowTheResourceDataColumn(): void
    {
        $sizes = \array_map(
            static fn (Attribute $attribute): int => $attribute->size,
            \array_filter(
                Config::getParam('collections', [])['projects']['migrations']['attributes'],
                static fn (Attribute $attribute): bool => $attribute->key === 'resourceData',
            ),
        );

        $this->assertCount(1, $sizes);
        $this->assertLessThan(\array_values($sizes)[0], Report::LIMIT);
    }

    /**
     * @template T of Resource
     * @param T $resource
     * @return T
     */
    private function resource(Resource $resource, string $status = Resource::STATUS_SUCCESS, string $message = ''): Resource
    {
        $resource->setStatus($status, $message);

        return $resource;
    }
}
