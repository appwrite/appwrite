<?php

declare(strict_types=1);

namespace Tests\Unit\Event\Message;

use Appwrite\Event\Message\Migration as MigrationMessage;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

require_once __DIR__ . '/../../../../app/init.php';

final class MigrationTest extends TestCase
{
    public function testTerminalSnapshotSurvivesQueueSerialization(): void
    {
        $message = new MigrationMessage(
            project: new Document(['$id' => 'project-1']),
            migration: new Document([
                '$id' => 'migration-1',
                'attemptId' => 'attempt-current',
                'status' => 'pending',
                'stage' => 'finished',
            ]),
            platform: ['name' => 'test-platform'],
            terminal: new Document([
                '$id' => 'migration-1',
                'attemptId' => 'attempt-terminal',
                'status' => 'failed',
                'stage' => 'finished',
            ]),
        );

        $payload = $message->toArray();
        $restored = MigrationMessage::fromArray($payload);

        $this->assertSame('pending', $restored->migration->getAttribute('status'));
        $this->assertSame('attempt-current', $restored->migration->getAttribute('attemptId'));
        $this->assertInstanceOf(Document::class, $restored->terminal);
        $this->assertSame('failed', $restored->terminal->getAttribute('status'));
        $this->assertSame('attempt-terminal', $restored->terminal->getAttribute('attemptId'));
        $this->assertSame($payload, $restored->toArray());
    }

    public function testInitialMessagePayloadRemainsBackwardCompatible(): void
    {
        $payload = [
            'project' => ['$id' => 'project-1'],
            'migration' => [
                '$id' => 'migration-1',
                'status' => 'pending',
                'stage' => 'init',
            ],
            'platform' => [],
        ];

        $message = MigrationMessage::fromArray($payload);

        $this->assertNotInstanceOf(Document::class, $message->terminal);
        $this->assertSame($payload, $message->toArray());
    }

    public function testMalformedTerminalSnapshotFailsClosed(): void
    {
        $message = MigrationMessage::fromArray([
            'project' => ['$id' => 'project-1'],
            'migration' => ['$id' => 'migration-1'],
            'platform' => [],
            'terminal' => 'failed',
        ]);

        $this->assertNotInstanceOf(Document::class, $message->terminal);
        $this->assertArrayNotHasKey('terminal', $message->toArray());
    }
}
