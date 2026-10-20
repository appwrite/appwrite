<?php

declare(strict_types=1);

namespace Utopia\Queue;

/**
 * A message the broker moved to its dead stream, reported through onError. It is not a
 * broker failure, so a reporter that alerts on errors should tell it apart by type.
 * The handler's own failure, if any, is getPrevious() and was already reported.
 */
class DeadLetter extends \RuntimeException
{
    /**
     * @param array<string, string> $fields The dead-letter headers without their prefix.
     */
    public function __construct(
        public readonly string $queue,
        public readonly array $fields,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            "Dead-lettered a message from queue \"{$queue}\": " . json_encode(array_change_key_case($fields), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            previous: $previous,
        );
    }
}
