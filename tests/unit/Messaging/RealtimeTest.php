<?php

declare(strict_types=1);

namespace Tests\Unit\Messaging;

use PHPUnit\Framework\TestCase;
use Tests\Unit\Messaging\Fixture\TestingRealtime;

require_once __DIR__ . '/../../../app/init.php';

final class RealtimeTest extends TestCase
{
    public function testMessageIncludesStableEnvelopeId(): void
    {
        $message = (new TestingRealtime())->getPublishedMessage(
            projectId: 'project-1',
            payload: ['$id' => 'document-1'],
            events: ['databases.database-1.collections.collection-1.documents.document-1.update'],
            channels: ['documents'],
            roles: ['any'],
            options: ['envelopeId' => 'envelope-1'],
        );

        $this->assertSame('envelope-1', $message['data']['envelopeId']);
    }

    public function testLegacyMessageOmitsEnvelopeId(): void
    {
        $message = (new TestingRealtime())->getPublishedMessage(
            projectId: 'project-1',
            payload: ['$id' => 'document-1'],
            events: ['databases.database-1.collections.collection-1.documents.document-1.update'],
            channels: ['documents'],
            roles: ['any'],
        );

        $this->assertArrayNotHasKey('envelopeId', $message['data']);
    }
}
