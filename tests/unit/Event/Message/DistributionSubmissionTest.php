<?php

declare(strict_types=1);

namespace Tests\Unit\Event\Message;

use Appwrite\Event\Message\DistributionSubmission;
use Appwrite\Event\Message\DistributionSubmissionAction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class DistributionSubmissionTest extends TestCase
{
    public function testPayloadSurvivesTheQueue(): void
    {
        $message = new DistributionSubmission(
            project: new Document(['$id' => 'project-id', '$sequence' => '42', 'region' => 'fra', 'database' => 'database_db_fra1_01']),
            action: DistributionSubmissionAction::Control,
            id: 'submission-id',
            data: ['command' => 'halt'],
        );

        $resumed = DistributionSubmission::fromArray($message->toArray());

        $this->assertSame('project-id', $resumed->project->getId());
        $this->assertSame('42', $resumed->project->getSequence());
        $this->assertSame('fra', $resumed->project->getAttribute('region'));
        $this->assertSame('database_db_fra1_01', $resumed->project->getAttribute('database'));
        $this->assertSame(DistributionSubmissionAction::Control, $resumed->action);
        $this->assertSame('submission-id', $resumed->id);
        $this->assertSame(['command' => 'halt'], $resumed->data);
    }

    public function testOptionalFieldsDefaultWhenAbsent(): void
    {
        $resumed = DistributionSubmission::fromArray(['action' => 'advance', 'id' => 'submission-id']);

        $this->assertSame(DistributionSubmissionAction::Advance, $resumed->action);
        $this->assertSame('', $resumed->project->getId());
        $this->assertSame('submission-id', $resumed->id);
        $this->assertSame([], $resumed->data);
    }

    /**
     * @return \Iterator<string, array{array<string, mixed>}>
     */
    public static function invalid(): \Iterator
    {
        yield 'no action' => [['id' => 'submission-id']];
        yield 'empty action' => [['action' => '', 'id' => 'submission-id']];
        yield 'unknown action' => [['action' => 'dispatch', 'id' => 'submission-id']];
        yield 'no id' => [['action' => 'advance']];
        yield 'empty id' => [['action' => 'advance', 'id' => '']];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('invalid')]
    public function testInvalidPayloadIsRejected(array $payload): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DistributionSubmission::fromArray($payload);
    }
}
