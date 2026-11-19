<?php

namespace Appwrite\Schedule;

/**
 * Keeps a long-running scheduler loop alive across failures such as a
 * dropped Redis connection. Each failure is reported and the loop is
 * started again after a pause, so one bad connection costs a few seconds
 * instead of ending the process. The loop ends when an attempt returns.
 */
final class Supervisor
{
    /** @var \Closure(int): void */
    private \Closure $sleep;

    /**
     * @param int $backoff seconds to wait before starting again after a failure
     * @param (\Closure(\Throwable): void)|null $onError reports each failure
     * @param (callable(int): void)|null $sleep pauses for the given seconds
     */
    public function __construct(
        private readonly int $backoff,
        private readonly ?\Closure $onError = null,
        ?callable $sleep = null,
    ) {
        $this->sleep = $sleep === null ? \sleep(...) : $sleep(...);
    }

    /**
     * @param callable(): mixed $attempt runs the loop once, until it returns or throws
     */
    public function run(callable $attempt): void
    {
        while (true) {
            try {
                $attempt();

                return;
            } catch (\Throwable $error) {
                if ($this->onError !== null) {
                    ($this->onError)($error);
                }
            }

            ($this->sleep)($this->backoff);
        }
    }
}
