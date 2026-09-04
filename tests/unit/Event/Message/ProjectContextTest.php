<?php

declare(strict_types=1);

namespace Tests\Unit\Event\Message;

use Appwrite\Event\Message\ProjectContext;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class ProjectContextTest extends TestCase
{
    public function testSerializesOnlyStableWorkerContext(): void
    {
        $context = ProjectContext::fromDocument(new Document([
            '$id' => 'project-id',
            '$sequence' => '42',
            '$createdAt' => '2026-09-03T10:00:00.000+00:00',
            'teamId' => 'team-id',
            'teamInternalId' => '84',
            'region' => 'fra',
            'database' => 'mysql://user:secret@database/project',
            'webhooks' => [['url' => 'https://example.test']],
        ]));

        $this->assertSame([
            '$id' => 'project-id',
            '$sequence' => '42',
            'teamId' => 'team-id',
            'teamInternalId' => '84',
            '$createdAt' => '2026-09-03T10:00:00.000+00:00',
            'region' => 'fra',
        ], $context->toArray());
    }

    public function testParsesLegacyPartialContext(): void
    {
        $context = ProjectContext::fromArray([
            '$id' => 'project-id',
            '$sequence' => 42,
        ]);

        $this->assertSame('project-id', $context->id);
        $this->assertSame('42', $context->sequence);
        $this->assertSame('', $context->teamId);
        $this->assertSame('', $context->teamInternalId);
        $this->assertSame('', $context->createdAt);
        $this->assertSame('', $context->region);
    }
}
