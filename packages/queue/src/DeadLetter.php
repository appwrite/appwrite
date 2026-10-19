<?php

declare(strict_types=1);

namespace Utopia\Queue;

/**
 * A message the broker moved to its dead stream, as reported through onError.
 *
 * Not a failure of the broker: the dead stream is where a message is meant to go
 * once it cannot succeed. It travels the error channel because that is the one the
 * broker has, so a reporter that alerts on errors tells it apart by type and can
 * log it instead. The handler's own failure, when there was one, is getPrevious();
 * the adapter has already reported that through its error callback.
 *
 *     onError: function (\Throwable $error) {
 *         if ($error instanceof DeadLetter) {
 *             $logger->info($error->getMessage(), $error->fields);
 *
 *             return;
 *         }
 *         // ...
 *     }
 */
class DeadLetter extends \RuntimeException
{
    /**
     * @param array<string, string> $fields What the dead letter records about why it
     *        died, keyed like its headers without the prefix: Reason, Deliveries,
     *        Stream, Sequence, Host, At and, when the failure is known, Error.
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
