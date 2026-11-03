<?php

namespace Utopia\Agents;

/**
 * How much a reasoning model should think before answering. Adapters map it
 * to the provider's own control: an effort level or a thinking token budget.
 */
enum Effort: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    /**
     * Thinking budget for providers that take a token count.
     */
    public function budget(): int
    {
        return match ($this) {
            self::Low => 1024,
            self::Medium => 8192,
            self::High => 24576,
        };
    }
}
