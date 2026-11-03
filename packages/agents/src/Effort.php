<?php

namespace Utopia\Agents;

/**
 * How much a reasoning model should think before answering. Adapters map it
 * to the provider's own control: an effort level or a thinking token budget.
 */
enum Effort: string
{
    /** Reasoning off, where the model allows it. */
    case None = 'none';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case XHigh = 'xhigh';
    case Max = 'max';

    /**
     * Thinking budget for providers that take a token count; 0 turns thinking off.
     */
    public function budget(): int
    {
        return match ($this) {
            self::None => 0,
            self::Low => 1024,
            self::Medium => 8192,
            self::High => 24576,
            self::XHigh => 32768,
            self::Max => 63999,
        };
    }
}
