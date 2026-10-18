<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Databases\Queries\Oracle;

use Utopia\Database\Query;
use Utopia\Query\Method;

/**
 * An aggregate over the seeded orders, keyed by the alias it is requested under.
 */
enum Statistic: string
{
    case Rows = 'rowCount';
    case Labels = 'labelCount';
    case Sum = 'amountSum';
    case Average = 'amountAverage';
    case Minimum = 'amountMinimum';
    case Maximum = 'amountMaximum';
    case Deviation = 'deviation';
    case PopulationDeviation = 'populationDeviation';
    case SampleDeviation = 'sampleDeviation';
    case Variance = 'variance';
    case PopulationVariance = 'populationVariance';
    case SampleVariance = 'sampleVariance';
    case FlagsAnd = 'flagsAnd';
    case FlagsOr = 'flagsOr';
    case FlagsXor = 'flagsXor';

    public function method(): Method
    {
        return match ($this) {
            self::Rows => Method::Count,
            self::Labels => Method::CountDistinct,
            self::Sum => Method::Sum,
            self::Average => Method::Avg,
            self::Minimum => Method::Min,
            self::Maximum => Method::Max,
            self::Deviation => Method::Stddev,
            self::PopulationDeviation => Method::StddevPop,
            self::SampleDeviation => Method::StddevSamp,
            self::Variance => Method::Variance,
            self::PopulationVariance => Method::VarPop,
            self::SampleVariance => Method::VarSamp,
            self::FlagsAnd => Method::BitAnd,
            self::FlagsOr => Method::BitOr,
            self::FlagsXor => Method::BitXor,
        };
    }

    public function attribute(): string
    {
        return match ($this) {
            self::Rows => '*',
            self::Labels => 'label',
            self::FlagsAnd, self::FlagsOr, self::FlagsXor => 'flags',
            default => 'amount',
        };
    }

    public function query(): Query
    {
        return $this->on($this->attribute());
    }

    public function on(string $attribute): Query
    {
        return new Query($this->method(), $attribute, [$this->value]);
    }

    public function isCount(): bool
    {
        return $this === self::Rows || $this === self::Labels;
    }

    public function requiresNumber(): bool
    {
        return !$this->isCount() && $this !== self::Minimum && $this !== self::Maximum;
    }

    public function isExact(): bool
    {
        return match ($this) {
            self::Average,
            self::Deviation,
            self::PopulationDeviation,
            self::SampleDeviation,
            self::Variance,
            self::PopulationVariance,
            self::SampleVariance => false,
            default => true,
        };
    }

    public function expected(Seed $seed): int|float|null
    {
        $amounts = new Statistics($seed->amounts());
        $flags = new Statistics($seed->flags());

        return match ($this) {
            self::Rows => \count($seed->orders),
            self::Labels => \count(\array_unique($seed->labels())),
            self::Sum => $amounts->sum(),
            self::Average => $amounts->mean(),
            self::Minimum => $amounts->minimum(),
            self::Maximum => $amounts->maximum(),
            self::Deviation, self::PopulationDeviation => $amounts->populationDeviation(),
            self::SampleDeviation => $amounts->sampleDeviation(),
            self::Variance, self::PopulationVariance => $amounts->populationVariance(),
            self::SampleVariance => $amounts->sampleVariance(),
            self::FlagsAnd => $flags->bitwiseAnd(),
            self::FlagsOr => $flags->bitwiseOr(),
            self::FlagsXor => $flags->bitwiseXor(),
        };
    }

    public function overEmptySet(): ?int
    {
        return $this->isCount() ? 0 : null;
    }
}
