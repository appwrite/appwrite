<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution\Submission;

use Appwrite\Distribution\Submission\Status;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StatusTest extends TestCase
{
    /**
     * @return \Iterator<string, array{Status, Status}>
     */
    public static function allowed(): \Iterator
    {
        yield 'waiting to validating' => [Status::Waiting, Status::Validating];
        yield 'waiting to uploading' => [Status::Waiting, Status::Uploading];
        yield 'waiting to processing' => [Status::Waiting, Status::Processing];
        yield 'waiting to pending' => [Status::Waiting, Status::Pending];
        yield 'waiting to reviewing' => [Status::Waiting, Status::Reviewing];
        yield 'waiting to approved' => [Status::Waiting, Status::Approved];
        yield 'waiting to published' => [Status::Waiting, Status::Published];
        yield 'waiting to failed' => [Status::Waiting, Status::Failed];
        yield 'waiting to canceled' => [Status::Waiting, Status::Canceled];
        yield 'validating to uploading' => [Status::Validating, Status::Uploading];
        yield 'validating to processing' => [Status::Validating, Status::Processing];
        yield 'validating to pending' => [Status::Validating, Status::Pending];
        yield 'validating to reviewing' => [Status::Validating, Status::Reviewing];
        yield 'validating to approved' => [Status::Validating, Status::Approved];
        yield 'validating to published' => [Status::Validating, Status::Published];
        yield 'validating to failed' => [Status::Validating, Status::Failed];
        yield 'validating to canceled' => [Status::Validating, Status::Canceled];
        yield 'uploading to processing' => [Status::Uploading, Status::Processing];
        yield 'uploading to pending' => [Status::Uploading, Status::Pending];
        yield 'uploading to reviewing' => [Status::Uploading, Status::Reviewing];
        yield 'uploading to approved' => [Status::Uploading, Status::Approved];
        yield 'uploading to published' => [Status::Uploading, Status::Published];
        yield 'uploading to failed' => [Status::Uploading, Status::Failed];
        yield 'uploading to canceled' => [Status::Uploading, Status::Canceled];
        yield 'processing to pending' => [Status::Processing, Status::Pending];
        yield 'processing to reviewing' => [Status::Processing, Status::Reviewing];
        yield 'processing to approved' => [Status::Processing, Status::Approved];
        yield 'processing to published' => [Status::Processing, Status::Published];
        yield 'processing to rejected' => [Status::Processing, Status::Rejected];
        yield 'processing to failed' => [Status::Processing, Status::Failed];
        yield 'processing to canceled' => [Status::Processing, Status::Canceled];
        yield 'pending to reviewing' => [Status::Pending, Status::Reviewing];
        yield 'pending to approved' => [Status::Pending, Status::Approved];
        yield 'pending to published' => [Status::Pending, Status::Published];
        yield 'pending to rejected' => [Status::Pending, Status::Rejected];
        yield 'pending to failed' => [Status::Pending, Status::Failed];
        yield 'pending to canceled' => [Status::Pending, Status::Canceled];
        yield 'reviewing to approved' => [Status::Reviewing, Status::Approved];
        yield 'reviewing to published' => [Status::Reviewing, Status::Published];
        yield 'reviewing to rejected' => [Status::Reviewing, Status::Rejected];
        yield 'reviewing to failed' => [Status::Reviewing, Status::Failed];
        yield 'reviewing to canceled' => [Status::Reviewing, Status::Canceled];
        yield 'approved to published' => [Status::Approved, Status::Published];
        yield 'approved to failed' => [Status::Approved, Status::Failed];
        yield 'approved to canceled' => [Status::Approved, Status::Canceled];
        yield 'published to halted' => [Status::Published, Status::Halted];
        yield 'published to failed' => [Status::Published, Status::Failed];
        yield 'halted to published' => [Status::Halted, Status::Published];
        yield 'halted to failed' => [Status::Halted, Status::Failed];
    }

    /**
     * @return \Iterator<string, array{Status, Status}>
     */
    public static function denied(): \Iterator
    {
        yield 'rejected to waiting' => [Status::Rejected, Status::Waiting];
        yield 'rejected to reviewing' => [Status::Rejected, Status::Reviewing];
        yield 'rejected to published' => [Status::Rejected, Status::Published];
        yield 'rejected to failed' => [Status::Rejected, Status::Failed];
        yield 'failed to waiting' => [Status::Failed, Status::Waiting];
        yield 'failed to uploading' => [Status::Failed, Status::Uploading];
        yield 'failed to published' => [Status::Failed, Status::Published];
        yield 'failed to canceled' => [Status::Failed, Status::Canceled];
        yield 'canceled to waiting' => [Status::Canceled, Status::Waiting];
        yield 'canceled to validating' => [Status::Canceled, Status::Validating];
        yield 'canceled to published' => [Status::Canceled, Status::Published];
        yield 'canceled to failed' => [Status::Canceled, Status::Failed];
        yield 'waiting to waiting' => [Status::Waiting, Status::Waiting];
        yield 'reviewing to reviewing' => [Status::Reviewing, Status::Reviewing];
        yield 'published to published' => [Status::Published, Status::Published];
        yield 'halted to halted' => [Status::Halted, Status::Halted];
        yield 'halted to canceled' => [Status::Halted, Status::Canceled];
        yield 'approved to rejected' => [Status::Approved, Status::Rejected];
        yield 'approved to halted' => [Status::Approved, Status::Halted];
        yield 'approved to reviewing' => [Status::Approved, Status::Reviewing];
        yield 'halted to approved' => [Status::Halted, Status::Approved];
        yield 'halted to rejected' => [Status::Halted, Status::Rejected];
        yield 'published to canceled' => [Status::Published, Status::Canceled];
        yield 'published to approved' => [Status::Published, Status::Approved];
        yield 'published to rejected' => [Status::Published, Status::Rejected];
        yield 'reviewing to pending' => [Status::Reviewing, Status::Pending];
        yield 'uploading to validating' => [Status::Uploading, Status::Validating];
        yield 'waiting to rejected' => [Status::Waiting, Status::Rejected];
        yield 'waiting to halted' => [Status::Waiting, Status::Halted];
        yield 'validating to rejected' => [Status::Validating, Status::Rejected];
        yield 'uploading to rejected' => [Status::Uploading, Status::Rejected];
    }

    #[DataProvider('allowed')]
    public function testAllowsTransition(Status $from, Status $to): void
    {
        $this->assertTrue($from->canTransition($to));
    }

    #[DataProvider('denied')]
    public function testDeniesTransition(Status $from, Status $to): void
    {
        $this->assertFalse($from->canTransition($to));
    }

    public function testAllowsNoUnlistedTransition(): void
    {
        $count = 0;
        foreach (Status::cases() as $from) {
            foreach (Status::cases() as $to) {
                $count += (int) $from->canTransition($to);
            }
        }

        $this->assertSame(\iterator_count(self::allowed()), $count);
    }

    public function testValues(): void
    {
        $this->assertSame(
            ['waiting', 'validating', 'uploading', 'processing', 'pending', 'reviewing', 'approved', 'published', 'rejected', 'halted', 'failed', 'canceled'],
            Status::values(),
        );
    }
}
