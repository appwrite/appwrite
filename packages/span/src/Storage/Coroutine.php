<?php

declare(strict_types=1);

namespace Utopia\Span\Storage;

use Utopia\Span\Span;

/**
 * Coroutine-aware storage for Swoole environments
 * Stores spans per-coroutine using Swoole's coroutine context
 */
class Coroutine implements Storage
{
    private const string CONTEXT_KEY = '__utopia_span';

    public function get(): ?Span
    {
        $cid = \Swoole\Coroutine::getCid();

        if ($cid === -1) {
            return null;
        }

        $context = \Swoole\Coroutine::getContext($cid);

        if ($context === null) {
            return null;
        }

        $span = $context[self::CONTEXT_KEY] ?? null;

        return $span instanceof Span ? $span : null;
    }

    public function set(?Span $span): void
    {
        $cid = \Swoole\Coroutine::getCid();

        if ($cid === -1) {
            return;
        }

        $context = \Swoole\Coroutine::getContext($cid);

        if ($context === null) {
            return;
        }

        $context[self::CONTEXT_KEY] = $span;
    }
}
