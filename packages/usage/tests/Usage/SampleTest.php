<?php

namespace Utopia\Tests\Usage;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Utopia\Usage\Sample;

final class SampleTest extends TestCase
{
    public function testIdentityIsStableAndPayloadChangesAreDetectable(): void
    {
        $sample = $this->sample(value: 42);
        $retry = $this->sample(value: 42);
        $conflict = $this->sample(value: 43);

        $this->assertSame($sample->getId(), $retry->getId());
        $this->assertSame($sample->getPayloadHash(), $retry->getPayloadHash());
        $this->assertSame($sample->getId(), $conflict->getId());
        $this->assertNotSame($sample->getPayloadHash(), $conflict->getPayloadHash());
        $this->assertSame('a8e0eebf28f6eb0e2f632fd59b40734624d5c46a83edd2ba0530b0d83fbf3249', $sample->getId());
        $this->assertSame('dadbc87dbbe5fecba1e96f6c9c608c4ca09447566745c8e3e35bf06f76766568', $sample->getPayloadHash());
    }

    public function testEventVersionChangesPayloadButNotIdentity(): void
    {
        $first = $this->sample(eventVersion: 1);
        $second = $this->sample(eventVersion: 2);

        $this->assertSame($first->getId(), $second->getId());
        $this->assertNotSame($first->getPayloadHash(), $second->getPayloadHash());
    }

    public function testRejectsAnInvalidInterval(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Sample(
            environment: 'production',
            region: 'fra1',
            projectInternalId: '101',
            databaseInternalId: '202',
            member: 'mysql-0',
            generation: 'generation-1',
            sequence: 7,
            metric: 'bandwidth.inbound',
            intervalStart: new DateTimeImmutable('2026-08-01T00:01:00Z'),
            intervalEnd: new DateTimeImmutable('2026-08-01T00:00:00Z'),
            value: 42,
            eventVersion: 1,
        );
    }

    private function sample(int $value = 42, int $eventVersion = 1): Sample
    {
        return new Sample(
            environment: 'production',
            region: 'fra1',
            projectInternalId: '101',
            databaseInternalId: '202',
            member: 'mysql-0',
            generation: 'generation-1',
            sequence: 7,
            metric: 'bandwidth.inbound',
            intervalStart: new DateTimeImmutable('2026-08-01T00:00:00Z'),
            intervalEnd: new DateTimeImmutable('2026-08-01T00:01:00Z'),
            value: $value,
            eventVersion: $eventVersion,
        );
    }
}
