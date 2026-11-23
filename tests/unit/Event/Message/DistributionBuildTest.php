<?php

declare(strict_types=1);

namespace Tests\Unit\Event\Message;

use Appwrite\Event\Message\DistributionBuild;
use Appwrite\Event\Message\DistributionBuildAction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class DistributionBuildTest extends TestCase
{
    public function testPayloadSurvivesTheQueue(): void
    {
        $message = new DistributionBuild(
            project: new Document(['$id' => 'project-id', '$sequence' => '42', 'region' => 'fra', 'database' => 'database_db_fra1_01']),
            action: DistributionBuildAction::Event,
            id: 'build-id',
            event: 'build.completed',
            data: ['status' => 'ready'],
        );

        $resumed = DistributionBuild::fromArray($message->toArray());

        $this->assertSame('project-id', $resumed->project->getId());
        $this->assertSame('42', $resumed->project->getSequence());
        $this->assertSame('fra', $resumed->project->getAttribute('region'));
        $this->assertSame('database_db_fra1_01', $resumed->project->getAttribute('database'));
        $this->assertSame(DistributionBuildAction::Event, $resumed->action);
        $this->assertSame('build-id', $resumed->id);
        $this->assertSame('build.completed', $resumed->event);
        $this->assertSame(['status' => 'ready'], $resumed->data);
    }

    public function testOptionalFieldsDefaultWhenAbsent(): void
    {
        $resumed = DistributionBuild::fromArray(['action' => 'reconcile']);

        $this->assertSame(DistributionBuildAction::Reconcile, $resumed->action);
        $this->assertSame('', $resumed->project->getId());
        $this->assertSame('', $resumed->id);
        $this->assertSame('', $resumed->event);
        $this->assertSame([], $resumed->data);
    }

    /**
     * @return \Iterator<string, array{array<string, mixed>}>
     */
    public static function invalid(): \Iterator
    {
        yield 'no action' => [['id' => 'build-id']];
        yield 'empty action' => [['action' => '', 'id' => 'build-id']];
        yield 'unknown action' => [['action' => 'advance', 'id' => 'build-id']];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('invalid')]
    public function testPayloadWithoutAValidActionIsRejected(array $payload): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DistributionBuild::fromArray($payload);
    }
}
