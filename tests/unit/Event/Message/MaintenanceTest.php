<?php

declare(strict_types=1);

namespace Tests\Unit\Event\Message;

use Appwrite\Event\Message\Maintenance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Queue\PermanentFailure;

final class MaintenanceTest extends TestCase
{
    public function testResumePositionSurvivesTheQueue(): void
    {
        $message = new Maintenance(slot: 3, slots: 2016, due: 1772420400, period: 604800, region: 'east', pass: 1, cursor: 'project');

        $resumed = Maintenance::fromArray($message->toArray());

        $this->assertSame([1, 'project', 'east'], [$resumed->pass, $resumed->cursor, $resumed->region]);
    }

    /**
     * @return \Iterator<string, array{array<string, mixed>}>
     */
    public static function incomplete(): \Iterator
    {
        $payload = (new Maintenance(slot: 3, slots: 2016, due: 1772420400, period: 604800, region: 'east'))->toArray();

        yield 'no slots' => [\array_diff_key($payload, ['slots' => true])];
        yield 'zero slots' => [['slots' => 0] + $payload];
        yield 'no period' => [\array_diff_key($payload, ['period' => true])];
        yield 'negative period' => [['period' => -1] + $payload];
        yield 'no region' => [\array_diff_key($payload, ['region' => true])];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('incomplete')]
    public function testPayloadWithoutTheCycleIsAPermanentFailure(array $payload): void
    {
        $this->expectException(PermanentFailure::class);

        Maintenance::fromArray($payload);
    }
}
