<?php

namespace Utopia\Migration\Tests\General;

enum TargetTimeoutServerBehaviour: string
{
    public const int STREAMED_BYTES = 20;

    public const int STREAM_INTERVAL_MICROSECONDS = 200_000;

    case Streaming = 'streaming';
    case Stalled = 'stalled';
    case Silent = 'silent';
}
