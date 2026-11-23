<?php

declare(strict_types=1);

namespace Utopia\Distribution\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Distribution\Exception\Invalid;
use Utopia\Distribution\Issue;
use Utopia\Distribution\State;

final class StateTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function statuses(): iterable
    {
        yield State::STATUS_PROCESSING => [State::STATUS_PROCESSING];
        yield State::STATUS_PENDING => [State::STATUS_PENDING];
        yield State::STATUS_REVIEWING => [State::STATUS_REVIEWING];
        yield State::STATUS_APPROVED => [State::STATUS_APPROVED];
        yield State::STATUS_PUBLISHED => [State::STATUS_PUBLISHED];
        yield State::STATUS_REJECTED => [State::STATUS_REJECTED];
        yield State::STATUS_HALTED => [State::STATUS_HALTED];
    }

    #[DataProvider('statuses')]
    public function testEveryStatusIsAccepted(string $status): void
    {
        $state = new State($status, 'RAW');

        $this->assertSame($status, $state->status);
        $this->assertSame('RAW', $state->raw);
        $this->assertSame(1.0, $state->fraction);
        $this->assertSame([], $state->issues);
    }

    public function testFractionAndIssuesAreKept(): void
    {
        $issue = new Issue('GUIDELINE_2_1', 'App completeness', manual: true);

        $state = new State(State::STATUS_PUBLISHED, 'inProgress', 0.2, [$issue]);

        $this->assertSame(0.2, $state->fraction);
        $this->assertSame([$issue], $state->issues);
    }

    public function testUnknownStatusThrows(): void
    {
        $this->expectException(Invalid::class);

        new State('live', 'READY_FOR_DISTRIBUTION');
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function fractions(): iterable
    {
        yield 'zero' => [0.0];
        yield 'above one' => [1.5];
        yield 'not a number' => [NAN];
    }

    #[DataProvider('fractions')]
    public function testFractionOutsideRangeThrows(float $fraction): void
    {
        $this->expectException(Invalid::class);

        new State(State::STATUS_PUBLISHED, 'inProgress', $fraction);
    }
}
