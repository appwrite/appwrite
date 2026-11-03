<?php

namespace Utopia\Agents;

/**
 * Why a model stopped generating.
 */
enum FinishReason: string
{
    /** The model reached a natural stopping point or a stop sequence. */
    case Stop = 'stop';

    /** The output token limit was reached. */
    case Length = 'length';

    /** The model is waiting for tool results. */
    case ToolCalls = 'tool-calls';

    /** The provider filtered or refused the output. */
    case ContentFilter = 'content-filter';

    /** Any other reason the provider reported. */
    case Other = 'other';
}
