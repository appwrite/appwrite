<?php

namespace Appwrite\Tests\Async;

use Appwrite\Tests\Async\Exceptions\Critical;
use PHPUnit\Framework\Constraint\Constraint;

final class Eventually extends Constraint
{
    public function __construct(private int $timeoutMs, private int $waitMs)
    {
    }

    public function evaluate(mixed $probe, string $description = '', bool $returnResult = false): ?bool
    {
        if (!is_callable($probe)) {
            throw new \Exception('Probe must be a callable');
        }

        $start = microtime(true);
        $lastException = null;

        do {
            try {
                $probe();
                return true;
            } catch (Critical $exception) {
                throw $exception;
            } catch (\Exception $exception) {
                $lastException = $exception;
            }

            usleep($this->waitMs * 1000);
        } while (microtime(true) - $start < $this->timeoutMs / 1000);

        if ($returnResult) {
            return false;
        }

        throw $lastException;
    }

    protected function failureDescription(mixed $other): string
    {
        return 'the given probe was satisfied within ' . $this->timeoutMs . 'ms.';
    }

    public function toString(): string
    {
        return 'Eventually';
    }
}
