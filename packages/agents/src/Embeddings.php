<?php

namespace Utopia\Agents;

final readonly class Embeddings
{
    /**
     * @param  list<list<float>>  $vectors  One vector per input text, in input order
     * @param  int|null  $tokens  Input tokens processed, when the provider reports it
     * @param  int|null  $duration  Processing time in nanoseconds, when the provider reports it
     */
    public function __construct(
        public array $vectors,
        public ?int $tokens = null,
        public ?int $duration = null,
    ) {
    }
}
