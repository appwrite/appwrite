<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution\Release;

use Appwrite\Distribution\Release\Status;
use Appwrite\Distribution\Submission\Status as SubmissionStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
use Utopia\Distribution\Release;

final class StatusTest extends TestCase
{
    /**
     * @return \Iterator<string, array{list<array{string, string, SubmissionStatus}>, Status}>
     */
    public static function submissions(): \Iterator
    {
        yield 'no submissions' => [[], Status::Draft];
        yield 'one waiting' => [[['ios', Release::TRACK_PRODUCTION, SubmissionStatus::Waiting]], Status::Active];
        yield 'one uploading' => [[['ios', Release::TRACK_PRODUCTION, SubmissionStatus::Uploading]], Status::Active];
        yield 'one reviewing' => [[['ios', Release::TRACK_PRODUCTION, SubmissionStatus::Reviewing]], Status::Active];
        yield 'one approved' => [[['ios', Release::TRACK_PRODUCTION, SubmissionStatus::Approved]], Status::Active];
        yield 'one halted' => [[['ios', Release::TRACK_PRODUCTION, SubmissionStatus::Halted]], Status::Active];
        yield 'one canceled' => [[['ios', Release::TRACK_PRODUCTION, SubmissionStatus::Canceled]], Status::Active];
        yield 'one published' => [[['ios', Release::TRACK_PRODUCTION, SubmissionStatus::Published]], Status::Published];
        yield 'one rejected' => [[['ios', Release::TRACK_PRODUCTION, SubmissionStatus::Rejected]], Status::Attention];
        yield 'one failed' => [[['ios', Release::TRACK_PRODUCTION, SubmissionStatus::Failed]], Status::Attention];
        yield 'published on testflight only' => [[['ios', Release::TRACK_TESTFLIGHT, SubmissionStatus::Published]], Status::Active];
        yield 'published on internal only' => [[['android', Release::TRACK_INTERNAL, SubmissionStatus::Published]], Status::Active];
        yield 'promoted from beta to production' => [[['android', Release::TRACK_BETA, SubmissionStatus::Published], ['android', Release::TRACK_PRODUCTION, SubmissionStatus::Published]], Status::Published];
        yield 'production published before a newer beta' => [[['android', Release::TRACK_PRODUCTION, SubmissionStatus::Published], ['android', Release::TRACK_BETA, SubmissionStatus::Reviewing]], Status::Published];
        yield 'every target published' => [[['ios', Release::TRACK_PRODUCTION, SubmissionStatus::Published], ['android', Release::TRACK_PRODUCTION, SubmissionStatus::Published]], Status::Published];
        yield 'one target published on a test track' => [[['ios', Release::TRACK_PRODUCTION, SubmissionStatus::Published], ['android', Release::TRACK_ALPHA, SubmissionStatus::Published]], Status::Active];
        yield 'one target still reviewing' => [[['ios', Release::TRACK_PRODUCTION, SubmissionStatus::Published], ['android', Release::TRACK_PRODUCTION, SubmissionStatus::Reviewing]], Status::Active];
        yield 'one target halted' => [[['ios', Release::TRACK_PRODUCTION, SubmissionStatus::Published], ['android', Release::TRACK_PRODUCTION, SubmissionStatus::Halted]], Status::Active];
        yield 'one target rejected beside a published one' => [[['ios', Release::TRACK_PRODUCTION, SubmissionStatus::Published], ['android', Release::TRACK_PRODUCTION, SubmissionStatus::Rejected]], Status::Attention];
        yield 'one target failed beside a moving one' => [[['ios', Release::TRACK_PRODUCTION, SubmissionStatus::Uploading], ['android', Release::TRACK_PRODUCTION, SubmissionStatus::Failed]], Status::Attention];
        yield 'older failure before a publish' => [[['ios', Release::TRACK_PRODUCTION, SubmissionStatus::Failed], ['ios', Release::TRACK_PRODUCTION, SubmissionStatus::Published]], Status::Attention];
        yield 'older rejection before a resubmission' => [[['ios', Release::TRACK_PRODUCTION, SubmissionStatus::Rejected], ['ios', Release::TRACK_PRODUCTION, SubmissionStatus::Reviewing]], Status::Attention];
        yield 'failure on a test track' => [[['android', Release::TRACK_INTERNAL, SubmissionStatus::Failed], ['android', Release::TRACK_PRODUCTION, SubmissionStatus::Published]], Status::Attention];
        yield 'canceled beside a published one' => [[['ios', Release::TRACK_PRODUCTION, SubmissionStatus::Canceled], ['ios', Release::TRACK_PRODUCTION, SubmissionStatus::Published]], Status::Published];
    }

    /**
     * @param list<array{string, string, SubmissionStatus}> $submissions
     */
    #[DataProvider('submissions')]
    public function testDerive(array $submissions, Status $expected): void
    {
        $documents = [];
        foreach ($submissions as $index => [$target, $track, $status]) {
            $documents[] = new Document([
                '$id' => 'submission' . ($index + 1),
                '$sequence' => (string) ($index + 1),
                'targetInternalId' => $target,
                'track' => $track,
                'status' => $status->value,
            ]);
        }

        $this->assertSame($expected, Status::derive($documents));
    }
}
