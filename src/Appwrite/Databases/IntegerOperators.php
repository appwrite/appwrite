<?php

namespace Appwrite\Databases;

use Utopia\Database\Operator;
use Utopia\Database\OperatorType;
use Utopia\Database\Validator\BigInt;

/**
 * Numeric operators on an integer column with a fractional amount or limit. The library takes whole operands only;
 * main (database 7.4.1) handed fractional ones to the engine, which stored the result rounded its own way. Each such
 * operator is answered from the stored value as main's engine answered it, and sent as the whole-number change.
 */
final readonly class IntegerOperators
{
    private const array LIMITED_BY_MAXIMUM = [OperatorType::Increment, OperatorType::Multiply, OperatorType::Power];

    private const array LIMITED_BY_MINIMUM = [OperatorType::Decrement, OperatorType::Divide];

    /**
     * Without a limit, main bound the amount next to an integer column on PostgreSQL, which read it as an integer
     * and refused a fractional one.
     */
    /**
     * PostgreSQL computed these in NUMERIC, exactly at any size, where the other engines used a double.
     */
    /**
     * The largest power of ten an operand is written out with: far past a BIGINT and the precision of any fraction
     * an engine kept. A larger one is never expanded (it would take a billion digits for 1e1000000000); such a
     * number is beyond every result, or closer to zero than any.
     */
    private const int MAXIMUM_DIGITS = 64;

    private const int MAXIMUM_EXPONENT_DIGITS = 18;

    private const array EXACT_ON_POSTGRESQL = [OperatorType::Multiply, OperatorType::Divide, OperatorType::Modulo];

    private const array CAST_TO_INTEGER_ON_POSTGRESQL = [OperatorType::Increment, OperatorType::Decrement, OperatorType::Multiply, OperatorType::Divide];

    public function __construct(private Engine $engine)
    {
    }

    /**
     * Whether the operator needs the stored value: a numeric operator with a fractional amount or limit.
     */
    public static function fractional(Operator $operator): bool
    {
        if (!$operator->isNumericOperation()) {
            return false;
        }

        foreach (\array_slice($operator->getValues(), 0, 2) as $value) {
            if (self::isFractional($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Items added to an integer array as every engine read them back on main: cut toward zero.
     */
    public static function wholeItems(Operator $operator): Operator
    {
        $whole = fn (mixed $item): mixed => self::isFractional($item) ? (int) (float) $item : $item;
        $values = $operator->getValues();

        return match ($operator->getMethod()) {
            OperatorType::ArrayAppend, OperatorType::ArrayPrepend => $operator->setValues(\array_map(
                fn (mixed $value): mixed => \is_array($value) ? \array_map($whole, $value) : $whole($value),
                $values,
            )),
            OperatorType::ArrayInsert => isset($values[1]) ? $operator->setValues([$values[0], $whole($values[1])]) : $operator,
            default => $operator,
        };
    }

    /**
     * The whole-number change that stores what main stored for $operator applied to $stored. The operator comes
     * back unchanged when main's engine refused it (PostgreSQL took a fractional amount only with a limit) or the
     * result is undefined, so the library refuses it.
     */
    public function resolve(Operator $operator, int|float|string|null $stored): Operator
    {
        $method = $operator->getMethod();
        $values = $operator->getValues();
        $amount = $values[0] ?? 1;
        $limit = $values[1] ?? null;
        $current = BigInt::toNative($stored ?? 0);

        if ($this->engine === Engine::PostgreSQL && $limit === null && self::isFractional($amount) && \in_array($method, self::CAST_TO_INTEGER_ON_POSTGRESQL, true)) {
            return $operator;
        }

        $rounding = $this->engine->rounding($method, !self::isFractional($amount));
        if ($this->engine === Engine::PostgreSQL && \in_array($method, self::EXACT_ON_POSTGRESQL, true)) {
            $exact = self::rational($method, $current, $amount);
            if ($exact === null) {
                return $operator;
            }
            if ($limit !== null && $this->beyondRational($method, $exact, $amount, $limit)) {
                return Operator::increment(0);
            }
            $stores = $rounding->ratio(...$exact);
        } else {
            $exact = self::compute($method, (float) $current, (float) $amount);
            if ($limit !== null && ($this->undefinedPower($method, $current, $amount) || $this->beyond($method, $exact, (float) $amount, (float) $limit))) {
                return Operator::increment(0);
            }

            if (!\is_finite($exact)) {
                return $operator;
            }

            $stores = \in_array($method, [OperatorType::Increment, OperatorType::Decrement], true)
                ? self::shift($rounding, $current, (float) $amount, $method === OperatorType::Increment)
                : $rounding->round($exact);
        }
        $change = BigInt::subtract($stores, $current);

        return BigInt::compare($change, 0) >= 0
            ? Operator::increment($change)
            : Operator::decrement(BigInt::negate($change));
    }

    private static function isFractional(mixed $value): bool
    {
        return match (true) {
            \is_float($value) => \is_finite($value) && \floor($value) !== $value,
            \is_string($value) && \is_numeric($value) => !BigInt::isIntegerString($value) && \floor((float) $value) !== (float) $value,
            default => false,
        };
    }

    private static function compute(OperatorType $method, float $current, float $amount): float
    {
        return match ($method) {
            OperatorType::Increment => $current + $amount,
            OperatorType::Decrement => $current - $amount,
            OperatorType::Multiply => $current * $amount,
            OperatorType::Divide => $amount === 0.0 ? \NAN : $current / $amount,
            OperatorType::Modulo => $amount === 0.0 ? \NAN : \fmod($current, $amount),
            OperatorType::Power => $current === 0.0 && $amount < 0 ? \NAN : $current ** $amount,
            default => \NAN,
        };
    }

    /**
     * The exact result as a numerator and a positive denominator, or null for a division or modulo by zero.
     *
     * @return array{int|string, int|string}|null
     */
    private static function rational(OperatorType $method, int|string $current, int|float|string $amount): ?array
    {
        $decimal = self::decimal($amount);
        if ($decimal === null) {
            return self::isTiny($amount) && $method !== OperatorType::Divide ? [0, 1] : null;
        }
        [$numerator, $scale] = $decimal;
        $power = self::powerOfTen($scale);

        if ($method === OperatorType::Multiply) {
            return [BigInt::multiply($current, $numerator), $power];
        }
        if (BigInt::compare($numerator, 0) === 0) {
            return null;
        }
        if ($method === OperatorType::Modulo) {
            return [BigInt::modulo(BigInt::multiply($current, $power), $numerator), $power];
        }

        $dividend = BigInt::multiply($current, $power);

        return BigInt::compare($numerator, 0) < 0
            ? [BigInt::negate($dividend), BigInt::negate($numerator)]
            : [$dividend, $numerator];
    }

    /**
     * @param array{int|string, int|string} $exact
     */
    private function beyondRational(OperatorType $method, array $exact, int|float|string $amount, int|float|string $limit): bool
    {
        $factor = self::decimal($amount);
        if ($factor === null || BigInt::compare($factor[0], 0) === 0) {
            return false;
        }

        [$numerator, $denominator] = $exact;
        $bound = self::decimal($limit);
        $comparison = $bound === null
            ? self::compareWithOversized($numerator, $limit)
            : BigInt::compare(
                BigInt::multiply($numerator, self::powerOfTen($bound[1])),
                BigInt::multiply($bound[0], $denominator),
            );

        return match (true) {
            \in_array($method, self::LIMITED_BY_MAXIMUM, true) => $comparison > 0,
            \in_array($method, self::LIMITED_BY_MINIMUM, true) => $comparison < 0,
            default => false,
        };
    }

    /**
     * How a result compares with a limit decimal() leaves out: one beyond every result in magnitude, or one closer
     * to zero than any of them but zero.
     */
    private static function compareWithOversized(int|string $numerator, int|float|string $limit): int
    {
        $negative = \str_starts_with(\ltrim((string) $limit), '-');
        if (!self::isTiny($limit)) {
            return $negative ? 1 : -1;
        }

        $sign = BigInt::compare($numerator, 0);

        return $sign !== 0 ? $sign : ($negative ? 1 : -1);
    }

    /**
     * Whether a number decimal() leaves out is closer to zero than any result, not beyond every result.
     */
    private static function isTiny(int|float|string $value): bool
    {
        $order = self::order($value);

        return $order !== null && $order < -self::MAXIMUM_DIGITS;
    }

    /**
     * The power of ten of a number's leading digit (2 for 123, -3 for 0.001), from its text; null for zero. An
     * exponent too long to read as an integer saturates.
     */
    private static function order(int|float|string $value): ?int
    {
        [$mantissa, $exponent] = \explode('e', \strtolower(\trim((string) $value)), 2) + [1 => '0'];
        [$whole, $fraction] = \explode('.', \ltrim($mantissa, '+-'), 2) + [1 => ''];
        if (\ltrim($whole . $fraction, '0') === '') {
            return null;
        }

        $magnitude = \ltrim(\ltrim($exponent, '+-'), '0');
        if (\strlen($magnitude) > self::MAXIMUM_EXPONENT_DIGITS) {
            return \str_starts_with($exponent, '-') ? \PHP_INT_MIN : \PHP_INT_MAX;
        }

        $significant = \ltrim($whole, '0');
        $leading = $significant !== '' ? \strlen($significant) - 1 : -(\strspn($fraction, '0') + 1);

        return $leading + (int) $exponent;
    }

    /**
     * A number as an integer and the power of ten that divides it, from the text main bound for it; null when its
     * leading digit is more than MAXIMUM_DIGITS powers of ten from the units.
     *
     * @return array{int|string, int}|null
     */
    private static function decimal(int|float|string $value): ?array
    {
        $text = \strtolower(\trim((string) $value));
        [$mantissa, $exponent] = \explode('e', $text, 2) + [1 => '0'];
        [$whole, $fraction] = \explode('.', $mantissa, 2) + [1 => ''];
        $order = self::order($value);
        if ($order === null) {
            return [0, 0];
        }
        if (\abs($order) > self::MAXIMUM_DIGITS) {
            return null;
        }

        $scale = \strlen($fraction) - (int) $exponent;
        $digits = $whole . $fraction;

        if ($scale < 0) {
            $digits .= \str_repeat('0', -$scale);
            $scale = 0;
        }

        return [BigInt::toNative(BigInt::normalizeInteger($digits)), $scale];
    }

    private static function powerOfTen(int $exponent): int|string
    {
        return BigInt::toNative('1' . \str_repeat('0', $exponent));
    }

    private function beyond(OperatorType $method, float $exact, float $amount, float $limit): bool
    {
        return match (true) {
            $method === OperatorType::Multiply && $amount === 0.0, $method === OperatorType::Divide && $amount === 0.0 => false,
            \in_array($method, self::LIMITED_BY_MAXIMUM, true) => $exact > $limit,
            \in_array($method, self::LIMITED_BY_MINIMUM, true) => $exact < $limit,
            default => false,
        };
    }

    /**
     * With a limit, main left the value as it was where the power is undefined: zero to a negative power, a
     * negative base to a fractional one.
     */
    private function undefinedPower(OperatorType $method, int|string $current, int|float|string $amount): bool
    {
        return $method === OperatorType::Power
            && ((BigInt::compare($current, 0) === 0 && (float) $amount < 0) || (BigInt::compare($current, 0) < 0 && self::isFractional($amount)));
    }

    /**
     * The stored value plus or minus a fractional amount, exact at any size of the stored value.
     */
    private static function shift(Rounding $rounding, int|string $current, float $amount, bool $increase): int|string
    {
        $whole = BigInt::toNative(\sprintf('%.0f', \floor($amount)));
        $fraction = $amount - \floor($amount);

        if ($increase) {
            return $rounding->apply(BigInt::add($current, $whole), $fraction);
        }

        return $fraction === 0.0
            ? BigInt::subtract($current, $whole)
            : $rounding->apply(BigInt::subtract(BigInt::subtract($current, $whole), 1), 1 - $fraction);
    }
}
